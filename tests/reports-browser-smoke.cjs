#!/usr/bin/env node
'use strict';

/** Actual PHP view + browser. HTTP is intercepted locally, never sent to a CRM or Asaas. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const origin = 'https://crm.example.test';
const php = process.env.ASAAS_TEST_PHP || 'php';
const fixture = path.join(__dirname, 'fixtures/reports-ui-page.php');
const catalog = JSON.parse(execFileSync(php, [fixture, '--manifest'], {encoding: 'utf8', cwd: root, timeout: 10000}));
const html = execFileSync(php, [fixture], {encoding: 'utf8', cwd: root, timeout: 10000});
const viewOnly = execFileSync(php, [fixture, '--view-only'], {encoding: 'utf8', cwd: root, timeout: 10000});
const assets = {
    '/_fixture/bootstrap.css': require.resolve('bootstrap/dist/css/bootstrap.min.css'),
    '/modules/asaas/assets/css/asaas-reports.css': path.join(root, 'assets/css/asaas-reports.css'),
    '/modules/asaas/assets/js/asaas-reports.js': path.join(root, 'assets/js/asaas-reports.js')
};
const artifacts = path.join(__dirname, 'artifacts');
const errors = [];
const requests = [];
const unexpected = [];
let failExport = false;
let failQuery = false;
let scenarios = 0;
let current = '';
const watchdog = setTimeout(() => { console.error('Reports browser exceeded deadline: ' + current); process.exit(1); }, 60000);
watchdog.unref();
async function scenario(name, work) { current = name; await work(); scenarios++; console.log('OK  ' + name); }

function report(params) {
    const definition = catalog.find(item => item.id === params.get('report'));
    const offset = Number(params.get('offset') || 0);
    const summary = definition.kind === 'summary';
    const row = {id: 'fixture_1', value: '150.00', balance: '123.45', netValue: '147.51', customer: 'cus_fixture',
        status: 'RECEIVED', billingType: 'PIX', date: '2026-01-01', dueDate: '2026-01-01', paymentDate: '2026-01-02',
        dateCreated: '2026-01-01', type: 'NEW_FINANCIAL_EVENT', description: '<img src=x onerror="window.REPORT_XSS=true">',
        quantity: 3, income: '19.00', externalReference: '=1+2', 'authorization.id': 'auth_fixture', customerId: 'cus_fixture'};
    const rows = summary || offset === 2 ? [row] : [row, {...row, id: 'fixture_2'}];
    return {ok: true, report: {id: definition.id, kind: definition.kind, title: definition.title, description: definition.description,
        columns: definition.columns, rows, offset: summary ? 0 : offset, per_page: Number(params.get('per_page') || 25),
        returned: rows.length, total: summary ? 1 : 3, has_more: !summary && offset === 0, next_offset: !summary && offset === 0 ? 2 : null,
        environment: 'sandbox', environment_label: 'Homologação', generated_at: '2026-01-02T13:00:00-03:00',
        filter_description: params.get('customer') ? 'Cliente no Asaas: ' + params.get('customer') : 'Filtros desta consulta'}};
}

async function main() {
    fs.mkdirSync(artifacts, {recursive: true});
    const browser = await chromium.launch({headless: true, timeout: 20000});
    const context = await browser.newContext({viewport: {width: 1280, height: 1000}, serviceWorkers: 'block', acceptDownloads: true});
    context.setDefaultTimeout(6000);
    await context.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (url.origin === origin && request.method() === 'GET') {
            if (url.pathname === '/_fixture/reports') { return route.fulfill({status: 200, contentType: 'text/html', body: url.searchParams.has('view-only') ? viewOnly : html}); }
            if (assets[url.pathname]) { return route.fulfill({status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(assets[url.pathname])}); }
            if (url.pathname === '/admin/asaas/reports/data') {
                requests.push(url);
                if (failQuery) { return route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({ok: false, message: 'A conta Asaas não autorizou esta consulta.'})}); }
                return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(report(url.searchParams))});
            }
            if (url.pathname === '/admin/asaas/reports/export') {
                requests.push(url);
                if (failExport) { return route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({ok: false, message: 'A lista mudou durante a exportação. Nenhum arquivo parcial foi disponibilizado.'})}); }
                return route.fulfill({status: 200, headers: {'Content-Type': 'text/csv; charset=UTF-8', 'Content-Disposition': 'attachment; filename="asaas-payments-sandbox-20260102-160000.csv"', 'X-Asaas-Report-Rows': '3'},
                    body: '\ufeffAmbiente;ID;Valor\r\nHomologação;fixture_1;150,00\r\nHomologação;fixture_2;150,00\r\nHomologação;fixture_3;150,00\r\n'});
            }
            if (url.pathname === '/favicon.ico') { return route.fulfill({status: 204, body: ''}); }
        }
        unexpected.push(request.method() + ' ' + url.origin + url.pathname); return route.abort('blockedbyclient');
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    try {
        await page.goto(origin + '/_fixture/reports', {waitUntil: 'load'});
        await scenario('catálogo completo e campos isolados do formulário de configurações', async () => {
            assert.equal(await page.locator('[data-report-kind] option').count(), 20);
            assert.equal(requests.length, 0, 'Abrir configurações não deve consultar todos os relatórios ou consumir páginas da API.');
            assert.equal(await page.locator('form form').count(), 0);
            await page.locator('[data-report-kind]').selectOption('refunds');
            assert.equal(await page.locator('[data-report-filter="payment_id"]').getAttribute('required'), '');
            const isolation = await page.evaluate(() => ({
                forms: Array.from(document.querySelectorAll('[data-report-filter]')).map(input => input.form === null),
                settings: Array.from(new FormData(document.getElementById('fixture-settings')).keys()),
                valid: document.getElementById('fixture-settings').checkValidity()
            }));
            assert.ok(isolation.forms.every(Boolean));
            assert.deepEqual(isolation.settings, ['settings[paymentmethod_asaas_active]']);
            assert.equal(isolation.valid, true, 'Filtro obrigatório não deve impedir salvar configurações.');
            await page.locator('#fixture-save').click();
            assert.equal(await page.evaluate(() => window.fixtureSubmits), 1);
        });
        await scenario('consulta e paginação mantêm filtros e avançam pelo offset retornado', async () => {
            await page.locator('[data-report-kind]').selectOption('payments');
            await page.locator('[data-report-filter="customer"]').fill('cus_fixture');
            await page.locator('[data-report-filter="anticipated"]').selectOption('false');
            await page.locator('[data-report-filter="customer"]').press('Enter');
            await page.locator('[data-report-results]').waitFor({state: 'visible'});
            assert.equal(await page.evaluate(() => window.fixtureSubmits), 1, 'Enter no filtro não pode enviar o formulário de configurações.');
            assert.equal(requests.at(-1).searchParams.get('anticipated'), 'false');
            assert.equal(await page.locator('[data-report-page]').textContent(), 'Registros 1–2 de 3');
            await page.locator('[data-report-action="next"]').click();
            await page.waitForFunction(() => document.querySelector('[data-report-page]').textContent === 'Registros 3–3 de 3');
            assert.equal(requests.at(-1).searchParams.get('offset'), '2');
            assert.equal(requests.at(-1).searchParams.get('customer'), 'cus_fixture');
            await page.locator('[data-report-action="previous"]').click();
            await page.waitForFunction(() => document.querySelector('[data-report-page]').textContent === 'Registros 1–2 de 3');
            assert.equal(requests.at(-1).searchParams.get('offset'), '0');
        });
        await scenario('dados remotos são texto e o relatório cabe de 320 a 1280 px', async () => {
            await page.locator('[data-report-row="0"]').click();
            assert.equal(await page.locator('[data-report-detail] img').count(), 0);
            assert.equal(await page.evaluate(() => window.REPORT_XSS), undefined);
            assert.ok((await page.locator('[data-report-detail]').textContent()).includes('<img src=x'));
            for (const width of [320, 375, 768, 1280]) {
                await page.setViewportSize({width, height: 1000});
                const bounds = await page.evaluate(() => ({page: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                    controls: Array.from(document.querySelectorAll('.asaas-reports input,.asaas-reports select,.asaas-reports-actions button'))
                        .map(element => { const b = element.getBoundingClientRect(); return {left: b.left, right: b.right, width: b.width}; })}));
                assert.ok(bounds.page <= width + 1, width + 'px deve limitar rolagem à tabela, sem expandir a página: ' + JSON.stringify(bounds));
                bounds.controls.forEach(control => assert.ok(control.width > 0 && control.left >= -1 && control.right <= width + 1));
                if (width === 375 || width === 1280) { await page.screenshot({path: path.join(artifacts, 'reports-' + width + '.png'), fullPage: true}); }
            }
        });
        await scenario('exporta todos os filtros e não oferece arquivo parcial após falha', async () => {
            const complete = page.waitForEvent('download');
            await page.locator('[data-report-action="export"]').click();
            const downloaded = await complete;
            assert.equal(downloaded.suggestedFilename(), 'asaas-payments-sandbox-20260102-160000.csv');
            assert.ok(fs.readFileSync(await downloaded.path(), 'utf8').includes('fixture_3'));
            assert.equal(requests.at(-1).searchParams.get('offset'), '0');
            assert.equal(requests.at(-1).searchParams.get('anticipated'), 'false');
            await page.waitForFunction(() => document.querySelector('[data-report-feedback]').textContent.includes('com 3 registro'));
            failExport = true;
            await page.locator('[data-report-action="export"]').click();
            await page.locator('[data-report-feedback].alert-danger').waitFor();
            assert.ok((await page.locator('[data-report-feedback]').textContent()).includes('Nenhum arquivo parcial'));
            assert.equal(await page.locator('[data-report-action="export"]').isEnabled(), true);
            failExport = false;
        });
        await scenario('impressão identifica página parcial e respeita resultado desatualizado', async () => {
            await page.locator('[data-report-action="print"]').click();
            const printFrame = page.frameLocator('#asaas-reports-print');
            assert.ok((await printFrame.locator('body').textContent()).includes('Página exibida: Registros 1–2 de 3'));
            assert.equal(await printFrame.locator('img').count(), 0);
            await page.locator('[data-report-filter="customer"]').fill('cus_outro');
            assert.equal(await page.locator('[data-report-results]').isHidden(), true);
            assert.equal(await page.locator('[data-report-action="print"]').isDisabled(), true);
            assert.equal(await page.locator('[data-report-action="next"]').isDisabled(), true);
        });
        await scenario('erros de consulta são apresentados e perfil de leitura não recebe controles de exportação', async () => {
            failQuery = true;
            await page.locator('[data-report-action="query"]').click();
            await page.locator('[data-report-feedback].alert-danger').waitFor();
            assert.ok((await page.locator('[data-report-feedback]').textContent()).includes('não autorizou'));
            assert.equal(await page.locator('[data-report-results]').isHidden(), true);
            failQuery = false;
            await page.goto(origin + '/_fixture/reports?view-only', {waitUntil: 'load'});
            assert.equal(await page.locator('[data-report-action="export"]').count(), 0);
            assert.equal(await page.locator('[data-report-action="print"]').count(), 0);
            assert.equal(await page.locator('[data-report-action="query"]').isEnabled(), true);
            await page.locator('[data-report-kind]').selectOption('balance');
            assert.equal(await page.locator('[data-report-filter]').count(), 0);
            assert.equal(await page.locator('[data-report-per-page]').isDisabled(), true);
            await page.locator('[data-report-action="query"]').click();
            await page.locator('[data-report-results]').waitFor({state: 'visible'});
            assert.ok((await page.locator('[data-report-rows]').textContent()).includes('123,45'));
        });
        assert.deepEqual(errors, [], 'Nenhum erro de JavaScript é esperado.');
        assert.deepEqual(unexpected, [], 'Nenhuma requisição pode sair da fixture.');
        console.log(scenarios + ' cenários de navegador concluídos; nenhuma API real foi acessada.');
    } finally { await context.close(); await browser.close(); clearTimeout(watchdog); }
}
main().catch(error => { console.error(current + ': ' + error.stack); process.exitCode = 1; clearTimeout(watchdog); });
