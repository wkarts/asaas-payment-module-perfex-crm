#!/usr/bin/env node
'use strict';

/** Actual PHP view + Bootstrap 3 + Chromium on loopback HTTP only; no Asaas/CRM access.
 * ASAAS_TEST_PHP selects PHP. --contract-only checks HTML; --transport-only also checks real HTTP PRG without Chromium.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.ASAAS_TEST_PHP || 'php';
const defaultOrigin = 'https://crm.example.test';
const recurringPath = '/asaas/recurring/index/recurring-browser-fixture';
const invoicePath = '/invoice/42/recurring-browser-fixture';
let currentScenario = 'renderização PHP';
const watchdog = setTimeout(() => {
    console.error('Recurring browser exceeded its 90-second deadline in: ' + currentScenario);
    process.exit(1);
}, 90000);
watchdog.unref();
const modes = ['inactive', 'active', 'suspended', 'uncertain', 'paid', 'guest', 'invoice', 'hostile'];
function renderFixture(mode, origin = defaultOrigin) {
    return execFileSync(php, [path.join(__dirname, 'fixtures/recurring-ui-page.php'), mode],
        {cwd: root, encoding: 'utf8', maxBuffer: 1024 * 1024, timeout: 10000,
            env: {...process.env, ASAAS_RECURRING_FIXTURE_ORIGIN: origin}});
}
const fixtures = Object.fromEntries(modes.map(mode => [mode, renderFixture(mode)]));
let scenarios = 0;
async function scenario(name, work) { currentScenario = name; await work(); scenarios++; console.log('OK  ' + name); }
function deadline(promise, description, diagnostic = null) {
    let timer;
    return Promise.race([promise, new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('Timed out waiting for ' + description + ' in: ' + currentScenario
            + (diagnostic ? ' | ' + JSON.stringify(diagnostic()) : ''))), 10000);
    })]).finally(() => clearTimeout(timer));
}

/** Real 303 responses need a real destination: Playwright routes only the first URL in a redirect chain. */
async function fixtureServer(initialMode, assets = {}) {
    let origin;
    let displayMode = initialMode;
    let afterPost = 'paid';
    let release = null;
    let recordPending;
    const pending = new Promise(resolve => { recordPending = resolve; });
    const pendingResponses = new Set();
    const submissions = [];
    const requests = [];
    const failures = [];
    const rendered = new Map();
    const html = mode => {
        if (!rendered.has(mode)) { rendered.set(mode, renderFixture(mode, origin)); }
        return rendered.get(mode);
    };
    const server = http.createServer((request, response) => {
        const send = (status, type, body, extra = {}) => {
            response.writeHead(status, {'Content-Type': type, 'Cache-Control': 'no-store', ...extra});
            response.end(body);
        };
        const handle = async () => {
            const url = new URL(request.url, origin);
            requests.push({method: request.method, path: url.pathname});
            if (request.headers.host !== new URL(origin).host) { return send(400, 'text/plain', 'Invalid fixture host.'); }
            if (request.method === 'GET' && assets[url.pathname]) {
                return send(200, url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', fs.readFileSync(assets[url.pathname]));
            }
            if (url.pathname === '/favicon.ico') { return send(204, 'text/plain', ''); }
            if (request.method === 'GET' && url.pathname === '/_fixture/observer') {
                return send(200, 'text/html; charset=utf-8', '<!doctype html><html><head><title>Recurring test observer</title></head><body></body></html>');
            }
            if (request.method === 'POST' && url.pathname === recurringPath) {
                const chunks = [];
                let size = 0;
                for await (const chunk of request) {
                    size += chunk.length;
                    if (size > 16384) { return send(413, 'text/plain', 'Fixture body too large.'); }
                    chunks.push(chunk);
                }
                const body = new URLSearchParams(Buffer.concat(chunks).toString('utf8'));
                submissions.push(body);
                if (body.get('csrf_fixture') !== 'fixture-csrf-token' || body.get('recurringNonce') !== 'b'.repeat(64)) {
                    return send(403, 'text/plain', 'Invalid fixture CSRF.');
                }
                await new Promise(resolve => {
                    release = () => { pendingResponses.delete(release); resolve(); };
                    pendingResponses.add(release);
                    recordPending();
                });
                displayMode = afterPost;
                return send(303, 'text/plain', '', {Location: origin + recurringPath});
            }
            if (request.method === 'GET' && [recurringPath, invoicePath, '/_fixture/page'].includes(url.pathname)) {
                const mode = url.pathname === invoicePath ? 'invoice'
                    : (url.pathname === recurringPath && displayMode === 'invoice' ? 'inactive' : displayMode);
                return send(200, 'text/html; charset=utf-8', html(mode));
            }
            failures.push('Unexpected fixture request: ' + request.method + ' ' + url.pathname);
            return send(404, 'text/plain', 'Unknown fixture route.');
        };
        handle().catch(error => { failures.push(error.message); if (!response.headersSent) { send(500, 'text/plain', 'Fixture failed.'); } else { response.destroy(); } });
    });
    server.requestTimeout = 15000;
    server.headersTimeout = 15000;
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    origin = 'http://127.0.0.1:' + server.address().port;
    return {origin, submissions, requests, failures,
        waitForPending() { return deadline(pending, 'the loopback native POST'); },
        release() { assert.ok(release, 'A native POST response must be pending.'); release(); },
        setAfterPost(mode) { afterPost = mode; },
        releasePending() { for (const finish of pendingResponses) { finish(); } },
        async close() {
            for (const finish of pendingResponses) { finish(); }
            server.closeAllConnections();
            await new Promise(resolve => server.close(resolve));
        }};
}

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

    await scenario('servidor loopback executa POST, 303 e GET reais sem DNS externo', async () => {
        const server = await fixtureServer('active');
        try {
            const responsePromise = fetch(server.origin + recurringPath, {method: 'POST', redirect: 'follow',
                body: new URLSearchParams({action: 'enroll', csrf_fixture: 'fixture-csrf-token', recurringNonce: 'b'.repeat(64)})});
            await server.waitForPending();
            server.release();
            const response = await deadline(responsePromise, 'the loopback 303 chain');
            assert.equal(response.status, 200);
            assert.equal(response.redirected, true);
            assert.equal(response.url, server.origin + recurringPath);
            assert.match(await response.text(), /Pagamento confirmado/);
            assert.deepEqual(server.requests, [{method: 'POST', path: recurringPath}, {method: 'GET', path: recurringPath}]);
            assert.equal(server.submissions.length, 1);
            assert.deepEqual(server.failures, []);
        } finally { await server.close(); }
    });
    if (process.argv.includes('--transport-only')) {
        console.log('Recurring transport: HTML e HTTP POST/303/GET aprovados; Chromium não executado neste modo.');
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
    const browser = await chromium.launch({headless: true, timeout: 20000});
    const errors = [];
    const unexpected = [];
    const contexts = [];
    const servers = [];
    async function fixturePage(initialMode = 'inactive', viewport = {width: 1280, height: 960}, observeDeparture = false) {
        const transport = await fixtureServer(initialMode, assets);
        servers.push(transport);
        const origin = transport.origin;
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
        page.setDefaultTimeout(10000);
        page.setDefaultNavigationTimeout(15000);
        page.on('pageerror', error => errors.push(error.message));
        let recordSubmit;
        let observedResultView = null;
        const observationKinds = [];
        const observationPrefix = '__ASAAS_RECURRING_BROWSER_EVENT__:';
        const submitted = new Promise(resolve => { recordSubmit = resolve; });
        // Submit is observed before navigation. Departure is read from the surviving
        // same-origin observer below, without CDP delivery during unload.
        page.on('console', message => {
            const text = message.text();
            if (!text.startsWith(observationPrefix)) { return; }
            let snapshot;
            try { snapshot = JSON.parse(text.slice(observationPrefix.length)); }
            catch (_) { errors.push('Invalid recurring test observation.'); return; }
            observationKinds.push(snapshot.type);
            if (snapshot.type === 'submit') { recordSubmit(snapshot); }
        });
        await context.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== origin) { unexpected.push(url.origin); return route.abort(); }
            return route.continue();
        });
        await page.goto(origin + '/_fixture/page');
        let observerPage = null;
        if (observeDeparture) {
            const opened = page.waitForEvent('popup');
            await page.evaluate(url => {
                window.__asaasRecurringTestObserver = window.open(url, '_blank');
            }, origin + '/_fixture/observer');
            observerPage = await opened;
            observerPage.on('pageerror', error => errors.push(error.message));
            await observerPage.waitForLoadState();
            await observerPage.evaluate(() => { window.__asaasRecurringDepartureFlags = ''; });
            await page.bringToFront();
        }
        // Install after the actual script, before the click. Browser-native form navigation
        // can suspend later DOM RPCs while its response is held. Observe submit/pagehide
        // synchronously inside their natural dispatch instead of evaluating a departing page.
        await page.evaluate(marker => {
            const root = document.getElementById('asaas-recurring');
            if (!root) { return; }
            const observer = window.__asaasRecurringTestObserver || null;
            delete window.__asaasRecurringTestObserver;
            const forms = [...root.querySelectorAll('[data-asaas-recurring-form]')];
            const names = ['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode',
                'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'];
            const clearance = () => ({
                fields: names.map(name => {
                    const field = document.getElementById('recurring-' + name);
                    return {name, present: !!field, empty: !!field && field.value === '', defaultEmpty: !!field && field.defaultValue === ''};
                }),
                consentUnchecked: !document.getElementById('recurring-consent')?.checked,
                consentDefaultUnchecked: !document.getElementById('recurring-consent')?.defaultChecked,
                privacy: window.__recurringPrivacyAccesses.slice()
            });
            const emit = snapshot => console.debug(marker + JSON.stringify(snapshot));
            let probing = false;
            root.addEventListener('submit', event => {
                if (probing || !event.target.matches('[data-asaas-recurring-form]')) { return; }
                const snapshot = {
                    type: 'submit', operation: event.target.getAttribute('data-asaas-recurring-form'),
                    prevented: event.defaultPrevented,
                    buttons: [...root.querySelectorAll('[data-recurring-submit]')]
                        .map(button => ({form: button.closest('form').id, disabled: button.disabled})),
                    forms: forms.map(form => ({id: form.id, busy: form.getAttribute('aria-busy')})),
                    statusVisible: !document.getElementById('asaas-recurring-status').classList.contains('hidden'),
                    clearance: clearance()
                };
                // Bubble runs after the real form handler. Attempt both repeats while its
                // pending flag is active; only booleans/IDs leave through primitive console text.
                probing = true;
                try { snapshot.replays = forms.map(form => form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}))); }
                finally { probing = false; }
                emit(snapshot);
            });
            if (observer) {
                // This window survives the source document. The synchronous same-origin write
                // records only field names/booleans; no input value, token or quote is copied.
                // Writing window.name during pagehide is too late for the destination's name.
                window.addEventListener('pagehide', () => {
                    observer.__asaasRecurringDepartureFlags = marker + JSON.stringify({type: 'pagehide', clearance: clearance()});
                });
            }
        }, observationPrefix);
        return {...transport, page,
            waitForSubmit() { return deadline(submitted, 'the synchronous submit observation'); },
            markResultView(state) { observedResultView = state; },
            async readDeparture() {
                assert.ok(observerPage, 'Natural departure needs the same-origin observer.');
                const captured = await observerPage.evaluate(marker => {
                    const flags = window.__asaasRecurringDepartureFlags;
                    window.__asaasRecurringDepartureFlags = '';
                    if (typeof flags !== 'string' || !flags.startsWith(marker)) { return {found: false}; }
                    return {found: true, snapshot: JSON.parse(flags.slice(marker.length)), privacy: window.__recurringPrivacyAccesses};
                }, observationPrefix);
                const diagnostic = JSON.stringify({found: captured.found, finalPath: new URL(page.url()).pathname,
                    observedResultView, requests: transport.requests, observations: observationKinds, pageErrors: errors});
                assert.equal(captured.found, true, 'Natural pagehide must record its flags in the live observer: ' + diagnostic);
                assert.equal(captured.snapshot.type, 'pagehide');
                assert.deepEqual(captured.privacy, [], 'Observer must not access cookies, storage or extra network APIs.');
                await observerPage.close();
                return captured.snapshot;
            }};
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
    function assertSubmission(snapshot, operation) {
        const diagnostic = JSON.stringify({state: snapshot, pageErrors: errors});
        assert.equal(snapshot.operation, operation, 'Expected native operation: ' + diagnostic);
        assert.equal(snapshot.prevented, false, 'The first native POST must remain permitted: ' + diagnostic);
        assert.equal(snapshot.buttons.length, 2, 'Both operation buttons must exist: ' + diagnostic);
        assert.equal(snapshot.buttons.every(button => button.disabled), true, 'Native submit must synchronously lock both operations: ' + diagnostic);
        assert.equal(snapshot.forms.length, 2, 'Both operation forms must exist: ' + diagnostic);
        assert.equal(snapshot.forms.every(form => form.busy === 'true') && snapshot.statusVisible, true,
            'Both forms must report the pending operation accessibly: ' + diagnostic);
        assert.deepEqual(snapshot.replays, [false, false], 'Both repeated operations must be prevented: ' + diagnostic);
        assert.deepEqual(snapshot.clearance.privacy, []);
    }
    function assertCleared(snapshot) {
        const cleared = snapshot.clearance;
        assert.equal(cleared.fields.length, 10);
        assert.equal(cleared.fields.every(field => field.present && field.empty && field.defaultEmpty), true,
            'Actual card/holder fields and defaults must be cleared: ' + JSON.stringify(cleared));
        assert.equal(cleared.consentUnchecked && cleared.consentDefaultUnchecked, true, 'Explicit consent must not survive departure.');
        assert.deepEqual(cleared.privacy, []);
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
            assert.equal(await link.getAttribute('href'), fixture.origin + recurringPath);
            await Promise.all([fixture.page.waitForURL(fixture.origin + recurringPath), link.click()]);
            assert.equal(await fixture.page.locator('#asaas-recurring-enroll').count(), 1);
            assert.equal(fixture.submissions.length, 0, 'Opening enrollment cannot capture a card.');
        });
        await scenario('formulário nativo exige consentimento, envia CSRF e impede operação duplicada', async () => {
            const fixture = await fixturePage('active', undefined, true);
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
            await fixture.waitForPending();
            assertSubmission(await fixture.waitForSubmit(), 'enroll');
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
            const navigation = page.waitForURL(fixture.origin + recurringPath);
            fixture.release();
            await navigation;
            await page.locator('.asaas-recurring-state').waitFor();
            assert.equal(await page.locator('#asaas-recurring-enroll').count(), 0, 'PRG renders current paid state without the earlier input.');
            assert.equal(await page.locator('#asaas-recurring-revoke').count(), 1);
            fixture.markResultView('paid');
            assertCleared(await fixture.readDeparture());
            assert.equal(fixture.submissions.length, 1);
            assert.deepEqual(await page.evaluate(() => window.__recurringPrivacyAccesses), []);
        });
        await scenario('revogação usa apenas ação, CSRF e nonce, limpa dados digitados e não cobra', async () => {
            const fixture = await fixturePage('active', undefined, true);
            fixture.setAfterPost('inactive');
            await fillCard(fixture.page);
            const request = fixture.page.waitForRequest(req => req.method() === 'POST');
            await fixture.page.locator('#asaas-recurring-revoke [data-recurring-submit]').click({noWaitAfter: true});
            await request;
            await fixture.waitForPending();
            const submission = await fixture.waitForSubmit();
            assertSubmission(submission, 'revoke');
            assertCleared(submission);
            assert.equal(fixture.submissions.length, 1);
            assert.deepEqual([...fixture.submissions[0].keys()].sort(), ['action', 'csrf_fixture', 'recurringNonce']);
            assert.equal(fixture.submissions[0].get('action'), 'revoke');
            const navigation = fixture.page.waitForURL(fixture.origin + recurringPath);
            fixture.release();
            await navigation;
            await fixture.page.locator('#asaas-recurring-enroll').waitFor();
            assert.equal(await fixture.page.locator('#asaas-recurring-revoke').count(), 0);
            assert.equal(await fixture.page.locator('#recurring-consent').isChecked(), false);
            fixture.markResultView('inactive');
            assertCleared(await fixture.readDeparture());
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
        for (const server of servers) { assert.deepEqual(server.failures, [], 'Loopback server must handle all fixture requests.'); }
        console.log('Recurring browser: ' + scenarios + ' cenários aprovados em Chromium; sem API externa ou persistência de dados de cartão.');
    } finally {
        for (const server of servers) { server.releasePending(); }
        for (const context of contexts) { await context.close(); }
        await browser.close();
        for (const server of servers) { await server.close(); }
    }
}
main().catch(error => { console.error(error.stack || error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
