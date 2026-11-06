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
    return {ok: true, csrf: csrf(hash), data: {linked_open_invoices: 3, webhook_errors: 4, webhook_event_errors: 2, log_errors: 5,
        last_webhook_at: '2026-11-06 12:00:00', cron: {enabled: 1, days: 10, limit: 30,
            last_run: {finished_at: '2026-11-06 12:05:00', status: 'partial', processed: 3, reconciled: 2, errors: 0, remaining: 5}}}};
}
function invoice(id, extra = {}) {
    return {invoice_id: id, invoice_number: 'TEST-' + id, client_name: 'Cliente de teste', crm_status: 1,
        total: '21.45', balance: '21.45', asaas_payment_id: 'pay_fixture_' + id, asaas_status: 'PENDING', billing_type: 'PIX',
        last_sync_at: null, last_webhook_at: null, webhook_status: null, suspected_divergence: false, ...extra};
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
    const context = {document, window, URL, URLSearchParams, Set, Intl, console: {log: forbidden, error: forbidden, warn: forbidden},
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
    check(lazy.get('[data-monitor-metric="cron_last_run"]').textContent.includes('2 conciliadas'), 'The last cron outcome is shown without an extra Asaas call.');
    check(lazy.get('[data-monitor-metric="cron_last_run"]').textContent.includes('5 aguardando a próxima rodada'), 'A partial cron run explains its remaining workload.');
    assertSecretsPreserved(lazy);

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
    const foreign = fixture({endpoint: 'https://external.example.test/monitor/'}); foreign.shown(); await settle();
    check(foreign.calls.length === 0 && !foreign.get('[data-monitor-feedback]').hidden, 'A misconfigured external destination cannot receive the administrator session or CSRF.');
    check(forbiddenAccesses === 0, 'Monitoring never accesses HTML sinks, cookies, storage, extra transports or console logs.');
    console.log('Monitor UI: ' + scenarios + ' scenarios, ' + assertions + ' assertions, 0 failures.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
