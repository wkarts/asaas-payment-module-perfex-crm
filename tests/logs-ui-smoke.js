'use strict';

// Execute the shipped Logs view/handler with a small DOM fixture. No real endpoint is called.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/asaas-logs.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../views/settings/logs.php'), 'utf8');
let scenarios = 0; let assertions = 0; let forbiddenAccesses = 0;
function check(condition, message) { assertions++; assert.ok(condition, message); }
function forbidden() { forbiddenAccesses++; throw new Error('Unexpected HTML, storage or logging access.'); }

class Element {
    constructor(tag, document) {
        this.tagName = tag.toUpperCase(); this.ownerDocument = document; this.parentNode = null;
        this.children = []; this.attributes = {}; this.listeners = {}; this._text = '';
        this.className = ''; this.hidden = false; this.disabled = false; this.value = ''; this.type = '';
        this.classList = {contains: name => this.className.split(/\s+/).includes(name)};
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (['id', 'type', 'name', 'href', 'value'].includes(name)) { this[name] = String(value); }
        if (name === 'class') { this.className = String(value); }
        if (['hidden', 'disabled'].includes(name)) { this[name] = true; }
    }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
    removeAttribute(name) { delete this.attributes[name]; }
    get parentElement() { return this.parentNode; }
    appendChild(child) { if (child.parentNode) { child.parentNode.removeChild(child); } this.children.push(child); child.parentNode = this; return child; }
    removeChild(child) { const index = this.children.indexOf(child); assert.ok(index >= 0); this.children.splice(index, 1); child.parentNode = null; return child; }
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
        if (!tag && attrs.length === 0) { throw new Error('Unsupported fixture selector: ' + selector); }
        return attrs.every(match => match[2] === undefined ? this.getAttribute(match[1]) !== null : this.getAttribute(match[1]) === match[2]);
    }
    querySelectorAll(selector) {
        const found = []; const visit = element => element.children.forEach(child => { if (child.matches(selector)) { found.push(child); } visit(child); });
        visit(this); return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { for (let element = this; element; element = element.parentNode) { if (element.matches(selector)) { return element; } } return null; }
    addEventListener(type, handler) { (this.listeners[type] || (this.listeners[type] = [])).push(handler); }
    dispatchEvent(event) { for (const handler of this.listeners[event.type] || []) { handler(event); if (event.stopped) { break; } } }
    click() { if (!this.disabled) { this.dispatchEvent({type: 'click'}); } }
}

