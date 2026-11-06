'use strict';

// Executes the actual browser handler with a minimal DOM/event fixture; no dependencies.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/asaas-card-checkout.js'), 'utf8');
let assertions = 0;
let privacySinkAccesses = 0;

function check(condition, message) {
    assertions++;
    assert.ok(condition, message);
}

function forbiddenSink() {
    privacySinkAccesses++;
    throw new Error('The card handler must not use cookies, storage or an extra network request.');
}

function fixture(valid = true, hasForm = true, withPrices = false, priceOptions = null) {
    const handlers = {};
    const windowHandlers = {};
    const timers = [];
    const redirects = [];
    const cleared = [];
    const form = {
        attrs: {},
        reports: 0,
        addEventListener(name, callback) { handlers[name] = callback; },
        checkValidity() { return valid; },
        reportValidity() { this.reports++; },
        setAttribute(name, value) { this.attrs[name] = value; }
    };
    const button = {disabled: false, textContent: 'Confirmar'};
    const status = {
        textContent: '',
        classList: {remove(name) { status.visible = name === 'hidden'; }}
    };
    const fields = {
        holderName: {value: 'Titular da tentativa', defaultValue: ''},
        cardNumber: {value: '4111111111111111', defaultValue: ''},
        expirationMonth: {value: '12', defaultValue: ''},
        expirationYear: {value: '2030', defaultValue: ''},
        securityCode: {value: '123', defaultValue: ''},
        holderEmail: {value: 'entered@example.test', defaultValue: 'customer@example.test'},
        holderDocument: {value: '12345678909', defaultValue: '52998224725'},
        holderPostalCode: {value: '01310-000', defaultValue: '01001-000'},
        holderAddressNumber: {value: '100', defaultValue: '200'},
        holderPhone: {value: '11987654321', defaultValue: '11900000000'}
    };
    const csrf = {value: 'csrf-fixture', defaultValue: 'csrf-fixture'};
    const installments = {value: '2'};
    const installmentHelp = {hidden: true};
    const priceFields = Object.fromEntries(['principal', 'fee', 'total', 'schedule'].map(name => [name, {textContent: ''}]));
    const summary = withPrices ? {hidden: true, querySelector(selector) { return priceFields[selector.replace('[data-card-price-', '').replace(']', '')]; }} : null;
    if (withPrices) {
        installments.options = (priceOptions || [
            {count: 1, principal: '100.00', fee: '3.59', total: '103.59', schedule: 'Pagamento à vista.'},
            {count: 3, principal: '100.00', fee: '4.13', total: '104.13', schedule: '2 parcelas de R$ 34,71 e a última de R$ 34,71.'}
        ]).map(offer => ({
            value: String(offer.count), disabled: !!offer.disabled, hidden: !!offer.hidden,
            parentElement: {disabled: !!offer.groupDisabled},
            getAttribute(name) { return offer[name.replace('data-card-', '')]; }
        }));
        installments.selectedIndex = 0;
        installments.addEventListener = (name, callback) => { handlers['installments:' + name] = callback; };
    }
    const elements = {
        'asaas-card-checkout': hasForm ? form : null,
        payment: button,
        'asaas-card-checkout-status': status,
        csrf_token: csrf,
        installmentCount: installments,
        'asaas-card-price-summary': summary,
        'asaas-card-installment-help': installmentHelp,
        ...fields
    };
    const document = {getElementById: id => elements[id]};
    Object.defineProperty(document, 'cookie', {get: forbiddenSink, set: forbiddenSink});
    const window = {
        location: {
            href: 'https://crm.example.test/admin/asaas/checkout/cartao/hash#form',
            replace(url) { redirects.push(url); }
        },
        addEventListener(name, callback) { windowHandlers[name] = callback; },
        setTimeout(callback, time) { timers.push({callback, time}); return timers.length; },
        clearTimeout(id) { cleared.push(id); },
        fetch: forbiddenSink,
        XMLHttpRequest: forbiddenSink,
        navigator: {sendBeacon: forbiddenSink}
    };
    const context = {document, window, navigator: window.navigator, fetch: forbiddenSink, XMLHttpRequest: forbiddenSink};
    for (const target of [window, context]) {
        for (const key of ['localStorage', 'sessionStorage', 'indexedDB', 'caches']) {
            Object.defineProperty(target, key, {get: forbiddenSink});
        }
    }
    vm.runInNewContext(source, context);
    return {form, button, status, fields, csrf, installments, installmentHelp, summary, priceFields, handlers, windowHandlers, timers, redirects, cleared};
}

