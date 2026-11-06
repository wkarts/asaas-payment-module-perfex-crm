(function () {
    'use strict';

    var monitor = null;

    function initialize() {
        if (monitor) { return monitor; }
        var root = document.getElementById('asaas-monitor');
        if (!root) { return null; }

        var form = root.closest('form');
        var csrf = {name: root.getAttribute('data-csrf-name') || '', hash: root.getAttribute('data-csrf-hash') || ''};
        var state = {active: 'invoices', loading: false, running: false, stop: false, selected: new Set(), invoices: [], pages: {}};
        var deferredRefresh = null;
        ['invoices', 'events', 'logs'].forEach(function (kind) {
            state.pages[kind] = {page: 1, perPage: 25, hasMore: false, total: null};
        });
        var feedback = root.querySelector('[data-monitor-feedback]');
        var task = root.querySelector('[data-monitor-task]');
        var taskMessage = root.querySelector('[data-monitor-task-message]');
        var progress = root.querySelector('[data-monitor-progress]');
        var resultsBody = root.querySelector('[data-monitor-results]');
        var selectAll = root.querySelector('#asaas-monitor-select-all');
        var base;
        var invoiceBase;
        try {
            base = new URL(root.getAttribute('data-endpoint'), window.location.href);
            invoiceBase = new URL(root.getAttribute('data-invoice-base'), window.location.href);
            if (base.origin !== window.location.origin || invoiceBase.origin !== window.location.origin
                || !/^https?:$/.test(base.protocol) || !/^https?:$/.test(invoiceBase.protocol)) {
                throw new Error('Invalid monitor destination.');
            }
        } catch (error) {
            feedback.hidden = false;
            feedback.className = 'alert alert-danger';
            feedback.textContent = 'Não foi possível preparar o monitoramento. Atualize a página das configurações.';
            Array.prototype.forEach.call(root.querySelectorAll('button'), function (button) { button.disabled = true; });
            return null;
        }

        function text(value, fallback) {
            return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
                ? String(value).slice(0, 4000) : (fallback === undefined ? '—' : fallback);
        }
        function identifier(value) {
            var id = String(value === null || value === undefined ? '' : value);
            return /^[1-9][0-9]{0,9}$/.test(id) && Number(id) <= 2147483647 ? id : null;
        }
        function integer(value, fallback) {
            if (typeof value !== 'number' && !(typeof value === 'string' && /^[0-9]+$/.test(value))) { return fallback; }
            var number = Number(value);
            return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
        }
        function money(value) {
            if (value === null || value === undefined || value === '' || !/^-?[0-9]+(?:\.[0-9]+)?$/.test(String(value))) {
                return '—';
            }
            var number = Number(value);
            return Number.isFinite(number) ? new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(number) : '—';
        }
        function node(tag, value, className) {
            var element = document.createElement(tag);
            if (value !== undefined) { element.textContent = text(value, ''); }
            if (className) { element.className = className; }
            return element;
        }
        function clear(element) {
            while (element.firstChild) { element.removeChild(element.firstChild); }
        }
        function notify(message, error) {
            feedback.textContent = message;
            feedback.className = error ? 'alert alert-danger' : 'alert alert-info';
            feedback.hidden = !message;
        }
        function secondary(parent, value) {
            parent.appendChild(node('span', value, 'asaas-monitor-secondary'));
        }
        function cell(row, value, className) {
            var element = node('td', value, className);
            row.appendChild(element);
            return element;
        }
        function label(value, map) { return Object.prototype.hasOwnProperty.call(map, String(value)) ? map[String(value)] : text(value); }
        var crmLabels = {'1': 'Não pago', '2': 'Pago', '3': 'Parcial', '4': 'Vencido', '5': 'Cancelado', '6': 'Rascunho'};
        var paymentLabels = {
            PENDING: 'Pendente', RECEIVED: 'Recebido', CONFIRMED: 'Confirmado', OVERDUE: 'Vencido',
            RECEIVED_IN_CASH: 'Recebido em dinheiro', AUTHORIZED: 'Autorizado', REFUNDED: 'Estornado',
            DELETED: 'Excluído', DUNNING_REQUESTED: 'Negativação solicitada', AWAITING_RISK_ANALYSIS: 'Em análise'
        };
        var webhookLabels = {processed: 'Processado', error: 'Erro', received: 'Recebido / pendente', pending: 'Pendente', retry: 'Aguardando nova tentativa'};
        var resultLabels = {
            divergent: 'Divergência identificada', reconciled: 'Conciliada', already_reconciled: 'Já conciliada',
            pending: 'Pendente', blocked: 'Bloqueada para conciliação', not_linked: 'Sem vínculo', not_found: 'Não encontrada',
            amount_mismatch: 'Valores divergentes', error: 'Erro', partial_reconciled: 'Conciliação parcial', unknown: 'Resultado não confirmado'
        };

        function invoiceLink(id, caption) {
            if (!identifier(id)) { return node('span', caption || 'Fatura indisponível'); }
            var link = node('a', caption || ('Fatura ' + id), 'asaas-monitor-invoice');
            link.href = invoiceBase.href.replace(/\/?$/, '/') + identifier(id);
            link.addEventListener('click', function (event) {
                if (state.running) { event.preventDefault(); }
            });
            return link;
        }
        function validCsrf(value) {
            return value && typeof value.name === 'string' && /^[A-Za-z_][A-Za-z0-9_-]{0,127}$/.test(value.name)
                && typeof value.hash === 'string' && value.hash.length > 0 && value.hash.length <= 512 && !/[\s\x00-\x1f\x7f]/.test(value.hash);
        }
        function currentCsrf() {
            if (!validCsrf(csrf)) { return csrf; }
            var input = form && form.querySelector('input[type="hidden"][name="' + csrf.name + '"]');
            return input && validCsrf({name: csrf.name, hash: input.value}) ? {name: csrf.name, hash: input.value} : csrf;
        }
        function renewCsrf(value) {
            if (!validCsrf(value)) { return false; }
            if (form) {
                var previous = validCsrf(csrf) ? form.querySelector('input[type="hidden"][name="' + csrf.name + '"]') : null;
                var input = previous || form.querySelector('input[type="hidden"][name="' + value.name + '"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    form.appendChild(input);
                }
                input.name = value.name;
                input.value = value.hash;
            }
            csrf = {name: value.name, hash: value.hash};
            if (form && form.getAttribute('data-asaas-logs-csrf-uncertain') === '1') {
                form.setAttribute('data-asaas-logs-csrf-uncertain', '0');
                document.dispatchEvent(new CustomEvent('asaas:settings:csrf-renewed'));
            }
            return true;
        }
        async function request(action, method, parameters) {
            var url = new URL(action, base.href.replace(/\/?$/, '/'));
            var options = {method: method, credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
            var encoded = new URLSearchParams();
            Object.keys(parameters || {}).forEach(function (key) { encoded.append(key, String(parameters[key])); });
            if (method === 'POST') {
                var token = currentCsrf();
                if (!validCsrf(token)) { throw new Error('Atualize os registros para renovar a proteção do formulário antes de executar a operação.'); }
                encoded.append(token.name, token.hash);
                options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                options.body = encoded.toString();
            } else {
                url.search = encoded.toString();
            }
            var response;
            var payload;
            try {
                response = await window.fetch(url.href, options);
                payload = await response.json();
            } catch (error) {
                throw new Error('Não foi possível confirmar a resposta do servidor. Atualize os registros antes de repetir uma operação.');
            }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload) || !renewCsrf(payload.csrf)) {
                throw new Error('O servidor retornou uma resposta incompleta. Atualize os registros antes de repetir uma operação.');
            }
            if (!response.ok || payload.ok !== true) {
                throw new Error(response.status === 401 || response.status === 403
                    ? 'A sessão ou a permissão de administrador precisa ser verificada. Atualize a página antes de continuar.'
                    : 'Não foi possível concluir a solicitação. Consulte o diagnóstico e atualize os registros.');
            }
            return payload;
        }

        function logsLoading() {
            return form && form.getAttribute('data-asaas-logs-loading') === '1';
        }
        function logsCsrfUncertain() {
            return form && form.getAttribute('data-asaas-logs-csrf-uncertain') === '1';
        }
        function updateControls() {
            var ownBusy = state.loading || state.running;
            var wasBusy = form && form.getAttribute('data-asaas-monitor-busy') === '1';
            if (form) { form.setAttribute('data-asaas-monitor-busy', ownBusy ? '1' : '0'); }
            var busy = ownBusy || logsLoading();
            var uncertain = logsCsrfUncertain();
            root.setAttribute('aria-busy', busy ? 'true' : 'false');
            Array.prototype.forEach.call(root.querySelectorAll('button, input, select'), function (control) {
                var action = control.getAttribute('data-monitor-action');
                if (action === 'stop') {
                    control.disabled = !state.running || state.stop;
                    return;
                }
                if (control.type === 'hidden') { return; }
                control.disabled = busy;
                if (action === 'compare-selected' || action === 'reconcile-selected') { control.disabled = busy || uncertain || state.selected.size === 0; }
                if (control.getAttribute('data-monitor-invoice-action')) { control.disabled = busy || uncertain; }
                if (action === 'previous' || action === 'next') {
                    var page = state.pages[control.getAttribute('data-monitor-kind')];
                    control.disabled = busy || (action === 'previous' ? page.page <= 1 : !page.hasMore);
                }
            });
            var ids = state.invoices.map(function (row) { return identifier(row.invoice_id); }).filter(Boolean);
            selectAll.disabled = busy || ids.length === 0;
            selectAll.checked = ids.length > 0 && ids.every(function (id) { return state.selected.has(id); });
            selectAll.indeterminate = !selectAll.checked && ids.some(function (id) { return state.selected.has(id); });
            root.querySelector('[data-monitor-selected-count]').textContent = state.selected.size
                ? state.selected.size + (state.selected.size === 1 ? ' fatura selecionada nesta página.' : ' faturas selecionadas nesta página.')
                : 'Nenhuma fatura selecionada.';
            if (wasBusy && !ownBusy) { document.dispatchEvent(new CustomEvent('asaas:monitor:idle')); }
        }
        function tableState(kind, message) {
            var body = root.querySelector('[data-monitor-list="' + kind + '"]');
            clear(body);
            var row = node('tr');
            var entry = cell(row, message, 'asaas-monitor-state');
            entry.colSpan = {invoices: 7, events: 5, logs: 4}[kind];
            body.appendChild(row);
        }
        function rowAction(group, action, id, caption, info) {
            var button = node('button', caption, info ? 'btn btn-info btn-xs' : 'btn btn-default btn-xs');
            button.type = 'button';
            button.setAttribute('data-monitor-invoice-action', action);
            button.setAttribute('title', action === 'compare'
                ? 'Consultar agora o pagamento no Asaas, sem registrar recebimento.'
                : 'Consultar o Asaas e registrar somente os recebimentos confirmados que faltam no CRM.');
            button.addEventListener('click', function () { runBatch(action, [id]); });
            group.appendChild(button);
        }
        function paymentEvidence(parent, status) {
            var caption = 'Situação a conferir';
            var tone = 'default';
            if (['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'].indexOf(status) !== -1) {
                caption = 'Recebimento confirmado'; tone = 'success';
            } else if (['PENDING', 'OVERDUE', 'DUNNING_REQUESTED'].indexOf(status) !== -1) {
                caption = 'Sem confirmação de pagamento'; tone = 'warning';
            } else if (status === 'AUTHORIZED') {
                caption = 'Somente autorizado'; tone = 'info';
            } else if (status === 'AWAITING_RISK_ANALYSIS') {
                caption = 'Pagamento em análise'; tone = 'warning';
            } else if (status === 'REFUNDED') {
                caption = 'Pagamento estornado';
            } else if (status === 'DELETED') {
                caption = 'Cobrança excluída';
            }
            var badge = node('span', caption, 'label label-' + tone + ' asaas-monitor-payment-evidence');
            badge.setAttribute('data-monitor-payment-evidence', '');
            parent.appendChild(badge);
            secondary(parent, 'Estado: ' + label(status, paymentLabels));
        }
        function renderInvoices(rows) {
            var body = root.querySelector('[data-monitor-list="invoices"]');
            clear(body);
            if (rows.length === 0) { tableState('invoices', 'Nenhuma fatura foi encontrada para este filtro.'); return; }
            rows.forEach(function (record) {
                var row = node('tr');
                var id = identifier(record.invoice_id);
                var checkCell = cell(row, undefined, 'asaas-monitor-check');
                if (id) {
                    var check = document.createElement('input');
                    check.type = 'checkbox';
                    check.setAttribute('data-monitor-invoice-id', id);
                    check.setAttribute('aria-label', 'Selecionar fatura ' + text(record.invoice_number, id));
                    check.checked = state.selected.has(id);
                    check.addEventListener('change', function () {
                        if (state.running || state.loading) { check.checked = state.selected.has(id); return; }
                        if (check.checked) { state.selected.add(id); } else { state.selected.delete(id); }
                        updateControls();
                    });
                    checkCell.appendChild(check);
                }
                var invoiceCell = cell(row);
                invoiceCell.appendChild(invoiceLink(id, text(record.invoice_number, id ? 'Fatura ' + id : 'Registro sem identificador')));
                secondary(invoiceCell, text(record.client_name));
                if (record.suspected_divergence === true || record.suspected_divergence === 1 || record.suspected_divergence === '1') {
                    invoiceCell.appendChild(node('span', 'Possível divergência', 'label label-warning'));
                }
                cell(row, label(record.crm_status, crmLabels));
                var remoteCell = cell(row);
                paymentEvidence(remoteCell, record.asaas_status);
                secondary(remoteCell, text(record.asaas_payment_id));
                secondary(remoteCell, label(record.billing_type, {BOLETO: 'Boleto', PIX: 'Pix', CREDIT_CARD: 'Cartão de crédito', UNDEFINED: 'Forma a definir'}));
                var amountCell = cell(row, money(record.total));
                secondary(amountCell, 'Saldo: ' + money(record.balance));
                var datesCell = cell(row, 'Última atualização: ' + text(record.last_sync_at));
                secondary(datesCell, 'Webhook: ' + text(record.last_webhook_at));
                if (record.webhook_status) { secondary(datesCell, 'Evento: ' + label(record.webhook_status, webhookLabels)); }
                var actions = node('div', undefined, 'asaas-monitor-row-actions');
                if (id) {
                    rowAction(actions, 'compare', id, 'Comparar', false);
                    rowAction(actions, 'reconcile', id, 'Conciliar', true);
                } else { actions.textContent = 'Identificador inválido'; }
                cell(row).appendChild(actions);
                body.appendChild(row);
            });
        }
        function renderEvents(rows) {
            var body = root.querySelector('[data-monitor-list="events"]');
            clear(body);
            if (rows.length === 0) { tableState('events', 'Nenhum evento de webhook foi encontrado para este filtro.'); return; }
            rows.forEach(function (record) {
                var row = node('tr');
                var eventCell = cell(row, text(record.event_type));
                secondary(eventCell, text(record.event_id));
                var paymentCell = cell(row);
                paymentCell.appendChild(invoiceLink(record.invoice_id, identifier(record.invoice_id) ? 'Fatura ' + record.invoice_id : 'Fatura não vinculada'));
                secondary(paymentCell, text(record.payment_id));
                var dates = cell(row, text(record.received_at));
                secondary(dates, 'Processado: ' + text(record.processed_at));
                cell(row, label(record.process_status, webhookLabels));
                cell(row, text(record.error_message), 'asaas-monitor-message');
                body.appendChild(row);
            });
        }
        function renderLogs(rows) {
            var body = root.querySelector('[data-monitor-list="logs"]');
            clear(body);
            if (rows.length === 0) { tableState('logs', 'Nenhum registro de diagnóstico foi encontrado para este filtro.'); return; }
            rows.forEach(function (record) {
                var row = node('tr');
                cell(row, text(record.created_at));
                cell(row, label(record.level, {error: 'Erro', warning: 'Aviso', info: 'Informação', debug: 'Diagnóstico'}));
                var message = cell(row, text(record.message), 'asaas-monitor-message');
                if (record.context && typeof record.context === 'object' && Object.keys(record.context).length) {
                    var details = node('details', undefined, 'asaas-monitor-context');
                    details.appendChild(node('summary', 'Detalhes do registro'));
                    details.appendChild(node('pre', JSON.stringify(record.context, null, 2).slice(0, 10000)));
                    message.appendChild(details);
                }
                cell(row, text(record.correlation_id));
                body.appendChild(row);
            });
        }
        function metric(name, value) { root.querySelector('[data-monitor-metric="' + name + '"]').textContent = value; }
        async function loadOverview() {
            var payload = await request('overview', 'GET');
            var data = payload.data;
            if (!data || typeof data !== 'object' || Array.isArray(data) || !data.cron || typeof data.cron !== 'object') {
                throw new Error('O resumo da integração retornou dados incompletos. Atualize os registros.');
            }
            ['linked_open_invoices', 'webhook_errors', 'webhook_event_errors', 'log_errors'].forEach(function (key) {
                metric(key, text(integer(data[key], null)));
            });
            metric('last_webhook_at', text(data.last_webhook_at));
            metric('cron_enabled', data.cron.enabled === true || data.cron.enabled === 1 || data.cron.enabled === '1' ? 'Habilitada' : 'Desabilitada');
            var last = data.cron.last_run;
            if (last && typeof last === 'object') {
                var remaining = integer(last.remaining, 0);
                metric('cron_last_run', text(last.finished_at || last.started_at) + ' · '
                    + label(last.status, {completed: 'Concluída', partial: 'Parcial', error: 'Com erro'})
                    + ' · ' + integer(last.processed, 0) + ' verificadas, ' + integer(last.reconciled, 0) + ' conciliadas, ' + integer(last.errors, 0) + ' com erro.'
                    + (remaining > 0 ? ' ' + remaining + ' aguardando a próxima rodada.' : ''));
            } else { metric('cron_last_run', last ? text(last) : 'Nenhuma execução registrada.'); }
            metric('cron_configuration', 'Janela: ' + integer(data.cron.days, 0) + ' dias · Limite por execução: ' + integer(data.cron.limit, 0) + ' faturas.');
        }
        function listParameters(kind) {
            var page = state.pages[kind];
            var parameters = {page: page.page, per_page: page.perPage};
            if (kind === 'invoices') {
                parameters.q = root.querySelector('#asaas-monitor-query').value.trim().slice(0, 100);
                parameters.status = root.querySelector('#asaas-monitor-invoice-status').value;
            } else if (kind === 'events') {
                parameters.status = root.querySelector('#asaas-monitor-event-status').value;
            } else {
                parameters.level = root.querySelector('#asaas-monitor-log-level').value;
                parameters.source = root.querySelector('#asaas-monitor-log-source').value;
            }
            return parameters;
        }
        async function loadList(kind) {
            if (kind === 'invoices') { state.selected.clear(); state.invoices = []; }
            state.pages[kind].hasMore = false;
            tableState(kind, 'Carregando registros...');
            var payload = await request(kind, 'GET', listParameters(kind));
            if (!Array.isArray(payload.data) || !payload.pagination || typeof payload.pagination !== 'object'
                || payload.data.some(function (row) { return !row || typeof row !== 'object' || Array.isArray(row); })) {
                throw new Error('A listagem retornou dados incompletos. Atualize os registros.');
            }
            var page = state.pages[kind];
            page.page = Math.max(1, integer(payload.pagination.page, page.page));
            page.hasMore = payload.pagination.has_more === true || payload.pagination.has_more === 1;
            page.total = integer(payload.pagination.total, null);
            root.querySelector('[data-monitor-page="' + kind + '"]').textContent = 'Página ' + page.page
                + (page.total === null ? '' : ' · ' + page.total + ' registros');
            if (kind === 'invoices') { state.invoices = payload.data; renderInvoices(payload.data); }
            if (kind === 'events') { renderEvents(payload.data); }
            if (kind === 'logs') { renderLogs(payload.data); }
        }
        async function refresh(includeOverview) {
            if (state.loading || state.running) { return; }
            if (logsLoading()) {
                deferredRefresh = deferredRefresh === true || includeOverview === true;
                notify('Aguardando o diagnóstico de logs antes de atualizar o monitoramento.', false);
                updateControls();
                return;
            }
            deferredRefresh = null;
            state.loading = true;
            notify('', false);
            updateControls();
            if (includeOverview) {
                try { await loadOverview(); } catch (error) { notify(error.message, true); }
            }
            try { await loadList(state.active); }
            catch (error) { tableState(state.active, 'Não foi possível carregar os registros. Use Atualizar registros para tentar novamente.'); notify(error.message, true); }
            finally { state.loading = false; updateControls(); }
        }
        function showTab(kind) {
            if (!state.pages[kind] || state.loading || state.running) { return; }
            state.active = kind;
            Array.prototype.forEach.call(root.querySelectorAll('[data-monitor-tab]'), function (button) {
                var selected = button.getAttribute('data-monitor-tab') === kind;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-selected', selected ? 'true' : 'false');
                button.tabIndex = selected ? 0 : -1;
            });
            Array.prototype.forEach.call(root.querySelectorAll('[data-monitor-panel]'), function (panel) {
                panel.hidden = panel.getAttribute('data-monitor-panel') !== kind;
            });
            refresh(false);
        }
        function appendResult(record) {
            var row = node('tr');
            cell(row).appendChild(invoiceLink(record.invoice_id));
            var result = cell(row, label(record.result, resultLabels));
            if (record.result === 'error' || record.result === 'unknown' || record.result === 'partial_reconciled') { result.className = 'text-danger'; }
            var statuses = cell(row, 'CRM: ' + label(record.crm_status, crmLabels));
            secondary(statuses, 'Asaas: ' + label(record.remote_status, paymentLabels));
            secondary(statuses, 'Saldo: ' + money(record.balance));
            var added = integer(record.receipts_added, null);
            var receiptPending = record.receipt_verification_pending === true;
            var receipts = cell(row, added === null || receiptPending ? 'Não confirmado' : added + ' registrado(s)');
            if (receiptPending && added > 0) {
                secondary(receipts, added + (added === 1 ? ' recebimento já verificado.' : ' recebimentos já verificados.'));
            }
            secondary(receipts, 'Confirmado no Asaas: ' + money(record.remote_paid_amount));
            cell(row, text(record.message), 'asaas-monitor-message');
            resultsBody.appendChild(row);
        }
        async function runBatch(action, requestedIds) {
            if (state.loading || state.running || (action !== 'compare' && action !== 'reconcile')) { return; }
            if (logsLoading() || logsCsrfUncertain()) {
                notify(logsLoading() ? 'Aguarde o diagnóstico de logs antes de executar a operação.'
                    : 'Atualize os registros para renovar a proteção do formulário antes de executar a operação.', true);
                return;
            }
            var ids = Array.from(new Set(requestedIds.map(identifier).filter(Boolean)));
            if (ids.length === 0) { notify('Selecione uma fatura válida para executar a operação.', true); return; }
            state.running = true;
            state.stop = false;
            notify('', false);
            task.hidden = false;
            clear(resultsBody);
            root.querySelector('[data-monitor-task-title]').textContent = action === 'compare' ? 'Comparação com o Asaas' : 'Conciliação de recebimentos';
            progress.style.width = '0%';
            progress.setAttribute('aria-valuenow', '0');
            var finished = 0;
            var failed = false;
            updateControls();
            try {
                for (var index = 0; index < ids.length; index++) {
                    if (state.stop) { break; }
                    var id = ids[index];
                    taskMessage.textContent = (action === 'compare' ? 'Comparando' : 'Conciliando') + ' fatura ' + id + ' · ' + (index + 1) + ' de ' + ids.length + '.';
                    var slowTimer = window.setTimeout(function () {
                        taskMessage.textContent = 'Aguardando a resposta da operação atual. Não repita a solicitação; Parar lote aguarda esse retorno antes de interromper.';
                    }, 60000);
                    try {
                        var payload = await request(action, 'POST', {'invoice_ids[]': id, trigger: ids.length > 1 ? 'batch' : 'manual'});
                        if (!Array.isArray(payload.data) || payload.data.length !== 1 || !payload.data[0]
                            || Array.isArray(payload.data[0]) || identifier(payload.data[0].invoice_id) !== id
                            || typeof payload.data[0].result !== 'string' || !Object.prototype.hasOwnProperty.call(resultLabels, payload.data[0].result)
                            || payload.data[0].result === 'unknown' || integer(payload.data[0].receipts_added, null) === null
                            || (Object.prototype.hasOwnProperty.call(payload.data[0], 'receipt_verification_pending')
                                && typeof payload.data[0].receipt_verification_pending !== 'boolean')) {
                            throw new Error('O resultado da fatura não pôde ser confirmado.');
                        }
                        var record = payload.data[0];
                        appendResult(record);
                        finished++;
                        progress.style.width = String(Math.round(finished * 100 / ids.length)) + '%';
                        progress.setAttribute('aria-valuenow', String(Math.round(finished * 100 / ids.length)));
                        if (record.result === 'error' || record.result === 'partial_reconciled' || record.receipt_verification_pending === true) {
                            failed = true;
                            taskMessage.textContent = 'Lote interrompido na fatura ' + id + '. Consulte o resultado abaixo e os registros antes de iniciar outra operação. '
                                + (ids.length - index - 1) + ' fatura(s) não foram iniciadas.';
                            break;
                        }
                    } catch (error) {
                        failed = true;
                        appendResult({invoice_id: id, result: 'unknown', message: 'Não foi possível confirmar o retorno. Consulte os registros e compare a situação no Asaas antes de repetir a conciliação.'});
                        taskMessage.textContent = 'Lote interrompido: o resultado da fatura ' + id + ' não foi confirmado. A solicitação não será repetida automaticamente. '
                            + (ids.length - index - 1) + ' fatura(s) não foram iniciadas.';
                        notify(error.message, true);
                        break;
                    } finally { window.clearTimeout(slowTimer); }
                }
                if (!failed) {
                    taskMessage.textContent = state.stop && finished < ids.length
                        ? 'Lote interrompido após a resposta atual. ' + finished + ' de ' + ids.length + ' faturas verificadas; as demais não foram iniciadas.'
                        : 'Operação concluída para ' + finished + ' fatura(s). Consulte os resultados abaixo e atualize os registros para rever a listagem.';
                }
            } finally { state.running = false; updateControls(); }
        }

        Array.prototype.forEach.call(root.querySelectorAll('[data-monitor-tab]'), function (button) {
            button.addEventListener('click', function () { showTab(button.getAttribute('data-monitor-tab')); });
            button.addEventListener('keydown', function (event) {
                if (state.loading || state.running || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) { return; }
                event.preventDefault();
                var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-monitor-tab]'));
                var current = tabs.indexOf(button);
                var next = event.key === 'Home' ? 0 : (event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length);
                tabs[next].focus();
                showTab(tabs[next].getAttribute('data-monitor-tab'));
            });
        });
        Array.prototype.forEach.call(root.querySelectorAll('[data-monitor-action]'), function (button) {
            button.addEventListener('click', function () {
                var action = button.getAttribute('data-monitor-action');
                var kind = button.getAttribute('data-monitor-kind');
                if (action === 'stop' && state.running) {
                    state.stop = true;
                    taskMessage.textContent = 'Parada solicitada. A operação atual continuará até retornar; as próximas faturas não serão iniciadas.';
                    updateControls();
                    return;
                }
                if (state.running || state.loading) { return; }
                if (action === 'refresh') { refresh(true); }
                if (action === 'filter') { state.pages[kind].page = 1; refresh(false); }
                if (action === 'previous' && state.pages[kind].page > 1) { state.pages[kind].page--; refresh(false); }
                if (action === 'next' && state.pages[kind].hasMore) { state.pages[kind].page++; refresh(false); }
                if (action === 'compare-selected') { runBatch('compare', Array.from(state.selected)); }
                if (action === 'reconcile-selected') { runBatch('reconcile', Array.from(state.selected)); }
            });
        });
        selectAll.addEventListener('change', function () {
            if (state.running || state.loading) { updateControls(); return; }
            state.invoices.forEach(function (record) {
                var id = identifier(record.invoice_id);
                if (id) { if (selectAll.checked) { state.selected.add(id); } else { state.selected.delete(id); } }
            });
            Array.prototype.forEach.call(root.querySelectorAll('[data-monitor-invoice-id]'), function (check) {
                check.checked = state.selected.has(check.getAttribute('data-monitor-invoice-id'));
            });
            updateControls();
        });
        root.querySelector('#asaas-monitor-query').addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); if (!state.running && !state.loading) { state.pages.invoices.page = 1; refresh(false); } }
        });
        document.addEventListener('asaas:logs:busy', updateControls);
        document.addEventListener('asaas:logs:idle', function () {
            updateControls();
            if (deferredRefresh !== null) { refresh(deferredRefresh); }
        });
        document.addEventListener('asaas:settings:csrf-renewed', updateControls);
        if (form) {
            form.addEventListener('submit', function (event) {
                if (state.running || state.loading) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    notify('Aguarde o retorno da operação de monitoramento antes de salvar as configurações. Para interromper um lote, use Parar lote.', true);
                }
            }, true);
        }
        updateControls();
        monitor = {open: function () { refresh(true); }};
        return monitor;
    }

    document.addEventListener('asaas:settings:shown', function (event) {
        if (event.detail && event.detail.group === 'monitoring') {
            var instance = initialize();
            if (instance) { instance.open(); }
        }
    });
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); }
    else { initialize(); }
}());