function parseView(parent, document) {
    const html = view.replace(/data-endpoint="[^"]*"/, 'data-endpoint="https://crm.example.test/admin/asaas/asaas/logs_data"')
        .replace(/data-csrf-name="[^"]*"/, 'data-csrf-name="csrf_fixture"')
        .replace(/data-csrf-hash="[^"]*"/, 'data-csrf-hash="csrf-original"')
        .replace(/<\?php[\s\S]*?\?>/g, '');
    const stack = [parent]; const voidTags = new Set(['input', 'br', 'hr', 'img', 'meta', 'link']);
    for (const token of html.match(/<[^>]*>|[^<]+/g) || []) {
        if (token.startsWith('</')) { stack.pop(); continue; }
        if (token.startsWith('<')) {
            const tag = token.match(/^<([a-z][a-z0-9-]*)/i); if (!tag) { continue; }
            const element = document.createElement(tag[1]);
            for (const match of token.slice(tag[0].length, -1).matchAll(/([\w-]+)(?:="([^"]*)")?/g)) { element.setAttribute(match[1], match[2] || ''); }
            stack[stack.length - 1].appendChild(element);
            if (!voidTags.has(tag[1].toLowerCase())) { stack.push(element); }
        } else { stack[stack.length - 1]._text += token; }
    }
}
function response(payload, status = 200, contentType = 'application/json; charset=utf-8') {
    return {ok: status >= 200 && status < 300, status, headers: {get: name => name === 'content-type' ? contentType : null}, json: async () => payload};
}
function report(extra = {}) {
    return {ok: true, csrf: {name: 'csrf_fixture', hash: 'csrf-from-logs'}, report: {database_state: 'ok', source: 'database', file_enabled: false, file_exists: false,
        file_path: '/srv/crm/application/logs/asaas-2026-11-06.php', directory_writable: true,
        rows: [{created_at: '2026-11-06 10:00:00', level: 'error', message: 'Falha de teste', context: {invoice_id: 42, http_status: 400}, correlation_id: 'fixture-correlation'}], ...extra}};
}
function deferred() {
    let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}
function fixture(options = {}) {
    const document = new Element('document'); document.ownerDocument = document; document.readyState = options.loading ? 'loading' : 'complete';
    document.createElement = tag => new Element(tag, document); document.getElementById = id => document.querySelector('#' + id);
    Object.defineProperty(document, 'cookie', {get: forbidden, set: forbidden});
    const form = document.appendChild(document.createElement('form'));
    const token = form.appendChild(document.createElement('input')); token.setAttribute('type', 'hidden'); token.setAttribute('name', 'csrf_fixture'); token.value = 'csrf-original';
    const key = form.appendChild(document.createElement('input')); key.type = 'password'; key.value = 'private-fixture-key';
    const layout = form.appendChild(document.createElement('div')); layout.setAttribute('id', 'asaas-settings-groups');
    layout.hidden = options.compatible === false; layout.className = layout.hidden ? 'hide' : '';
    const pane = layout.appendChild(document.createElement('div')); pane.setAttribute('data-asaas-settings-panel', 'logs'); pane.hidden = true;
    if (options.present !== false) { parseView(pane, document); }
    const nativeControl = pane.appendChild(document.createElement('input')); nativeControl.name = 'settings[paymentmethod_asaas_debug]'; nativeControl.value = '1';
    const fallback = pane.appendChild(document.createElement('p')).appendChild(document.createElement('a'));
    fallback.setAttribute('data-asaas-logs-open', ''); fallback.setAttribute('href', '/admin/asaas/asaas/logs');
    const root = document.getElementById('asaas-logs'); if (root && options.endpoint !== undefined) { root.setAttribute('data-endpoint', options.endpoint); }
    const calls = []; const queued = []; const timers = new Map(); const events = []; let timerId = 0;
    for (const name of ['asaas:logs:busy', 'asaas:logs:idle', 'asaas:settings:csrf-renewed', 'asaas:settings:show']) { document.addEventListener(name, () => events.push(name)); }
    const window = {location: {href: 'https://crm.example.test/admin/settings?group=payment_gateways', origin: 'https://crm.example.test'},
        AbortController,
        fetch: async (url, settings) => {
            calls.push({url: new URL(url), settings});
            if (!queued.length) { throw new Error('Unqueued request.'); }
            const next = queued.shift(); return typeof next === 'function' ? next() : await next;
        },
        setTimeout: (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; },
        clearTimeout: id => { timers.delete(id); }};
    const context = {window, document, URL, CustomEvent: class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } },
        console: {log: forbidden, error: forbidden, warn: forbidden}, XMLHttpRequest: forbidden};
    for (const target of [window, context]) { for (const property of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) { Object.defineProperty(target, property, {get: forbidden}); } }
    const run = () => vm.runInNewContext(source, context, {filename: 'asaas-logs.js'}); run();
    return {document, form, root, pane, layout, fallback, token, key, nativeControl, calls, queued, timers, events, run,
        get: selector => root.querySelector(selector),
        queue: (payload, status, contentType) => queued.push(response(payload, status, contentType)),
        shown: group => { pane.hidden = group !== 'logs'; document.dispatchEvent({type: 'asaas:settings:shown', detail: {group}}); },
        submit: () => { const event = {type: 'submit', defaultPrevented: false, stopped: false,
            preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }}; form.dispatchEvent(event); return event; }};
}
async function settle() { for (let i = 0; i < 4; i++) { await new Promise(resolve => setImmediate(resolve)); } }
async function open(f, payload = report()) { f.queue(payload); f.shown('logs'); await settle(); }
function checkRequest(call) {
    check(call.url.href === 'https://crm.example.test/admin/asaas/asaas/logs_data', 'Uses only the local diagnostic endpoint.');
    check(call.settings.method === 'GET' && call.settings.credentials === 'same-origin', 'Read-only authenticated request.');
    check(call.settings.cache === 'no-store' && call.settings.redirect === 'error', 'No cache or login redirect accepted.');
    check(call.settings.headers.Accept === 'application/json' && call.settings.headers['X-Requested-With'] === 'XMLHttpRequest', 'JSON and AJAX contract.');
    check(!call.settings.body && !/private-fixture-key|csrf-original/.test(call.url.href), 'Settings and secrets are not sent to the viewer.');
}
async function scenario(name, test) { await test(); scenarios++; process.stdout.write('PASS ' + name + '\n'); }