function event() {
    return {prevented: 0, preventDefault() { this.prevented++; }};
}

function fieldValues(fields) {
    return Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, field.value]));
}

function checkAllCleared(fixture) {
    for (const [name, field] of Object.entries(fixture.fields)) {
        check(field.value === '' && field.defaultValue === '', name + ' must not survive in the departing or restored DOM.');
    }
    check(fixture.csrf.value === 'csrf-fixture', 'Clearing payment data must preserve the framework CSRF field.');
    check(fixture.installments.value === '2', 'Clearing sensitive inputs must not change the installment contract.');
}

const empty = fixture(true, false);
check(!empty.handlers.submit, 'No form should install no submit handler.');

const invalid = fixture(false);
const invalidBefore = JSON.stringify(fieldValues(invalid.fields));
const invalidEvent = event();
invalid.handlers.submit(invalidEvent);
check(invalidEvent.prevented === 1, 'Invalid input must stop submit.');
check(invalid.form.reports === 1, 'Invalid input must show browser validation.');
check(!invalid.button.disabled && invalid.timers.length === 0, 'Invalid input must leave correction available.');
check(JSON.stringify(fieldValues(invalid.fields)) === invalidBefore, 'Validation must retain input while the payer corrects the same open form.');

const active = fixture();
const activeBefore = JSON.stringify(fieldValues(active.fields));
const firstEvent = event();
active.handlers.submit(firstEvent);
check(firstEvent.prevented === 0, 'The valid first request must keep native POST.');
check(active.button.disabled, 'The button must be disabled during submit.');
check(active.form.attrs['aria-busy'] === 'true', 'The busy state must be accessible.');
check(active.fields.cardNumber.value === '4111111111111111' && active.fields.securityCode.value === '123', 'Fields must remain available for native POST.');
check(JSON.stringify(fieldValues(active.fields)) === activeBefore, 'All card and holder fields must remain available for native POST serialization.');
check(active.csrf.value === 'csrf-fixture', 'CSRF must remain available for POST.');
check(active.status.visible && active.status.textContent.includes('Aguarde'), 'Processing status must be announced.');
check(active.timers.length === 1 && active.timers[0].time === 60000, 'The slow-response notice must wait one minute.');

const repeatEvent = event();
active.handlers.submit(repeatEvent);
check(repeatEvent.prevented === 1 && active.timers.length === 1, 'Repeated submit must not start a second attempt.');

active.timers[0].callback();
check(active.status.textContent.includes('mais de um minuto') && active.button.disabled, 'A delayed request must remain protected.');

const initial = fixture();
initial.windowHandlers.pageshow({persisted: false});
for (const name of ['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode']) {
    check(initial.fields[name].value === '' && initial.fields[name].defaultValue === '', name + ' restored by the browser must be cleared.');
}
for (const name of ['holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone']) {
    check(initial.fields[name].value === initial.fields[name].defaultValue && initial.fields[name].value !== '', name + ' may use only the customer default rendered by the server.');
}
check(initial.redirects.length === 0 && !initial.button.disabled, 'Normal page display must permit input without navigating.');

active.windowHandlers.pageshow({persisted: true});
checkAllCleared(active);
check(active.redirects.length === 1 && active.redirects[0] === 'https://crm.example.test/admin/asaas/checkout/cartao/hash', 'The restored page must navigate to the server status.');
check(active.button.disabled && active.cleared.length === 1, 'History restoration must not allow POST before server recheck.');
const restoredEvent = event();
active.handlers.submit(restoredEvent);
check(restoredEvent.prevented === 1 && active.timers.length === 1, 'History restoration must not start another capture.');

const leaving = fixture();
leaving.windowHandlers.pagehide({persisted: true});
checkAllCleared(leaving);
check(leaving.cleared.length === 1, 'Leaving the page must cancel the response notice timer.');

