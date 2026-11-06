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
    await scenario('scripts válidos, carregados na ordem contratada e sem persistência de segredos', () => {
        const scripts = ['asaas-settings.js', 'asaas-webhook-settings.js', 'asaas-monitor.js'];
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
        '/_fixture/asaas-settings.js': path.join(root, 'assets/js/asaas-settings.js'),
        '/_fixture/asaas-webhook-settings.js': path.join(root, 'assets/js/asaas-webhook-settings.js'),
        '/_fixture/asaas-monitor.js': path.join(root, 'assets/js/asaas-monitor.js')
    };
    assert.match(fs.readFileSync(assets['/_fixture/bootstrap.js'], 'utf8'), /Bootstrap v3\./);
    const artifacts = path.join(__dirname, 'artifacts');
    fs.mkdirSync(artifacts, {recursive: true});
    const browser = await chromium.launch({headless: true});
    const errors = [];
    const unexpectedRequests = [];
    const monitorRequests = [];
    const submissions = [];
    let csrfSequence = 0;

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
                    submissions.push(Array.from(new URLSearchParams(request.postData())).sort((a, b) => a[0].localeCompare(b[0])));
                    return route.fulfill({status: 204});
                }
                const name = url.searchParams.get('fixture') || 'standard';
                return route.fulfill({contentType: 'text/html; charset=utf-8', body: fixtures[name].html});
            }
            if (assets[url.pathname]) {
                return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(assets[url.pathname])});
            }
            if (url.pathname === '/favicon.ico') { return route.fulfill({status: 204}); }
            if (url.pathname.startsWith('/admin/asaas/monitor/')) {
                const action = url.pathname.split('/').pop();
                monitorRequests.push({action, method: request.method(), body: request.postData()});
                const payload = {ok: true, csrf: {name: 'csrf_fixture', hash: 'fixture-csrf-' + (++csrfSequence)}};
                if (action === 'overview') {
                    payload.data = {linked_open_invoices: 2, webhook_errors: 1, log_errors: 1, last_webhook_at: '2026-01-10 09:10:00',
                        cron: {enabled: true, days: 7, limit: 50, last_run: {status: 'completed', finished_at: '2026-01-10 09:00:00', processed: 8, reconciled: 2, errors: 0}}};
                } else if (action === 'invoices') {
                    payload.data = [{invoice_id: 42, invoice_number: 'FAT-000042', client_name: 'Cliente de teste', crm_status: 1,
                        asaas_status: 'RECEIVED', asaas_payment_id: 'pay_fixture42', billing_type: 'PIX', total: '150.00', balance: '150.00',
                        last_sync_at: '2026-01-10 08:30:00', last_webhook_at: '2026-01-10 08:29:00', webhook_status: 'error', suspected_divergence: true},
                    {invoice_id: 43, invoice_number: 'FAT-000043', client_name: 'Outro cliente de teste', crm_status: 1,
                        asaas_status: 'PENDING', asaas_payment_id: 'pay_fixture43', billing_type: 'BOLETO', total: '95.00', balance: '95.00', suspected_divergence: false}];
                } else if (action === 'events') {
                    payload.data = [{invoice_id: 42, event_type: 'PAYMENT_RECEIVED', event_id: 'evt_fixture42', payment_id: 'pay_fixture42',
                        received_at: '2026-01-10 08:29:00', processed_at: null, process_status: 'error', error_message: 'Falha de processamento simulada.'}];
                } else if (action === 'logs') {
                    payload.data = [{created_at: '2026-01-10 08:29:00', level: 'error', message: 'Falha simulada da API <img src=x onerror=window.fixtureXss=true>',
                        correlation_id: 'fixture-operation42', context: {operation: 'invoice_sync', invoice_id: 42}}];
                } else if (action === 'compare' || action === 'reconcile') {
                    const id = new URLSearchParams(request.postData()).get('invoice_ids[]');
                    payload.data = [{invoice_id: Number(id), result: action === 'compare' ? 'divergent' : 'reconciled', crm_status: 1,
                        remote_status: 'RECEIVED', balance: '150.00', receipts_added: action === 'compare' ? 0 : 1,
                        remote_paid_amount: '150.00', message: 'Resultado simulado da fatura.'}];
                } else {
                    unexpectedRequests.push(request.url());
                    return route.abort();
                }
                if (['invoices', 'events', 'logs'].includes(action)) {
                    payload.pagination = {page: 1, has_more: false, total: payload.data.length};
                }
                return route.fulfill({contentType: 'application/json', body: JSON.stringify(payload)});
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
        });

        await scenario('grupos completos incluem campos desconhecidos e os blocos after originais', async () => {
            const expected = {
                general: ['active', 'label', 'currencies', 'description', 'future_option'],
                environment: ['api_key', 'api_key_sandbox', 'sandbox', 'show_environment_in_production'],
                payments: ['billet_only', 'card_only', 'pix_only', 'installmentCount'],
                charges: ['interest_value', 'fine_value', 'discount_type', 'discount_value', 'discount_days', 'diasdevencimento', 'disable_charge_notification'],
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
            await assertTab(page, 'payments');
            assert.equal(await page.locator(inputSelector('installmentCount')).evaluate(input => document.activeElement === input), true);
            assert.equal(submissions.length, 0);
            await page.evaluate(() => {
                document.querySelector('[name="settings[paymentmethod_asaas_installmentCount]"]').value = '1';
                document.querySelector('[name="settings[paymentmethod_asaas_sync_limit]"]').value = '50';
            });
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

        await scenario('monitor carrega sob demanda, consulta registros e renova o CSRF do formulário', async () => {
            assert.equal(monitorRequests.length, 0);
            await showGroup(page, 'monitoring');
            await page.waitForFunction(() => document.querySelectorAll('[data-monitor-list="invoices"] [data-monitor-invoice-id]').length === 2 && document.getElementById('asaas-monitor').getAttribute('aria-busy') === 'false');
            assert.deepEqual(monitorRequests.map(request => request.action), ['overview', 'invoices']);
            assert.equal(await page.locator('[data-monitor-metric="linked_open_invoices"]').textContent(), '2');
            assert.equal(await page.locator('[name="csrf_fixture"]').inputValue(), 'fixture-csrf-2');
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

        await scenario('renderer inesperado e ausência da view preservam formulário plano e máscaras', async () => {
            for (const fixture of ['wrapped', 'flat']) {
                await page.goto('https://crm.example.test/admin/settings?fixture=' + fixture);
                await page.waitForFunction(() => document.getElementById('online_payments_asaas_tab').getAttribute('data-asaas-settings-initialized') === '1');
                await masked(page);
                assert.equal(await page.locator(inputSelector('api_key')).isVisible(), true);
                assert.equal(await page.locator(inputSelector('future_option')).isVisible(), true);
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
            await noJsContext.close();
        });
        assert.deepEqual(errors, [], 'Nenhuma exceção JavaScript deve ocorrer.');
        assert.deepEqual(unexpectedRequests, [], 'Nenhum recurso/requisição externa não simulada deve ocorrer.');
        await context.close();
    } catch (error) {
        if (page && !page.isClosed()) { await page.screenshot({path: path.join(artifacts, 'settings-failure.png'), fullPage: true}).catch(() => {}); }
        throw error;
    } finally {
        await browser.close();
    }
    console.log('\nSettings UI: ' + scenarios + ' cenários aprovados, incluindo Chromium e Bootstrap 3 reais.');
}

main().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
