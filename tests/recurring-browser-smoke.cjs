#!/usr/bin/env node
'use strict';

/** Actual PHP view + Bootstrap 3 + Chromium, entirely intercepted HTTP; no Asaas/CRM access.
 * ASAAS_TEST_PHP selects PHP. --contract-only validates the rendered fixture without npm/browser.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.ASAAS_TEST_PHP || 'php';
const origin = 'https://crm.example.test';
const recurringPath = '/asaas/recurring/index/recurring-browser-fixture';
const invoicePath = '/invoice/42/recurring-browser-fixture';
const modes = ['inactive', 'active', 'suspended', 'uncertain', 'paid', 'guest', 'invoice', 'hostile'];
const fixtures = Object.fromEntries(modes.map(mode => [mode, execFileSync(php,
    [path.join(__dirname, 'fixtures/recurring-ui-page.php'), mode], {cwd: root, encoding: 'utf8', maxBuffer: 1024 * 1024})]));
let scenarios = 0;
async function scenario(name, work) { await work(); scenarios++; console.log('OK  ' + name); }

async function main() {
    await scenario('view real preserva CSRF, consentimento explícito, preços e estados sem dados de cartão', async () => {
        const counts = {inactive: 1, active: 2, suspended: 2, uncertain: 1, paid: 1, guest: 0, invoice: 0, hostile: 1};
        for (const mode of modes) {
            assert.equal((fixtures[mode].match(/<form\b/g) || []).length, counts[mode], mode + ' form count');
            assert.doesNotMatch(fixtures[mode], /name="(?:creditCardToken|cardToken|installmentCount)"/);
            assert.doesNotMatch(fixtures[mode], /name="recurringConsent"[^>]*\bchecked\b/);
            assert.doesNotMatch(fixtures[mode], /name="(?:cardNumber|securityCode|expirationMonth|expirationYear)"[^>]*\bvalue=/);
        }
        for (const mode of ['inactive', 'active', 'suspended']) {
            assert.match(fixtures[mode], /name="csrf_fixture" value="fixture-csrf-token"/);
            assert.match(fixtures[mode], new RegExp('name="recurringNonce" value="' + 'b'.repeat(64) + '"'));
            assert.match(fixtures[mode], /name="recurringConsent"[^>]*required/);
            assert.match(fixtures[mode], /Total desta cobrança em 1x/);
            assert.match(fixtures[mode], /R\$ 103,59/);
        }
        assert.doesNotMatch(fixtures.uncertain, /id="asaas-recurring-enroll"|class="asaas-recurring-alternative/);
        assert.doesNotMatch(fixtures.paid, /id="asaas-recurring-enroll"/);
        assert.match(fixtures.paid, /id="asaas-recurring-revoke"/);
        assert.match(fixtures.hostile, /&lt;script&gt;window\.fixtureXss/);
        assert.doesNotMatch(fixtures.hostile, /<script>window\.fixtureXss|<svg onload=/);
        assert.match(fixtures.invoice, /data-asaas-recurring-invoice-option/);
        assert.match(fixtures.invoice, /value="asaas" checked/);
        assert.doesNotMatch(fs.readFileSync(path.join(root, 'views/payment.php'), 'utf8'), /asaas\/recurring\/|data-asaas-recurring/);
    });
    if (process.argv.includes('--contract-only')) {
        console.log('Recurring browser: ' + scenarios + ' contrato de renderização aprovado; Chromium não executado neste modo.');
        return;
    }

    const {chromium} = require('playwright');
    const assets = {
        '/_fixture/bootstrap.css': require.resolve('bootstrap/dist/css/bootstrap.min.css'),
        '/modules/asaas/assets/css/asaas-recurring.css': path.join(root, 'assets/css/asaas-recurring.css'),
        '/modules/asaas/assets/js/asaas-recurring.js': path.join(root, 'assets/js/asaas-recurring.js')
    };
    const artifacts = path.join(__dirname, 'artifacts');
    fs.mkdirSync(artifacts, {recursive: true});
    const browser = await chromium.launch({headless: true});
    const errors = [];
    const unexpected = [];
    const contexts = [];
    async function fixturePage(initialMode = 'inactive', viewport = {width: 1280, height: 960}) {
        const context = await browser.newContext({viewport});
        contexts.push(context);
        await context.addInitScript(() => {
            window.__recurringPrivacyAccesses = [];
            const forbidden = name => function () { window.__recurringPrivacyAccesses.push(name); throw new Error('Unexpected privacy sink: ' + name); };
            Object.defineProperty(document, 'cookie', {configurable: true, get: forbidden('cookie'), set: forbidden('cookie')});
            for (const key of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) {
                Object.defineProperty(window, key, {configurable: true, get: forbidden(key)});
            }
            window.fetch = forbidden('fetch');
            window.XMLHttpRequest = forbidden('XMLHttpRequest');
            navigator.sendBeacon = forbidden('sendBeacon');
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        let displayMode = initialMode;
        let afterPost = 'paid';
        let release = null;
        const submissions = [];
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== origin) { unexpected.push(url.origin); return route.abort(); }
            if (assets[url.pathname]) {
                return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(assets[url.pathname])});
            }
            if (url.pathname === '/favicon.ico') { return route.fulfill({status: 204}); }
            if (request.method() === 'POST' && url.pathname === recurringPath) {
                const body = new URLSearchParams(request.postData() || '');
                submissions.push(body);
                if (body.get('csrf_fixture') !== 'fixture-csrf-token' || body.get('recurringNonce') !== 'b'.repeat(64)) {
                    return route.fulfill({status: 403, body: 'Invalid fixture CSRF.'});
                }
                await new Promise(resolve => { release = resolve; });
                displayMode = afterPost;
                return route.fulfill({status: 303, headers: {Location: origin + recurringPath, 'Cache-Control': 'no-store'}, body: ''});
            }
            if (request.method() === 'GET' && [recurringPath, invoicePath, '/_fixture/page'].includes(url.pathname)) {
                return route.fulfill({contentType: 'text/html; charset=utf-8', headers: {'Cache-Control': 'no-store'},
                    body: fixtures[url.pathname === invoicePath ? 'invoice' : (url.pathname === recurringPath && displayMode === 'invoice' ? 'inactive' : displayMode)]});
            }
            unexpected.push(request.method() + ' ' + url.pathname);
            return route.abort();
        });
        await page.goto(origin + '/_fixture/page');
        return {page, submissions, release() { assert.ok(release, 'A native POST response must be pending.'); release(); }, setAfterPost(mode) { afterPost = mode; }};
    }
    async function fillCard(page) {
        for (const [field, value] of Object.entries({holderName: 'Titular de teste', cardNumber: '4111111111111111', expirationMonth: '12', expirationYear: '2030', securityCode: '123'})) {
            await page.locator('#recurring-' + field).fill(value);
        }
    }
    async function assertNoOverflow(page) {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'No horizontal document overflow.');
        assert.equal(await page.evaluate(() => [...document.querySelectorAll('#asaas-recurring input:not([type=hidden]), #asaas-recurring button')]
            .every(node => { const r = node.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 1; })), true, 'Fields and actions must remain in the viewport.');
    }
    try {
        await scenario('Chromium renderiza os estados sem autorização, ativo, suspenso, incerto, pago e login', async () => {
            const states = {inactive: [1, 0, 'Ainda não autorizado'], active: [1, 1, 'Recorrência ativa'], suspended: [1, 1, 'Recorrência suspensa'],
                uncertain: [0, 1, 'Confirmação pendente'], paid: [0, 1, 'Pagamento confirmado'], guest: [0, 0, 'Entre na conta do cliente']};
            for (const [mode, [enroll, revoke, label]] of Object.entries(states)) {
                const {page} = await fixturePage(mode);
                assert.equal(await page.locator('#asaas-recurring-enroll').count(), enroll);
                assert.equal(await page.locator('#asaas-recurring-revoke').count(), revoke);
                assert.equal(await page.getByText(label, {exact: false}).count() > 0, true, mode + ' state explanation');
                assert.equal(await page.locator('.asaas-recurring-alternative').count(), ['inactive', 'active', 'suspended'].includes(mode) ? 1 : 0);
                assert.deepEqual(await page.evaluate(() => window.__recurringPrivacyAccesses), []);
                await assertNoOverflow(page);
                await page.screenshot({path: path.join(artifacts, 'recurring-' + mode + '.png'), fullPage: true});
            }
        });
        await scenario('entrada na fatura permanece separada do menu boleto, Pix e cartão pontual', async () => {
            const fixture = await fixturePage('invoice');
            assert.equal(await fixture.page.locator('[name=paymentmode][value=asaas]:checked').count(), 1);
            const link = fixture.page.locator('[data-asaas-recurring-invoice-option] a');
            assert.equal(await link.getAttribute('href'), origin + recurringPath);
            await Promise.all([fixture.page.waitForURL(origin + recurringPath), link.click()]);
            assert.equal(await fixture.page.locator('#asaas-recurring-enroll').count(), 1);
            assert.equal(fixture.submissions.length, 0, 'Opening enrollment cannot capture a card.');
        });
        await scenario('formulário nativo exige consentimento, envia CSRF e impede operação duplicada', async () => {
            const fixture = await fixturePage('active');
            const page = fixture.page;
            await fillCard(page);
            assert.equal(await page.locator('#recurring-consent').isChecked(), false);
            assert.equal(await page.locator('#asaas-recurring-enroll').evaluate(form => form.checkValidity()), false);
            await page.locator('#asaas-recurring-enroll [data-recurring-submit]').click();
            assert.equal(fixture.submissions.length, 0, 'Unchecked consent must not submit.');
            await page.locator('#recurring-cardNumber').fill('invalid-card-value');
            assert.equal(await page.locator('#recurring-cardNumber').evaluate(input => input.validity.patternMismatch), true);
            await page.locator('#recurring-cardNumber').fill('4111111111111111');
            await page.locator('#recurring-consent').check();
            const request = page.waitForRequest(req => req.method() === 'POST' && new URL(req.url()).pathname === recurringPath);
            await page.locator('#asaas-recurring-enroll [data-recurring-submit]').click({noWaitAfter: true});
            await request;
            // The submit handler locks synchronously before native navigation starts. Read
            // that state directly: RAF polling can stall while this test holds the POST
            // response. Never wait for a later frame to prove a synchronous submit guard.
            const submitState = await page.evaluate(() => ({
                path: window.location.pathname,
                buttons: [...document.querySelectorAll('[data-recurring-submit]')]
                    .map(button => ({form: button.closest('form').id, disabled: button.disabled})),
                forms: [...document.querySelectorAll('[data-asaas-recurring-form]')]
                    .map(form => ({id: form.id, busy: form.getAttribute('aria-busy')})),
                statusVisible: !document.getElementById('asaas-recurring-status').classList.contains('hidden')
            }));
            const submitDiagnostic = JSON.stringify({state: submitState, pageErrors: errors});
            assert.equal(submitState.buttons.length, 2, 'Both operation buttons must exist: ' + submitDiagnostic);
            assert.equal(submitState.buttons.every(button => button.disabled), true, 'Native submit must synchronously lock both operations: ' + submitDiagnostic);
            assert.equal(submitState.forms.length, 2, 'Both operation forms must exist: ' + submitDiagnostic);
            assert.equal(submitState.forms.every(form => form.busy === 'true') && submitState.statusVisible, true,
                'Both forms must report the pending operation accessibly: ' + submitDiagnostic);
            assert.equal(fixture.submissions.length, 1);
            const body = fixture.submissions[0];
            assert.equal(body.get('action'), 'enroll');
            assert.equal(body.get('csrf_fixture'), 'fixture-csrf-token');
            assert.equal(body.get('recurringNonce'), 'b'.repeat(64));
            assert.equal(body.get('recurringConsent'), '1');
            assert.equal(body.get('consentVersion'), '2026-11-v1');
            assert.equal(body.get('cardQuote'), 'a'.repeat(64));
            assert.equal(body.get('cardNumber'), '4111111111111111');
            assert.equal(body.get('securityCode'), '123');
            assert.equal(body.has('installmentCount') || body.has('cardToken') || body.has('creditCardToken'), false);
            assert.deepEqual(await page.evaluate(() => [...document.querySelectorAll('[data-asaas-recurring-form]')]
                .map(form => form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})))), [false, false]);
            // The native body has already been serialized. Exercise the actual departure cleanup.
            const cleared = await page.evaluate(() => {
                window.dispatchEvent(new PageTransitionEvent('pagehide', {persisted: false}));
                return {values: [...document.querySelectorAll('[id^="recurring-"]:not([type=checkbox])')].map(field => [field.value, field.defaultValue]),
                    consent: document.getElementById('recurring-consent').checked, privacy: window.__recurringPrivacyAccesses};
            });
            assert.equal(cleared.values.every(([value, defaultValue]) => value === '' && defaultValue === ''), true);
            assert.equal(cleared.consent, false);
            assert.deepEqual(cleared.privacy, []);
            fixture.release();
            await page.waitForURL(origin + recurringPath);
            await page.locator('.asaas-recurring-state').waitFor();
            assert.equal(await page.locator('#asaas-recurring-enroll').count(), 0, 'PRG renders current paid state without the earlier input.');
            assert.equal(await page.locator('#asaas-recurring-revoke').count(), 1);
            assert.equal(fixture.submissions.length, 1);
            assert.deepEqual(await page.evaluate(() => window.__recurringPrivacyAccesses), []);
        });
        await scenario('revogação usa apenas ação, CSRF e nonce, limpa dados digitados e não cobra', async () => {
            const fixture = await fixturePage('active');
            fixture.setAfterPost('inactive');
            await fillCard(fixture.page);
            const request = fixture.page.waitForRequest(req => req.method() === 'POST');
            await fixture.page.locator('#asaas-recurring-revoke [data-recurring-submit]').click({noWaitAfter: true});
            await request;
            assert.equal(fixture.submissions.length, 1);
            assert.deepEqual([...fixture.submissions[0].keys()].sort(), ['action', 'csrf_fixture', 'recurringNonce']);
            assert.equal(fixture.submissions[0].get('action'), 'revoke');
            assert.equal(await fixture.page.locator('#recurring-cardNumber').inputValue(), '');
            assert.equal(await fixture.page.locator('#recurring-securityCode').inputValue(), '');
            assert.equal(await fixture.page.locator('#asaas-recurring-enroll [data-recurring-submit]').isDisabled(), true);
            fixture.release();
            await fixture.page.waitForURL(origin + recurringPath);
            await fixture.page.locator('#asaas-recurring-enroll').waitFor();
            assert.equal(await fixture.page.locator('#asaas-recurring-revoke').count(), 0);
            assert.equal(await fixture.page.locator('#recurring-consent').isChecked(), false);
        });
        await scenario('texto e dados do titular são escapados sem injeção HTML ou chamadas extras', async () => {
            const {page} = await fixturePage('hostile');
            assert.equal(await page.evaluate(() => window.fixtureXss === true), false);
            assert.equal(await page.locator('#asaas-recurring img, #asaas-recurring svg').count(), 0);
            assert.equal(await page.locator('#recurring-holderEmail').inputValue(), '"><svg onload="window.fixtureXss = true;">');
            assert.equal(await page.locator('#recurring-consent').isChecked(), false);
        });
        await scenario('mobile mantém formulário, consentimento e ações dentro da página', async () => {
            const {page} = await fixturePage('active', {width: 390, height: 844});
            await assertNoOverflow(page);
            assert.equal(await page.locator('#recurring-consent').isVisible(), true);
            assert.equal(await page.locator('#asaas-recurring-revoke [data-recurring-submit]').isVisible(), true);
            await page.screenshot({path: path.join(artifacts, 'recurring-mobile.png'), fullPage: true});
        });
        assert.deepEqual(errors, [], 'No browser script errors.');
        assert.deepEqual(unexpected, [], 'All requests must stay inside the isolated fixture.');
        console.log('Recurring browser: ' + scenarios + ' cenários aprovados em Chromium; API e armazenamento não utilizados.');
    } finally {
        for (const context of contexts) { await context.close(); }
        await browser.close();
    }
}
main().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
