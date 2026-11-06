'use strict';

// Run the actual recurring handler; no browser, CRM, credentials or Asaas connection.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/asaas-recurring.js'), 'utf8');
let assertions = 0;
let privacySinkAccesses = 0;
function check(condition, message) { assertions++; assert.ok(condition, message); }
function forbiddenSink() { privacySinkAccesses++; throw new Error('Payment input must not reach a storage or extra network sink.'); }
function event() { return {prevented: 0, preventDefault() { this.prevented++; }}; }

function fixture({valid = true, hasRoot = true, modes = ['enroll', 'revoke']} = {}) {
    const events = {};
    const windowEvents = {};
    const timers = [];
    const cleared = [];
    const redirects = [];
    const buttons = modes.map(mode => ({disabled: false, textContent: mode}));
    const forms = modes.map((mode, index) => ({
        attrs: {}, reports: 0,
        addEventListener(name, callback) { events[mode + ':' + name] = callback; },
        checkValidity() { return mode === 'revoke' || valid; },
        reportValidity() { this.reports++; },
        getAttribute(name) { return name === 'data-asaas-recurring-form' ? mode : this.attrs[name]; },
        setAttribute(name, value) { this.attrs[name] = value; },
        querySelector() { return buttons[index]; }
    }));
    const fields = {
        holderName: {value: 'Titular do teste', defaultValue: 'autofilled-name'},
        cardNumber: {value: '4111111111111111', defaultValue: 'autofilled-pan'},
        expirationMonth: {value: '12', defaultValue: 'autofilled-month'},
        expirationYear: {value: '2030', defaultValue: 'autofilled-year'},
        securityCode: {value: '123', defaultValue: 'autofilled-cvv'},
        holderEmail: {value: 'entered@example.test', defaultValue: 'customer@example.test'},
        holderDocument: {value: '12345678909', defaultValue: '52998224725'},
        holderPostalCode: {value: '01310-000', defaultValue: '01001-000'},
        holderAddressNumber: {value: '100', defaultValue: '200'},
        holderPhone: {value: '11987654321', defaultValue: '11900000000'}
    };
    const consent = {checked: true, defaultChecked: true};
    const csrf = {value: 'csrf-fixture'};
    const nonce = {value: 'b'.repeat(64)};
    const quote = {value: 'a'.repeat(64)};
    const status = {textContent: '', classList: {remove(name) { status.visible = name === 'hidden'; }}};
    const root = {querySelectorAll(selector) { return selector === '[data-asaas-recurring-form]' ? forms : buttons; }};
    const elements = {'asaas-recurring': hasRoot ? root : null, 'asaas-recurring-status': status, 'recurring-consent': consent, csrf_fixture: csrf, recurringNonce: nonce, cardQuote: quote};
    for (const [name, field] of Object.entries(fields)) { elements['recurring-' + name] = field; }
    const document = {getElementById(name) { return elements[name]; }};
    Object.defineProperty(document, 'cookie', {get: forbiddenSink, set: forbiddenSink});
    const window = {
        location: {href: 'https://crm.example.test/asaas/recurring/index/hash?untrusted=1#form', replace(url) { redirects.push(url); }},
        addEventListener(name, callback) { windowEvents[name] = callback; },
        setTimeout(callback, time) { timers.push({callback, time}); return timers.length; },
        clearTimeout(id) { cleared.push(id); },
        fetch: forbiddenSink, XMLHttpRequest: forbiddenSink, navigator: {sendBeacon: forbiddenSink}
    };
    const context = {document, window, navigator: window.navigator, fetch: forbiddenSink, XMLHttpRequest: forbiddenSink};
    for (const target of [window, context]) {
        for (const key of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) { Object.defineProperty(target, key, {get: forbiddenSink}); }
    }
    vm.runInNewContext(source, context);
    return {forms, buttons, fields, consent, csrf, nonce, quote, status, events, windowEvents, timers, cleared, redirects};
}
function values(f) { return JSON.stringify(f.fields); }
function clearedFields(f, names = Object.keys(f.fields)) {
    for (const name of names) { check(f.fields[name].value === '' && f.fields[name].defaultValue === '', name + ' must be removed from DOM and defaults.'); }
    check(!f.consent.checked && !f.consent.defaultChecked, 'Consent must never be restored automatically.');
    check(f.csrf.value === 'csrf-fixture' && f.nonce.value === 'b'.repeat(64) && f.quote.value === 'a'.repeat(64), 'Cleanup must preserve native CSRF, independent form nonce and the acknowledged quote.');
}

