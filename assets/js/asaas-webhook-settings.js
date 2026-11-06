(function () {
    'use strict';

    function initializeWebhookSettings() {
        var tokenInput = document.querySelector('input[name="settings[paymentmethod_asaas_webhook_secret]"]');
        var panel = document.getElementById('asaas-webhook-settings');
        if (!tokenInput || !panel || panel.getAttribute('data-initialized') === '1') {
            return;
        }

        panel.setAttribute('data-initialized', '1');
        var initialToken = tokenInput.value;
        var urlInput = document.getElementById('asaas-webhook-url');
        var feedback = document.getElementById('asaas-webhook-feedback');
        var securityState = document.getElementById('asaas-webhook-security-state');
        var toggleButton = panel.querySelector('[data-asaas-webhook-action="toggle"]');

        function tokenError(value, preserveCurrent) {
            if (value === '' || (preserveCurrent && value === initialToken)) {
                return '';
            }

            var length = Array.from(value).length;
            if (length < 32 || length > 255 || /[\s\p{Z}\p{C}]/u.test(value)) {
                return 'O novo token deve ter de 32 a 255 caracteres, sem espaços ou caracteres de controle.';
            }

            var apiKeyInputs = document.querySelectorAll('input[name="settings[paymentmethod_asaas_api_key]"], input[name="settings[paymentmethod_asaas_api_key_sandbox]"]');
            var isApiKey = value.toLowerCase().indexOf('$aact_') === 0;
            Array.prototype.forEach.call(apiKeyInputs, function (input) {
                isApiKey = isApiKey || (input.value !== '' && input.value === value);
            });

            return isApiKey ? 'Use um token exclusivo para o webhook, diferente da API Key do Asaas.' : '';
        }

        function showFeedback(message, isError) {
            feedback.textContent = message;
            feedback.className = isError ? 'text-danger mtop10' : 'text-success mtop10';
        }

        function updateSecurityState() {
            var value = tokenInput.value;
            securityState.className = 'text-warning mtop10';
            if (value === '') {
                securityState.textContent = initialToken === ''
                    ? 'Autenticação por token desativada: o campo está vazio. Informe ou gere um token e configure o mesmo valor no Asaas.'
                    : 'Ao salvar o campo vazio, a autenticação por token será desativada. O token atual permanece ativo até salvar.';
            } else if (value !== initialToken) {
                securityState.textContent = 'Alteração pendente: salve as configurações e configure o mesmo token no Asaas. Gerar ou editar o campo não salva automaticamente.';
            } else if (tokenError(value, false)) {
                securityState.textContent = 'Token legado preservado. Ao substituí-lo, use um token exclusivo de 32 a 255 caracteres, sem espaços.';
            } else {
                securityState.className = 'text-muted mtop10';
                securityState.textContent = 'Token configurado no CRM. Confirme que o mesmo valor está cadastrado no webhook do Asaas.';
            }

            tokenInput.setAttribute('aria-invalid', tokenError(value, true) ? 'true' : 'false');
        }

        function setTokenVisible(visible) {
            tokenInput.type = visible ? 'text' : 'password';
            toggleButton.textContent = visible ? 'Ocultar token' : 'Exibir token';
            toggleButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
        }

        function fallbackCopy(value) {
            var previousFocus = document.activeElement;
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            var copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }
            document.body.removeChild(textarea);
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            return copied;
        }

        function copyValue(input, isToken) {
            var value = input.value;
            if (!value) {
                showFeedback('Informe ou gere um token antes de copiar.', true);
                return;
            }

            function finish(copied) {
                if (copied) {
                    showFeedback(isToken ? 'Token copiado. Use o mesmo valor no Asaas.' : 'URL do webhook copiada.', false);
                    return;
                }
                input.focus();
                input.select();
                showFeedback('Não foi possível copiar automaticamente. O conteúdo foi selecionado para cópia manual.', true);
            }

            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(value).then(function () {
                    finish(true);
                }, function () {
                    finish(fallbackCopy(value));
                });
            } else {
                finish(fallbackCopy(value));
            }
        }

        panel.querySelector('[data-asaas-webhook-action="generate"]').addEventListener('click', function () {
            if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
                showFeedback('A geração segura não está disponível neste navegador. Informe um token válido manualmente.', true);
                return;
            }

            try {
                var bytes = new Uint8Array(32);
                window.crypto.getRandomValues(bytes);
                tokenInput.value = Array.prototype.map.call(bytes, function (byte) {
                    return ('0' + byte.toString(16)).slice(-2);
                }).join('');
            } catch (error) {
                showFeedback('Não foi possível gerar um token seguro. Tente novamente ou informe um token válido manualmente.', true);
                return;
            }

            setTokenVisible(false);
            tokenInput.dispatchEvent(new Event('input', { bubbles: true }));
            showFeedback('Novo token gerado. Copie-o para o Asaas e salve as configurações do CRM.', false);
        });

        toggleButton.addEventListener('click', function () {
            setTokenVisible(tokenInput.type === 'password');
        });
        panel.querySelector('[data-asaas-webhook-action="copy-token"]').addEventListener('click', function () {
            copyValue(tokenInput, true);
        });
        panel.querySelector('[data-asaas-webhook-action="copy-url"]').addEventListener('click', function () {
            copyValue(urlInput, false);
        });
        tokenInput.addEventListener('input', function () {
            showFeedback('', false);
            updateSecurityState();
        });

        if (tokenInput.form) {
            tokenInput.form.addEventListener('submit', function (event) {
                var error = tokenError(tokenInput.value, true);
                if (!error) {
                    return;
                }
                event.preventDefault();
                event.stopImmediatePropagation();
                if (window.jQuery && window.jQuery.fn.tab) {
                    window.jQuery('a[href="#online_payments_asaas_tab"]').tab('show');
                }
                document.dispatchEvent(new CustomEvent('asaas:settings:show', { detail: { group: 'webhook' } }));
                showFeedback(error, true);
                tokenInput.setAttribute('aria-invalid', 'true');
                tokenInput.focus();
            }, true);
        }

        setTokenVisible(false);
        updateSecurityState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeWebhookSettings);
    } else {
        initializeWebhookSettings();
    }
}());
