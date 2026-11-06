'use strict';

// Executes the actual browser handler with a minimal DOM/event fixture; no dependencies.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/asaas-card-checkout.js'), 'utf8');
let assertions = 0;

function check(condition, message) {
    assertions++;
    assert.ok(condition, message);
}

function fixture(valid = true, hasForm = true) {
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
        cardNumber: {value: '4111111111111111'},
        securityCode: {value: '123'}
    };
    const elements = {
        'asaas-card-checkout': hasForm ? form : null,
        payment: button,
        'asaas-card-checkout-status': status,
        ...fields
    };
    const window = {
        location: {
            href: 'https://crm.example.test/admin/asaas/checkout/cartao/hash#form',
            replace(url) { redirects.push(url); }
        },
        addEventListener(name, callback) { windowHandlers[name] = callback; },
        setTimeout(callback, time) { timers.push({callback, time}); return timers.length; },
        clearTimeout(id) { cleared.push(id); }
    };
    vm.runInNewContext(source, {document: {getElementById: id => elements[id]}, window});
    return {form, button, status, fields, handlers, windowHandlers, timers, redirects, cleared};
}

function event() {
    return {prevented: 0, preventDefault() { this.prevented++; }};
}

const empty = fixture(true, false);
check(!empty.handlers.submit, 'No form should install no submit handler.');

const invalid = fixture(false);
const invalidEvent = event();
invalid.handlers.submit(invalidEvent);
check(invalidEvent.prevented === 1, 'Invalid input must stop submit.');
check(invalid.form.reports === 1, 'Invalid input must show browser validation.');
check(!invalid.button.disabled && invalid.timers.length === 0, 'Invalid input must leave correction available.');

const active = fixture();
const firstEvent = event();
active.handlers.submit(firstEvent);
check(firstEvent.prevented === 0, 'The valid first request must keep native POST.');
check(active.button.disabled, 'The button must be disabled during submit.');
check(active.form.attrs['aria-busy'] === 'true', 'The busy state must be accessible.');
check(active.fields.cardNumber.value === '4111111111111111' && active.fields.securityCode.value === '123', 'Fields must remain available for native POST.');
check(active.status.visible && active.status.textContent.includes('Aguarde'), 'Processing status must be announced.');
check(active.timers.length === 1 && active.timers[0].time === 60000, 'The slow-response notice must wait one minute.');

const repeatEvent = event();
active.handlers.submit(repeatEvent);
check(repeatEvent.prevented === 1 && active.timers.length === 1, 'Repeated submit must not start a second attempt.');

active.timers[0].callback();
check(active.status.textContent.includes('mais de um minuto') && active.button.disabled, 'A delayed request must remain protected.');

active.windowHandlers.pageshow({persisted: false});
check(active.redirects.length === 0, 'Normal page display must not navigate.');
active.windowHandlers.pageshow({persisted: true});
check(active.fields.cardNumber.value === '' && active.fields.securityCode.value === '', 'History restoration must clear card secrets.');
check(active.redirects.length === 1 && active.redirects[0] === 'https://crm.example.test/admin/asaas/checkout/cartao/hash', 'The restored page must navigate to the server status.');
check(active.button.disabled && active.cleared.length === 1, 'History restoration must not allow POST before server recheck.');

console.log('Card checkout UI: 6 scenarios, ' + assertions + ' assertions, 0 failures.');