for (const options of [{hasRoot: false}, {modes: []}]) {
    const f = fixture(options);
    check(Object.keys(f.events).length === 0 && Object.keys(f.windowEvents).length === 0, 'No enrollment/revocation controls means no handlers.');
}

const invalid = fixture({valid: false});
const beforeInvalid = values(invalid);
const invalidEvent = event();
invalid.events['enroll:submit'](invalidEvent);
check(invalidEvent.prevented === 1 && invalid.forms[0].reports === 1, 'Invalid card input or unchecked consent must report native validation.');
check(invalid.buttons.every(button => !button.disabled) && invalid.timers.length === 0, 'Invalid input must remain editable without a payment attempt.');
check(values(invalid) === beforeInvalid && invalid.consent.checked, 'Correction in the same form must preserve entered values.');

const enroll = fixture();
const beforeEnroll = values(enroll);
const first = event();
enroll.events['enroll:submit'](first);
check(first.prevented === 0 && values(enroll) === beforeEnroll && enroll.consent.checked, 'First valid enrollment must preserve the native POST body, including card and consent.');
check(enroll.buttons.every(button => button.disabled) && enroll.forms.every(form => form.attrs['aria-busy'] === 'true'), 'Enrollment must lock both operations while its outcome is pending.');
check(enroll.status.visible && enroll.status.textContent.includes('Aguarde'), 'Pending enrollment must provide an accessible explanation.');
check(enroll.timers.length === 1 && enroll.timers[0].time === 60000, 'Slow response message waits one minute without scheduling another capture.');
for (const mode of ['enroll', 'revoke']) {
    const repeat = event();
    enroll.events[mode + ':submit'](repeat);
    check(repeat.prevented === 1 && enroll.timers.length === 1, 'Concurrent ' + mode + ' must not issue a second native POST.');
}
enroll.timers[0].callback();
check(enroll.status.textContent.includes('não será repetida automaticamente') && enroll.buttons.every(button => button.disabled), 'A slow response must keep the forms locked without automatic retry.');

const revoke = fixture();
const revokeEvent = event();
revoke.events['revoke:submit'](revokeEvent);
check(revokeEvent.prevented === 0 && revoke.status.textContent.includes('cobranças futuras'), 'Revocation uses a native POST and explains its future-only effect.');
clearedFields(revoke);
check(revoke.buttons.every(button => button.disabled) && revoke.buttons[1].textContent.includes('Desativando'), 'Revocation locks enrollment and displays progress.');

const shown = fixture();
shown.windowEvents.pageshow({persisted: false});
clearedFields(shown, ['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode']);
for (const name of ['holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone']) {
    check(shown.fields[name].value === shown.fields[name].defaultValue && shown.fields[name].value !== '', name + ' may restore only its server-rendered customer default.');
}
check(shown.redirects.length === 0 && shown.buttons.every(button => !button.disabled), 'Normal first display must permit a fresh authorization.');

const leaving = fixture();
leaving.windowEvents.pagehide({persisted: true});
clearedFields(leaving);
check(leaving.cleared.length === 1 && leaving.redirects.length === 0, 'Leaving clears secrets without making another request.');

const posted = fixture();
posted.events['enroll:submit'](event());
// Native navigation serializes this body before it dispatches pagehide.
const submitted = JSON.parse(values(posted));
posted.windowEvents.pagehide({persisted: false});
clearedFields(posted);
check(submitted.cardNumber.value === '4111111111111111' && submitted.securityCode.value === '123', 'Navigation cleanup cannot erase the already-serialized native body.');
check(posted.cleared[0] === 1, 'Navigation must stop the pending response notice.');
posted.windowEvents.pageshow({persisted: true});
clearedFields(posted);
check(posted.redirects.length === 1 && posted.redirects[0] === 'https://crm.example.test/asaas/recurring/index/hash', 'A history restoration requires a new canonical GET without query or fragment.');
check(posted.buttons.every(button => button.disabled), 'History restoration must not enable another operation before server recheck.');
const restored = event();
posted.events['enroll:submit'](restored);
check(restored.prevented === 1 && posted.timers.length === 1, 'Restored enrollment cannot resend the earlier payment.');

const onlyRevoke = fixture({modes: ['revoke']});
onlyRevoke.events['revoke:submit'](event());
check(onlyRevoke.buttons[0].disabled, 'A paid or uncertain invoice with only revocation still uses its submit guard.');
check(privacySinkAccesses === 0, 'Handler must not access cookies, storage, IndexedDB, Cache API, fetch, XHR or beacon.');
console.log('Recurring UI: 11 scenarios, ' + assertions + ' assertions, 0 failures.');
