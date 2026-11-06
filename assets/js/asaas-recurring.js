(function () {
    'use strict';

    var root = document.getElementById('asaas-recurring');
    if (!root) { return; }
    var forms = Array.prototype.slice.call(root.querySelectorAll('[data-asaas-recurring-form]'));
    if (forms.length === 0) { return; }
    var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-recurring-submit]'));
    var status = document.getElementById('asaas-recurring-status');
    var consent = document.getElementById('recurring-consent');
    var submitting = false;
    var slowTimer = null;
    var cardFields = ['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode'];
    var holderFields = ['holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'];

    function clearFields(names) {
        names.forEach(function (name) {
            var field = document.getElementById('recurring-' + name);
            if (field) { field.value = ''; field.defaultValue = ''; }
        });
    }

    function clearConsent() {
        if (consent) { consent.checked = false; consent.defaultChecked = false; }
    }

    function showStatus(message) {
        if (!status) { return; }
        status.textContent = message;
        status.classList.remove('hidden');
    }

    function lockForms() {
        forms.forEach(function (form) { form.setAttribute('aria-busy', 'true'); });
        buttons.forEach(function (button) { button.disabled = true; });
    }

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (submitting) { event.preventDefault(); return; }
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }
            var revoke = form.getAttribute('data-asaas-recurring-form') === 'revoke';
            submitting = true;
            lockForms();
            // A revocation form never submits card credentials from the enrollment form.
            if (revoke) { clearFields(cardFields.concat(holderFields)); clearConsent(); }
            var button = form.querySelector('[data-recurring-submit]');
            if (button) { button.textContent = revoke ? 'Desativando recorrência...' : 'Autorizando e processando...'; }
            showStatus(revoke ? 'Desativando as cobranças futuras. Aguarde a confirmação.'
                : 'Enviando a autorização e o pagamento desta fatura. Aguarde a confirmação antes de tentar novamente.');
            slowTimer = window.setTimeout(function () {
                showStatus('A confirmação pode levar mais de um minuto. Aguarde o retorno; a solicitação não será repetida automaticamente.');
            }, 60000);
            // Keep the first native POST intact; do not copy data to storage or an extra HTTP request.
        });
    });

    window.addEventListener('pagehide', function () {
        // Native form data is serialized before navigation. Clear the departing DOM/history.
        clearFields(cardFields.concat(holderFields));
        clearConsent();
        window.clearTimeout(slowTimer);
    });

    window.addEventListener('pageshow', function (event) {
        clearFields(cardFields);
        clearConsent();
        if (!event.persisted) {
            holderFields.forEach(function (name) {
                var field = document.getElementById('recurring-' + name);
                if (field) { field.value = field.defaultValue; }
            });
            return;
        }
        window.clearTimeout(slowTimer);
        clearFields(holderFields);
        submitting = true;
        lockForms();
        showStatus('Atualizando a autorização e a situação da fatura. Aguarde.');
        // A fresh GET checks current state; never forward query data restored by the browser.
        window.location.replace(window.location.href.split(/[?#]/)[0]);
    });
}());
