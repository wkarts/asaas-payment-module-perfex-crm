(function () {
    'use strict';

    var form = document.getElementById('asaas-card-checkout');
    if (!form) {
        return;
    }

    var button = document.getElementById('payment');
    var status = document.getElementById('asaas-card-checkout-status');
    var submitting = false;
    var slowTimer = null;
    var cardFields = ['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode'];
    var holderFields = ['holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'];
    var installments = document.getElementById('installmentCount');
    var summary = document.getElementById('asaas-card-price-summary');

    function showPrice() {
        if (!installments || !summary || submitting) { return; }
        var option = installments.options[installments.selectedIndex];
        if (!option) { return; }
        ['principal', 'fee', 'total'].forEach(function (name) {
            var output = summary.querySelector('[data-card-price-' + name + ']');
            var value = option.getAttribute('data-card-' + name);
            if (output && value && /^\d+\.\d{2}$/.test(value)) {
                output.textContent = Number(value).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
            }
        });
        var schedule = summary.querySelector('[data-card-price-schedule]');
        if (schedule) { schedule.textContent = option.getAttribute('data-card-schedule') || ''; }
        summary.hidden = false;
    }
    if (installments && summary) {
        installments.addEventListener('change', showPrice);
        showPrice();
    }

    function clearFields(ids) {
        ids.forEach(function (id) {
            var field = document.getElementById(id);
            if (field) {
                field.value = '';
                field.defaultValue = '';
            }
        });
    }

    function showStatus(message) {
        status.textContent = message;
        status.classList.remove('hidden');
    }

    form.addEventListener('submit', function (event) {
        if (submitting) {
            event.preventDefault();
            return;
        }

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        submitting = true;
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        button.textContent = 'Processando pagamento...';
        showStatus('Enviando o pagamento. Aguarde a confirmação antes de tentar novamente.');

        slowTimer = window.setTimeout(function () {
            showStatus('A confirmação pode levar mais de um minuto. Aguarde o retorno e não envie o pagamento novamente enquanto ele estiver sendo processado.');
        }, 60000);
    });

    window.addEventListener('pagehide', function () {
        // Navigation happens after the browser has serialized a native form POST.
        // Clear the departing DOM before it can be restored from page history.
        clearFields(cardFields.concat(holderFields));
        window.clearTimeout(slowTimer);
    });

    window.addEventListener('pageshow', function (event) {
        clearFields(cardFields);
        if (!event.persisted) {
            // Keep only defaults rendered from the existing customer profile.
            // Do not reuse cardholder values restored by the browser.
            holderFields.forEach(function (id) {
                var field = document.getElementById(id);
                if (field) {
                    field.value = field.defaultValue;
                }
            });
            return;
        }

        // A fresh GET lets the server verify the charge before allowing another attempt.
        window.clearTimeout(slowTimer);
        clearFields(holderFields);
        submitting = true;
        button.disabled = true;
        button.textContent = 'Atualizando pagamento...';
        showStatus('Atualizando a situação do pagamento. Aguarde.');
        window.location.replace(window.location.href.split('#')[0]);
    });
}());
