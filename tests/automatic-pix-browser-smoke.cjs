#!/usr/bin/env node
'use strict';

/** Actual PHP view, Bootstrap 3 and Chromium against loopback fixtures. No bank access. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.ASAAS_TEST_PHP || 'php';
const pixPath = '/asaas/automatic_pix/index/pix-browser-fixture';
const modes = ['inactive', 'created', 'active', 'uncertain', 'cancelled', 'guest', 'invoice', 'hostile'];
const render = (mode, origin = 'https://crm.example.test') => execFileSync(php,
    [path.join(__dirname, 'fixtures/automatic-pix-ui-page.php'), mode],
    {cwd: root, encoding: 'utf8', timeout: 10000, env: {...process.env, ASAAS_PIX_FIXTURE_ORIGIN: origin}});
let scenarios = 0;
const scenario = async (name, run) => { await run(); scenarios++; console.log('OK  ' + name); };
const watchdog = setTimeout(() => { console.error('Pix browser deadline exceeded.'); process.exit(1); }, 90000);
watchdog.unref();

async function main() {
    const fixtures = Object.fromEntries(modes.map(mode => [mode, render(mode)]));
    await scenario('view real exige consentimento e nonce, separa pagamento de autorização e escapa texto', async () => {
        assert.match(fixtures.inactive, /name="pixConsent"[^>]*required/);
        assert.doesNotMatch(fixtures.inactive, /name="pixConsent"[^>]*checked/);
        assert.match(fixtures.inactive, new RegExp('name="pixNonce" value="' + 'b'.repeat(64) + '"'));
        assert.match(fixtures.inactive, /name="csrf_fixture" value="fixture-csrf-token"/);
        assert.match(fixtures.created, /não garante a ativação da autorização/);
        assert.doesNotMatch(fixtures.uncertain, /Pagar somente esta fatura|name="action" value="enroll"/);
        assert.doesNotMatch(fixtures.guest, /<form\b|name="pixNonce"/);
        assert.match(fixtures.hostile, /&lt;script&gt;window\.fixtureXss/);
        assert.doesNotMatch(fixtures.hostile, /<script>window\.fixtureXss|<img src=x/);
        for (const html of Object.values(fixtures)) {
            assert.doesNotMatch(html, /name="(?:creditCard|cardNumber|cvv|ccv|apiKey|authorizationId)"|localStorage|sessionStorage|document\.cookie/);
        }
    });
    if (process.argv.includes('--contract-only')) {
        console.log('Pix UI: ' + scenarios + ' contrato aprovado; Chromium não executado.');
        return;
    }
    const {chromium} = require('playwright');
    const assets = {
        '/_fixture/bootstrap.css': require.resolve('bootstrap/dist/css/bootstrap.min.css'),
        '/modules/asaas/assets/css/asaas-automatic-pix.css': path.join(root, 'assets/css/asaas-automatic-pix.css'),
    };
    const errors = [], unexpected = [], submissions = [], requests = [];
    let origin;
    const cache = new Map();
    const html = mode => { if (!cache.has(mode)) { cache.set(mode, render(mode, origin)); } return cache.get(mode); };
    const server = http.createServer(async (request, response) => {
        const send = (code, type, value, headers = {}) => { response.writeHead(code, {'Content-Type': type, 'Cache-Control': 'no-store', ...headers}); response.end(value); };
        try {
            const url = new URL(request.url, origin);
            requests.push({method: request.method, path: url.pathname});
            if (request.headers.host !== new URL(origin).host) { return send(400, 'text/plain', 'Invalid host.'); }
            if (request.method === 'GET' && assets[url.pathname]) { return send(200, 'text/css', fs.readFileSync(assets[url.pathname])); }
            if (url.pathname === '/favicon.ico') { return send(204, 'text/plain', ''); }
            if (request.method === 'GET' && (url.pathname === '/_fixture/page' || url.pathname === pixPath)) {
                const mode = url.searchParams.get('mode') || 'created';
                return modes.includes(mode) ? send(200, 'text/html; charset=utf-8', html(mode)) : send(404, 'text/plain', 'Unknown fixture.');
            }
            if (request.method === 'POST' && url.pathname === pixPath) {
                const chunks = []; let bytes = 0;
                for await (const chunk of request) { bytes += chunk.length; if (bytes > 8192) { return send(413, 'text/plain', 'Too large.'); } chunks.push(chunk); }
                const body = new URLSearchParams(Buffer.concat(chunks).toString('utf8')); submissions.push(body);
                if (body.get('csrf_fixture') !== 'fixture-csrf-token' || body.get('pixNonce') !== 'b'.repeat(64)) { return send(403, 'text/plain', 'Invalid nonce.'); }
                return send(303, 'text/plain', '', {Location: origin + pixPath});
            }
            unexpected.push(request.method + ' ' + url.pathname); return send(404, 'text/plain', 'Unknown route.');
        } catch (error) { errors.push(error.message); if (!response.headersSent) { send(500, 'text/plain', 'Fixture error.'); } else { response.destroy(); } }
    });
    server.requestTimeout = server.headersTimeout = 15000;
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    origin = 'http://127.0.0.1:' + server.address().port;
    const artifacts = path.join(__dirname, 'artifacts'); fs.mkdirSync(artifacts, {recursive: true});
    let browser;
    try {
        browser = await chromium.launch({headless: true, timeout: 20000});
        const context = await browser.newContext();
        await context.route('**/*', route => {
            const url = route.request().url();
            if (url.startsWith(origin + '/')) { return route.continue(); }
            unexpected.push(url); return route.abort();
        });
        await context.addInitScript(() => {
            window.__pixStorageAccess = [];
            const forbidden = name => function () { window.__pixStorageAccess.push(name); throw new Error('Unexpected storage access: ' + name); };
            Object.defineProperty(document, 'cookie', {configurable: true, get: forbidden('cookie'), set: forbidden('cookie')});
            for (const name of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) { Object.defineProperty(window, name, {configurable: true, get: forbidden(name)}); }
        });
        const page = await context.newPage(); page.setDefaultTimeout(10000); page.on('pageerror', error => errors.push(error.message));
        await scenario('páginas e card compactos em 360, 768 e 1280 pixels sem sobreposição aos totais', async () => {
            for (const width of [360, 768, 1280]) {
                await page.setViewportSize({width, height: 960});
                for (const mode of ['inactive', 'created', 'guest', 'invoice']) {
                    await page.goto(origin + '/_fixture/page?mode=' + mode);
                    const geometry = await page.evaluate(() => {
                        const card = document.querySelector('.asaas-pix-invoice-card');
                        const totals = document.querySelector('[data-fixture-totals]');
                        const next = document.querySelector('[data-fixture-payment]');
                        return {width: window.innerWidth, scroll: document.documentElement.scrollWidth,
                            card: card && {top: card.getBoundingClientRect().top, height: card.getBoundingClientRect().height, bottom: card.getBoundingClientRect().bottom},
                            totalBottom: totals && totals.getBoundingClientRect().bottom, paymentTop: next && next.getBoundingClientRect().top,
                            storage: window.__pixStorageAccess};
                    });
                    assert.ok(geometry.scroll <= width + 1, mode + ': no horizontal overflow at ' + width);
                    assert.deepEqual(geometry.storage, []);
                    if (mode === 'invoice') {
                        assert.ok(geometry.card.top >= geometry.totalBottom - 1, 'Card must clear native floated totals.');
                        assert.ok(geometry.paymentTop >= geometry.card.bottom - 1, 'Native payment section must follow the card.');
                        assert.ok(geometry.card.height < (width < 600 ? 240 : 150), 'No oversized blank card.');
                    }
                    if ([360, 1280].includes(width) && ['inactive', 'invoice'].includes(mode)) {
                        await page.screenshot({path: path.join(artifacts, 'pix-' + mode + '-' + width + '.png'), fullPage: true});
                    }
                }
            }
        });
        await scenario('consentimento explícito e POST nativo seguidos de 303 sem reenvio no refresh', async () => {
            await page.goto(origin + '/_fixture/page?mode=inactive');
            assert.equal(await page.locator('[name=pixConsent]').isChecked(), false);
            await page.getByRole('button', {name: 'Gerar QR Code de adesão'}).click();
            assert.equal(submissions.length, 0, 'Required unchecked consent blocks native form submission.');
            await page.locator('[name=pixConsent]').check();
            await page.locator('[name=frequency]').selectOption('QUARTERLY');
            await Promise.all([page.waitForURL(origin + pixPath), page.getByRole('button', {name: 'Gerar QR Code de adesão'}).click()]);
            assert.equal(submissions.length, 1); assert.equal(submissions[0].get('frequency'), 'QUARTERLY');
            assert.equal(submissions[0].get('action'), 'enroll'); assert.equal(submissions[0].get('pixConsent'), '1');
            assert.equal(await page.locator('#asaas-pix-copy').getAttribute('readonly'), '');
            await page.reload(); assert.equal(submissions.length, 1, 'PRG refresh repeats only GET.');
            assert.ok(requests.some(request => request.method === 'POST' && request.path === pixPath));
        });
        await scenario('estado incerto não oferece nova cobrança e textos hostis não executam código', async () => {
            await page.goto(origin + '/_fixture/page?mode=uncertain');
            assert.equal(await page.getByText('Pagar somente esta fatura pelos meios disponíveis').count(), 0);
            assert.equal(await page.getByRole('button', {name: 'Gerar QR Code de adesão'}).count(), 0);
            await page.goto(origin + '/_fixture/page?mode=hostile');
            assert.equal(await page.evaluate(() => typeof window.fixtureXss), 'undefined');
            assert.deepEqual(await page.evaluate(() => window.__pixStorageAccess), []);
            assert.deepEqual(await context.cookies(), []);
            assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        });
        console.log('PASS Pix UI: ' + scenarios + ' cenários, 12 combinações responsivas e POST/303/GET reais; sem chamadas externas.');
    } finally {
        if (browser) { await browser.close(); }
        server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    }
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