const posted = fixture();
posted.handlers.submit(event());
// Native form navigation constructs its POST body before dispatching pagehide.
const submitted = JSON.parse(JSON.stringify(fieldValues(posted.fields)));
posted.windowHandlers.pagehide({persisted: false});
checkAllCleared(posted);
check(submitted.cardNumber === '4111111111111111' && submitted.securityCode === '123', 'Navigation cleanup must preserve the already serialized PAN/CVV.');
check(submitted.holderEmail === 'entered@example.test' && submitted.expirationYear === '2030', 'Navigation cleanup must preserve the serialized holder/expiration data.');
check(posted.cleared[0] === 1, 'The outstanding notice timer must be cancelled after navigation.');

posted.windowHandlers.pageshow({persisted: true});
checkAllCleared(posted);
check(posted.redirects.length === 1 && posted.button.disabled, 'Returning to a submitted form must recheck the server state once.');
const postedRestoredEvent = event();
posted.handlers.submit(postedRestoredEvent);
check(postedRestoredEvent.prevented === 1 && posted.timers.length === 1, 'Back/forward navigation cannot dispatch a second payment.');

const priced = fixture(true, true, true);
check(!priced.summary.hidden && priced.priceFields.total.textContent.includes('103,59') && priced.priceFields.fee.textContent.includes('3,59'), 'The first server offer must disclose its total and surcharge.');
priced.installments.selectedIndex = 1;
priced.handlers['installments:change']();
check(priced.priceFields.total.textContent.includes('104,13') && priced.priceFields.schedule.textContent.includes('34,71'), 'Changing installments must update the displayed server offer and exact schedule.');
check(priced.fields.cardNumber.value === '4111111111111111' && priced.fields.securityCode.value === '123', 'Showing prices must not read or mutate card fields.');
priced.handlers.submit(event());
priced.installments.selectedIndex = 0;
priced.handlers['installments:change']();
check(priced.priceFields.total.textContent.includes('104,13'), 'The acknowledged display must not change while a payment is submitting.');

const installmentChoices = fixture(true, true, true, [1, 12, 13, 21].map(count => ({count})));
check(installmentChoices.installmentHelp.hidden, 'Paying once must not display the extended-installment brand notice.');
for (const [index, visible] of [[1, false], [2, true], [3, true], [0, false], [1, false]]) {
    installmentChoices.installments.selectedIndex = index;
    installmentChoices.handlers['installments:change']();
    check(installmentChoices.installmentHelp.hidden === !visible,
        'The notice must follow the selected available offer: ' + installmentChoices.installments.options[index].value + 'x.');
}

for (const count of [13, 21]) {
    const firstExtended = fixture(true, true, true, [{count}]);
    check(!firstExtended.installmentHelp.hidden, 'An initial available ' + count + 'x offer must display its brand restriction.');
}

const limitedChoices = fixture(true, true, true, [{count: 1}, {count: 12}]);
limitedChoices.installments.value = '21';
limitedChoices.installments.selectedIndex = -1;
limitedChoices.handlers['installments:change']();
check(limitedChoices.installmentHelp.hidden, 'A value without a corresponding offered option cannot activate the notice.');
installmentChoices.installments.options.splice(2);
installmentChoices.installments.selectedIndex = 2;
installmentChoices.handlers['installments:change']();
check(installmentChoices.installmentHelp.hidden, 'An option that is no longer available cannot retain the notice.');

for (const offer of [{count: 13, disabled: true}, {count: 21, hidden: true}, {count: 13, groupDisabled: true}, {count: 22}]) {
    const unavailable = fixture(true, true, true, [offer]);
    check(unavailable.installmentHelp.hidden, 'Unavailable or unsupported installment offers must not activate the notice.');
}
const disabledChoices = fixture(true, true, true, [{count: 13}]);
disabledChoices.installments.disabled = true;
disabledChoices.handlers['installments:change']();
check(disabledChoices.installmentHelp.hidden, 'A disabled installment selector must not advertise an available choice.');

check(privacySinkAccesses === 0, 'Card submit/navigation must not access cookies, local/session storage, IndexedDB, Cache API, fetch, XHR or beacon.');

console.log('Card checkout UI: 15 scenarios, ' + assertions + ' assertions, 0 failures.');