(async function () {
    await scenario('no panel or incompatible renderer keeps the legacy route and performs no requests', async () => {
        const absent = fixture({present: false}); absent.shown('logs'); await settle();
        check(absent.calls.length === 0, 'Other admin pages make no requests.'); check(!absent.fallback.hidden, 'Legacy route remains usable without panel.');
        const flat = fixture({compatible: false}); flat.shown('logs'); await settle();
        check(flat.calls.length === 0, 'Flat fallback never loads the hidden panel.'); check(!flat.fallback.hidden && !flat.fallback.parentElement.hidden, 'Legacy route visible for fallback renderer.');
    });
    await scenario('lazy report preserves native controls and loads once per successful opening', async () => {
        const f = fixture(); check(f.calls.length === 0, 'No initialization request.');
        check(f.pane.children.at(-1) === f.root, 'Report follows original native controls.');
        check(f.fallback.hidden, 'Full-page link replaced only in the working embedded layout.');
        check(f.get('[data-logs-action="refresh"]').type === 'button', 'Refresh cannot submit the settings form.');
        f.shown('environment'); await settle(); check(f.calls.length === 0, 'Other groups do not load logs.');
        await open(f); check(f.calls.length === 1, 'One load on first open.'); checkRequest(f.calls[0]);
        check(f.get('[data-logs-content]').hidden === false && f.get('[data-logs-rows]').children.length === 1, 'Report displayed.');
        check(f.get('[data-logs-file-path]').textContent === report().report.file_path, 'Daily file path retained.');
        check(f.get('[data-logs-file-enabled]').textContent === 'desabilitado', 'Saved file flag displayed.');
        check(f.token.value === 'csrf-from-logs', 'Native CSRF is renewed with the current server hash.');
        check(f.key.value === 'private-fixture-key' && f.key.type === 'password' && f.nativeControl.value === '1', 'Original setting values are untouched.');
        f.shown('general'); f.shown('logs'); f.run(); f.shown('logs'); await settle();
        check(f.calls.length === 1, 'Reopening or repeated script initialization does not duplicate requests.');
        check(f.document.querySelectorAll('form').length === 1, 'No nested form introduced.');
    });
    await scenario('HTML-like diagnostics and metadata render only as text with collapsible details', async () => {
        const f = fixture(); const value = '<img src=x onerror=alert(1)>';
        await open(f, report({file_path: value, rows: [{created_at: value, level: value, message: value,
            context: {details: '<script>fixture</script>', invoice_id: 42}, correlation_id: value}]}));
        check(f.root.querySelectorAll('img').length === 0 && f.root.querySelectorAll('script').length === 0, 'No injected elements created.');
        check(f.get('[data-logs-rows]').textContent.includes(value), 'Diagnostic remains readable as literal text.');
        check(f.root.querySelectorAll('details').length === 1 && f.root.querySelectorAll('pre')[0].textContent.includes('invoice_id'), 'Context remains available in details.');
        check(f.get('[data-logs-file-path]').textContent === value, 'Path rendered as text.');
    });
    await scenario('file fallback retains storage warning file permissions and source', async () => {
        const f = fixture(); await open(f, report({database_state: 'missing', source: 'file', file_enabled: true, file_exists: true, directory_writable: false}));
        check(!f.get('[data-logs-database]').hidden && f.get('[data-logs-database]').textContent.includes('migrations'), 'Missing database remediation shown.');
        check(!f.get('[data-logs-source]').hidden && f.get('[data-logs-source]').textContent.includes('256 KiB'), 'Bounded file fallback explained.');
        check(f.get('[data-logs-file-enabled]').textContent === 'habilitado', 'Enabled flag retained.');
        check(f.get('[data-logs-file-exists]').textContent.includes('já existe'), 'Existing file reported.');
        check(f.get('[data-logs-directory-writable]').textContent.includes('não permite escrita'), 'Directory permission problem shown.');
    });
    await scenario('empty or unavailable storage has a useful nonfinancial empty state', async () => {
        const f = fixture(); await open(f, report({database_state: 'unavailable', source: 'none', file_path: null, rows: []}));
        check(!f.get('[data-logs-empty]').hidden && f.get('[data-logs-table]').hidden, 'Empty state visible.');
        check(!f.get('[data-logs-path-unavailable]').hidden && f.get('[data-logs-file-details]').hidden, 'Unavailable path explained without invention.');
        check(!f.get('[data-logs-database]').hidden, 'Unavailable database shown.');
        check(f.root.getAttribute('aria-busy') === 'false', 'Read completed without blocking settings.');
    });
    await scenario('refresh serializes reads and never retries a failed transport automatically', async () => {
        const f = fixture(); await open(f); const waiting = deferred(); f.queued.push(waiting.promise);
        const button = f.get('[data-logs-action="refresh"]'); button.click(); button.click(); await settle();
        check(f.calls.length === 2 && button.disabled, 'Concurrent refresh blocked.');
        waiting.reject(new Error('private_network_marker_should_not_be_rendered')); await settle();
        check(f.get('[data-logs-content]').hidden && !f.get('[data-logs-feedback]').hidden, 'Failed refresh does not present stale data as current.');
        check(!f.root.textContent.includes('private_network_marker'), 'Transport exception details not exposed.');
        check(f.calls.length === 2 && !button.disabled, 'No automatic retry; explicit refresh available.');
        f.queue(report({rows: []})); button.click(); await settle(); check(f.calls.length === 3 && !f.get('[data-logs-empty]').hidden, 'Explicit retry recovers.');
    });
    await scenario('access errors and non-JSON responses do not display response bodies', async () => {
        for (const status of [401, 403, 500]) {
            const f = fixture(); f.queue({message: 'private_response_marker'}, status); f.shown('logs'); await settle();
            check(f.get('[data-logs-content]').hidden && !f.get('[data-logs-feedback]').hidden, 'Error status shown safely.');
            check(!f.root.textContent.includes('private_response_marker'), 'Raw error payload not shown.');
            check(f.calls.length === 1 && !f.get('[data-logs-action="refresh"]').disabled, 'No automatic repeated call.');
        }
        const html = fixture(); html.queue({message: 'private_html_marker'}, 200, 'text/html'); html.shown('logs'); await settle();
        check(html.get('[data-logs-content]').hidden && !html.root.textContent.includes('private_html_marker'), 'Login/debug HTML is not accepted as a report.');
    });
    await scenario('invalid JSON parser errors never expose raw body fragments', async () => {
        const f = fixture(); const invalid = response(null); invalid.json = async () => { throw new SyntaxError('Unexpected token private_json_marker_should_not_be_rendered'); };
        f.queued.push(invalid); f.shown('logs'); await settle();
        check(f.get('[data-logs-content]').hidden && !f.get('[data-logs-feedback]').hidden, 'Malformed JSON yields safe failure.');
        check(!f.root.textContent.includes('private_json_marker'), 'Parser error fragment omitted.');
        check(!f.get('[data-logs-action="refresh"]').disabled && f.calls.length === 1, 'Control recovers without retry.');
    });
    await scenario('malformed or oversized reports cannot masquerade as valid diagnostics', async () => {
        for (const payload of [{ok: true, csrf: report().csrf}, report({rows: [{message: 'partial'}]}), report({file_enabled: 'true'}), report({rows: Array(101).fill(report().report.rows[0])})]) {
            const f = fixture(); await open(f, payload);
            check(f.get('[data-logs-content]').hidden, 'Invalid report hidden.');
            check(f.get('[data-logs-feedback]').textContent.includes('incompletos'), 'Explicit incomplete response.');
        }
        const f = fixture(); await open(f, report({rows: Array(100).fill(report().report.rows[0])}));
        check(f.get('[data-logs-rows]').children.length === 100, 'Full existing report bound supported.');
    });
    await scenario('unsafe endpoint leaves legacy navigation available', async () => {
        for (const endpoint of ['', 'https://untrusted.example.test/logs', 'javascript:alert(1)', 'file:///etc/passwd']) {
            const f = fixture({endpoint}); f.shown('logs'); await settle();
            check(f.calls.length === 0 && f.get('[data-logs-action="refresh"]').disabled, 'Unsafe endpoint is never called.');
            check(!f.fallback.hidden, 'Legacy visualizer remains available.');
        }
    });
    await scenario('read timeout releases controls and ignores a late response', async () => {
        const f = fixture(); const waiting = deferred(); f.queued.push(waiting.promise); f.shown('logs'); await settle();
        check(f.timers.size === 1 && [...f.timers.values()][0].delay === 15000, 'Bounded diagnostic read.');
        [...f.timers.values()][0].callback(); await settle();
        check(f.calls[0].settings.signal.aborted && !f.get('[data-logs-action="refresh"]').disabled, 'Only the read is aborted and controls recover.');
        check(f.get('[data-logs-feedback]').textContent.includes('demorou') && f.get('[data-logs-content]').hidden, 'Timeout explained.');
        waiting.resolve(response(report())); await settle();
        check(f.get('[data-logs-content]').hidden && f.calls.length === 1, 'Late response cannot replace timeout or trigger retries.');
    });
    await scenario('DOMContentLoaded and repeated script inclusion initialize only once', async () => {
        const f = fixture({loading: true}); f.run(); check(f.root.getAttribute('data-asaas-logs-initialized') === null, 'Waits for DOM.');
        f.document.dispatchEvent({type: 'DOMContentLoaded'}); await open(f);
        check(f.calls.length === 1 && f.root.getAttribute('data-asaas-logs-initialized') === '1', 'One initialization and one request.');
    });
    await scenario('GET renewal updates only the expected native hidden CSRF field', async () => {
        const f = fixture(); const payload = report(); payload.csrf.hash = 'csrf-cookie-renewed-by-get'; await open(f, payload);
        check(f.token.value === 'csrf-cookie-renewed-by-get' && f.token.name === 'csrf_fixture' && f.token.type === 'hidden', 'Existing native field renewed without renaming.');
        check(f.form.getAttribute('data-asaas-logs-csrf-uncertain') === null, 'Valid token clears uncertainty.');
        check(f.events.includes('asaas:settings:csrf-renewed'), 'Other local handlers can observe valid renewal.');
        check(!f.submit().defaultPrevented, 'Normal save is available after token renewal.');
    });
    await scenario('malformed or hostile CSRF never creates fields or overwrites credentials', async () => {
        for (const csrf of [{name: 'other_hidden', hash: 'hostile'}, {name: 'settings[paymentmethod_asaas_api_key]', hash: 'hostile'},
            {name: 'csrf_fixture"]', hash: 'hostile'}, {name: 'csrf_fixture', hash: ''}, {name: 'csrf_fixture', hash: 'bad hash'},
            {name: 'csrf_fixture', hash: 'x'.repeat(513)}, {name: 'csrf_fixture', hash: {invalid: true}}, null]) {
            const f = fixture(); const other = f.form.appendChild(f.document.createElement('input')); other.setAttribute('type', 'hidden'); other.setAttribute('name', 'other_hidden'); other.value = 'keep';
            const before = f.form.querySelectorAll('input').length; const payload = report(); payload.csrf = csrf; await open(f, payload);
            check(f.token.value === 'csrf-original' && other.value === 'keep' && f.key.value === 'private-fixture-key', 'Native token and unrelated settings preserved on hostile metadata.');
            check(f.form.querySelectorAll('input').length === before && f.form.getAttribute('data-asaas-logs-csrf-uncertain') === '1', 'No new fields; uncertain token remains explicit.');
            check(f.submit().defaultPrevented, 'Save blocked until valid renewal.');
        }
    });
    await scenario('missing or nonhidden CSRF field cannot be invented by the logs response', async () => {
        for (const mode of ['missing', 'visible']) {
            const f = fixture();
            if (mode === 'missing') { f.form.removeChild(f.token); } else { f.token.setAttribute('type', 'text'); }
            const before = f.form.querySelectorAll('input').length; await open(f);
            check(f.form.querySelectorAll('input').length === before && f.token.value === 'csrf-original', 'Only a preexisting hidden native field is eligible.');
            check(f.get('[data-logs-content]').hidden && f.submit().defaultPrevented, 'Form remains protected when native field is unavailable.');
        }
    });
    await scenario('JSON error renews CSRF before reporting an unavailable diagnostic', async () => {
        const f = fixture(); f.queue({ok: false, message: 'private_error_marker', csrf: {name: 'csrf_fixture', hash: 'csrf-on-error'}}, 503);
        f.shown('logs'); await settle();
        check(f.token.value === 'csrf-on-error' && f.form.getAttribute('data-asaas-logs-csrf-uncertain') === null, 'Error response renews the cookie-matching hash.');
        check(f.get('[data-logs-content]').hidden && !f.submit().defaultPrevented, 'Diagnostic error does not strand the settings form.');
        check(!f.root.textContent.includes('private_error_marker'), 'Raw server message remains hidden.');
    });
    await scenario('logs defer their GET until Monitor becomes idle and Logs remain visible', async () => {
        const f = fixture(); f.form.setAttribute('data-asaas-monitor-busy', '1'); f.queue(report()); f.shown('logs'); await settle();
        check(f.calls.length === 0 && f.get('[data-logs-action="refresh"]').disabled, 'GET does not overlap Monitor operation.');
        f.form.removeAttribute('data-asaas-monitor-busy'); f.document.dispatchEvent({type: 'asaas:monitor:idle'}); await settle();
        check(f.calls.length === 1 && !f.get('[data-logs-content]').hidden, 'Pending read starts when Monitor finishes.');
        const hidden = fixture(); hidden.form.setAttribute('data-asaas-monitor-busy', '1'); hidden.queue(report()); hidden.shown('logs'); hidden.shown('general');
        hidden.form.removeAttribute('data-asaas-monitor-busy'); hidden.document.dispatchEvent({type: 'asaas:monitor:idle'}); await settle();
        check(hidden.calls.length === 0, 'Hidden Logs do not make a delayed request.');
        hidden.shown('logs'); await settle(); check(hidden.calls.length === 1, 'Reopening Logs resumes the explicitly requested read.');
    });
    await scenario('native submit is blocked while GET is pending and after uncertain CSRF', async () => {
        const f = fixture(); const waiting = deferred(); f.queued.push(waiting.promise); f.shown('logs'); await settle();
        check(f.form.getAttribute('data-asaas-logs-loading') === '1' && f.events.includes('asaas:logs:busy'), 'Busy flag is visible before the request can overlap.');
        const submit = f.submit(); check(submit.defaultPrevented && submit.stopped && f.events.includes('asaas:settings:show'), 'Native submit prevented with the Logs feedback made visible.');
        waiting.reject(new Error('Network unavailable')); await settle();
        check(f.form.getAttribute('data-asaas-logs-loading') === null && f.events.includes('asaas:logs:idle'), 'Request lock released after failure.');
        check(f.form.getAttribute('data-asaas-logs-csrf-uncertain') === '1' && f.submit().defaultPrevented, 'Unknown cookie/hash blocks stale submissions.');
        // A successful native Monitor GET can also renew the token and clear the shared uncertainty flag.
        f.token.value = 'csrf-from-monitor'; f.form.removeAttribute('data-asaas-logs-csrf-uncertain');
        f.document.dispatchEvent({type: 'asaas:settings:csrf-renewed', detail: {source: 'monitor'}});
        check(!f.submit().defaultPrevented && f.calls.length === 1, 'External valid renewal restores save without automatic POST or retry.');
    });
    check(forbiddenAccesses === 0, 'No HTML injection sink, console logging, cookie or persistent storage access.');
    process.stdout.write('\nLogs UI: ' + scenarios + ' cenários, ' + assertions + ' asserções, 0 falhas.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
