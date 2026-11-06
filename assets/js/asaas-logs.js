(function () {
    'use strict';

    function initialize() {
        var root = document.getElementById('asaas-logs');
        if (!root || root.getAttribute('data-asaas-logs-initialized') === '1') { return; }
        root.setAttribute('data-asaas-logs-initialized', '1');

        var pane = root.closest('[data-asaas-settings-panel="logs"]');
        var layout = root.closest('#asaas-settings-groups');
        var outer = root.closest('#online_payments_asaas_tab');
        var form = root.closest('form');
        var csrf = {name: root.getAttribute('data-csrf-name') || '', hash: root.getAttribute('data-csrf-hash') || ''};
        var feedback = root.querySelector('[data-logs-feedback]');
        var content = root.querySelector('[data-logs-content]');
        var refreshButton = root.querySelector('[data-logs-action="refresh"]');
        var body = root.querySelector('[data-logs-rows]');
        var loaded = false;
        var loading = false;
        var pending = false;
        var endpoint;

        function notify(message, isError) {
            feedback.textContent = message;
            feedback.className = isError ? 'alert alert-danger' : 'alert alert-info';
            feedback.hidden = !message;
        }
        function text(value) {
            return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
                ? String(value) : '';
        }
        function node(tag, value, className) {
            var element = document.createElement(tag);
            if (value !== undefined) { element.textContent = text(value); }
            if (className) { element.className = className; }
            return element;
        }
        function clear(element) {
            while (element.firstChild) { element.removeChild(element.firstChild); }
        }
        function field(selector, value) {
            root.querySelector(selector).textContent = text(value);
        }
        function compatibleLayout() {
            return pane && layout && !layout.hidden && !layout.classList.contains('hide');
        }
        function visible() {
            return compatibleLayout() && !pane.hidden && (!outer || outer.classList.contains('active'));
        }
        function monitorBusy() {
            return form && form.getAttribute('data-asaas-monitor-busy') === '1';
        }
        function validCsrf(value) {
            return value && typeof value.name === 'string' && /^[A-Za-z_][A-Za-z0-9_-]{0,127}$/.test(value.name)
                && typeof value.hash === 'string' && value.hash.length > 0 && value.hash.length <= 512 && !/[\s\x00-\x1f\x7f]/.test(value.hash);
        }
        function renewCsrf(value) {
            if (!form || !validCsrf(csrf) || !validCsrf(value) || value.name !== csrf.name) { return false; }
            var input = form.querySelector('input[type="hidden"][name="' + csrf.name + '"]');
            if (!input || input.type !== 'hidden') { return false; }
            // Only update the native CSRF input that existed in this form; never create or rename settings.
            input.value = value.hash;
            csrf = {name: value.name, hash: value.hash};
            form.removeAttribute('data-asaas-logs-csrf-uncertain');
            document.dispatchEvent(new CustomEvent('asaas:settings:csrf-renewed', {detail: {source: 'logs'}}));
            return true;
        }
        function publicError(message) {
            var error = new Error(message);
            error.asaasLogsMessage = message;
            return error;
        }

        try {
            var address = root.getAttribute('data-endpoint');
            if (!address) { throw new Error('Missing endpoint.'); }
            endpoint = new URL(address, window.location.href);
            if (endpoint.origin !== window.location.origin || !/^https?:$/.test(endpoint.protocol)) {
                throw new Error('Invalid endpoint.');
            }
        } catch (error) {
            notify('Não foi possível preparar o visualizador de logs. Atualize a página ou utilize o visualizador completo.', true);
            refreshButton.disabled = true;
            return;
        }

        // The original settings renderer appends its controls after the panel markup.
        // Keep their order and show the read-only report below them.
        if (pane) { pane.appendChild(root); }
        if (compatibleLayout()) {
            Array.prototype.forEach.call(pane.querySelectorAll('[data-asaas-logs-open]'), function (link) {
                link.hidden = true;
                if (link.parentElement && link.parentElement.tagName === 'P' && link.parentElement.children.length === 1) {
                    link.parentElement.hidden = true;
                }
            });
        }

        function validReport(report) {
            return report && typeof report === 'object' && !Array.isArray(report)
                && ['ok', 'missing', 'unavailable'].indexOf(report.database_state) !== -1
                && ['database', 'file', 'none'].indexOf(report.source) !== -1
                && typeof report.file_enabled === 'boolean'
                && typeof report.file_exists === 'boolean'
                && typeof report.directory_writable === 'boolean'
                && (report.file_path === null || typeof report.file_path === 'string')
                && Array.isArray(report.rows) && report.rows.length <= 100
                && report.rows.every(function (row) {
                    return row && typeof row === 'object' && !Array.isArray(row)
                        && typeof row.message === 'string' && typeof row.level === 'string'
                        && row.context && typeof row.context === 'object';
                });
        }

        function render(report) {
            var warning = root.querySelector('[data-logs-database]');
            warning.hidden = report.database_state === 'ok';
            warning.textContent = report.database_state === 'missing'
                ? 'A tabela de logs do módulo ainda não está disponível. Verifique se a atualização das migrations do módulo foi concluída.'
                : 'Não foi possível consultar os logs no banco. Verifique as permissões de leitura e escrita e a atualização das migrations do módulo.';
            if (!warning.hidden) {
                warning.textContent += ' Uma falha no armazenamento do diagnóstico não deve interromper a cobrança. Erros e falhas de armazenamento também são encaminhados ao log de erros do PHP.';
            }
            field('[data-logs-file-enabled]', report.file_enabled ? 'habilitado' : 'desabilitado');
            var hasPath = typeof report.file_path === 'string' && report.file_path !== '';
            root.querySelector('[data-logs-file-details]').hidden = !hasPath;
            root.querySelector('[data-logs-path-unavailable]').hidden = hasPath;
            field('[data-logs-file-path]', hasPath ? report.file_path : '');
            field('[data-logs-file-exists]', report.file_exists
                ? 'O arquivo do dia já existe.' : 'O arquivo será criado no primeiro registro com a opção habilitada.');
            field('[data-logs-directory-writable]', report.directory_writable
                ? 'O diretório permite escrita.'
                : 'O diretório não existe ou não permite escrita pelo PHP; solicite a correção da permissão ao administrador do servidor.');
            var source = root.querySelector('[data-logs-source]');
            source.hidden = report.source !== 'file';
            source.textContent = 'Exibindo os registros mais recentes do arquivo Asaas do dia, pois a consulta ao banco está indisponível. A leitura é limitada aos últimos 256 KiB e a 100 registros.';
            field('[data-logs-count]', report.rows.length + ' registro(s) exibido(s)'
                + (report.source === 'database' ? ' · Origem: banco de dados.' : (report.source === 'file' ? ' · Origem: arquivo do dia.' : '.')));
            root.querySelector('[data-logs-empty]').hidden = report.rows.length !== 0;
            root.querySelector('[data-logs-table]').hidden = report.rows.length === 0;
            clear(body);
            report.rows.forEach(function (record) {
                var row = node('tr');
                row.appendChild(node('td', record.created_at));
                var level = node('td');
                var levelName = record.level.toLowerCase();
                var labels = {error: 'Erro', warning: 'Aviso', info: 'Informação', debug: 'Diagnóstico'};
                var colors = {error: 'danger', warning: 'warning', info: 'info', debug: 'default'};
                level.appendChild(node('span', Object.prototype.hasOwnProperty.call(labels, levelName) ? labels[levelName] : record.level,
                    'label label-' + (Object.prototype.hasOwnProperty.call(colors, levelName) ? colors[levelName] : 'default')));
                row.appendChild(level);
                var message = node('td', undefined, 'asaas-logs-message');
                message.appendChild(node('p', record.message));
                if (Object.keys(record.context).length > 0) {
                    var details = node('details');
                    details.appendChild(node('summary', 'Ver detalhes do diagnóstico'));
                    details.appendChild(node('pre', JSON.stringify(record.context, null, 2)));
                    message.appendChild(details);
                }
                row.appendChild(message);
                row.appendChild(node('td', record.correlation_id));
                body.appendChild(row);
            });
            content.hidden = false;
        }

        async function refresh() {
            if (loading) { return; }
            if (monitorBusy()) {
                pending = true;
                refreshButton.disabled = true;
                notify('Aguarde a operação do Monitoramento. Os logs serão consultados quando ela terminar, se esta guia continuar aberta.', false);
                return;
            }
            pending = false;
            loading = true;
            if (form) {
                form.setAttribute('data-asaas-logs-loading', '1');
                // GET can refresh the framework's CSRF cookie. Until JSON is verified, its new hash is unknown.
                form.setAttribute('data-asaas-logs-csrf-uncertain', '1');
            }
            document.dispatchEvent(new CustomEvent('asaas:logs:busy'));
            root.setAttribute('aria-busy', 'true');
            refreshButton.disabled = true;
            content.hidden = true;
            notify('Consultando os registros locais da integração...', false);
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timer;
            try {
                var response = await Promise.race([
                    window.fetch(endpoint.href, {method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        signal: controller ? controller.signal : undefined}),
                    new Promise(function (resolve, reject) {
                        timer = window.setTimeout(function () {
                            if (controller) { controller.abort(); }
                            reject(publicError('A consulta aos logs demorou além do esperado. Atualize os registros para tentar novamente.'));
                        }, 15000);
                    })
                ]);
                if (!/application\/json/i.test(response.headers.get('content-type') || '')) {
                    throw publicError('Não foi possível consultar os logs. Atualize a página para conferir sua sessão e tente novamente.');
                }
                var payload = await response.json();
                if (!payload || typeof payload !== 'object' || Array.isArray(payload) || !renewCsrf(payload.csrf)) {
                    throw publicError('Não foi possível renovar a proteção do formulário. Atualize os registros de log ou recarregue a página antes de salvar.');
                }
                if (response.status === 401 || response.status === 403) {
                    throw publicError('Sua sessão não permite consultar os logs. Atualize a página e confirme o acesso de administrador.');
                }
                if (!response.ok || payload.ok !== true) {
                    throw publicError('Não foi possível consultar os logs. Atualize os registros para tentar novamente.');
                }
                if (!validReport(payload.report)) {
                    throw publicError('O diagnóstico retornou dados incompletos. Atualize os registros para tentar novamente.');
                }
                render(payload.report);
                loaded = true;
                notify('', false);
            } catch (error) {
                loaded = false;
                content.hidden = true;
                var message = error && error.name === 'AbortError'
                    ? 'A consulta aos logs demorou além do esperado. Atualize os registros para tentar novamente.'
                    : (error && error.asaasLogsMessage ? error.asaasLogsMessage : 'Não foi possível consultar os logs. Atualize os registros para tentar novamente.');
                if (form && form.getAttribute('data-asaas-logs-csrf-uncertain') === '1') {
                    message += ' Antes de salvar as configurações ou executar outra operação, atualize os registros ou recarregue a página para renovar a proteção do formulário.';
                }
                notify(message, true);
            } finally {
                window.clearTimeout(timer);
                loading = false;
                root.setAttribute('aria-busy', 'false');
                refreshButton.disabled = false;
                if (form) { form.removeAttribute('data-asaas-logs-loading'); }
                document.dispatchEvent(new CustomEvent('asaas:logs:idle'));
            }
        }

        refreshButton.addEventListener('click', function () { refresh(); });
        document.addEventListener('asaas:settings:shown', function (event) {
            if (event.detail && event.detail.group === 'logs' && visible() && (!loaded || pending)) { refresh(); }
        });
        document.addEventListener('asaas:monitor:idle', function () {
            if (monitorBusy() || loading) { return; }
            refreshButton.disabled = false;
            if (pending && visible()) { refresh(); }
        });
        if (form) {
            form.addEventListener('submit', function (event) {
                if (!loading && form.getAttribute('data-asaas-logs-csrf-uncertain') !== '1') { return; }
                event.preventDefault();
                event.stopImmediatePropagation();
                document.dispatchEvent(new CustomEvent('asaas:settings:show', {detail: {group: 'logs'}}));
                notify(loading ? 'Aguarde a consulta aos logs terminar antes de salvar as configurações.'
                    : 'Atualize os registros de log ou recarregue a página para renovar a proteção do formulário antes de salvar.', true);
                if (typeof feedback.scrollIntoView === 'function') { feedback.scrollIntoView({block: 'nearest'}); }
            }, true);
        }
        if (visible()) { refresh(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
}());
