#!/usr/bin/env node
'use strict';

/**
 * node tests/settings-ui-smoke.js           contrato PHP/HTML e sintaxe, sem npm
 * node tests/settings-ui-smoke.js --browser contrato + Chromium/Bootstrap reais
 *
 * O modo browser usa playwright, jquery@3 e bootstrap@3 instalados somente no
 * diretório temporário do CI (NODE_PATH). As rotas são todas simuladas: nenhuma
 * API/conta real é chamada. ASAAS_TEST_PHP permite escolher o executável PHP.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const groups = ['general', 'environment', 'payments', 'charges', 'webhook', 'sync', 'logs', 'monitoring'];
const secretNames = ['api_key', 'api_key_sandbox', 'webhook_secret'];
const anticipationDefaults = {
    card_anticipation_mode: 'none', card_anticipation_first_days: '30',
    card_anticipation_manual_rate_1: '1.25', card_anticipation_manual_rate_installments: '1.70',
    card_anticipation_automatic_rate_1: '1.15', card_anticipation_automatic_rate_installments: '1.60'
};
const anticipationSettings = Object.keys(anticipationDefaults);
const cardSettings = ['installmentCount', 'card_min_installment', 'card_free_installments', 'card_fee_mode',
    'card_fee_fixed_mode', 'card_fee_fixed', 'card_fee_percent_1', 'card_fee_percent_2_6', 'card_fee_percent_7_12', 'card_fee_percent_13_21'].concat(anticipationSettings);
const php = process.env.ASAAS_TEST_PHP || 'php';
let scenarios = 0;

function renderFixture(...args) {
    return JSON.parse(execFileSync(php, [path.join(__dirname, 'fixtures/settings-ui-page.php'), '--manifest', ...args], {
        cwd: root, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024
    }));
}
function inputSelector(name) { return '[name="settings[paymentmethod_asaas_' + name + ']"]'; }
async function scenario(name, callback) {
    await callback();
    scenarios++;
    console.log('OK  ' + name);
}

async function main() {
    const fixtures = {standard: renderFixture(), wrapped: renderFixture('--wrapped'), flat: renderFixture('--no-layout')};
    await scenario('views reais: oito guias, formulário único e opções do gateway preservadas', () => {
        assert.deepEqual(fixtures.standard.groups, groups);
        assert.equal(fixtures.standard.forms, 1);
        assert.equal(fixtures.standard.nested_forms, 0);
        assert.equal((fixtures.standard.html.match(/<form\b/g) || []).length, 1);
        for (const key of fixtures.standard.settings) {
            assert(fixtures.standard.controls.some(input => input.name === 'settings[paymentmethod_asaas_' + key + ']'), key);
            assert(fixtures.standard.html.includes('data-fixture-after="' + key + '"'));
        }
        assert(fixtures.standard.html.includes('id="asaas-monitor"'));
        assert(fixtures.standard.html.includes('id="asaas-logs"'));
        assert(fixtures.standard.html.includes('id="asaas-webhook-url"'));
    });
    await scenario('renderização inicial mascara credenciais e mantém um único campo de ambiente', () => {
        for (const fixture of Object.values(fixtures)) {
            for (const key of secretNames) {
                const fields = fixture.controls.filter(input => input.name === 'settings[paymentmethod_asaas_' + key + ']');
                assert.equal(fields.length, 1);
                assert.equal(fields[0].type, 'password');
                assert(fields[0].value.length > 31);
            }
            const environment = fixture.controls.filter(input => input.name === 'settings[paymentmethod_asaas_sandbox]');
            assert.deepEqual(environment.map(input => input.value), ['1', '0']);
            assert.equal(environment.filter(input => input.checked).length, 1);
        }
        assert.deepEqual(fixtures.flat.groups, []);
        assert(fixtures.wrapped.html.includes('fixture-incompatible-wrapper'));
    });
    await scenario('cartão usa campos nativos únicos e subguias próprias dentro de Cobranças', () => {
        for (const fixture of Object.values(fixtures)) {
            for (const key of cardSettings) {
                assert.equal(fixture.controls.filter(input => input.name === 'settings[paymentmethod_asaas_' + key + ']').length, 1, key);
            }
            assert.equal(fixture.controls.find(input => input.name === 'settings[paymentmethod_asaas_card_fee_mode]').value, 'absorb');
            assert.equal(fixture.controls.find(input => input.name === 'settings[paymentmethod_asaas_card_fee_fixed]').value, '0.49');
            assert(fixture.html.includes('Limite de parcelas no cartão (1 a 21)'));
            assert(fixture.html.includes('Os valores iniciais das taxas são referências'));
        }
        assert(fixtures.standard.html.includes('data-asaas-charges-panel="general"'));
        assert(fixtures.standard.html.includes('data-asaas-charges-panel="card"'));
        assert(fixtures.standard.html.includes('Encargos e descontos'));
        assert(!fixtures.flat.html.includes('data-asaas-charges-panel'));
    });
    await scenario('antecipação tem seis opções nativas, taxas mensais e início sem repasse adicional', () => {
        for (const fixture of Object.values(fixtures)) {
            for (const [key, value] of Object.entries(anticipationDefaults)) {
                const fields = fixture.controls.filter(input => input.name === 'settings[paymentmethod_asaas_' + key + ']');
                assert.equal(fields.length, 1, key);
                assert.equal(fields[0].value, value, key);
            }
            assert(fixture.html.includes('Avulsa à vista (% ao mês)'));
            assert(fixture.html.includes('Avulsa parcelada (% ao mês)'));
            assert(fixture.html.includes('Automática à vista (% ao mês)'));
            assert(fixture.html.includes('Automática parcelada (% ao mês)'));
            assert(fixture.html.includes('não solicita antecipações nem ativa a antecipação automática no Asaas'));
            assert(fixture.html.includes('O cliente vê um único valor de encargos do cartão.'));
        }
        assert(fixtures.standard.html.includes('data-asaas-card-section="anticipation"'));
    });
    await scenario('scripts válidos, carregados na ordem contratada e sem persistência de segredos', () => {
        const scripts = ['asaas-settings.js', 'asaas-webhook-settings.js', 'asaas-monitor.js', 'asaas-logs.js'];
        let previous = -1;
        for (const filename of scripts) {
            const source = fs.readFileSync(path.join(root, 'assets/js', filename), 'utf8');
            new vm.Script(source, {filename});
            assert(!/\b(?:localStorage|sessionStorage)\b|document\.cookie/.test(source));
            const index = fixtures.standard.html.indexOf('src="/_fixture/' + filename + '"');
            assert(index > previous);
            previous = index;
        }
        const source = fs.readFileSync(path.join(root, 'assets/js/asaas-settings.js'), 'utf8');
        assert(!/\bfetch\s*\(|\bXMLHttpRequest\b/.test(source));
    });
    if (!process.argv.includes('--browser')) {
        console.log('\nSettings UI: ' + scenarios + ' cenários de contrato aprovados. Comportamento DOM/visual exige --browser.');
        return;
    }

    const {chromium} = require('playwright');
    const assets = {
        '/_fixture/jquery.js': require.resolve('jquery/dist/jquery.min.js'),
        '/_fixture/bootstrap.js': require.resolve('bootstrap/dist/js/bootstrap.min.js'),
        '/_fixture/bootstrap.css': require.resolve('bootstrap/dist/css/bootstrap.min.css'),
        '/_fixture/fixture.css': path.join(__dirname, 'fixtures/settings-ui-fixture.css'),
        '/_fixture/asaas-settings.css': path.join(root, 'assets/css/asaas-settings.css'),
        '/_fixture/asaas-monitor.css': path.join(root, 'assets/css/asaas-monitor.css'),
        '/_fixture/asaas-logs.css': path.join(root, 'assets/css/asaas-logs.css'),
        '/_fixture/asaas-settings.js': path.join(root, 'assets/js/asaas-settings.js'),
        '/_fixture/asaas-webhook-settings.js': path.join(root, 'assets/js/asaas-webhook-settings.js'),
        '/_fixture/asaas-monitor.js': path.join(root, 'assets/js/asaas-monitor.js'),
        '/_fixture/asaas-logs.js': path.join(root, 'assets/js/asaas-logs.js')
    };
    assert.match(fs.readFileSync(assets['/_fixture/bootstrap.js'], 'utf8'), /Bootstrap v3\./);
    const artifacts = path.join(__dirname, 'artifacts');
    fs.mkdirSync(artifacts, {recursive: true});
    const browser = await chromium.launch({headless: true});
    const errors = [];
    const unexpectedRequests = [];
    const monitorRequests = [];
    const diagnosticsRequests = [];
    const submissions = [];
    const csrfRejections = [];
    const pendingResponses = new Set();
    let csrfSequence = 0;
    let diagnosticsResponse = 'database';
    let remotePaymentsResponse = 'ok';
    let holdLogResponse = null;
    let holdMonitorResponse = null;

    // Simula o contrato nativo: GET devolve/reenvia o cookie vigente; POST valida
    // o token contra o cookie recebido e gera outro hash. Não desabilita CSRF.
    function csrfCookie(headers) {
        const entry = String(headers.cookie || '').split(';').map(value => value.trim())
            .find(value => value.startsWith('csrf_cookie_fixture='));
        return entry ? entry.slice('csrf_cookie_fixture='.length) : '';
    }
    function csrfHeaders(hash) {
        return {'set-cookie': 'csrf_cookie_fixture=' + hash + '; Path=/; Secure; HttpOnly; SameSite=Lax'};
    }
    function delayedResponse() {
        let markEntered;
        let finish;
        const entered = new Promise(resolve => { markEntered = resolve; });
        const pending = new Promise(resolve => { finish = resolve; });
        const gate = {entered, pending, markEntered, release: () => { finish(); pendingResponses.delete(gate); }};
        pendingResponses.add(gate);
        return gate;
    }
    async function waitForGate(gate) {
        let timer;
        try {
            await Promise.race([gate.entered, new Promise((resolve, reject) => {
                timer = setTimeout(() => reject(new Error('A requisição esperada não chegou à resposta retida da fixture.')), 10000);
            })]);
        } finally { clearTimeout(timer); }
    }

    async function attachRoutes(page) {
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== 'https://crm.example.test') {
                unexpectedRequests.push(request.url());
                return route.abort();
            }
            if (url.pathname === '/admin/settings') {
                if (request.method() === 'POST') {
                    const values = new URLSearchParams(request.postData());
                    const cookie = csrfCookie(await request.allHeaders());
                    if (!cookie || values.get('csrf_fixture') !== cookie) {
                        csrfRejections.push('settings');
                        return route.fulfill({status: 403, body: 'CSRF da fixture inválido.'});
                    }
                    submissions.push(Array.from(values).sort((a, b) => a[0].localeCompare(b[0])));
                    return route.fulfill({status: 204});
                }
                const name = url.searchParams.get('fixture') || 'standard';
                return route.fulfill({contentType: 'text/html; charset=utf-8', headers: csrfHeaders('fixture-csrf-token'), body: fixtures[name].html});
            }
            if (assets[url.pathname]) {
                return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(assets[url.pathname])});
            }
            if (url.pathname === '/favicon.ico') { return route.fulfill({status: 204}); }
            if (url.pathname === '/admin/asaas/asaas/logs_data') {
                diagnosticsRequests.push({method: request.method(), body: request.postData()});
                const hash = csrfCookie(await request.allHeaders()) || 'fixture-csrf-' + (++csrfSequence);
                const csrf = {name: 'csrf_fixture', hash};
                if (diagnosticsResponse === 'forbidden') {
                    return route.fulfill({status: 403, contentType: 'application/json', headers: csrfHeaders(hash),
                        body: JSON.stringify({ok: false, csrf, message: 'A sessão administrativa precisa ser verificada.'})});
                }
                const fromFile = diagnosticsResponse === 'file';
                const report = {
                    database_state: fromFile ? 'unavailable' : 'ok', source: fromFile ? 'file' : 'database',
                    file_enabled: true, file_exists: true, directory_writable: true,
                    file_path: '/var/www/fixture/application/logs/asaas-2026-01-10.php',
                    rows: [{created_at: '2026-01-10 09:15:00', level: fromFile ? 'warning' : 'info',
                        message: fromFile ? 'Diagnóstico simulado recuperado do arquivo.' : 'Diagnóstico local <img src=x onerror=window.fixtureXss=true>',
                        correlation_id: 'fixture-diagnostic-42', context: {invoice_id: 42, operation: 'invoice_sync', result: 'fixture'}}]
                };
                const gate = holdLogResponse;
                holdLogResponse = null;
                if (gate) { gate.markEntered(); await gate.pending; }
                return route.fulfill({contentType: 'application/json', headers: csrfHeaders(hash), body: JSON.stringify({ok: true, csrf, report})});
            }
            if (url.pathname.startsWith('/admin/asaas/monitor/')) {
                const action = url.pathname.split('/').pop();
                monitorRequests.push({action, method: request.method(), body: request.postData(), query: Object.fromEntries(url.searchParams)});
                const cookie = csrfCookie(await request.allHeaders());
                if (request.method() === 'POST' && (!cookie || new URLSearchParams(request.postData()).get('csrf_fixture') !== cookie)) {
                    csrfRejections.push(action);
                    return route.fulfill({status: 403, contentType: 'application/json', body: JSON.stringify({ok: false, message: 'CSRF da fixture inválido.'})});
                }
                const hash = request.method() === 'POST' || !cookie ? 'fixture-csrf-' + (++csrfSequence) : cookie;
                const payload = {ok: true, csrf: {name: 'csrf_fixture', hash}};
                if (action === 'overview') {
                    payload.data = {eligible_open_invoices: 3, linked_open_invoices: 2, unlinked_open_invoices: 1,
                        webhook_errors: 1, webhook_event_errors: 1, log_errors: 1, last_webhook_at: '2026-01-10 09:10:00',
                        cron: {enabled: true, days: 7, limit: 50, last_run: {status: 'completed', finished_at: '2026-01-10 09:00:00', processed: 8, reconciled: 2, errors: 0}}};
                } else if (action === 'invoices') {
                    payload.data = [{invoice_id: 42, invoice_number: 'FAT-000042', client_name: 'Cliente de teste', crm_status: 1,
                        asaas_status: 'RECEIVED', asaas_payment_id: 'pay_fixture42', billing_type: 'PIX', total: '150.00', balance: '150.00',
                        last_sync_at: '2026-01-10 08:30:00', last_webhook_at: '2026-01-10 08:29:00', webhook_status: 'error', suspected_divergence: true},
                    {invoice_id: 43, invoice_number: 'FAT-000043', client_name: 'Outro cliente de teste', crm_status: 1,
                        asaas_status: 'PENDING', asaas_payment_id: 'pay_fixture43', billing_type: 'BOLETO', total: '95.00', balance: '95.00', suspected_divergence: false},
                    {invoice_id: 44, invoice_number: 'FAT-000044', client_name: 'Cliente com fatura antiga', crm_status: 4,
                        asaas_status: null, asaas_payment_id: null, billing_type: null, total: '150.00', balance: '150.00',
                        binding_status: 'missing', binding_message: 'Vínculo local ausente. Comparar consulta a referência da fatura no Asaas.', suspected_divergence: false}];
                    if (url.searchParams.get('status') === 'missing') { payload.data = payload.data.filter(row => row.binding_status === 'missing'); }
                } else if (action === 'payments') {
                    if (remotePaymentsResponse === 'error') {
                        return route.fulfill({status: 503, contentType: 'application/json', headers: csrfHeaders(hash),
                            body: JSON.stringify({ok: false, csrf: payload.csrf, error: {code: 'provider_unavailable', http_status: 429,
                                message: 'O Asaas limitou temporariamente a consulta. Confira o diagnóstico e tente novamente.'}})});
                    }
                    const remoteRow = (paymentId, invoiceId, binding, eligible, extra = {}) => ({payment_id: paymentId,
                        invoice_id: invoiceId, invoice_number: invoiceId ? 'FAT-0000' + invoiceId : null, status: 'CONFIRMED', billing_type: 'CREDIT_CARD',
                        value: '150.00', original_value: null, payment_date: '2026-01-10', due_date: '2026-01-09', date_created: '2025-12-01',
                        installment: null, installment_number: null, binding_status: binding, action_eligible: eligible, binding_message: '', ...extra});
                    payload.data = Number(url.searchParams.get('page')) === 2
                        ? [remoteRow('pay_other_page', null, 'unmatched', false, {binding_message: 'Sem fatura correspondente neste CRM.'})]
                        : [remoteRow('pay_fixture42', 42, 'linked', true, {status: 'RECEIVED', billing_type: 'PIX'}),
                            remoteRow('pay_fixture44_1', 44, 'recoverable', true, {value: '75.00', installment: 'ins_fixture44', installment_number: 1,
                                binding_message: 'Referência, cliente e valores conferidos; vínculo a recuperar.'}),
                            remoteRow('pay_fixture44_2', 44, 'recoverable', true, {value: '75.00', installment: 'ins_fixture44', installment_number: 2}),
                            remoteRow('pay_unmatched', null, 'unmatched', false, {status: 'OVERDUE', payment_date: null,
                                binding_message: 'Sem fatura correspondente neste CRM.'}),
                            remoteRow('pay_ambiguous', 45, 'ambiguous', false, {binding_message: 'Mais de uma correspondência encontrada.'}),
                            remoteRow('pay_blocked', 46, 'blocked', false, {binding_message: 'A consulta desta cobrança precisa ser concluída antes de registrar recebimento.',
                                error_code: 'provider_unavailable', http_status: 429})];
                    const remoteStatus = url.searchParams.get('status');
                    if (remoteStatus && remoteStatus !== 'all') { payload.data = payload.data.filter(row => row.status === remoteStatus); }
                    payload.pagination = {page: Number(url.searchParams.get('page')) || 1, per_page: Number(url.searchParams.get('per_page')) || 25,
                        has_more: Number(url.searchParams.get('page')) !== 2, total: 51};
                    if (payload.pagination.page === 1) { payload.message = 'Consulta parcial: parte dos vínculos exige nova conferência por limite de tempo.'; }
                } else if (action === 'events') {
                    payload.data = [{invoice_id: 42, event_type: 'PAYMENT_RECEIVED', event_id: 'evt_fixture42', payment_id: 'pay_fixture42',
                        received_at: '2026-01-10 08:29:00', processed_at: null, process_status: 'error', error_message: 'Falha de processamento simulada.'}];
                } else if (action === 'logs') {
                    const webhook = url.searchParams.get('source') === 'webhook';
                    payload.data = [{created_at: '2026-01-10 08:29:00', level: 'error', message: webhook ? 'Falha de autenticação do webhook.' : 'Falha simulada da API <img src=x onerror=window.fixtureXss=true>',
                        correlation_id: 'fixture-operation42', context: {operation: webhook ? 'webhook_auth' : 'invoice_sync', invoice_id: 42,
                            http_status: webhook ? 401 : 400, error_code: webhook ? 'webhook_auth_failed' : 'invalid_value',
                            details: 'Detalhe seguro da fixture <script>window.fixtureXss=true</script>'}}];
                } else if (action === 'compare' || action === 'reconcile') {
                    const id = new URLSearchParams(request.postData()).get('invoice_ids[]');
                    payload.data = [{invoice_id: Number(id), result: action === 'compare' ? 'divergent' : 'reconciled', crm_status: 1,
                        remote_status: 'RECEIVED', balance: '150.00', receipts_added: action === 'compare' ? 0 : 1,
                        remote_paid_amount: '150.00', binding_recovered: Number(id) === 44,
                        message: Number(id) === 44 ? 'Cobrança localizada pela referência da fatura e vínculo recuperado.' : 'Resultado simulado da fatura.'}];
                } else {
                    unexpectedRequests.push(request.url());
                    return route.abort();
                }
                if (['invoices', 'events', 'logs'].includes(action)) {
                    payload.pagination = {page: 1, has_more: false, total: payload.data.length};
                }
                if (request.method() === 'POST' && holdMonitorResponse) {
                    const gate = holdMonitorResponse;
                    holdMonitorResponse = null;
                    gate.markEntered();
                    await gate.pending;
                }
                return route.fulfill({contentType: 'application/json', headers: csrfHeaders(hash), body: JSON.stringify(payload)});
            }
            unexpectedRequests.push(request.url());
            return route.abort();
        });
    }
    async function showGroup(page, group) {
        await page.evaluate(group => document.dispatchEvent(new CustomEvent('asaas:settings:show', {detail: {group}})), group);
        await page.waitForFunction(group => !document.getElementById('asaas-settings-' + group).hidden, group);
    }
    async function masked(page) {
        assert.deepEqual(await page.evaluate(names => names.map(name => document.querySelector('[name="settings[paymentmethod_asaas_' + name + ']"]').type), secretNames), ['password', 'password', 'password']);
    }
    async function assertTab(page, group) {
        assert.equal(await page.locator('#asaas-settings-tab-' + group).getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#asaas-settings-tab-' + group).getAttribute('tabindex'), '0');
        assert.equal(await page.locator('#online_payments_asaas_tab').evaluate(node => node.classList.contains('active')), true);
        assert.equal(await page.locator('[data-asaas-settings-panel]:not([hidden])').count(), 1);
        assert.equal(await page.locator('#asaas-settings-' + group).isVisible(), true);
    }
    async function assertNativeCsrf(page) {
        const cookie = (await page.context().cookies('https://crm.example.test')).find(value => value.name === 'csrf_cookie_fixture');
        assert(cookie && cookie.value, 'A fixture precisa ter um cookie CSRF vigente.');
        const field = page.locator('input[type="hidden"][name="csrf_fixture"]');
        assert.equal(await field.count(), 1);
        assert.equal(await field.inputValue(), cookie.value, 'O formulário deve acompanhar o cookie devolvido pela última resposta.');
        return cookie.value;
    }
    let page;
    try {
        const context = await browser.newContext({viewport: {width: 1280, height: 960}});
        page = await context.newPage();
        await attachRoutes(page);
        await page.goto('https://crm.example.test/admin/settings');
        await page.waitForSelector('#asaas-settings-groups:not([hidden])');

        await scenario('DOM real: move os nós originais, mantém eventos, valores, CSRF e outros gateways', async () => {
            const result = await page.evaluate(() => {
                const original = window.__asaasFixture;
                const form = document.getElementById('settings-form');
                original.nodes.find(input => input.name === 'settings[paymentmethod_asaas_api_key]').dispatchEvent(new Event('fixture-original-event'));
                return {
                    sameNodes: original.nodes.every(input => input.isConnected && input.form === form),
                    sameCount: original.nodes.length === form.querySelectorAll('input[name], textarea[name], select[name]').length,
                    sameValues: JSON.stringify(original.values) === JSON.stringify(original.nodes.map(input => ({name: input.name, value: input.value, checked: input.checked, disabled: input.disabled}))),
                    samePayload: JSON.stringify(original.payload) === JSON.stringify(Array.from(new FormData(form)).sort((a, b) => a[0].localeCompare(b[0]))),
                    sameOther: original.other === document.getElementById('online_payments_other_tab').innerHTML,
                    listenerCalls: original.originalListenerCalls, forms: document.forms.length, nestedForms: document.querySelectorAll('form form').length
                };
            });
            assert.deepEqual(result, {sameNodes: true, sameCount: true, sameValues: true, samePayload: true, sameOther: true, listenerCalls: 1, forms: 1, nestedForms: 0});
            await assertTab(page, 'general');
            await masked(page);
            assert.equal(monitorRequests.length, 0, 'O monitor não deve consultar nada na abertura da tela.');
            assert.equal(diagnosticsRequests.length, 0, 'Os logs não devem consultar nada antes de abrir a guia.');
        });

        await scenario('grupos completos incluem campos desconhecidos e os blocos after originais', async () => {
            const expected = {
                general: ['active', 'label', 'currencies', 'description', 'future_option'],
                environment: ['api_key', 'api_key_sandbox', 'sandbox', 'show_environment_in_production'],
                payments: ['billet_only', 'card_only', 'pix_only'],
                charges: ['interest_value', 'fine_value', 'discount_type', 'discount_value', 'discount_days', 'diasdevencimento', 'disable_charge_notification'].concat(cardSettings),
                webhook: ['webhook_secret'],
                sync: ['sync_enabled', 'sync_days', 'sync_limit', 'sync_invoice_lifecycle', 'manual_payment_sync', 'manual_payment_receive_in_cash', 'delete_charge', 'update_charge', 'ultima_atualizacao_servicos_municipais'],
                logs: ['debug', 'file_log_enabled']
            };
            assert.equal(await page.evaluate(expected => Object.keys(expected).every(group => expected[group].every(key => {
                const pane = document.getElementById('asaas-settings-' + group);
                return pane.contains(document.querySelector('[name="settings[paymentmethod_asaas_' + key + ']"]'))
                    && pane.contains(document.querySelector('[data-fixture-after="' + key + '"]'));
            })), expected), true);
            assert.equal(await page.locator('#asaas-settings-webhook #asaas-webhook-settings #asaas-webhook-url').count(), 1);
            await page.locator('#asaas-settings-tab-environment').click();
            await assertTab(page, 'environment');
            await page.screenshot({path: path.join(artifacts, 'settings-desktop.png'), fullPage: true});
        });

        await scenario('subguias de Cobranças preservam campos, teclado e o painel Asaas ativo', async () => {
            await showGroup(page, 'charges');
            assert.equal(await page.locator('#asaas-charges-general').isVisible(), true);
            assert.equal(await page.locator('#asaas-charges-general ' + inputSelector('interest_value')).count(), 1);
            assert.equal(await page.locator(inputSelector('installmentCount')).isVisible(), false);
            await page.locator('#asaas-charges-tab-card').click();
            await assertTab(page, 'charges');
            assert.equal(await page.locator('[data-asaas-charges-panel]:not([hidden])').count(), 1);
            assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
            assert.equal(await page.evaluate(keys => keys.every(key => {
                const pane = document.getElementById('asaas-charges-card');
                return pane.contains(document.querySelector('[name="settings[paymentmethod_asaas_' + key + ']"]'))
                    && pane.contains(document.querySelector('[data-fixture-after="' + key + '"]'));
            }), cardSettings), true);
            await page.locator('#asaas-charges-tab-card').focus();
            await page.keyboard.press('ArrowLeft');
            assert.equal(await page.locator('#asaas-charges-general').isVisible(), true);
            assert.equal(await page.evaluate(() => document.activeElement.id), 'asaas-charges-tab-general');
            await page.keyboard.press('End');
            assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
            await assertTab(page, 'charges');
            assert.equal(await page.locator('#asaas-charges-tab-card').getAttribute('aria-selected'), 'true');
            assert.equal(await page.locator('#asaas-charges-tab-general').getAttribute('tabindex'), '-1');
            await page.screenshot({path: path.join(artifacts, 'settings-card.png'), fullPage: true});
            await showGroup(page, 'environment');
        });

        await scenario('seletores de taxas sincronizam somente os campos nativos e mantêm o payload', async () => {
            const before = await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))).sort((a, b) => a[0].localeCompare(b[0])));
            await showGroup(page, 'charges');
            const mode = page.locator('[data-asaas-card-enum="card_fee_mode"]');
            const fixed = page.locator('[data-asaas-card-enum="card_fee_fixed_mode"]');
            const anticipation = page.locator('[data-asaas-card-enum="card_anticipation_mode"]');
            assert.equal(await mode.getAttribute('name'), null);
            assert.equal(await fixed.getAttribute('name'), null);
            assert.equal(await anticipation.getAttribute('name'), null);
            assert.equal(await mode.inputValue(), 'absorb');
            assert.equal(await anticipation.inputValue(), 'none');
            await mode.selectOption('customer');
            await fixed.selectOption('installment');
            await anticipation.selectOption('manual');
            assert.equal(await page.locator(inputSelector('card_fee_mode')).inputValue(), 'customer');
            assert.equal(await page.locator(inputSelector('card_fee_fixed_mode')).inputValue(), 'installment');
            assert.equal(await page.locator(inputSelector('card_anticipation_mode')).inputValue(), 'manual');
            const payload = await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))));
            for (const [key, value] of [['card_fee_mode', 'customer'], ['card_fee_fixed_mode', 'installment'], ['card_anticipation_mode', 'manual']]) {
                assert.deepEqual(payload.filter(entry => entry[0] === 'settings[paymentmethod_asaas_' + key + ']'), [['settings[paymentmethod_asaas_' + key + ']', value]]);
            }
            assert.equal(await page.locator('form form').count(), 0);
            assert.equal(await page.locator('[data-asaas-card-enum-fallback="card_fee_mode"]').isVisible(), false);
            assert.equal(await page.locator('[data-asaas-card-enum-fallback="card_anticipation_mode"]').isVisible(), false);
            await anticipation.selectOption('automatic');
            assert.equal(await page.locator(inputSelector('card_anticipation_mode')).inputValue(), 'automatic');
            await page.evaluate(() => document.getElementById('settings-form').reset());
            await page.waitForFunction(() => document.querySelector('[data-asaas-card-enum="card_fee_mode"]').value === 'absorb');
            assert.equal(await fixed.inputValue(), 'charge');
            assert.equal(await anticipation.inputValue(), 'none');
            assert.deepEqual(await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))).sort((a, b) => a[0].localeCompare(b[0]))), before);
            await showGroup(page, 'environment');
        });

        await scenario('Homologação/Produção são exclusivos e independentes do diagnóstico', async () => {
            assert.deepEqual(await page.locator(inputSelector('sandbox')).evaluateAll(inputs => inputs.map(input => Array.from(document.querySelectorAll('label[for]')).find(label => label.htmlFor === input.id).textContent)), ['Homologação', 'Produção']);
            await page.locator(inputSelector('sandbox') + '[value="0"]').check();
            assert.equal(await page.locator(inputSelector('sandbox') + ':checked').count(), 1);
            assert.equal(await page.locator(inputSelector('sandbox') + ':checked').inputValue(), '0');
            assert.equal(await page.locator(inputSelector('debug') + ':checked').inputValue(), '1');
            await page.locator(inputSelector('sandbox') + '[value="1"]').check();
        });

        await scenario('revelação explícita sincroniza ARIA e as três credenciais são remascaradas', async () => {
            const toggle = page.locator('[data-asaas-secret-toggle="api_key"]');
            await toggle.click();
            assert.equal(await page.locator(inputSelector('api_key')).getAttribute('type'), 'text');
            assert.equal(await toggle.getAttribute('aria-pressed'), 'true');
            assert.equal(await toggle.textContent(), 'Ocultar chave');
            assert.equal(await page.locator(inputSelector('api_key_sandbox')).getAttribute('type'), 'password');
            await page.locator('[data-asaas-secret-toggle="api_key_sandbox"]').click();
            assert.equal(await page.locator(inputSelector('api_key_sandbox')).getAttribute('type'), 'text');
            await page.locator('#asaas-settings-tab-payments').click();
            await masked(page);
            assert.equal(await toggle.getAttribute('aria-pressed'), 'false');
            await showGroup(page, 'environment');
            for (const eventName of ['pagehide', 'pageshow']) {
                await toggle.click();
                await page.evaluate(name => window.dispatchEvent(new Event(name)), eventName);
                await masked(page);
            }
            await toggle.click();
            await page.evaluate(() => {
                Object.defineProperty(document, 'visibilityState', {configurable: true, value: 'hidden'});
                document.dispatchEvent(new Event('visibilitychange'));
                delete document.visibilityState;
            });
            await masked(page);
            await toggle.click();
            await page.locator('a[href="#online_payments_other_tab"]').click();
            await masked(page);
            assert.equal(await page.locator('#online_payments_other_tab').isVisible(), true);
            await showGroup(page, 'webhook');
            await assertTab(page, 'webhook');
            const webhookToggle = page.locator('[data-asaas-webhook-action="toggle"]');
            await webhookToggle.click();
            assert.equal(await webhookToggle.getAttribute('aria-pressed'), 'true');
            await showGroup(page, 'general');
            await masked(page);
            assert.equal(await webhookToggle.textContent(), 'Exibir token');
            assert.equal(await webhookToggle.getAttribute('aria-pressed'), 'false');
        });

        await scenario('teclado, foco e guias Bootstrap aninhadas mantêm o painel pai ativo', async () => {
            await page.locator('#asaas-settings-tab-general').focus();
            await page.keyboard.press('ArrowRight');
            await assertTab(page, 'environment');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'asaas-settings-tab-environment');
            await page.keyboard.press('ArrowLeft');
            await assertTab(page, 'general');
            await page.keyboard.press('Home');
            await page.keyboard.press('Enter');
            await page.keyboard.press('Space');
            await assertTab(page, 'general');
            await page.evaluate(() => document.dispatchEvent(new CustomEvent('asaas:settings:show', {detail: {group: 'constructor'}})));
            await assertTab(page, 'general');
            await page.addScriptTag({url: '/_fixture/asaas-settings.js'});
            assert.equal(await page.locator('[data-asaas-card-enum]').count(), 3, 'Reinicializar o script não deve duplicar os seletores de cartão.');
            await showGroup(page, 'environment');
            await page.locator('[data-asaas-secret-toggle="api_key"]').click();
            assert.equal(await page.locator(inputSelector('api_key')).getAttribute('type'), 'text', 'Inicialização duplicada não pode duplicar o handler.');
            await showGroup(page, 'webhook');
        });

        await scenario('gerar/copiar token não revela conteúdo; validação abre e foca a guia Webhook', async () => {
            const token = page.locator(inputSelector('webhook_secret'));
            await page.locator('[data-asaas-webhook-action="generate"]').click();
            assert.match(await token.inputValue(), /^[0-9a-f]{64}$/);
            await masked(page);
            await page.evaluate(() => {
                Object.defineProperty(navigator, 'clipboard', {configurable: true, value: {writeText: () => Promise.reject(new Error('fixture clipboard disabled'))}});
                document.execCommand = () => false;
            });
            await page.locator('[data-asaas-webhook-action="copy-token"]').click();
            await page.waitForFunction(() => document.getElementById('asaas-webhook-feedback').textContent.includes('Não foi possível copiar'));
            await masked(page);
            assert.equal(await page.locator('textarea[readonly]').count(), 0);
            await token.fill('curto');
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'webhook');
            assert.equal(await token.evaluate(input => document.activeElement === input), true);
            assert.equal(await token.getAttribute('aria-invalid'), 'true');
            assert.equal(submissions.length, 0);
            await token.fill(fixtures.standard.controls.find(input => input.name.endsWith('_webhook_secret]')).value);
            assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
            assert.equal(await page.evaluate(() => document.cookie), '');
        });

        await scenario('validação nativa revela a primeira guia inválida sem ocultar o foco', async () => {
            await page.evaluate(() => {
                const first = document.querySelector('[name="settings[paymentmethod_asaas_installmentCount]"]');
                const second = document.querySelector('[name="settings[paymentmethod_asaas_sync_limit]"]');
                first.value = '0';
                second.value = '0';
            });
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'charges');
            assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
            assert.equal(await page.locator(inputSelector('installmentCount')).evaluate(input => document.activeElement === input), true);
            assert.equal(submissions.length, 0);
            await page.evaluate(() => {
                document.querySelector('[name="settings[paymentmethod_asaas_installmentCount]"]').value = '1';
                document.querySelector('[name="settings[paymentmethod_asaas_sync_limit]"]').value = '50';
            });
        });

        await scenario('taxas e parcelas sem repasse inválidas abrem a subguia e bloqueiam o envio', async () => {
            await showGroup(page, 'charges');
            const free = page.locator(inputSelector('card_free_installments'));
            const rate = page.locator(inputSelector('card_fee_percent_1'));
            await free.fill('2');
            await page.locator('#asaas-charges-tab-general').click();
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'charges');
            assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
            assert.equal(await free.evaluate(input => document.activeElement === input), true);
            assert.match(await free.evaluate(input => input.validationMessage), /limite de parcelas/);
            assert.equal(submissions.length, 0);
            await free.fill('0');
            await rate.fill('99.01');
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'charges');
            assert.equal(await rate.evaluate(input => document.activeElement === input), true);
            assert.match(await rate.evaluate(input => input.validationMessage), /99%/);
            assert.equal(submissions.length, 0);
            await rate.fill('2,99');
            assert.equal(await rate.evaluate(input => input.validity.valid), true);
            await rate.fill('2.99');
        });

        await scenario('modalidade, prazo e taxa de antecipação inválidos revelam a subguia correta', async () => {
            const mode = page.locator('[data-asaas-card-enum="card_anticipation_mode"]');
            const days = page.locator(inputSelector('card_anticipation_first_days'));
            const rate = page.locator(inputSelector('card_anticipation_automatic_rate_installments'));
            await showGroup(page, 'charges');
            await page.locator('#asaas-charges-tab-card').click();
            for (const field of anticipationSettings.filter(name => name !== 'card_anticipation_mode')) {
                assert.equal(await page.locator(inputSelector(field)).isVisible(), true, 'As duas modalidades continuam configuráveis mesmo em Não incluir.');
            }
            for (const value of ['366', '30.5', '-1']) {
                await days.fill(value);
                await page.locator('#asaas-charges-tab-general').click();
                await showGroup(page, 'general');
                await page.locator('#settings-save').click();
                await assertTab(page, 'charges');
                assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
                assert.equal(await days.evaluate(input => document.activeElement === input), true);
                assert.equal(await days.evaluate(input => input.validity.valid), false);
                assert.equal(submissions.length, 0);
            }
            await days.fill('30');
            await rate.fill('1.155');
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'charges');
            assert.equal(await rate.evaluate(input => document.activeElement === input), true);
            assert.match(await rate.evaluate(input => input.validationMessage), /duas casas/);
            assert.equal(submissions.length, 0);
            await rate.fill('1,60');
            assert.equal(await rate.evaluate(input => input.validity.valid), true);
            await rate.fill('1.60');
            await mode.selectOption('');
            await showGroup(page, 'general');
            await page.locator('#settings-save').click();
            await assertTab(page, 'charges');
            assert.equal(await page.locator('#asaas-charges-card').isVisible(), true);
            assert.equal(await mode.evaluate(input => document.activeElement === input), true);
            assert.equal(submissions.length, 0);
            await mode.selectOption('none');
        });

        await scenario('submit nativo envia os mesmos nomes/valores e CSRF depois da organização', async () => {
            const before = await page.evaluate(() => window.__asaasFixture.payload);
            await showGroup(page, 'environment');
            await page.locator('[data-asaas-secret-toggle="api_key"]').click();
            await Promise.all([
                page.waitForRequest(request => new URL(request.url()).pathname === '/admin/settings' && request.method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(submissions.length, 1);
            assert.deepEqual(submissions[0], before);
            await masked(page);
        });

        await scenario('Logs carrega diagnóstico local sob demanda dentro da guia, sem navegar ou alterar o formulário', async () => {
            const address = page.url();
            const payload = await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))));
            assert.equal(diagnosticsRequests.length, 0);
            await showGroup(page, 'logs');
            await page.waitForFunction(() => document.querySelector('[data-logs-rows]').textContent.includes('Diagnóstico local')
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.deepEqual(diagnosticsRequests, [{method: 'GET', body: null}]);
            assert.equal(monitorRequests.length, 0, 'O diagnóstico não deve acionar o monitor nem operações financeiras.');
            assert.equal(page.url(), address);
            assert.equal(await page.locator('#asaas-settings-logs #asaas-logs').count(), 1);
            assert.equal(await page.locator('[data-asaas-logs-open]').isVisible(), false);
            assert.equal(await page.locator('[data-logs-file-enabled]').textContent(), 'habilitado');
            assert.equal(await page.locator('[data-logs-file-path]').textContent(), '/var/www/fixture/application/logs/asaas-2026-01-10.php');
            assert.equal(await page.locator('[data-logs-database]').isVisible(), false);
            assert.equal(await page.locator('[data-logs-source]').isVisible(), false);
            assert.match(await page.locator('[data-logs-count]').textContent(), /1 registro.*banco de dados/);
            assert.equal(await page.locator('[data-logs-rows] img').count(), 0);
            assert.equal(await page.evaluate(() => window.fixtureXss === true), false);
            await page.locator('[data-logs-rows] summary').click();
            assert.match(await page.locator('[data-logs-rows] pre').textContent(), /"invoice_id": 42/);
            assert.match(await page.locator('[data-logs-rows]').textContent(), /fixture-diagnostic-42/);
            assert.equal(await page.evaluate(() => Boolean(document.querySelector('[data-fixture-after="file_log_enabled"]')
                .compareDocumentPosition(document.getElementById('asaas-logs')) & Node.DOCUMENT_POSITION_FOLLOWING)), true,
            'O relatório deve aparecer depois dos controles originais.');
            assert.deepEqual(await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form')))), payload);
            assert.equal(await page.locator('form').count(), 1);
            assert.equal(await page.locator('form form').count(), 0);
            assert.equal(submissions.length, 1);
            await page.screenshot({path: path.join(artifacts, 'settings-logs.png'), fullPage: true});
        });

        await scenario('Logs exibe fallback de arquivo e permite recuperação manual de falha sem sair das configurações', async () => {
            const address = page.url();
            diagnosticsResponse = 'file';
            await page.locator('[data-logs-action="refresh"]').click();
            await page.waitForFunction(() => document.querySelector('[data-logs-rows]').textContent.includes('recuperado do arquivo')
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-logs-database]').isVisible(), true);
            assert.equal(await page.locator('[data-logs-source]').isVisible(), true);
            assert.match(await page.locator('[data-logs-source]').textContent(), /256 KiB/);
            assert.match(await page.locator('[data-logs-count]').textContent(), /arquivo do dia/);
            assert.equal(await page.locator('[data-logs-rows] tr').count(), 1, 'Atualizar deve substituir o resultado, não duplicá-lo.');
            diagnosticsResponse = 'forbidden';
            await page.locator('[data-logs-action="refresh"]').click();
            await page.waitForFunction(() => document.querySelector('[data-logs-feedback]').textContent.includes('sessão')
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-logs-content]').isVisible(), false, 'Dados anteriores não podem parecer um diagnóstico recém-atualizado.');
            assert.equal(await page.locator('[data-logs-action="refresh"]').isEnabled(), true);
            assert.equal(diagnosticsRequests.length, 3, 'Uma falha não deve disparar repetição automática.');
            assert.equal(page.url(), address);
            diagnosticsResponse = 'database';
            await page.locator('[data-logs-action="refresh"]').click();
            await page.waitForFunction(() => !document.querySelector('[data-logs-content]').hidden
                && document.querySelector('[data-logs-rows]').textContent.includes('Diagnóstico local')
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-logs-feedback]').isVisible(), false);
            assert.equal(await page.locator('[data-logs-database]').isVisible(), false);
            assert.equal(await page.locator('[data-logs-source]').isVisible(), false);
            assert.equal(diagnosticsRequests.length, 4);
            assert(diagnosticsRequests.every(request => request.method === 'GET' && request.body === null));
            assert.equal(submissions.length, 1);
        });

        await scenario('monitor carrega sob demanda, consulta registros e compartilha o CSRF nativo do formulário', async () => {
            assert.equal(monitorRequests.length, 0);
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 3 && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.deepEqual(monitorRequests.map(request => request.action), ['overview', 'invoices']);
            assert.equal(await page.locator('[data-monitor-metric="linked_open_invoices"]').textContent(), '2');
            assert.equal(await page.locator('[name="csrf_fixture"]').inputValue(), 'fixture-csrf-token');
            assert.deepEqual(await page.locator('[data-monitor-list="invoices"] [data-monitor-payment-evidence]').allTextContents(),
                ['Recebimento confirmado', 'Sem confirmação de pagamento', 'Ainda não consultada']);
            assert.match(await page.locator('#asaas-monitor-payment-help').textContent(), /webhook.*processad/i);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor.png'), fullPage: true});
            await page.locator('[data-monitor-tab="events"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-list="events"]').textContent.includes('PAYMENT_RECEIVED') && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            await page.locator('[data-monitor-tab="logs"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-list="logs"]').textContent.includes('Falha simulada da API') && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-monitor-list="logs"] img').count(), 0);
            assert.equal(await page.evaluate(() => window.fixtureXss === true), false);
            assert.equal(await page.locator('form form').count(), 0);
            assert.equal(submissions.length, 1, 'A navegação/consulta do monitor não submete as configurações.');
        });

        await scenario('alternar de Comparar para Logs mantém o CSRF renovado e permite salvar as configurações', async () => {
            await page.locator('[data-monitor-tab="invoices"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 3
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const previousToken = await page.locator('[name="csrf_fixture"]').inputValue();
            await page.locator('[data-monitor-list="invoices"] [data-monitor-invoice-action="compare"]').first().click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-results]').textContent.includes('Resultado simulado da fatura.')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const comparison = monitorRequests.filter(request => request.action === 'compare');
            assert.equal(comparison.length, 1);
            assert.equal(comparison[0].method, 'POST');
            const submitted = new URLSearchParams(comparison[0].body);
            assert.equal(submitted.get('invoice_ids[]'), '42');
            assert.equal(submitted.get('csrf_fixture'), previousToken);
            assert.equal(monitorRequests.some(request => request.action === 'reconcile'), false);
            const renewedToken = await page.locator('[name="csrf_fixture"]').inputValue();
            assert.notEqual(renewedToken, previousToken);
            await showGroup(page, 'logs');
            assert.equal(diagnosticsRequests.length, 4, 'Reabrir um relatório já carregado não deve repetir sua consulta.');
            await page.locator('[data-logs-action="refresh"]').click();
            await page.waitForFunction(() => !document.querySelector('[data-logs-content]').hidden
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.equal(diagnosticsRequests.length, 5);
            assert.equal(await page.locator('[name="csrf_fixture"]').inputValue(), renewedToken, 'GET de logs não deve sobrescrever o token renovado pelo monitor.');
            const expected = await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))).sort((a, b) => a[0].localeCompare(b[0])));
            await Promise.all([
                page.waitForRequest(request => new URL(request.url()).pathname === '/admin/settings' && request.method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(submissions.length, 2);
            assert.deepEqual(submissions[1], expected);
            assert.equal(new Map(submissions[1]).get('csrf_fixture'), renewedToken);
            assert.equal(await page.locator('form form').count(), 0);
            await masked(page);
        });

        await scenario('GET de Logs recupera o CSRF após expiração do cookie e o próximo Comparar continua válido', async () => {
            const previousToken = await assertNativeCsrf(page);
            // Nesta fixture há somente o cookie CSRF fictício, sem sessão real.
            await context.clearCookies();
            await page.locator('[data-logs-action="refresh"]').click();
            await page.waitForFunction(() => !document.querySelector('[data-logs-content]').hidden
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.notEqual(await assertNativeCsrf(page), previousToken);
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const [response] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/asaas/monitor/compare' && response.request().method() === 'POST'),
                page.locator('[data-monitor-list="invoices"] [data-monitor-invoice-action="compare"]').first().click()
            ]);
            assert.equal(response.status(), 200);
            await page.waitForFunction(() => document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            await assertNativeCsrf(page);
            assert.deepEqual(csrfRejections, []);
        });

        await scenario('POST do monitor em andamento adia o primeiro GET de Logs até a resposta renovar o CSRF', async () => {
            await page.goto('https://crm.example.test/admin/settings');
            await page.waitForSelector('#asaas-settings-groups:not([hidden])');
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 3
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const previousToken = await assertNativeCsrf(page);
            const logsBefore = diagnosticsRequests.length;
            const gate = holdMonitorResponse = delayedResponse();
            await page.locator('[data-monitor-list="invoices"] [data-monitor-invoice-action="compare"]').first().click();
            await waitForGate(gate);
            assert.equal(await page.locator('#settings-form').getAttribute('data-asaas-monitor-busy'), '1');
            await showGroup(page, 'logs');
            assert.equal(await page.evaluate(() => window.__asaasFixture.fetches.filter(request => request.url.endsWith('/logs_data')).length), 0,
                'Nenhum GET de Logs pode capturar o cookie antigo enquanto o POST está em andamento.');
            assert.equal(diagnosticsRequests.length, logsBefore);
            gate.release();
            await page.waitForFunction(() => !document.querySelector('[data-logs-content]').hidden
                && document.getElementById('asaas-logs').getAttribute('aria-busy') === 'false');
            assert.equal(diagnosticsRequests.length, logsBefore + 1);
            assert.notEqual(await assertNativeCsrf(page), previousToken);
            const savedBefore = submissions.length;
            const [saved] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/settings' && response.request().method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(saved.status(), 204);
            assert.equal(submissions.length, savedBefore + 1);
            assert.deepEqual(csrfRejections, []);
        });

        await scenario('GET de Logs em andamento adia leituras do monitor e impede Comparar e Salvar até liberar o CSRF', async () => {
            const gate = holdLogResponse = delayedResponse();
            const requestsBefore = monitorRequests.length;
            const savedBefore = submissions.length;
            const fetchesBefore = await page.evaluate(() => window.__asaasFixture.fetches.filter(request => request.url.includes('/asaas/monitor/')).length);
            await page.locator('[data-logs-action="refresh"]').click();
            await waitForGate(gate);
            assert.equal(await page.locator('#settings-form').getAttribute('data-asaas-logs-loading'), '1');
            await showGroup(page, 'monitoring');
            const compare = page.locator('[data-monitor-list="invoices"] [data-monitor-invoice-action="compare"]').first();
            assert.equal(await compare.isDisabled(), true);
            await compare.dispatchEvent('click');
            assert.equal(await page.evaluate(() => window.__asaasFixture.fetches.filter(request => request.url.includes('/asaas/monitor/')).length), fetchesBefore,
                'Não pode iniciar GET ou POST do monitor enquanto a resposta de Logs está retida.');
            assert.equal(monitorRequests.length, requestsBefore);
            const canceled = await page.evaluate(() => !document.getElementById('settings-form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})));
            assert.equal(canceled, true, 'O envio nativo deve ser impedido durante a leitura que pode renovar o cookie.');
            await page.evaluate(() => document.getElementById('settings-form').requestSubmit(document.getElementById('settings-save')));
            // O aviso de submit pode abrir Logs; voltar ao monitor continua sem
            // iniciar requisições até o GET em andamento terminar.
            await showGroup(page, 'monitoring');
            assert.equal(monitorRequests.length, requestsBefore);
            gate.release();
            await page.waitForFunction(before => document.getElementById('settings-form').getAttribute('data-asaas-logs-loading') !== '1'
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false'
                && window.__asaasFixture.fetches.filter(request => request.url.includes('/asaas/monitor/')).length >= before + 2, fetchesBefore);
            const deferred = monitorRequests.slice(requestsBefore);
            assert.deepEqual(deferred.map(request => [request.action, request.method]), [['overview', 'GET'], ['invoices', 'GET']]);
            assert.equal(submissions.length, savedBefore, 'Liberar a leitura não pode submeter configurações automaticamente.');
            await assertNativeCsrf(page);
            const [response] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/asaas/monitor/compare' && response.request().method() === 'POST'),
                compare.click()
            ]);
            assert.equal(response.status(), 200);
            await page.waitForFunction(() => document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            await assertNativeCsrf(page);
            const [saved] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/settings' && response.request().method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(saved.status(), 204);
            assert.equal(submissions.length, savedBefore + 1);
            assert.deepEqual(csrfRejections, []);
        });

        await scenario('fatura antiga sem vínculo pode ser localizada e comparada dentro do monitor', async () => {
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-monitor-metric="eligible_open_invoices"]').textContent(), '3');
            assert.equal(await page.locator('[data-monitor-metric="unlinked_open_invoices"]').textContent(), '1');
            assert.match(await page.locator('[data-monitor-metric="cron_configuration"]').textContent(), /aberto não têm corte por idade/);
            await page.locator('#asaas-monitor-invoice-status').selectOption('missing');
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="invoices"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 1
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.match(await page.locator('[data-monitor-list="invoices"]').textContent(), /Ainda não consultada/);
            assert.match(await page.locator('[data-monitor-list="invoices"]').textContent(), /Vínculo a recuperar/);
            assert.equal(monitorRequests.filter(request => request.action === 'invoices').at(-1).query.status, 'missing');
            const operationsBefore = monitorRequests.filter(request => request.method === 'POST').length;
            await page.locator('[data-monitor-list="invoices"] [data-monitor-invoice-action="compare"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-results]').textContent.includes('vínculo recuperado')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const operation = monitorRequests.filter(request => request.method === 'POST').slice(operationsBefore);
            assert.equal(operation.length, 1);
            assert.equal(operation[0].action, 'compare');
            assert.equal(new URLSearchParams(operation[0].body).get('invoice_ids[]'), '44');
            assert.match(await page.locator('[data-monitor-results]').textContent(), /0 registrado\(s\)/);
            await assertNativeCsrf(page);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor-recovery.png'), fullPage: true});
            await page.locator('#asaas-monitor-invoice-status').selectOption('open');
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="invoices"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 3
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
        });

        await scenario('contadores de erro abrem diagnóstico filtrado com código, HTTP e detalhes seguros', async () => {
            const writesBefore = monitorRequests.filter(request => request.method === 'POST').length;
            await page.locator('[data-monitor-action="show-errors"][data-monitor-source="all"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-list="logs"]').textContent.includes('HTTP 400')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const diagnostic = monitorRequests.filter(request => request.action === 'logs').at(-1);
            assert.equal(diagnostic.method, 'GET');
            assert.equal(diagnostic.query.level, 'error');
            assert.equal(diagnostic.query.source, 'all');
            assert.equal(await page.locator('[data-monitor-tab="logs"]').getAttribute('aria-selected'), 'true');
            assert.match(await page.locator('[data-monitor-list="logs"]').textContent(), /Código: invalid_value/);
            await page.locator('[data-monitor-list="logs"] summary').click();
            assert.match(await page.locator('[data-monitor-list="logs"] pre').textContent(), /Detalhe seguro/);
            assert.equal(await page.locator('[data-monitor-list="logs"] script').count(), 0);
            assert.equal(await page.evaluate(() => window.fixtureXss === true), false);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor-errors.png'), fullPage: true});
            await page.locator('[data-monitor-action="show-errors"][data-monitor-source="webhook"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-list="logs"]').textContent.includes('HTTP 401')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.equal(monitorRequests.filter(request => request.action === 'logs').at(-1).query.source, 'webhook');
            assert.match(await page.locator('[data-monitor-list="logs"]').textContent(), /webhook_auth_failed/);
            assert.equal(monitorRequests.filter(request => request.method === 'POST').length, writesBefore);
            await assertNativeCsrf(page);
        });

        await scenario('catálogo do Asaas consulta, filtra e pagina sem alterar o formulário ou pagamentos', async () => {
            const writesBefore = monitorRequests.filter(request => request.method === 'POST').length;
            const settingsBefore = await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))).filter(entry => entry[0].startsWith('settings[')));
            assert.equal(monitorRequests.filter(request => request.action === 'payments').length, 0);
            await page.locator('[data-monitor-tab="payments"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="payments"] tr').length === 6
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const first = monitorRequests.filter(request => request.action === 'payments').at(-1);
            assert.equal(first.method, 'GET');
            assert.equal(first.query.date_from, undefined);
            assert.equal(first.query.date_to, undefined);
            assert.equal(await page.locator('[data-monitor-list="payments"] [data-monitor-payment-invoice-id]').count(), 3);
            assert.equal(await page.locator('[data-monitor-list="payments"] [data-monitor-invoice-action]').count(), 6);
            assert.match(await page.locator('[data-monitor-list="payments"]').textContent(), /Sem confirmação de pagamento/);
            assert.match(await page.locator('[data-monitor-list="payments"]').textContent(), /Correspondência ambígua/);
            assert.match(await page.locator('[data-monitor-feedback]').textContent(), /parte dos vínculos exige nova conferência/);
            assert.match(await page.locator('[data-monitor-list="payments"]').textContent(), /HTTP 429/);
            assert.match(await page.locator('[data-monitor-list="payments"]').textContent(), /provider_unavailable/);
            await page.locator('#asaas-monitor-payment-select-all').check();
            assert.match(await page.locator('[data-monitor-payment-selected-count]').textContent(), /2 faturas selecionadas/);
            await page.locator('#asaas-monitor-payment-status').selectOption('CONFIRMED');
            await page.locator('#asaas-monitor-payment-date-field').selectOption('created');
            await page.locator('#asaas-monitor-payment-date-from').fill('2025-12-01');
            await page.locator('#asaas-monitor-payment-date-to').fill('2026-01-31');
            await page.locator('#asaas-monitor-payment-per-page').selectOption('50');
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="payments"] tr').length === 4
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.deepEqual(monitorRequests.filter(request => request.action === 'payments').at(-1).query,
                {page: '1', per_page: '50', status: 'CONFIRMED', date_field: 'created', date_from: '2025-12-01', date_to: '2026-01-31'});
            assert.equal(await page.locator('[data-monitor-action="reconcile-selected"][data-monitor-kind="payments"]').isDisabled(), true);
            await page.locator('[data-monitor-action="next"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-list="payments"]').textContent.includes('pay_other_page')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.equal(monitorRequests.filter(request => request.action === 'payments').at(-1).query.page, '2');
            assert.equal(await page.locator('[data-monitor-action="next"][data-monitor-kind="payments"]').isDisabled(), true);
            assert.equal(await page.locator('[data-monitor-action="previous"][data-monitor-kind="payments"]').isEnabled(), true);
            await page.locator('#asaas-monitor-payment-date-from').fill('2026-02-01');
            const readsBeforeInvalid = monitorRequests.filter(request => request.action === 'payments').length;
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-feedback]').textContent.includes('data inicial'));
            assert.equal(monitorRequests.filter(request => request.action === 'payments').length, readsBeforeInvalid);
            await page.locator('#asaas-monitor-payment-date-from').fill('');
            await page.locator('#asaas-monitor-payment-date-to').fill('');
            await page.locator('#asaas-monitor-payment-status').selectOption('all');
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="payments"] tr').length === 6
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.deepEqual(await page.evaluate(() => Array.from(new FormData(document.getElementById('settings-form'))).filter(entry => entry[0].startsWith('settings['))), settingsBefore);
            assert.equal(await page.locator('#asaas-monitor input[name], #asaas-monitor select[name]').count(), 0);
            for (const selector of ['#asaas-monitor-payment-date-from', '#asaas-monitor-payment-date-to']) {
                assert.equal(await page.locator(selector).evaluate(input => input.form === null), true,
                    'A validação do filtro de data deve ficar fora do formulário nativo de configurações.');
                await page.locator(selector).evaluate(input => input.setCustomValidity('Data do filtro incompleta.'));
                assert.equal(await page.evaluate(() => document.getElementById('settings-form').checkValidity()), true,
                    'Um filtro incompleto não pode bloquear Salvar configurações.');
            }
            const savedBefore = submissions.length;
            const [saved] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/settings' && response.request().method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(saved.status(), 204);
            assert.equal(submissions.length, savedBefore + 1);
            assert.deepEqual(submissions[savedBefore].filter(entry => entry[0].startsWith('settings[')),
                settingsBefore.slice().sort((a, b) => a[0].localeCompare(b[0])));
            for (const selector of ['#asaas-monitor-payment-date-from', '#asaas-monitor-payment-date-to']) {
                await page.locator(selector).evaluate(input => input.setCustomValidity(''));
            }
            assert.equal(monitorRequests.filter(request => request.method === 'POST').length, writesBefore);
            await assertNativeCsrf(page);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor-payments.png'), fullPage: true});
        });

        await scenario('catálogo trata falha segura e concilia somente faturas elegíveis sem duplicar parcelas', async () => {
            remotePaymentsResponse = 'error';
            const beforeFailure = monitorRequests.filter(request => request.action === 'payments').length;
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-feedback]').textContent.includes('HTTP 429')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.match(await page.locator('[data-monitor-feedback]').textContent(), /provider_unavailable/);
            assert.equal(monitorRequests.filter(request => request.action === 'payments').length, beforeFailure + 1);
            assert.equal(await page.locator('[data-monitor-action="reconcile-selected"][data-monitor-kind="payments"]').isDisabled(), true);
            await assertNativeCsrf(page);
            remotePaymentsResponse = 'ok';
            await page.locator('[data-monitor-action="filter"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="payments"] tr').length === 6
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            await page.locator('[data-monitor-payment-invoice-id="44"]').first().check();
            assert.equal(await page.locator('[data-monitor-payment-invoice-id="44"]:checked').count(), 2);
            assert.match(await page.locator('[data-monitor-payment-selected-count]').textContent(), /1 fatura selecionada/);
            const compareBefore = monitorRequests.filter(request => request.action === 'compare').length;
            await page.locator('[data-monitor-action="compare-selected"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelector('[data-monitor-results]').textContent.includes('vínculo recuperado')
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const comparisons = monitorRequests.filter(request => request.action === 'compare').slice(compareBefore);
            assert.equal(comparisons.length, 1);
            assert.equal(new URLSearchParams(comparisons[0].body).get('invoice_ids[]'), '44');
            await page.locator('#asaas-monitor-payment-select-all').check();
            const reconcileBefore = monitorRequests.filter(request => request.action === 'reconcile').length;
            await page.locator('[data-monitor-action="reconcile-selected"][data-monitor-kind="payments"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-results] tr').length === 2
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            const reconciliations = monitorRequests.filter(request => request.action === 'reconcile').slice(reconcileBefore);
            assert.deepEqual(reconciliations.map(request => new URLSearchParams(request.body).get('invoice_ids[]')).sort(), ['42', '44']);
            assert(reconciliations.every(request => new URLSearchParams(request.body).get('trigger') === 'batch'));
            assert.equal(await page.locator('form form').count(), 0);
            await assertNativeCsrf(page);
            assert.deepEqual(csrfRejections, []);
            await page.setViewportSize({width: 390, height: 844});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.equal(await page.locator('#asaas-monitor-payment-status').isVisible(), true);
            assert.equal(await page.locator('#asaas-monitor-payment-date-from').isVisible(), true);
            assert.equal(await page.locator('#asaas-monitor-payment-date-to').isVisible(), true);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor-payments-mobile.png'), fullPage: true});
            await page.locator('[data-monitor-tab="invoices"]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 3
                && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
        });

        await scenario('área de cerca de 800px mantém logs, monitor e cartão dentro do painel', async () => {
            await page.setViewportSize({width: 900, height: 1000});
            await showGroup(page, 'logs');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            await page.screenshot({path: path.join(artifacts, 'settings-logs-narrow.png'), fullPage: true});
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.equal(await page.locator('[data-monitor-list="invoices"] [data-monitor-payment-evidence]').count(), 3);
            await page.screenshot({path: path.join(artifacts, 'settings-monitor-narrow.png'), fullPage: true});
            await showGroup(page, 'charges');
            await page.locator('#asaas-charges-tab-card').click();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            await page.screenshot({path: path.join(artifacts, 'settings-card-narrow.png'), fullPage: true});
            assert.equal(await page.locator('[data-asaas-card-section="anticipation"]').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length), 2);
            await page.locator('[aria-labelledby="asaas-card-anticipation-title"]').screenshot({path: path.join(artifacts, 'settings-anticipation-narrow.png')});
        });

        await scenario('mobile mantém rolagem das guias dentro do painel e ambiente legível', async () => {
            await page.setViewportSize({width: 390, height: 844});
            await showGroup(page, 'environment');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.equal(await page.locator('.asaas-settings-tab-scroll').evaluate(element => element.scrollWidth > element.clientWidth), true);
            await page.locator('#asaas-settings-tab-environment').focus();
            await page.keyboard.press('ArrowRight');
            await assertTab(page, 'payments');
            await showGroup(page, 'environment');
            await page.screenshot({path: path.join(artifacts, 'settings-mobile.png'), fullPage: true});
        });

        await scenario('Logs no mobile contém a rolagem da tabela sem alargar a página ou ocultar ações', async () => {
            await showGroup(page, 'logs');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.equal(await page.locator('[data-logs-table]').evaluate(element => element.scrollWidth > element.clientWidth), true);
            assert.equal(await page.locator('[data-logs-action="refresh"]').isVisible(), true);
            assert.equal(await page.locator('#settings-save').isVisible(), true);
            await page.screenshot({path: path.join(artifacts, 'settings-logs-mobile.png'), fullPage: true});
        });

        await scenario('cartão no mobile organiza campos em uma coluna e preserva subguias e salvamento', async () => {
            await showGroup(page, 'charges');
            await page.locator('#asaas-charges-tab-card').click();
            await assertTab(page, 'charges');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.equal(await page.locator('[data-asaas-card-section="installments"]').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length), 1);
            assert.equal(await page.locator('[data-asaas-card-section="anticipation"]').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length), 1);
            assert.equal(await page.locator('[data-asaas-card-enum="card_fee_mode"]').isVisible(), true);
            assert.equal(await page.locator('[data-asaas-card-enum="card_anticipation_mode"]').isVisible(), true);
            assert.equal(await page.locator(inputSelector('card_fee_percent_13_21')).isVisible(), true);
            for (const name of anticipationSettings.filter(name => name !== 'card_anticipation_mode')) {
                assert.equal(await page.locator(inputSelector(name)).isVisible(), true, name);
            }
            assert.equal(await page.locator('#settings-save').isVisible(), true);
            await page.screenshot({path: path.join(artifacts, 'settings-card-mobile.png'), fullPage: true});
            await page.locator('[aria-labelledby="asaas-card-anticipation-title"]').screenshot({path: path.join(artifacts, 'settings-anticipation-mobile.png')});
        });

        await scenario('renderer inesperado e ausência da view preservam formulário plano e máscaras', async () => {
            for (const fixture of ['wrapped', 'flat']) {
                await page.goto('https://crm.example.test/admin/settings?fixture=' + fixture);
                await page.waitForFunction(() => document.getElementById('online_payments_asaas_tab').getAttribute('data-asaas-settings-initialized') === '1');
                await masked(page);
                assert.equal(await page.locator(inputSelector('api_key')).isVisible(), true);
                assert.equal(await page.locator(inputSelector('future_option')).isVisible(), true);
                assert.equal(await page.locator('[data-asaas-logs-open]').isVisible(), true, 'O visualizador antigo deve continuar acessível no fallback.');
                assert.equal(await page.locator(inputSelector('card_fee_mode')).isVisible(), true, 'O enum original permanece editável no fallback.');
                assert.equal(await page.locator('[data-asaas-card-enum-fallback="card_fee_mode"]').isVisible(), true);
                assert.equal(await page.locator(inputSelector('card_anticipation_mode')).isVisible(), true);
                assert.equal(await page.locator('[data-asaas-card-enum-fallback="card_anticipation_mode"]').isVisible(), true);
                if (fixture === 'wrapped') { assert.equal(await page.locator('#asaas-settings-groups').isVisible(), false); }
                assert.equal(await page.evaluate(() => JSON.stringify(window.__asaasFixture.payload) === JSON.stringify(Array.from(new FormData(document.getElementById('settings-form'))).sort((a, b) => a[0].localeCompare(b[0])))), true);
            }
        });

        await scenario('sem JavaScript os campos originais continuam editáveis e as guias ficam ocultas', async () => {
            const noJsContext = await browser.newContext({javaScriptEnabled: false, viewport: {width: 1280, height: 960}});
            const noJsPage = await noJsContext.newPage();
            await attachRoutes(noJsPage);
            await noJsPage.goto('https://crm.example.test/admin/settings');
            assert.equal(await noJsPage.locator('#asaas-settings-groups').isVisible(), false);
            assert.equal(await noJsPage.locator(inputSelector('api_key')).isVisible(), true);
            assert.equal(await noJsPage.locator(inputSelector('api_key')).getAttribute('type'), 'password');
            assert.equal(await noJsPage.locator(inputSelector('future_option')).isVisible(), true);
            assert.equal(await noJsPage.locator(inputSelector('card_fee_mode')).isVisible(), true);
            assert.equal(await noJsPage.locator(inputSelector('card_fee_mode')).inputValue(), 'absorb');
            assert.equal(await noJsPage.locator('[data-asaas-card-enum-fallback="card_fee_mode"]').isVisible(), true);
            assert.equal(await noJsPage.locator(inputSelector('card_anticipation_mode')).inputValue(), 'none');
            assert.equal(await noJsPage.locator('[data-asaas-card-enum-fallback="card_anticipation_mode"]').isVisible(), true);
            for (const name of anticipationSettings) {
                assert.equal(await noJsPage.locator(inputSelector(name)).isVisible(), true, name);
            }
            assert.equal(await noJsPage.locator('[data-asaas-logs-open]').isVisible(), true, 'Sem JavaScript o acesso existente aos logs deve permanecer disponível.');
            assert.match(await noJsPage.locator('[data-asaas-logs-open]').getAttribute('href'), /\/admin\/asaas\/asaas\/logs$/);
            await noJsContext.close();
        });
        await scenario('antecipação salva modalidade e quatro taxas pelo formulário nativo sem chamada financeira', async () => {
            await page.setViewportSize({width: 1280, height: 960});
            await page.goto('https://crm.example.test/admin/settings');
            await page.waitForFunction(() => document.getElementById('online_payments_asaas_tab').getAttribute('data-asaas-settings-initialized') === '1');
            const savedBefore = submissions.length;
            const monitorBefore = monitorRequests.length;
            const diagnosticsBefore = diagnosticsRequests.length;
            await showGroup(page, 'charges');
            await page.locator('#asaas-charges-tab-card').click();
            const values = {
                card_anticipation_mode: 'automatic', card_anticipation_first_days: '28',
                card_anticipation_manual_rate_1: '1,30', card_anticipation_manual_rate_installments: '1,75',
                card_anticipation_automatic_rate_1: '1,10', card_anticipation_automatic_rate_installments: '1,55'
            };
            await page.locator('[data-asaas-card-enum="card_anticipation_mode"]').selectOption(values.card_anticipation_mode);
            for (const [name, value] of Object.entries(values)) {
                if (name !== 'card_anticipation_mode') { await page.locator(inputSelector(name)).fill(value); }
            }
            const [saved] = await Promise.all([
                page.waitForResponse(response => new URL(response.url()).pathname === '/admin/settings' && response.request().method() === 'POST'),
                page.locator('#settings-save').click()
            ]);
            assert.equal(saved.status(), 204);
            assert.equal(submissions.length, savedBefore + 1);
            for (const [name, value] of Object.entries(values)) {
                assert.deepEqual(submissions[savedBefore].filter(entry => entry[0] === 'settings[paymentmethod_asaas_' + name + ']'),
                    [['settings[paymentmethod_asaas_' + name + ']', value]], name);
            }
            assert.equal(monitorRequests.length, monitorBefore);
            assert.equal(diagnosticsRequests.length, diagnosticsBefore);
            assert.deepEqual(await page.evaluate(() => window.__asaasFixture.fetches), [], 'Configurar a antecipação não deve fazer requisições financeiras.');
            assert.equal(await page.locator('[data-asaas-card-enum="card_anticipation_mode"]').inputValue(), 'automatic');
            await page.locator('[aria-labelledby="asaas-card-anticipation-title"]').screenshot({path: path.join(artifacts, 'settings-anticipation.png')});
        });
        assert.deepEqual(errors, [], 'Nenhuma exceção JavaScript deve ocorrer.');
        assert.deepEqual(unexpectedRequests, [], 'Nenhum recurso/requisição externa não simulada deve ocorrer.');
        assert.deepEqual(csrfRejections, [], 'Todos os POSTs devem apresentar o token correspondente ao cookie nativo vigente.');
        await context.close();
    } catch (error) {
        if (page && !page.isClosed()) { await page.screenshot({path: path.join(artifacts, 'settings-failure.png'), fullPage: true}).catch(() => {}); }
        throw error;
    } finally {
        for (const gate of pendingResponses) { gate.release(); }
        await browser.close();
    }
    console.log('\nSettings UI: ' + scenarios + ' cenários aprovados, incluindo Chromium e Bootstrap 3 reais.');
}

main().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
