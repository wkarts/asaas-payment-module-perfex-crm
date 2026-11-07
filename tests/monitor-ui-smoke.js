'use strict';

// Execute the shipped view and handler with a small DOM/event fixture, without dependencies.
// Requests remain inside this fixture; no CRM credentials, Asaas calls or real receipts are used.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/asaas-monitor.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../views/settings/monitor.php'), 'utf8');
let assertions = 0;
let scenarios = 0;
let forbiddenAccesses = 0;
function check(condition, message) { assertions++; assert.ok(condition, message); }
function forbidden() { forbiddenAccesses++; throw new Error('Unexpected HTML, storage, logging or side-channel access.'); }

class Element {
    constructor(tag, document) {
        this.tagName = tag.toUpperCase(); this.ownerDocument = document; this.parentNode = null;
        this.children = []; this.attributes = {}; this.listeners = {}; this.style = {};
        this._text = ''; this.className = ''; this.type = ''; this.name = ''; this.value = '';
        this.hidden = false; this.disabled = false; this.checked = false; this.indeterminate = false;
        this.classList = {toggle: (name, enabled) => {
            const classes = new Set(this.className.split(/\s+/).filter(Boolean));
            if (enabled) { classes.add(name); } else { classes.delete(name); }
            this.className = [...classes].join(' ');
        }};
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (['type', 'name', 'value', 'id', 'href'].includes(name)) { this[name] = String(value); }
        if (name === 'class') { this.className = String(value); }
        if (['disabled', 'checked', 'hidden'].includes(name)) { this[name] = true; }
    }
    getAttribute(name) {
        if (['type', 'name', 'id', 'href'].includes(name)) { return this[name] || null; }
        if (name === 'class') { return this.className || null; }
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }
    appendChild(child) {
        if (child.parentNode) { child.parentNode.removeChild(child); }
        this.children.push(child); child.parentNode = this; return child;
    }
    removeChild(child) {
        const index = this.children.indexOf(child);
        assert.ok(index >= 0, 'Cannot remove an absent DOM node.');
        this.children.splice(index, 1); child.parentNode = null; return child;
    }
    get firstChild() { return this.children[0] || null; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this.children.forEach(child => { child.parentNode = null; }); this.children = []; this._text = String(value); }
    get innerHTML() { return forbidden(); }
    set innerHTML(value) { forbidden(value); }
    matches(selector) {
        if (selector[0] === '#') { return this.id === selector.slice(1); }
        const tag = selector.match(/^[a-z][a-z0-9-]*/i);
        if (tag && this.tagName !== tag[0].toUpperCase()) { return false; }
        const attrs = [...selector.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)];
        if (!tag && attrs.length === 0) { throw new Error('Fixture selector unsupported: ' + selector); }
        return attrs.every(match => match[2] === undefined ? this.getAttribute(match[1]) !== null : this.getAttribute(match[1]) === match[2]);
    }
    querySelectorAll(selector) {
        const selectors = selector.split(',').map(value => value.trim());
        const found = [];
        const visit = node => node.children.forEach(child => {
            if (selectors.some(part => child.matches(part))) { found.push(child); }
            visit(child);
        });
        visit(this); return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { for (let node = this; node; node = node.parentNode) { if (node.matches(selector)) { return node; } } return null; }
    addEventListener(type, handler) { (this.listeners[type] || (this.listeners[type] = [])).push(handler); }
    dispatchEvent(event) {
        if (!event.target) { event.target = this; }
        event.currentTarget = this;
        for (const handler of this.listeners[event.type] || []) { handler(event); if (event.stopped) { break; } }
        if (event.bubbles && !event.stopped && this.parentNode) { this.parentNode.dispatchEvent(event); }
        return !event.defaultPrevented;
    }
    click() { if (!this.disabled) { this.dispatchEvent(event('click', {bubbles: true})); } }
    focus() { this.ownerDocument.activeElement = this; }
}

