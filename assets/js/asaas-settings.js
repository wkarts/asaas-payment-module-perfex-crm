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
            billet_only: 'payments', card_only: 'payments', pix_only: 'payments', installmentCount: 'payments',
            interest_value: 'charges', fine_value: 'charges', discount_type: 'charges', discount_value: 'charges',
            discount_days: 'charges', diasdevencimento: 'charges', disable_charge_notification: 'charges',
            webhook_secret: 'webhook',
            sync_enabled: 'sync', sync_days: 'sync', sync_limit: 'sync', sync_invoice_lifecycle: 'sync',
            manual_payment_sync: 'sync', manual_payment_receive_in_cash: 'sync', delete_charge: 'sync',
            update_charge: 'sync', ultima_atualizacao_servicos_municipais: 'sync',
            debug: 'logs', file_log_enabled: 'logs'
        };

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
            form.addEventListener('reset', maskSecrets);
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

        // Move the real controls and their following help blocks, never duplicate a form field.
        var roots = new Map();
        var compatible = true;
        Array.prototype.forEach.call(panel.querySelectorAll('input[name], textarea[name], select[name]'), function (input) {
            if (layout.contains(input)) { return; }
            var match = /^settings\[paymentmethod_asaas_([^\]]+)\]$/.exec(input.name);
            if (!match) { return; }
            var group = Object.prototype.hasOwnProperty.call(groups, match[1]) ? groups[match[1]] : 'general';
            var root = input;
            while (root.parentElement && root.parentElement !== panel) { root = root.parentElement; }
            if (root.parentElement !== panel || !panes[group] || (roots.has(root) && roots.get(root) !== group)) {
                compatible = false;
                return;
            }
            roots.set(root, group);
        });
        // An unrecognized core renderer keeps its working flat form instead of hiding controls.
        if (!compatible || roots.size === 0) {
            return;
        }
        var children = Array.prototype.slice.call(panel.childNodes);
        var target = null;
        children.forEach(function (node) {
            if (node === layout) { return; }
            if (roots.has(node)) { target = panes[roots.get(node)]; }
            if (target) { target.appendChild(node); }
        });
        // The native renderer places its opening separator after the pre-render hook.
        var separator = layout.nextElementSibling;
        if (separator && separator.tagName === 'HR') { panel.insertBefore(separator, layout); }

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
