#!/usr/bin/env node
'use strict';

/** Real PHP view + Bootstrap + Chromium. Every request is fulfilled locally; no CRM/API. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const origin = 'https://crm.example.test';
const php = process.env.ASAAS_TEST_PHP || 'php';
const modes = ['extended', 'limited', 'extended-first', 'blocked'];
const pages = Object.fromEntries(modes.map(mode => [mode, execFileSync(php,
    [path.join(__dirname, 'fixtures/card-ui-page.php'), mode],
    {cwd: root, encoding: 'utf8', maxBuffer: 1024 * 1024, timeout: 10000})]));
const assets = {
    '/_fixture/bootstrap.css': require.resolve('bootstrap/dist/css/bootstrap.min.css'),
    '/modules/asaas/assets/css/asaas-card-checkout.css': path.join(root, 'assets/css/asaas-card-checkout.css'),
    '/modules/asaas/assets/js/asaas-card-checkout.js': path.join(root, 'assets/js/asaas-card-checkout.js')
};
const artifacts = path.join(__dirname, 'artifacts');
const errors = [];
const unexpected = [];
const contexts = [];
let currentScenario = 'inicialização';
let scenarios = 0;
const watchdog = setTimeout(() => {
    console.error('Card browser exceeded its 60-second deadline in: ' + currentScenario);
    process.exit(1);
}, 60000);
watchdog.unref();

async function scenario(name, work) {
    currentScenario = name;
    await work();
    scenarios++;
    console.log('OK  ' + name);
}

async function main() {
    fs.mkdirSync(artifacts, {recursive: true});
    const browser = await chromium.launch({headless: true, timeout: 20000});
    async function fixture(mode = 'extended', javaScriptEnabled = true) {
        const context = await browser.newContext({viewport: {width: 1280, height: 960}, javaScriptEnabled, serviceWorkers: 'block'});
        contexts.push(context);
        context.setDefaultTimeout(5000);
        await context.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin === origin && request.method() === 'GET') {
                if (url.pathname === '/_fixture/card/' + mode) {
                    return route.fulfill({status: 200, contentType: 'text/html; charset=utf-8', body: pages[mode]});
                }
                if (assets[url.pathname]) {
                    return route.fulfill({status: 200,
                        contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript',
                        body: fs.readFileSync(assets[url.pathname])});
                }
                if (url.pathname === '/favicon.ico') { return route.fulfill({status: 204, body: ''}); }
            }
            unexpected.push(request.method() + ' ' + url.origin + url.pathname);
            return route.abort('blockedbyclient');
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/_fixture/card/' + mode, {waitUntil: 'load', timeout: 15000});
        return page;
    }
    try {
        await scenario('checkout mantém resumo, campos e ação dentro da tela de 320 a 1280 px', async () => {
            const page = await fixture();
            assert.equal(await page.locator('#installmentCount').inputValue(), '1');
            assert.equal(await page.locator('#asaas-card-installment-help').isHidden(), true);
            const fields = ['installmentCount', 'holderName', 'cardNumber', 'expirationMonth', 'expirationYear',
                'securityCode', 'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'];
            for (const width of [320, 375, 768, 1280]) {
                await page.setViewportSize({width, height: 960});
                const layout = await page.evaluate(names => ({
                    pageWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                    fields: names.concat('payment', 'asaas-card-price-summary').map(name => {
                        const node = document.getElementById(name);
                        const {left, right, width, height} = node.getBoundingClientRect();
                        return {name, left, right, width, height};
                    })
                }), fields);
                assert.ok(layout.pageWidth <= width + 1, width + 'px: checkout must not overflow horizontally: ' + JSON.stringify(layout));
                for (const field of layout.fields) {
                    assert.ok(field.width > 0 && field.height > 0 && field.left >= -1 && field.right <= width + 1,
                        width + 'px: field/action/summary must remain visible and within the page: ' + JSON.stringify(field));
                }
                for (const name of fields) {
                    assert.equal(await page.locator('label[for="' + name + '"]').count(), 1, name + ' must keep its label.');
                }
                assert.equal(await page.locator('#securityCode').getAttribute('type'), 'password');
                await page.screenshot({path: path.join(artifacts, 'card-checkout-' + width + '.png'), fullPage: true});
            }
            assert.equal(await page.locator('#asaas-card-checkout input[name="csrf_fixture"]').inputValue(), 'fixture-csrf-token');
            assert.equal(await page.locator('#asaas-card-checkout').getAttribute('autocomplete'), 'off');
        });

        await scenario('aviso de bandeira acompanha apenas parcelas disponíveis de 13 a 21', async () => {
            const page = await fixture();
            const select = page.locator('#installmentCount');
            const help = page.locator('#asaas-card-installment-help');
            for (const count of [1, 3, 12, 13, 21, 12, 3, 1]) {
                await select.selectOption(String(count));
                assert.equal(await help.isVisible(), count >= 13, 'Notice visibility for ' + count + ' installments.');
                if (count >= 13) {
                    assert.match(await help.textContent(), /Visa.*Mastercard/i);
                }
            }
            await select.selectOption('21');
            assert.match(await page.locator('[data-card-price-total]').textContent(), /1\.050,30/);
            assert.match(await page.locator('[data-card-price-fee]').textContent(), /50,30/);
            await page.screenshot({path: path.join(artifacts, 'card-checkout-21-installments.png'), fullPage: true});

            const limited = await fixture('limited');
            assert.deepEqual(await limited.locator('#installmentCount option').evaluateAll(options => options.map(option => option.value)), ['1', '3', '12']);
            for (const count of ['1', '3', '12']) {
                await limited.locator('#installmentCount').selectOption(count);
                assert.equal(await limited.locator('#asaas-card-installment-help').isHidden(), true, 'No extended notice without a real extended offer.');
            }
        });

        await scenario('primeira oferta estendida apresenta a condição sem depender de mudança no seletor', async () => {
            const page = await fixture('extended-first');
            assert.equal(await page.locator('#installmentCount').inputValue(), '13');
            assert.equal(await page.locator('#asaas-card-installment-help').isVisible(), true);
        });

        await scenario('sem JavaScript mantém preços e identifica a condição apenas nas ofertas estendidas', async () => {
            const page = await fixture('extended', false);
            assert.equal(await page.locator('#asaas-card-installment-help').isHidden(), true, 'Initial 1x notice must not flash before JavaScript.');
            assert.equal(await page.locator('#asaas-card-checkout noscript table').isVisible(), true);
            const offerRows = await page.locator('#asaas-card-checkout noscript tbody tr').evaluateAll(rows =>
                Object.fromEntries(rows.map(row => [row.cells[0].firstChild.textContent.trim(), row.textContent])));
            assert.match(offerRows['13'], /Visa.*Mastercard/i);
            assert.doesNotMatch(offerRows['3'], /Visa|Mastercard/i);
            const first = await fixture('extended-first', false);
            assert.equal(await first.locator('#asaas-card-installment-help').isHidden(), true, 'Without JavaScript, the condition must stay attached to each offer.');
            assert.match(await first.locator('noscript tbody tr').first().textContent(), /13.*Visa.*Mastercard/i);
            await first.locator('#installmentCount').selectOption('1');
            assert.equal(await first.locator('#asaas-card-installment-help').isHidden(), true, 'Without JavaScript, changing to 1x must not retain a standalone extended notice.');
            const limited = await fixture('limited', false);
            assert.equal(await limited.locator('#asaas-card-installment-help').isHidden(), true);
        });

        await scenario('pagamento pendente preserva consulta e bloqueia outro formulário de cobrança', async () => {
            const page = await fixture('blocked');
            assert.equal(await page.locator('#asaas-card-checkout, #cardNumber, #payment').count(), 0);
            assert.match(await page.locator('body').innerText(), /Aguardando confirmação do pagamento/);
            assert.equal(await page.locator('a[href="' + origin + '/invoice/42/card-browser-fixture"]').count(), 1);
        });

        assert.deepEqual(errors, [], 'The rendered view must not raise browser errors.');
        assert.deepEqual(unexpected, [], 'No request may leave the isolated fixtures or submit a payment.');
        console.log('Card browser: ' + scenarios + ' cenários aprovados em Chromium; sem cobrança ou acesso externo.');
    } finally {
        for (const context of contexts) { await context.close(); }
        await browser.close();
        clearTimeout(watchdog);
    }
}
main().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
