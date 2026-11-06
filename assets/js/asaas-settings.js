(function () {
    'use strict';

    function initializeAsaasSettings() {
        var panel = document.getElementById('online_payments_asaas_tab');
        if (!panel || panel.getAttribute('data-asaas-settings-initialized') === '1') {
            return;
        }
        panel.setAttribute('data-asaas-settings-initialized', '1');

        var layout = document.getElementById('asaas-settings-groups');
        var prefix = 'settings[paymentmethod_asaas_';
        var secrets = [];
        var groups = {
            api_key: 'environment', api_key_sandbox: 'environment', sandbox: 'environment',
            show_environment_in_production: 'environment',
            billet_only: 'payments', card_only: 'payments', pix_only: 'payments',
            interest_value: 'charges', fine_value: 'charges', discount_type: 'charges', discount_value: 'charges',
            discount_days: 'charges', diasdevencimento: 'charges', disable_charge_notification: 'charges',
            webhook_secret: 'webhook',
            sync_enabled: 'sync', sync_days: 'sync', sync_limit: 'sync', sync_invoice_lifecycle: 'sync',
            manual_payment_sync: 'sync', manual_payment_receive_in_cash: 'sync', delete_charge: 'sync',
            update_charge: 'sync', ultima_atualizacao_servicos_municipais: 'sync',
            debug: 'logs', file_log_enabled: 'logs'
        };
        var cardSettings = {
            installmentCount: 'installments', card_min_installment: 'installments', card_free_installments: 'installments',
            card_fee_mode: 'fees', card_fee_fixed_mode: 'fees', card_fee_fixed: 'fees',
            card_fee_percent_1: 'rates', card_fee_percent_2_6: 'rates', card_fee_percent_7_12: 'rates', card_fee_percent_13_21: 'rates',
            card_anticipation_mode: 'anticipation', card_anticipation_first_days: 'anticipation',
            card_anticipation_manual_rate_1: 'anticipation', card_anticipation_manual_rate_installments: 'anticipation',
            card_anticipation_automatic_rate_1: 'anticipation', card_anticipation_automatic_rate_installments: 'anticipation'
        };
        Object.keys(cardSettings).forEach(function (name) { groups[name] = 'charges'; });

        var cardMaximum = panel.querySelector('input[name="' + prefix + 'installmentCount]"]');
        var cardFree = panel.querySelector('input[name="' + prefix + 'card_free_installments]"]');
        var cardDecimals = panel.querySelectorAll('input[data-asaas-card-decimal]');
        function validateCardSettings() {
            if (cardMaximum && cardFree) {
                var maximum = Number(cardMaximum.value);
                var free = Number(cardFree.value);
                cardFree.max = maximum >= 1 && maximum <= 21 ? String(maximum) : '21';
                cardFree.setCustomValidity(free > maximum && maximum >= 1 && maximum <= 21
                    ? 'As parcelas sem repasse não podem ultrapassar o limite de parcelas do cartão.' : '');
            }
            Array.prototype.forEach.call(cardDecimals, function (input) {
                var value = input.value.trim();
                var number = Number(value.replace(',', '.'));
                var kind = input.getAttribute('data-asaas-card-decimal');
                var message = '';
                if (!/^(?:[0-9]+(?:[.,][0-9]{1,2})?|[.,][0-9]{1,2})$/.test(value) || !Number.isFinite(number)) {
                    message = 'Informe um valor não negativo, com até duas casas decimais.';
                } else if (kind === 'percentage' && number > 99) {
                    message = 'O percentual deve estar entre 0% e 99%.';
                } else if (kind === 'fixed' && number > 100000) {
                    message = 'A taxa fixa não pode ultrapassar R$ 100.000,00.';
                } else if (kind === 'minimum' && number < 5) {
                    message = 'O valor mínimo da parcela deve ser de pelo menos R$ 5,00.';
                }
                input.setCustomValidity(message);
            });
        }
        Object.keys(cardSettings).forEach(function (name) {
            var input = panel.querySelector('input[name="' + prefix + name + ']"]');
            if (input) { input.addEventListener('input', validateCardSettings); input.addEventListener('change', validateCardSettings); }
        });
        validateCardSettings();

        function setSecretVisible(secret, visible) {
            secret.input.type = visible ? 'text' : 'password';
            if (secret.button) {
                secret.button.textContent = (visible ? 'Ocultar ' : 'Exibir ') + (secret.webhook ? 'token' : 'chave');
                secret.button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            }
        }

        function maskSecrets() {
            secrets.forEach(function (secret) { setSecretVisible(secret, false); });
        }

        ['api_key', 'api_key_sandbox', 'webhook_secret'].forEach(function (name) {
            var input = panel.querySelector('input[name="' + prefix + name + ']"]');
            if (!input) {
                return;
            }
            var webhook = name === 'webhook_secret';
            var button = panel.querySelector(webhook
                ? '#asaas-webhook-settings [data-asaas-webhook-action="toggle"]'
                : '[data-asaas-secret-toggle="' + name + '"]');
            if (!button && !webhook) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-default btn-sm mtop5';
                button.setAttribute('data-asaas-secret-toggle', name);
                (input.closest('.form-group') || input.parentElement).appendChild(button);
            }
            if (!input.id) {
                input.id = 'asaas-secret-' + name;
            }
            input.setAttribute('autocomplete', 'new-password');
            input.setAttribute('spellcheck', 'false');
            if (button) {
                button.type = 'button';
                button.setAttribute('aria-controls', input.id);
            }
            var secret = {input: input, button: button, webhook: webhook};
            secrets.push(secret);
            // Webhook generation/copy/toggle remain owned by the existing webhook handler.
            if (button && !webhook) {
                button.addEventListener('click', function () { setSecretVisible(secret, input.type === 'password'); });
            }
        });
        maskSecrets();

        var environmentInputs = panel.querySelectorAll('input[name="' + prefix + 'sandbox]"]');
        Array.prototype.forEach.call(environmentInputs, function (input) {
            var labelText = input.value === '1' ? 'Homologação' : (input.value === '0' ? 'Produção' : '');
            if (!labelText) {
                return;
            }
            Array.prototype.forEach.call(panel.querySelectorAll('label[for]'), function (label) {
                if (label.getAttribute('for') === input.id) {
                    label.textContent = labelText;
                }
            });
            input.required = true;
            input.addEventListener('change', function () {
                if (input.checked) {
                    Array.prototype.forEach.call(environmentInputs, function (other) {
                        if (other !== input) { other.checked = false; }
                    });
                }
            });
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { maskSecrets(); }
        });
        window.addEventListener('pagehide', maskSecrets);
        window.addEventListener('pageshow', maskSecrets);
        document.addEventListener('click', function (event) {
            var tab = event.target.closest && event.target.closest('a[data-toggle="tab"]');
            if (tab && (!layout || !layout.contains(tab))) { maskSecrets(); }
        }, true);

        var form = panel.closest('form');
        if (form) {
            form.addEventListener('submit', maskSecrets, true);
            form.addEventListener('reset', function () {
                maskSecrets();
                window.setTimeout(validateCardSettings, 0);
            });
        }
        if (!layout || !panel.contains(layout)) {
            return;
        }

        var tabs = Array.prototype.slice.call(layout.querySelectorAll('[data-asaas-settings-group]'));
        var panes = Object.create(null);
        Array.prototype.forEach.call(layout.querySelectorAll('[data-asaas-settings-panel]'), function (pane) {
            panes[pane.getAttribute('data-asaas-settings-panel')] = pane;
        });
        if (!panes.general || tabs.length === 0) {
            return;
        }

        var chargeTabs = Array.prototype.slice.call(layout.querySelectorAll('[data-asaas-charges-group]'));
        var chargePanes = Object.create(null);
        var cardSections = Object.create(null);
        Array.prototype.forEach.call(layout.querySelectorAll('[data-asaas-charges-panel]'), function (pane) {
            chargePanes[pane.getAttribute('data-asaas-charges-panel')] = pane;
        });
        Array.prototype.forEach.call(layout.querySelectorAll('[data-asaas-card-section]'), function (section) {
            cardSections[section.getAttribute('data-asaas-card-section')] = section;
        });
        var chargeLayout = chargeTabs.length === 2 && chargePanes.general && chargePanes.card
            && cardSections.installments && cardSections.fees && cardSections.rates && cardSections.anticipation;

        // Move the real controls and their following help blocks, never duplicate a form field.
        var roots = new Map();
        var compatible = true;
        Array.prototype.forEach.call(panel.querySelectorAll('input[name], textarea[name], select[name]'), function (input) {
            if (layout.contains(input)) { return; }
            var match = /^settings\[paymentmethod_asaas_([^\]]+)\]$/.exec(input.name);
            if (!match) { return; }
            var group = Object.prototype.hasOwnProperty.call(groups, match[1]) ? groups[match[1]] : 'general';
            var destination = group === 'charges' && chargeLayout
                ? (Object.prototype.hasOwnProperty.call(cardSettings, match[1]) ? match[1] : 'charges-general') : group;
            var root = input;
            while (root.parentElement && root.parentElement !== panel) { root = root.parentElement; }
            if (root.parentElement !== panel || !panes[group] || (roots.has(root) && roots.get(root).destination !== destination)) {
                compatible = false;
                return;
            }
            roots.set(root, {group: group, destination: destination});
        });
        // An unrecognized core renderer keeps its working flat form instead of hiding controls.
        if (!compatible || roots.size === 0) {
            return;
        }
        var children = Array.prototype.slice.call(panel.childNodes);
        var target = null;
        children.forEach(function (node) {
            if (node === layout) { return; }
            if (roots.has(node)) {
                var descriptor = roots.get(node);
                target = panes[descriptor.group];
                if (descriptor.destination === 'charges-general') {
                    target = chargePanes.general;
                } else if (Object.prototype.hasOwnProperty.call(cardSettings, descriptor.destination)) {
                    target = document.createElement('div');
                    target.className = 'asaas-card-setting';
                    cardSections[cardSettings[descriptor.destination]].appendChild(target);
                }
            }
            if (target) { target.appendChild(node); }
        });
        // The native renderer places its opening separator after the pre-render hook.
        var separator = layout.nextElementSibling;
        if (separator && separator.tagName === 'HR') { panel.insertBefore(separator, layout); }

        // Perfex guarantees input/yes_no/textarea settings. Enhance new enum inputs
        // without replacing their nodes or adding another named form control.
        var enumSelectors = [];
        [
            ['card_fee_mode', [['absorb', 'Absorver as taxas'], ['customer', 'Repassar as taxas ao cliente']]],
            ['card_fee_fixed_mode', [['charge', 'Uma vez por cobrança'], ['installment', 'Em cada parcela']]],
            ['card_anticipation_mode', [['none', 'Não incluir antecipação'], ['manual', 'Avulsa'], ['automatic', 'Automática']]]
        ].forEach(function (definition) {
            var input = panel.querySelector('input[name="' + prefix + definition[0] + ']"]');
            if (!input || !input.parentElement) { return; }
            var select = document.createElement('select');
            var initialValue = input.defaultValue;
            select.id = 'asaas-card-setting-' + definition[0];
            select.className = 'form-control';
            select.required = true;
            select.disabled = input.disabled;
            select.setAttribute('data-asaas-card-enum', definition[0]);
            [['', 'Selecione uma opção']].concat(definition[1]).forEach(function (choice) {
                var option = document.createElement('option');
                option.value = choice[0];
                option.textContent = choice[1];
                select.appendChild(option);
            });
            select.value = input.value;
            Array.prototype.forEach.call(panel.querySelectorAll('label[for]'), function (label) {
                if (label.getAttribute('for') === input.id) { label.htmlFor = select.id; }
            });
            input.insertAdjacentElement('afterend', select);
            input.type = 'hidden';
            select.addEventListener('change', function () {
                input.value = select.value;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
            });
            var fallback = panel.querySelector('[data-asaas-card-enum-fallback="' + definition[0] + '"]');
            if (fallback) { fallback.hidden = true; }
            enumSelectors.push({input: input, select: select, initialValue: initialValue});
        });
        if (form) {
            form.addEventListener('reset', function () {
                window.setTimeout(function () {
                    enumSelectors.forEach(function (field) {
                        field.input.value = field.initialValue;
                        field.select.value = field.input.value;
                    });
                }, 0);
            });
        }

        var navigation = layout.querySelector('.asaas-settings-navigation');
        var scrollArea = layout.querySelector('.asaas-settings-tab-scroll');
        var scrollButtons = layout.querySelectorAll('[data-asaas-settings-scroll]');

        function updateScrollControls() {
            if (!scrollArea || !navigation) { return; }
            var overflow = scrollArea.clientWidth > 0 && scrollArea.scrollWidth > scrollArea.clientWidth + 2;
            navigation.classList.toggle('asaas-settings-overflow', overflow);
            Array.prototype.forEach.call(scrollButtons, function (button) {
                button.hidden = !overflow;
                var backward = button.getAttribute('data-asaas-settings-scroll') === 'backward';
                button.disabled = backward ? scrollArea.scrollLeft <= 1
                    : scrollArea.scrollLeft + scrollArea.clientWidth >= scrollArea.scrollWidth - 1;
            });
        }

        function showOuterTab() {
            var link = document.querySelector('a[href="#online_payments_asaas_tab"][data-toggle="tab"]');
            if (!link) { return; }
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.tab === 'function') {
                window.jQuery(link).tab('show');
                return;
            }
            // Match the native Bootstrap classes when its JavaScript plugin is unavailable.
            Array.prototype.forEach.call(panel.parentElement.children, function (sibling) {
                if (sibling.classList.contains('tab-pane')) { sibling.classList.toggle('active', sibling === panel); }
            });
            var list = link.closest('ul');
            if (list) {
                Array.prototype.forEach.call(list.children, function (item) { item.classList.toggle('active', item === link.parentElement); });
            }
        }

        function activateGroup(group, focusTab, openOuter) {
            if (!panes[group]) { return false; }
            maskSecrets();
            if (openOuter) { showOuterTab(); }
            tabs.forEach(function (tab) {
                var selected = tab.getAttribute('data-asaas-settings-group') === group;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.setAttribute('tabindex', selected ? '0' : '-1');
                tab.parentElement.classList.toggle('active', selected);
                if (selected && focusTab) {
                    tab.focus();
                    if (typeof tab.scrollIntoView === 'function') { tab.scrollIntoView({block: 'nearest', inline: 'nearest'}); }
                }
            });
            Object.keys(panes).forEach(function (name) {
                panes[name].classList.toggle('active', name === group);
                panes[name].hidden = name !== group;
            });
            updateScrollControls();
            layout.dispatchEvent(new CustomEvent('asaas:settings:shown', {bubbles: true, detail: {group: group}}));
            return true;
        }

        function activateChargesGroup(group, focusTab) {
            if (!chargeLayout || !chargePanes[group]) { return; }
            chargeTabs.forEach(function (tab) {
                var selected = tab.getAttribute('data-asaas-charges-group') === group;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.setAttribute('tabindex', selected ? '0' : '-1');
                tab.parentElement.classList.toggle('active', selected);
                if (selected && focusTab) {
                    tab.focus();
                    if (typeof tab.scrollIntoView === 'function') { tab.scrollIntoView({block: 'nearest', inline: 'nearest'}); }
                }
            });
            Object.keys(chargePanes).forEach(function (name) {
                chargePanes[name].hidden = name !== group;
                chargePanes[name].classList.toggle('active', name === group);
            });
        }

        chargeTabs.forEach(function (tab, index) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                activateChargesGroup(tab.getAttribute('data-asaas-charges-group'), false);
            });
            tab.addEventListener('keydown', function (event) {
                var targetIndex;
                if (event.key === 'ArrowRight') { targetIndex = (index + 1) % chargeTabs.length; }
                else if (event.key === 'ArrowLeft') { targetIndex = (index + chargeTabs.length - 1) % chargeTabs.length; }
                else if (event.key === 'Home') { targetIndex = 0; }
                else if (event.key === 'End') { targetIndex = chargeTabs.length - 1; }
                else if (event.key === ' ' || event.key === 'Enter') { targetIndex = index; }
                else { return; }
                event.preventDefault();
                event.stopPropagation();
                activateChargesGroup(chargeTabs[targetIndex].getAttribute('data-asaas-charges-group'), true);
            });
        });

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation(); // Do not let the parent Bootstrap tab set consume the nested tab.
                activateGroup(tab.getAttribute('data-asaas-settings-group'), false, false);
            });
            tab.addEventListener('keydown', function (event) {
                var targetIndex;
                if (event.key === 'ArrowRight') { targetIndex = (index + 1) % tabs.length; }
                else if (event.key === 'ArrowLeft') { targetIndex = (index + tabs.length - 1) % tabs.length; }
                else if (event.key === 'Home') { targetIndex = 0; }
                else if (event.key === 'End') { targetIndex = tabs.length - 1; }
                else if (event.key === ' ' || event.key === 'Enter') { targetIndex = index; }
                else { return; }
                event.preventDefault();
                event.stopPropagation();
                activateGroup(tabs[targetIndex].getAttribute('data-asaas-settings-group'), true, false);
            });
        });

        document.addEventListener('asaas:settings:show', function (event) {
            if (event.detail && typeof event.detail.group === 'string') {
                activateGroup(event.detail.group, false, true);
                if (event.detail.group === 'charges' && typeof event.detail.chargesGroup === 'string') {
                    activateChargesGroup(event.detail.chargesGroup, false);
                }
            }
        });
        var firstInvalid = null;
        if (form) {
            form.addEventListener('invalid', function (event) {
                var pane = event.target.closest && event.target.closest('[data-asaas-settings-panel]');
                if (!pane || !layout.contains(pane)) { return; }
                if (firstInvalid) {
                    // Keep the browser's focus/validation message on the first visible error.
                    event.preventDefault();
                    return;
                }
                firstInvalid = event.target;
                activateGroup(pane.getAttribute('data-asaas-settings-panel'), false, true);
                var chargePane = event.target.closest('[data-asaas-charges-panel]');
                if (chargePane) { activateChargesGroup(chargePane.getAttribute('data-asaas-charges-panel'), false); }
                window.setTimeout(function () { firstInvalid = null; }, 0);
            }, true);
        }
        Array.prototype.forEach.call(scrollButtons, function (button) {
            button.addEventListener('click', function () {
                var direction = button.getAttribute('data-asaas-settings-scroll') === 'backward' ? -1 : 1;
                var distance = direction * Math.max(160, scrollArea.clientWidth * .75);
                if (typeof scrollArea.scrollBy === 'function') { scrollArea.scrollBy({left: distance, behavior: 'smooth'}); }
                else { scrollArea.scrollLeft += distance; }
                updateScrollControls();
            });
        });
        if (scrollArea) { scrollArea.addEventListener('scroll', updateScrollControls); }
        window.addEventListener('resize', updateScrollControls);
        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(function () {
                if (!panel.classList.contains('active') || panel.hidden) { maskSecrets(); }
                updateScrollControls();
            }).observe(panel, {attributes: true, attributeFilter: ['class', 'hidden', 'style']});
        }

        layout.hidden = false;
        layout.classList.remove('hide');
        activateGroup('general', false, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAsaasSettings);
    } else {
        initializeAsaasSettings();
    }
}());