function event(type, fields = {}) {
    return {type, ...fields, defaultPrevented: false, stopped: false,
        preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
}
function parseView(parent, document) {
    const html = view.replace(/data-endpoint="[^"]*"/, 'data-endpoint="https://crm.example.test/admin/asaas/monitor/"')
        .replace(/data-invoice-base="[^"]*"/, 'data-invoice-base="https://crm.example.test/admin/invoices/list_invoices/"')
        .replace(/data-csrf-name="[^"]*"/, 'data-csrf-name="csrf_fixture"')
        .replace(/data-csrf-hash="[^"]*"/, 'data-csrf-hash="initial_hash"')
        .replace(/<\?php[\s\S]*?\?>/g, '');
    const stack = [parent];
    const voidTags = new Set(['input', 'br', 'hr', 'img', 'meta', 'link']);
    for (const token of html.match(/<[^>]*>|[^<]+/g) || []) {
        if (token.startsWith('</')) { stack.pop(); continue; }
        if (token.startsWith('<')) {
            const tag = token.match(/^<([a-z][a-z0-9-]*)/i);
            if (!tag) { continue; }
            const element = document.createElement(tag[1]);
            const attrs = token.slice(tag[0].length, -1);
            for (const match of attrs.matchAll(/([\w-]+)(?:="([^"]*)")?/g)) { element.setAttribute(match[1], match[2] || ''); }
            stack[stack.length - 1].appendChild(element);
            if (!voidTags.has(tag[1].toLowerCase())) { stack.push(element); }
        } else { stack[stack.length - 1]._text += token; }
    }
    for (const select of parent.querySelectorAll('select')) { select.value = select.children[0].value; }
}
function response(payload, status = 200) { return {ok: status >= 200 && status < 300, status, json: async () => payload}; }
function csrf(hash) { return {name: 'csrf_fixture', hash}; }
function overview(hash = 'overview_hash') {
    return {ok: true, csrf: csrf(hash), data: {eligible_open_invoices: 4, linked_open_invoices: 3, unlinked_open_invoices: 1, webhook_errors: 4, webhook_event_errors: 2, log_errors: 5,
        last_webhook_at: '2026-11-06 12:00:00', cron: {enabled: 1, days: 10, limit: 30,
            last_run: {finished_at: '2026-11-06 12:05:00', status: 'partial', processed: 3, reconciled: 2, errors: 0, remaining: 5}}}};
}
function invoice(id, extra = {}) {
    return {invoice_id: id, invoice_number: 'TEST-' + id, client_name: 'Cliente de teste', crm_status: 1,
        total: '21.45', balance: '21.45', asaas_payment_id: 'pay_fixture_' + id, asaas_status: 'PENDING', billing_type: 'PIX',
        last_sync_at: null, last_webhook_at: null, webhook_status: null, suspected_divergence: false, ...extra};
}
function payment(id, extra = {}) {
    return {payment_id: 'pay_remote_' + id, invoice_id: id, invoice_number: 'TEST-' + id, status: 'CONFIRMED', billing_type: 'CREDIT_CARD',
        value: '21.45', original_value: null, payment_date: '2026-11-06', due_date: '2026-11-05', date_created: '2026-11-01',
        installment: null, installment_number: null, binding_status: 'linked', binding_message: 'Vínculo validado.', action_eligible: true, ...extra};
}
function listing(rows = [invoice(101), invoice(102), invoice(103)], hash = 'list_hash', pagination = {}) {
    return {ok: true, data: rows, csrf: csrf(hash), pagination: {page: 1, per_page: 25, has_more: false, total: rows.length, ...pagination}};
}
function result(id, kind = 'reconciled', hash = 'result_hash') {
    return {ok: true, csrf: csrf(hash), data: [{invoice_id: id, result: kind, message: 'Resultado de teste', crm_status: 2,
        balance: '0.00', remote_status: 'CONFIRMED', remote_paid_amount: '21.45', payment_ids: ['pay_fixture_' + id], receipts_added: kind === 'reconciled' ? 1 : 0}],
        summary: {processed: 1}, remaining_ids: []};
}
function deferred() {
    let resolve; let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}
function fixture(options = {}) {
    const document = new Element('document'); document.ownerDocument = document; document.readyState = 'complete';
    document.createElement = tag => new Element(tag, document);
    document.getElementById = id => document.querySelector('#' + id);
    Object.defineProperty(document, 'cookie', {get: forbidden, set: forbidden});
    const form = document.appendChild(document.createElement('form'));
    const token = form.appendChild(document.createElement('input')); token.type = 'hidden'; token.name = 'csrf_fixture'; token.value = 'native_hash';
    const api = form.appendChild(document.createElement('input')); api.type = 'password'; api.name = 'settings[paymentmethod_asaas_api_key]'; api.value = 'private_fixture';
    const hook = form.appendChild(document.createElement('input')); hook.type = 'password'; hook.name = 'settings[paymentmethod_asaas_webhook_auth_token]'; hook.value = 'webhook_fixture';
    if (options.present !== false) { parseView(form, document); }
    const root = document.getElementById('asaas-monitor');
    if (root && options.endpoint) { root.setAttribute('data-endpoint', options.endpoint); }
    if (root && options.canReconcile === false) { root.setAttribute('data-can-reconcile', '0'); }
    const calls = []; const queued = []; const timers = new Map(); let timerId = 0;
    const window = {location: {href: 'https://crm.example.test/admin/settings?group=payment_gateways', origin: 'https://crm.example.test'},
        fetch: async (url, settings) => {
            calls.push({url: new URL(url), settings: {...settings, headers: {...settings.headers}}});
            if (!queued.length) { throw new Error('Unexpected unqueued request.'); }
            const next = queued.shift(); return typeof next === 'function' ? next() : await next;
        },
        setTimeout: (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; },
        clearTimeout: id => { timers.delete(id); }, XMLHttpRequest: forbidden, AbortController: forbidden,
        navigator: {sendBeacon: forbidden}};
    const context = {document, window, URL, URLSearchParams, Set, Intl, CustomEvent: function (type, options) { return event(type, options); }, console: {log: forbidden, error: forbidden, warn: forbidden},
        fetch: forbidden, XMLHttpRequest: forbidden, AbortController: forbidden};
    for (const target of [window, context]) {
        for (const property of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) { Object.defineProperty(target, property, {get: forbidden}); }
    }
    vm.runInNewContext(source, context, {filename: 'asaas-monitor.js'});
    const f = {document, form, root, token, api, hook, calls, queued, timers,
        queue: (payload, status) => queued.push(response(payload, status)),
        shown: group => document.dispatchEvent(event('asaas:settings:shown', {detail: {group: group || 'monitoring'}})),
        get: selector => root.querySelector(selector),
        action: (name, kind) => root.querySelector('[data-monitor-action="' + name + '"]' + (kind ? '[data-monitor-kind="' + kind + '"]' : '')),
        tab: name => root.querySelector('[data-monitor-tab="' + name + '"]'),
        body: name => root.querySelector('[data-monitor-list="' + name + '"]')};
    f.open = async rows => { f.queue(overview()); f.queue(listing(rows)); f.shown(); await settle(); };
    f.selectAll = () => { const box = f.get('#asaas-monitor-select-all'); box.checked = true; box.dispatchEvent(event('change')); };
    return f;
}
async function settle() { for (let round = 0; round < 4; round++) { await new Promise(resolve => setImmediate(resolve)); } }
function assertSafeRequest(call, method, action) {
    check(call.settings.method === method, action + ' uses the agreed HTTP method.');
    check(call.url.href.startsWith('https://crm.example.test/admin/asaas/monitor/' + action), 'Requests stay on the local administrator endpoint.');
    check(call.settings.credentials === 'same-origin' && call.settings.cache === 'no-store' && call.settings.redirect === 'error', 'Session, cache and redirect rules remain explicit.');
    check(call.settings.headers['X-Requested-With'] === 'XMLHttpRequest', 'The framework receives its AJAX marker.');
    check(!call.settings.signal && !/private_fixture|webhook_fixture/.test(call.url.href + (call.settings.body || '')), 'No request abort or integration secret is attached.');
}
function assertSecretsPreserved(f) {
    check(f.api.value === 'private_fixture' && f.api.type === 'password', 'API credentials remain unchanged and masked.');
    check(f.hook.value === 'webhook_fixture' && f.hook.type === 'password', 'Webhook credentials remain unchanged and masked.');
}

async function main() {
    scenarios++;
    const absent = fixture({present: false}); absent.shown(); await settle();
    check(absent.calls.length === 0, 'Without the administrator monitor, no handler issues a request.');

    scenarios++;
    const lazy = fixture();
    lazy.shown('general'); await settle();
    check(lazy.calls.length === 0, 'Rendering settings or opening another tab never queries monitoring.');
    check(lazy.root.querySelectorAll('form').length === 0, 'Monitor markup cannot nest a form inside native settings.');
    check(lazy.root.querySelectorAll('input,select').every(input => !input.name), 'Read-only filters and selectors never enter native settings POST.');
    const html = '<img src=x onerror=alert(1)>';
    await lazy.open([invoice(101, {client_name: html, suspected_divergence: 1}), invoice('javascript:alert(1)')]);
    check(lazy.calls.length === 2, 'Opening monitoring loads only its local overview and invoice list.');
    assertSafeRequest(lazy.calls[0], 'GET', 'overview'); assertSafeRequest(lazy.calls[1], 'GET', 'invoices');
    check(lazy.calls[1].url.searchParams.get('status') === 'open' && lazy.calls[1].url.searchParams.get('per_page') === '25', 'Initial invoice filters follow the agreed contract.');
    check(lazy.token.value === 'list_hash' && lazy.token.name === 'csrf_fixture', 'Each JSON response renews the native settings CSRF field.');
    check(lazy.body('invoices').textContent.includes(html) && lazy.body('invoices').querySelectorAll('img').length === 0, 'API text is displayed literally, never interpreted as HTML.');
    check(lazy.body('invoices').querySelectorAll('a').length === 1 && lazy.body('invoices').querySelector('a').href.endsWith('/101'), 'Only validated positive invoice IDs produce native links.');
    check(lazy.body('invoices').querySelectorAll('[data-monitor-invoice-action]').length === 2, 'Malformed identifiers cannot create invoice actions.');
    check(lazy.get('[data-monitor-metric="webhook_errors"]').textContent === '4' && lazy.get('[data-monitor-metric="webhook_event_errors"]').textContent === '2', 'Delivery failures and event processing failures stay separate.');
    check(lazy.get('[data-monitor-metric="eligible_open_invoices"]').textContent === '4'
        && lazy.get('[data-monitor-metric="linked_open_invoices"]').textContent === '3'
        && lazy.get('[data-monitor-metric="unlinked_open_invoices"]').textContent === '1', 'The overview includes eligible legacy invoices and explains linked and missing counts.');
    check(lazy.get('[data-monitor-metric="cron_configuration"]').textContent.includes('Faturas em aberto não têm corte por idade')
        && lazy.get('[data-monitor-metric="cron_configuration"]').textContent.includes('quitadas recentes: 10 dias'), 'The cron limit and recent settled window cannot imply that older open invoices are excluded.');
    check(lazy.get('[data-monitor-metric="cron_last_run"]').textContent.includes('2 conciliadas'), 'The last cron outcome is shown without an extra Asaas call.');
    check(lazy.get('[data-monitor-metric="cron_last_run"]').textContent.includes('5 aguardando a próxima rodada'), 'A partial cron run explains its remaining workload.');
    assertSecretsPreserved(lazy);

    scenarios++;
    const legacyMetrics = fixture();
    const oldOverview = overview(); delete oldOverview.data.eligible_open_invoices; delete oldOverview.data.unlinked_open_invoices;
    legacyMetrics.queue(oldOverview); legacyMetrics.queue(listing([])); legacyMetrics.shown(); await settle();
    check(legacyMetrics.get('[data-monitor-metric="eligible_open_invoices"]').textContent === '3'
        && legacyMetrics.get('[data-monitor-metric="unlinked_open_invoices"]').textContent === '—', 'An older overview response keeps its linked count without inventing a missing count.');

    scenarios++;
    const missing = fixture();
    await missing.open([invoice(401, {asaas_payment_id: null, asaas_status: null, billing_type: null,
        binding_status: 'missing', binding_message: 'Comparar consulta a referência desta fatura.'})]);
    check(missing.body('invoices').textContent.includes('Vínculo a recuperar') && missing.body('invoices').textContent.includes('Ainda não consultada'), 'A missing local mapping is distinct from a payment that is pending at Asaas.');
    check(!missing.body('invoices').textContent.includes('Recebimento confirmado'), 'Legacy invoices without evidence cannot look paid.');
    check(missing.get('[data-monitor-invoice-id="401"]') && missing.get('[data-monitor-invoice-action="compare"]'), 'A valid legacy CRM invoice can be selected and compared to recover its mapping.');
    missing.get('#asaas-monitor-invoice-status').value = 'missing'; missing.queue(listing([invoice(401, {asaas_payment_id: null, binding_status: 'missing'})]));
    missing.action('filter', 'invoices').click(); await settle();
    check(missing.calls[2].url.searchParams.get('status') === 'missing', 'The missing-link filter uses the agreed server contract.');
    const recovered = result(401, 'divergent'); recovered.data[0].binding_recovered = true;
    recovered.data[0].message = 'Cobrança confirmada localizada; vínculo recuperado sem registrar pagamento.';
    missing.queue(recovered); missing.get('[data-monitor-invoice-action="compare"]').click(); await settle();
    check(missing.calls[3].url.pathname.endsWith('/compare') && new URLSearchParams(missing.calls[3].settings.body).get('invoice_ids[]') === '401', 'Legacy comparison uses the existing per-invoice endpoint and native CSRF.');
    check(missing.get('[data-monitor-results]').textContent.includes('vínculo recuperado') && missing.get('[data-monitor-results]').textContent.includes('0 registrado(s)'), 'The recovered binding does not claim a receipt was registered by comparison.');
    check(missing.get('[data-monitor-results]').textContent.includes('Vínculo recuperado'), 'A verified recovery flag adds an explicit result label independently of the operation message.');

    scenarios++;
    const evidence = fixture();
    await evidence.open([
        invoice(201, {asaas_status: 'PENDING', webhook_status: 'processed', last_webhook_at: '2026-11-06 08:00:00'}),
        invoice(202, {asaas_status: 'RECEIVED', crm_status: 3, balance: '10.00'}),
        invoice(203, {asaas_status: 'CONFIRMED'}), invoice(204, {asaas_status: 'RECEIVED_IN_CASH'}),
        invoice(205, {asaas_status: 'AUTHORIZED'}), invoice(206, {asaas_status: 'REFUNDED'}),
        invoice(207, {asaas_status: 'AWAITING_RISK_ANALYSIS'}), invoice(208, {asaas_status: null})
    ]);
    const evidenceRows = evidence.body('invoices').children;
    check(evidenceRows[0].textContent.includes('Sem confirmação de pagamento') && evidenceRows[0].textContent.includes('Evento: Processado'), 'A processed webhook cannot turn a pending charge into a confirmed payment.');
    check(!evidenceRows[0].querySelector('[data-monitor-payment-evidence]').className.includes('label-success'), 'Pending payment evidence never receives the confirmation styling.');
    check(evidenceRows[1].textContent.includes('Recebimento confirmado') && evidenceRows[1].textContent.includes('Parcial') && !evidenceRows[1].textContent.includes('Quitada'), 'A confirmed installment does not claim the entire invoice is settled.');
    check([1, 2, 3].every(index => evidenceRows[index].querySelector('[data-monitor-payment-evidence]').className.includes('label-success')), 'Only the existing eligible received states are highlighted as confirmed.');
    check([4, 5, 6, 7].every(index => !evidenceRows[index].querySelector('[data-monitor-payment-evidence]').className.includes('label-success')), 'Authorization, refund, risk analysis and missing status are never shown as confirmed receipts.');
    check(evidenceRows[4].textContent.includes('Somente autorizado') && evidenceRows[7].textContent.includes('Situação a conferir'), 'Authorization and absent evidence retain explicit, distinct meanings.');
    check(evidence.calls.length === 2 && evidence.calls.every(call => call.settings.method === 'GET'), 'Payment evidence uses the existing local snapshot without adding a remote call or receipt request.');

    scenarios++;
    const unpaid = fixture(); await unpaid.open([invoice(301)]);
    const unpaidResult = result(301, 'pending');
    Object.assign(unpaidResult.data[0], {crm_status: 1, remote_status: 'PENDING', balance: '21.45', remote_paid_amount: '0.00', payment_ids: [], message: 'O Asaas ainda não confirmou um recebimento elegível para esta fatura.'});
    unpaid.queue(unpaidResult); unpaid.get('[data-monitor-invoice-action="compare"]').click(); await settle();
    check(unpaid.get('[data-monitor-results]').textContent.includes('ainda não confirmou') && unpaid.get('[data-monitor-results]').textContent.includes('0 registrado(s)'), 'A fresh pending comparison explains that there is no confirmed payment and no receipt was added.');
    check(unpaid.calls.length === 3 && unpaid.calls[2].url.pathname.endsWith('/compare'), 'Checking payment does not silently trigger reconciliation.');

    scenarios++;
    const logsCoordination = fixture();
    logsCoordination.form.setAttribute('data-asaas-logs-loading', '1');
    logsCoordination.queue(overview()); logsCoordination.queue(listing([invoice(302)]));
    logsCoordination.shown(); await settle();
    check(logsCoordination.calls.length === 0 && logsCoordination.action('refresh').disabled, 'A pending Logs GET defers monitor reads instead of racing their CSRF cookies.');
    logsCoordination.form.setAttribute('data-asaas-logs-loading', '0');
    logsCoordination.document.dispatchEvent(event('asaas:logs:idle')); await settle();
    check(logsCoordination.calls.length === 2 && !logsCoordination.action('refresh').disabled, 'Deferred monitor reads resume only after Logs finishes.');
    logsCoordination.form.setAttribute('data-asaas-logs-loading', '1');
    logsCoordination.document.dispatchEvent(event('asaas:logs:busy'));
    logsCoordination.get('[data-monitor-invoice-action="compare"]').dispatchEvent(event('click'));
    check(logsCoordination.calls.length === 2, 'Neither a regular nor a synthetic action can start POST while Logs GET is active.');
    logsCoordination.form.setAttribute('data-asaas-logs-loading', '0');
    logsCoordination.form.setAttribute('data-asaas-logs-csrf-uncertain', '1');
    logsCoordination.document.dispatchEvent(event('asaas:logs:idle'));
    check(logsCoordination.get('[data-monitor-invoice-action="compare"]').disabled && !logsCoordination.action('refresh').disabled, 'An unknown CSRF response blocks POST but leaves the read-only recovery action available.');
    logsCoordination.queue(overview('recovered_overview')); logsCoordination.queue(listing([invoice(302)], 'recovered_hash'));
    logsCoordination.action('refresh').click(); await settle();
    check(logsCoordination.form.getAttribute('data-asaas-logs-csrf-uncertain') === '0' && logsCoordination.token.value === 'recovered_hash', 'A valid monitor GET repairs the form token after an uncertain Logs read.');
    const coordinatedPost = deferred(); logsCoordination.queued.push(coordinatedPost.promise);
    logsCoordination.get('[data-monitor-invoice-action="compare"]').click(); await settle();
    check(logsCoordination.form.getAttribute('data-asaas-monitor-busy') === '1' && new URLSearchParams(logsCoordination.calls[4].settings.body).get('csrf_fixture') === 'recovered_hash', 'Monitor publishes its busy state before POST and uses the token renewed by the preceding read.');
    coordinatedPost.resolve(response(result(302, 'pending'))); await settle();
    check(logsCoordination.form.getAttribute('data-asaas-monitor-busy') === '0', 'The busy marker is released after the entire operation settles.');

    scenarios++;
    const filters = fixture(); await filters.open();
    const selected = filters.get('[data-monitor-invoice-id="101"]'); selected.checked = true; selected.dispatchEvent(event('change'));
    check(filters.get('#asaas-monitor-select-all').indeterminate && !filters.get('#asaas-monitor-select-all').checked, 'Selecting an individual row exposes the partial page selection.');
    check(filters.get('[data-monitor-selected-count]').textContent.includes('1 fatura selecionada'), 'The selected count reflects actual user selection.');
    filters.selectAll();
    check(!filters.action('compare-selected').disabled && filters.get('[data-monitor-selected-count]').textContent.includes('3 faturas'), 'Selecting a page enables its batch actions.');
    filters.get('#asaas-monitor-query').value = 'FAT & cliente ? teste'; filters.get('#asaas-monitor-invoice-status').value = 'suspected';
    filters.queue(listing([invoice(104)], 'filtered_hash', {has_more: true, total: 23})); filters.action('filter', 'invoices').click(); await settle();
    const filtered = filters.calls[2];
    check(filtered.url.searchParams.get('q') === 'FAT & cliente ? teste' && filtered.url.searchParams.get('status') === 'suspected', 'Search terms and filters are encoded as independent query parameters.');
    check(filters.action('compare-selected').disabled && !filters.get('#asaas-monitor-select-all').checked, 'Changing a page clears stale invoice selection.');
    check(!filters.action('next', 'invoices').disabled && filters.action('previous', 'invoices').disabled, 'Pagination follows the server has_more indicator.');
    filters.queue(listing([], 'page_hash', {page: 2, total: 23})); filters.action('next', 'invoices').click(); await settle();
    check(filters.calls[3].url.searchParams.get('page') === '2' && filters.body('invoices').textContent.includes('Nenhuma fatura'), 'Pagination requests the next page and renders the empty state.');
    check(filters.action('next', 'invoices').disabled && !filters.action('previous', 'invoices').disabled, 'Boundary pagination remains usable after an empty page.');

    scenarios++;
    const batch = fixture(); await batch.open(); batch.selectAll(); batch.token.value = 'changed_by_native_form';
    const first = deferred(); const second = deferred(); batch.queued.push(first.promise, second.promise); batch.queue(result(103, 'already_reconciled', 'third_hash'));
    batch.action('reconcile-selected').click(); await settle();
    check(batch.calls.length === 3, 'Only the first invoice starts before its response returns.');
    assertSafeRequest(batch.calls[2], 'POST', 'reconcile');
    let body = new URLSearchParams(batch.calls[2].settings.body);
    check(body.getAll('invoice_ids[]').join() === '101' && body.get('trigger') === 'batch', 'Batch POST carries exactly one ID while retaining batch audit origin.');
    check(body.get('csrf_fixture') === 'changed_by_native_form' && [...body.keys()].length === 3, 'POST uses the current native CSRF and only the agreed request fields.');
    check(batch.action('reconcile-selected').disabled && batch.get('#asaas-monitor-query').disabled && !batch.action('stop').disabled, 'Controls lock while a receipt request is in flight, leaving Stop available.');
    batch.action('reconcile-selected').dispatchEvent(event('click')); check(batch.calls.length === 3, 'Repeated events cannot start a concurrent batch.');
    const submitBusy = event('submit'); batch.form.dispatchEvent(submitBusy);
    check(submitBusy.defaultPrevented && submitBusy.stopped, 'Native settings save waits while POST can rotate the CSRF token.');
    first.resolve(response(result(101, 'reconciled', 'first_hash'))); await settle();
    check(batch.calls.length === 4, 'The second invoice begins only after the first confirms its result.');
    body = new URLSearchParams(batch.calls[3].settings.body);
    check(body.get('invoice_ids[]') === '102' && body.get('csrf_fixture') === 'first_hash', 'The next operation receives the renewed CSRF hash.');
    second.resolve(response(result(102, 'reconciled', 'second_hash'))); await settle();
    check(batch.calls.length === 5 && new URLSearchParams(batch.calls[4].settings.body).get('csrf_fixture') === 'second_hash', 'Sequential renewal also protects later operations.');
    check(batch.get('[data-monitor-results]').children.length === 3 && batch.get('[data-monitor-progress]').getAttribute('aria-valuenow') === '100', 'Each result is retained and accessible progress reaches completion.');
    check(batch.token.value === 'third_hash' && batch.action('stop').disabled && !batch.action('refresh').disabled, 'Completion restores controls and leaves settings ready to save.');
    const submitIdle = event('submit'); batch.form.dispatchEvent(submitIdle); check(!submitIdle.defaultPrevented, 'Monitoring does not intercept normal settings save while idle.');
    assertSecretsPreserved(batch);

    scenarios++;
    const stop = fixture(); await stop.open(); stop.selectAll(); const pending = deferred(); stop.queued.push(pending.promise);
    stop.action('reconcile-selected').click(); await settle();
    check(stop.timers.size === 1 && [...stop.timers.values()][0].delay === 60000, 'Slow operations get a notice without a destructive timeout.');
    [...stop.timers.values()][0].callback();
    check(stop.get('[data-monitor-task-message]').textContent.includes('Aguardando') && stop.calls.length === 3, 'A slow notice does not cancel or repeat a receipt request.');
    stop.action('stop').click();
    check(stop.action('stop').disabled && stop.get('[data-monitor-task-message]').textContent.includes('continuará até retornar'), 'Stop waits for the outstanding operation to settle.');
    pending.resolve(response(result(101))); await settle();
    check(stop.calls.length === 3 && stop.get('[data-monitor-results]').children.length === 1, 'Stop leaves later invoices untouched and records the current outcome.');
    check(stop.get('[data-monitor-task-message]').textContent.includes('1 de 3') && stop.timers.size === 0, 'A stopped batch reports its completed subset and clears the notice timer.');

    const failures = [
        ['network rejection', () => { throw new Error('Network response unavailable'); }],
        ['non-JSON response', {ok: true, status: 200, json: async () => { throw new Error('<html>private_fixture</html>'); }}],
        ['missing CSRF', response({...result(101), csrf: undefined})],
        ['invalid CSRF name', response({...result(101), csrf: {name: 'settings[paymentmethod_asaas_api_key]', hash: 'replacement'}})],
        ['different invoice', response(result(999))],
        ['unknown result', response(result(101, 'new_unrecognized_action'))],
        ['missing result', response({...result(101), data: []})],
        ['missing receipt count', response({...result(101), data: [{...result(101).data[0], receipts_added: undefined}]})],
        ['invalid receipt count', response({...result(101), data: [{...result(101).data[0], receipts_added: true}]})],
        ['invalid receipt verification flag', response({...result(101), data: [{...result(101).data[0], receipt_verification_pending: 'yes'}]})],
        ['global HTTP error', response({ok: false, csrf: csrf('failure_hash'), message: 'private_fixture'}, 503)],
        ['per-invoice error', response(result(101, 'error'))],
        ['partial reconciliation', response({...result(101, 'partial_reconciled'), data: [{...result(101).data[0], result: 'partial_reconciled'}]})]
    ];
    for (const [name, failure] of failures) {
        scenarios++;
        const f = fixture(); await f.open(); f.selectAll(); f.queued.push(failure); f.action('reconcile-selected').click(); await settle();
        check(f.calls.length === 3, name + ': do not retry or start a later invoice.');
        check(f.get('[data-monitor-results]').children.length === 1 && f.get('[data-monitor-task-message]').textContent.includes('2 fatura(s) não foram iniciadas'), name + ': retain one outcome and explain the remaining work.');
        check(!f.action('refresh').disabled && f.action('stop').disabled, name + ': allow an explicit record recheck.');
        check(!f.root.textContent.includes('private_fixture') && !f.root.textContent.includes('<html>'), name + ': never reveal raw failed response bodies.');
        if (!['per-invoice error', 'partial reconciliation'].includes(name)) {
            check(f.get('[data-monitor-results]').textContent.includes('Não confirmado') && !f.get('[data-monitor-results]').textContent.includes('0 registrado(s)'), name + ': unknown receipt outcomes cannot be shown as zero receipts.');
            check(f.get('[data-monitor-task-message]').textContent.includes('não será repetida automaticamente'), name + ': show the no-retry guidance.');
        }
        if (name === 'partial reconciliation') { check(f.get('[data-monitor-results]').textContent.includes('1 registrado(s)'), 'A confirmed partial result retains its actual receipt count.'); }
        if (name === 'global HTTP error') { check(f.token.value === 'failure_hash', 'Even a valid error response renews the form token.'); }
        assertSecretsPreserved(f);
    }

    for (const confirmed of [0, 1]) {
        scenarios++;
        const uncertain = fixture(); await uncertain.open(); uncertain.selectAll();
        const payload = result(101, confirmed ? 'partial_reconciled' : 'error');
        payload.data[0].receipts_added = confirmed;
        payload.data[0].receipt_verification_pending = true;
        payload.data[0].message = 'A gravação foi iniciada, mas a confirmação do recibo precisa ser consultada.';
        uncertain.queue(payload); uncertain.action('reconcile-selected').click(); await settle();
        const outcome = uncertain.get('[data-monitor-results]').textContent;
        check(uncertain.calls.length === 3 && uncertain.get('[data-monitor-task-message]').textContent.includes('2 fatura(s) não foram iniciadas'), 'Unverified persistence must stop the batch without retrying.');
        check(outcome.includes('Não confirmado') && !outcome.includes('0 registrado(s)'), 'An unverified receipt cannot be described as no receipt, even in a valid JSON response.');
        check(confirmed ? outcome.includes('1 recebimento já verificado') : !outcome.includes('recebimento já verificado'), 'A partial uncertain response retains only the receipts already verified.');
        check(!uncertain.action('refresh').disabled && uncertain.action('stop').disabled, 'Record recheck remains available after an uncertain write.');
    }

    scenarios++;
    const audit = fixture(); await audit.open();
    audit.queue(listing([{level: 'warning', message: html, created_at: '2026-11-06', correlation_id: 'fixture-id', context: {reason: '<script>alert(1)</script>'}}], 'logs_hash'));
    audit.tab('logs').click(); await settle();
    assertSafeRequest(audit.calls[2], 'GET', 'logs');
    check(audit.body('logs').textContent.includes('<script>alert(1)</script>') && audit.body('logs').querySelectorAll('script').length === 0, 'Diagnostic context is rendered as inert text.');
    check(audit.get('[data-monitor-panel="invoices"]').hidden && !audit.get('[data-monitor-panel="logs"]').hidden, 'Internal tabs show only their corresponding panel.');
    audit.get('#asaas-monitor-log-source').value = 'webhook'; audit.get('#asaas-monitor-log-level').value = 'warning';
    audit.queue(listing([], 'logs_filter_hash')); audit.action('filter', 'logs').click(); await settle();
    check(audit.calls[3].url.searchParams.get('source') === 'webhook' && audit.calls[3].url.searchParams.get('level') === 'warning', 'Delivery failures can be filtered separately from persisted events.');
    audit.queue(listing([{event_id: 'evt_fixture', event_type: 'PAYMENT_CONFIRMED', payment_id: 'pay_fixture', invoice_id: 101,
        received_at: '2026-11-06', processed_at: null, process_status: 'received', error_message: html}], 'events_hash'));
    audit.tab('events').click(); await settle();
    assertSafeRequest(audit.calls[4], 'GET', 'events');
    check(audit.body('events').textContent.includes('Recebido / pendente') && audit.body('events').textContent.includes(html), 'Webhook rendering uses process_status and literal error text.');
    audit.get('#asaas-monitor-event-status').value = 'received'; audit.queue(listing([], 'events_filter_hash')); audit.action('filter', 'events').click(); await settle();
    check(audit.calls[5].url.searchParams.get('status') === 'received', 'Pending/retry events use the agreed received filter.');
    check(audit.calls.length === 6 && audit.calls.every(call => call.settings.method === 'GET'), 'Browsing logs and events performs no financial mutation.');

    scenarios++;
    const errors = fixture(); await errors.open();
    errors.get('#asaas-monitor-log-level').value = 'warning'; errors.get('#asaas-monitor-log-source').value = 'api';
    errors.queue(listing([{level: 'error', message: 'Falha de consulta', created_at: '2026-11-06', correlation_id: 'fixture-error',
        context: {http_status: 400, error_code: 'invalid_value', details: html, long_detail: 'x'.repeat(4500)}}], 'error_detail_hash'));
    errors.get('[data-monitor-action="show-errors"][data-monitor-source="all"]').click(); await settle();
    check(errors.calls[2].url.searchParams.get('level') === 'error' && errors.calls[2].url.searchParams.get('source') === 'all'
        && errors.calls[2].url.searchParams.get('page') === '1', 'Ver erros opens the first diagnostic page with all error sources.');
    check(!errors.get('[data-monitor-panel="logs"]').hidden && errors.tab('logs').getAttribute('aria-selected') === 'true', 'The diagnostic shortcut activates the corresponding accessible tab.');
    check(errors.body('logs').textContent.includes('HTTP 400') && errors.body('logs').textContent.includes('Código: invalid_value'), 'HTTP status and API code are readable without opening JSON.');
    check(errors.body('logs').querySelector('pre').textContent.includes('x'.repeat(4500))
        && errors.body('logs').querySelectorAll('img').length === 0, 'Diagnostic details are preserved beyond the generic cell limit and remain inert text.');
    errors.queue(listing([], 'webhook_error_hash'));
    errors.get('[data-monitor-action="show-errors"][data-monitor-source="webhook"]').click(); await settle();
    check(errors.calls[3].url.searchParams.get('level') === 'error' && errors.calls[3].url.searchParams.get('source') === 'webhook', 'The webhook error shortcut filters delivery diagnostics separately.');
    check(errors.calls.every(call => call.settings.method === 'GET'), 'Opening an error count does not modify a payment.');

    scenarios++;
    const remote = fixture(); await remote.open();
    const remoteRows = [
        payment(101, {payment_id: html, installment: 'ins_fixture', installment_number: 1}),
        payment(101, {payment_id: 'pay_remote_101_part_2', status: 'PENDING', installment: 'ins_fixture', installment_number: 2}),
        payment(104, {binding_status: 'recoverable', binding_message: 'Vínculo seguro a recuperar.', status: 'RECEIVED'}),
        payment(null, {binding_status: 'unmatched', action_eligible: false, invoice_number: null}),
        payment(105, {binding_status: 'ambiguous', action_eligible: false}),
        payment(106, {binding_status: 'blocked', action_eligible: false, binding_message: 'Consulta incompleta.', error_code: 'provider_unavailable', http_status: 429}),
        payment(107, {binding_status: 'recoverable', action_eligible: undefined}),
        payment('javascript:alert(1)')
    ];
    remote.queue({...listing(remoteRows, 'remote_list_hash', {has_more: true, total: 40}),
        message: 'Consulta parcial: parte dos vínculos exige nova conferência por limite de tempo. ' + html});
    remote.tab('payments').click(); await settle();
    check(remote.calls.length === 3, 'The remote catalog is fetched only after its tab is opened.');
    assertSafeRequest(remote.calls[2], 'GET', 'payments');
    check(remote.calls[2].url.searchParams.get('status') === 'all' && remote.calls[2].url.searchParams.get('date_field') === 'payment'
        && !remote.calls[2].url.searchParams.has('date_from') && !remote.calls[2].url.searchParams.has('date_to'), 'An unfiltered catalog does not silently restrict history by date.');
    check(remote.body('payments').textContent.includes('Recebimento confirmado') && remote.body('payments').textContent.includes('Sem confirmação de pagamento')
        && remote.body('payments').textContent.includes('Parcelamento: ins_fixture'), 'Remote payment evidence and installment membership remain explicit.');
    check(remote.body('payments').querySelectorAll('[data-monitor-payment-invoice-id]').length === 3
        && remote.body('payments').querySelectorAll('[data-monitor-invoice-action]').length === 6, 'Only positively eligible linked/recoverable rows expose batch selection and financial actions.');
    check(remote.body('payments').querySelectorAll('img').length === 0 && remote.body('payments').textContent.includes(html), 'Remote fields cannot create HTML or arbitrary destinations.');
    check(remote.get('[data-monitor-feedback]').textContent.includes('limite de tempo')
        && remote.get('[data-monitor-feedback]').textContent.includes(html) && remote.get('[data-monitor-feedback]').querySelectorAll('img').length === 0,
        'A partial lookup notice remains visible as inert text without discarding the returned rows.');
    check(remote.body('payments').textContent.includes('Consulta incompleta.') && remote.body('payments').textContent.includes('HTTP 429')
        && remote.body('payments').textContent.includes('Código: provider_unavailable'), 'A blocked remote row exposes its safe code/status beside the binding reason.');
    const paymentAll = remote.get('#asaas-monitor-payment-select-all'); paymentAll.checked = true; paymentAll.dispatchEvent(event('change'));
    check(remote.get('[data-monitor-payment-selected-count]').textContent.includes('2 faturas')
        && remote.body('payments').querySelectorAll('[data-monitor-payment-invoice-id]').every(box => box.checked), 'Selecting multiple installments deduplicates the selected CRM invoice while keeping its rows synchronized.');
    check(!remote.action('reconcile-selected', 'payments').disabled && remote.action('reconcile-selected').disabled, 'The remote selection never changes the separate CRM invoice selection.');
    remote.queue(result(101, 'reconciled', 'remote_first_hash')); remote.queue(result(104, 'reconciled', 'remote_second_hash'));
    remote.action('reconcile-selected', 'payments').click(); await settle();
    check(remote.calls.length === 5 && new URLSearchParams(remote.calls[3].settings.body).get('invoice_ids[]') === '101'
        && new URLSearchParams(remote.calls[4].settings.body).get('invoice_ids[]') === '104', 'Remote batches use the existing per-invoice reconciliation once per invoice, never a payment ID.');
    check(new URLSearchParams(remote.calls[3].settings.body).get('csrf_fixture') === 'remote_list_hash'
        && new URLSearchParams(remote.calls[4].settings.body).get('csrf_fixture') === 'remote_first_hash', 'Remote actions retain sequential native CSRF rotation.');
    assertSecretsPreserved(remote);

    scenarios++;
    const remoteFilters = fixture(); await remoteFilters.open(); remoteFilters.queue(listing([payment(501)], 'remote_filter_initial'));
    remoteFilters.tab('payments').click(); await settle();
    const selectedPayment = remoteFilters.get('[data-monitor-payment-invoice-id="501"]'); selectedPayment.checked = true; selectedPayment.dispatchEvent(event('change'));
    remoteFilters.get('#asaas-monitor-payment-status').value = 'CONFIRMED'; remoteFilters.get('#asaas-monitor-payment-date-field').value = 'due';
    remoteFilters.get('#asaas-monitor-payment-date-from').value = '2026-01-01'; remoteFilters.get('#asaas-monitor-payment-date-to').value = '2026-11-06';
    remoteFilters.get('#asaas-monitor-payment-per-page').value = '50';
    remoteFilters.queue(listing([], 'remote_filtered_hash', {has_more: true, per_page: 50, total: 60})); remoteFilters.action('filter', 'payments').click(); await settle();
    const remoteQuery = remoteFilters.calls[3].url.searchParams;
    check(remoteQuery.get('status') === 'CONFIRMED' && remoteQuery.get('date_field') === 'due'
        && remoteQuery.get('date_from') === '2026-01-01' && remoteQuery.get('date_to') === '2026-11-06'
        && remoteQuery.get('per_page') === '50' && remoteQuery.get('page') === '1', 'Remote date, status and size filters use the agreed API parameters.');
    check(remoteFilters.action('reconcile-selected', 'payments').disabled && remoteFilters.body('payments').textContent.includes('Nenhuma cobrança'), 'Filtering clears remote selections and handles an empty catalog.');
    remoteFilters.queue(listing([payment(502)], 'remote_page_hash', {page: 2, per_page: 50, total: 60})); remoteFilters.action('next', 'payments').click(); await settle();
    check(remoteFilters.calls[4].url.searchParams.get('page') === '2' && !remoteFilters.action('previous', 'payments').disabled
        && remoteFilters.action('next', 'payments').disabled, 'Remote paging follows server boundaries independently from CRM invoice pages.');
    remoteFilters.get('#asaas-monitor-payment-date-from').value = '2026-11-07';
    remoteFilters.action('filter', 'payments').click(); await settle();
    check(remoteFilters.calls.length === 5 && remoteFilters.get('[data-monitor-feedback]').textContent.includes('data inicial'), 'An inverted date interval is explained before any remote request.');
    remoteFilters.get('#asaas-monitor-payment-date-from').value = '2026-02-30';
    remoteFilters.action('filter', 'payments').click(); await settle();
    check(remoteFilters.calls.length === 5 && remoteFilters.get('[data-monitor-feedback]').textContent.includes('data válida'), 'An invalid calendar date cannot reach the provider.');
    remoteFilters.get('#asaas-monitor-payment-date-from').value = '';
    remoteFilters.get('#asaas-monitor-payment-date-from').validity = {badInput: true};
    remoteFilters.action('filter', 'payments').click(); await settle();
    check(remoteFilters.calls.length === 5 && remoteFilters.get('[data-monitor-feedback]').textContent.includes('data válida'), 'A partially typed native date is not treated as an intentionally empty filter.');

    scenarios++;
    const remoteFailure = fixture(); await remoteFailure.open();
    remoteFailure.queue({ok: false, csrf: csrf('remote_error_hash'), message: 'private_fixture', error: {
        code: 'provider_unavailable', http_status: 429, message: 'A consulta no Asaas está temporariamente indisponível.'}}, 503);
    remoteFailure.tab('payments').click(); await settle();
    check(remoteFailure.calls.length === 3 && remoteFailure.get('[data-monitor-feedback]').textContent.includes('HTTP 429')
        && remoteFailure.get('[data-monitor-feedback]').textContent.includes('provider_unavailable'), 'A catalog failure surfaces the explicit safe error code/status without retrying.');
    check(!remoteFailure.root.textContent.includes('private_fixture') && remoteFailure.token.value === 'remote_error_hash'
        && !remoteFailure.action('refresh').disabled, 'Failed remote reads preserve the renewed native token and expose a manual recovery path.');
    remoteFailure.queue(overview()); remoteFailure.queue(listing([payment(503)], 'remote_recovered_hash')); remoteFailure.action('refresh').click(); await settle();
    check(remoteFailure.body('payments').textContent.includes('pay_remote_503') && remoteFailure.get('[data-monitor-feedback]').hidden,
        'An explicit refresh recovers the remote catalog without creating a receipt.');
    remoteFailure.queue({ok: false, csrf: csrf('overview_error_hash'), error: {code: 'overview_unavailable', message: 'Resumo temporariamente indisponível.'}}, 503);
    remoteFailure.queue({...listing([payment(503)], 'partial_catalog_hash'), message: 'Consulta parcial: confira os vínculos restantes.'});
    remoteFailure.action('refresh').click(); await settle();
    check(remoteFailure.get('[data-monitor-feedback]').textContent.includes('Resumo temporariamente indisponível')
        && remoteFailure.get('[data-monitor-feedback]').textContent.includes('Consulta parcial')
        && remoteFailure.get('[data-monitor-feedback]').className.includes('alert-danger'), 'A partial catalog notice cannot hide an overview error from the same refresh.');

    scenarios++;
    const remoteLogs = fixture(); await remoteLogs.open(); remoteLogs.queue(listing([payment(504)])); remoteLogs.tab('payments').click(); await settle();
    remoteLogs.form.setAttribute('data-asaas-logs-loading', '1'); remoteLogs.document.dispatchEvent(event('asaas:logs:busy'));
    remoteLogs.body('payments').querySelector('[data-monitor-invoice-action="reconcile"]').dispatchEvent(event('click'));
    check(remoteLogs.calls.length === 3 && remoteLogs.body('payments').querySelector('[data-monitor-invoice-action="reconcile"]').disabled,
        'Remote financial actions cannot race an in-flight Logs response, including synthetic clicks.');
    remoteLogs.form.setAttribute('data-asaas-logs-loading', '0'); remoteLogs.document.dispatchEvent(event('asaas:logs:idle'));
    const failedAction = result(504, 'error'); Object.assign(failedAction.data[0], {http_status: 400, error_code: 'ambiguous_binding', details: html});
    remoteLogs.queue(failedAction); remoteLogs.body('payments').querySelector('[data-monitor-invoice-action="reconcile"]').click(); await settle();
    check(remoteLogs.get('[data-monitor-results]').textContent.includes('HTTP 400')
        && remoteLogs.get('[data-monitor-results]').textContent.includes('ambiguous_binding')
        && remoteLogs.get('[data-monitor-results]').textContent.includes(html)
        && remoteLogs.get('[data-monitor-results]').querySelectorAll('img').length === 0, 'An individual recovery error preserves safe provider diagnostics as inert text.');

    scenarios++;
    const single = fixture(); await single.open([invoice(101)]); single.queue(result(101, 'divergent'));
    single.get('[data-monitor-invoice-action="compare"]').click(); await settle();
    assertSafeRequest(single.calls[2], 'POST', 'compare');
    const singleBody = new URLSearchParams(single.calls[2].settings.body);
    check(singleBody.get('trigger') === 'manual' && singleBody.getAll('invoice_ids[]').join() === '101', 'Individual comparison keeps the manual audit origin and validated ID.');
    check(single.get('[data-monitor-results]').textContent.includes('Divergência identificada'), 'Comparison displays its remote result without calling reconciliation.');

    scenarios++;
    const broken = fixture(); broken.queue(overview()); broken.queue({...listing(), data: [null]}); broken.shown(); await settle();
    check(broken.body('invoices').textContent.includes('Não foi possível carregar') && broken.action('compare-selected').disabled, 'Malformed list responses leave a clear error and no stale selection.');
    broken.queue(overview()); broken.queue(listing([invoice(105)])); broken.action('refresh').click(); await settle();
    check(broken.body('invoices').textContent.includes('TEST-105') && broken.get('[data-monitor-feedback]').hidden, 'An explicit refresh recovers from a read failure.');

    scenarios++;
    const readOnly = fixture({canReconcile: false}); await readOnly.open();
    check(readOnly.action('reconcile-selected').hidden && readOnly.action('reconcile-selected', 'payments').hidden, 'A view-only staff member does not see receipt batch actions.');
    check(!readOnly.get('[data-monitor-invoice-action="reconcile"]') && readOnly.get('[data-monitor-invoice-action="compare"]'), 'Read-only rows expose comparison without a receipt action.');
    readOnly.selectAll();
    check(!readOnly.action('compare-selected').disabled && readOnly.action('reconcile-selected').disabled, 'Selections never enable reconciliation for a read-only role.');

    scenarios++;
    const lookup = fixture(); await lookup.open(); lookup.queue(listing([])); lookup.tab('payments').click(); await settle();
    lookup.get('#asaas-monitor-payment-query').value = 'FAT-000387/11/2026';
    lookup.queue({ok: true, data: [{id: 399, label: '<img src=x> FAT-000387/11/2026'}], has_more: false, csrf: csrf('target_csrf')});
    lookup.action('find-target').click(); await settle();
    assertSafeRequest(lookup.calls[3], 'GET', 'targets');
    check(lookup.calls[3].url.searchParams.get('q') === 'FAT-000387/11/2026'
        && lookup.calls[3].url.searchParams.get('search_kind') === 'invoice', 'The full native invoice number reaches the server lookup.');
    check(lookup.get('#asaas-monitor-target').value === '399' && lookup.root.querySelectorAll('img').length === 0, 'A unique match is selected and its label remains escaped text.');
    lookup.queue(listing([], 'filtered_csrf', {has_more: true, total: 30})); lookup.action('filter', 'payments').click(); await settle();
    check(lookup.calls[4].url.searchParams.get('target_id') === '399'
        && lookup.calls[4].url.searchParams.get('search_kind') === 'invoice', 'Provider browsing uses the explicit selected identity.');
    lookup.queue(listing([], 'next_csrf', {page: 2, has_more: false, total: 30})); lookup.action('next', 'payments').click(); await settle();
    check(lookup.calls[5].url.searchParams.get('target_id') === '399' && lookup.calls[5].url.searchParams.get('page') === '2', 'Search selection is preserved on subsequent provider pages.');
    lookup.get('#asaas-monitor-payment-query').value = 'Outro cliente'; lookup.get('#asaas-monitor-payment-query').dispatchEvent(event('input'));
    lookup.action('filter', 'payments').click(); await settle();
    check(lookup.calls.length === 6 && lookup.get('[data-monitor-feedback]').textContent.includes('Localizar'), 'Changing search text cannot reuse the previous customer or invoice silently.');

    scenarios++;
    const foreign = fixture({endpoint: 'https://external.example.test/monitor/'}); foreign.shown(); await settle();
    check(foreign.calls.length === 0 && !foreign.get('[data-monitor-feedback]').hidden, 'A misconfigured external destination cannot receive the administrator session or CSRF.');
    check(forbiddenAccesses === 0, 'Monitoring never accesses HTML sinks, cookies, storage, extra transports or console logs.');
    console.log('Monitor UI: ' + scenarios + ' scenarios, ' + assertions + ' assertions, 0 failures.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
