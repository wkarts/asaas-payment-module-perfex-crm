(function () {
    'use strict';

    var statusLabels = {
        PENDING: 'Pendente', CONFIRMED: 'Confirmado', RECEIVED: 'Recebido', OVERDUE: 'Vencido',
        RECEIVED_IN_CASH: 'Recebido em dinheiro', REFUNDED: 'Estornado', REFUND_REQUESTED: 'Estorno solicitado',
        REFUND_IN_PROGRESS: 'Estorno em andamento', CHARGEBACK_REQUESTED: 'Chargeback solicitado',
        CHARGEBACK_DISPUTE: 'Chargeback em disputa', AWAITING_CHARGEBACK_REVERSAL: 'Aguardando reversão de chargeback',
        DUNNING_REQUESTED: 'Negativação solicitada', DUNNING_RECEIVED: 'Recebido após negativação',
        AWAITING_RISK_ANALYSIS: 'Em análise de risco', BOLETO: 'Boleto', CREDIT_CARD: 'Cartão de crédito',
        DEBIT_CARD: 'Cartão de débito', PIX: 'Pix', UNDEFINED: 'Forma a definir', TRANSFER: 'Transferência', DEPOSIT: 'Depósito',
        ACTIVE: 'Ativa', INACTIVE: 'Inativa', CREATED: 'Criada', CANCELLED: 'Cancelado', REFUSED: 'Recusado', EXPIRED: 'Expirada',
        DONE: 'Concluído', FAILED: 'Falha', PAID: 'Pago', SCHEDULED: 'Agendado', BANK_PROCESSING: 'Processamento bancário',
        PROCESSING: 'Em processamento', PROCESSING_REFUND: 'Estorno em processamento', AWAITING_CREDIT: 'Aguardando crédito',
        BLOCKED_BY_VALUE_DIVERGENCE: 'Bloqueado por divergência de valor', DENIED: 'Negada', CREDITED: 'Creditada', DEBITED: 'Debitada',
        WEEKLY: 'Semanal', BIWEEKLY: 'Quinzenal', MONTHLY: 'Mensal', BIMONTHLY: 'Bimestral', QUARTERLY: 'Trimestral',
        SEMIANNUALLY: 'Semestral', YEARLY: 'Anual', ANNUALLY: 'Anual', MANUAL: 'Pelo sistema', SUBSCRIPTION: 'Assinatura Asaas',
        DEBIT: 'Débito', CREDIT: 'Crédito', CREDIT_REFUND: 'Devolução de crédito', DEBIT_REFUND: 'Devolução de débito',
        DEBIT_REFUND_CANCELLATION: 'Cancelamento de devolução', REQUESTED: 'Solicitado', AWAITING_REQUEST: 'Aguardando envio',
        IN_DISPUTE: 'Em disputa', DISPUTE_LOST: 'Disputa perdida', REVERSED: 'Revertido',
        AUTHORIZED: 'Autorizada', CANCELED: 'Cancelada', PROCESSING_CANCELLATION: 'Cancelamento em processamento',
        CANCELLATION_DENIED: 'Cancelamento negado', ERROR: 'Erro', AWAITING_APPROVAL: 'Aguardando aprovação',
        AWAITING_CANCELLATION: 'Aguardando cancelamento', PROCESSED: 'Processada', PARTIALLY_PAID: 'Paga parcialmente',
        CREDIT_BUREAU: 'Negativação', DEBT_RECOVERY_ASSISTANCE: 'Recuperação de crédito',
        AWAITING_BALANCE_VALIDATION: 'Validando saldo', AWAITING_INSTANT_PAYMENT_ACCOUNT_BALANCE: 'Aguardando saldo',
        AWAITING_CRITICAL_ACTION_AUTHORIZATION: 'Aguardando autorização', AWAITING_CUSTOMER_EXTERNAL_AUTHORIZATION: 'Aguardando autorização do cliente',
        AWAITING_CHECKOUT_RISK_ANALYSIS_REQUEST: 'Em análise de risco', AWAITING_CASH_IN_RISK_ANALYSIS_REQUEST: 'Em análise de risco'
    };

    function node(tag, content, className) {
        var element = document.createElement(tag);
        if (content !== undefined) { element.textContent = String(content); }
        if (className) { element.className = className; }
        return element;
    }
    function clear(element) { while (element.firstChild) { element.removeChild(element.firstChild); } }
    function text(value) { return value === null || value === undefined || value === '' ? '—' : String(value); }
    function integer(value) { return Number.isSafeInteger(value) && value >= 0; }
    function money(value) {
        return typeof value === 'string' && /^-?[0-9]+\.[0-9]{2}$/.test(value)
            ? new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(Number(value)) : '—';
    }
    function display(value, column) {
        if (value === null || value === undefined || value === '') { return '—'; }
        if (column.type === 'money') { return money(value); }
        if (column.type === 'boolean') { return value === true ? 'Sim' : 'Não'; }
        if (column.type === 'status') { return statusLabels[value] || text(value); }
        if (column.type === 'date') {
            var match = String(value).match(/^([0-9]{4})-([0-9]{2})-([0-9]{2})(.*)$/);
            return match ? match[3] + '/' + match[2] + '/' + match[1] + match[4].replace('T', ' ') : text(value);
        }
        return text(value);
    }

    function initialize(root) {
        if (root.dataset.reportsInitialized === '1') { return; }
        root.dataset.reportsInitialized = '1';
        var catalog, endpoint;
        var feedback = root.querySelector('[data-report-feedback]');
        try {
            catalog = JSON.parse(root.querySelector('[data-report-catalog]').textContent);
            endpoint = new URL(root.dataset.endpoint, window.location.href);
            if (!Array.isArray(catalog) || endpoint.origin !== window.location.origin) { throw new Error('Invalid configuration'); }
        } catch (error) {
            feedback.hidden = false;
            feedback.className = 'alert alert-danger';
            feedback.textContent = 'Não foi possível preparar os relatórios. Atualize a página.';
            return;
        }
        var definitions = Object.create(null);
        catalog.forEach(function (item) { if (item && typeof item.id === 'string') { definitions[item.id] = item; } });
        var kind = root.querySelector('[data-report-kind]');
        var perPage = root.querySelector('[data-report-per-page]');
        var filters = root.querySelector('[data-report-filters]');
        var results = root.querySelector('[data-report-results]');
        var head = root.querySelector('[data-report-head]');
        var body = root.querySelector('[data-report-rows]');
        var detail = root.querySelector('[data-report-detail]');
        var canExport = root.dataset.canExport === '1';
        var state = {busy: false, result: null, history: [], dirty: true};

        function notify(message, error) {
            feedback.textContent = message;
            feedback.className = error ? 'alert alert-danger' : 'alert alert-info';
            feedback.hidden = !message;
        }
        function action(name) { return root.querySelector('[data-report-action="' + name + '"]'); }
        function buttons() {
            root.querySelectorAll('[data-report-action]').forEach(function (button) { button.disabled = state.busy; });
            action('previous').disabled = state.busy || state.dirty || state.history.length === 0;
            action('next').disabled = state.busy || state.dirty || !state.result || state.result.has_more !== true;
            if (action('print')) { action('print').disabled = state.busy || state.dirty || !state.result || !canExport; }
            if (action('export')) { action('export').disabled = state.busy || !canExport; }
            kind.disabled = state.busy;
            perPage.disabled = state.busy || definitions[kind.value].kind === 'summary';
            filters.querySelectorAll('input, select').forEach(function (input) { input.disabled = state.busy; });
            body.querySelectorAll('[data-report-row]').forEach(function (button) { button.disabled = state.busy; });
            results.setAttribute('aria-busy', state.busy ? 'true' : 'false');
        }
        function addFilter(field, value) {
            var wrapper = node('div', undefined, 'form-group');
            var control;
            var identifier = root.id + '-filter-' + field.key;
            var label = node('label', field.label + (field.required ? ' *' : ''));
            label.htmlFor = identifier;
            wrapper.appendChild(label);
            if (field.type === 'select') {
                control = node('select', undefined, 'form-control input-sm');
                if (!field.required) {
                    var empty = node('option', 'Todos'); empty.value = ''; control.appendChild(empty);
                }
                Object.keys(field.options).forEach(function (key) {
                    var option = node('option', field.options[key]); option.value = key; control.appendChild(option);
                });
            } else {
                control = node('input', undefined, 'form-control input-sm');
                control.type = field.type === 'date' ? 'date' : 'text';
                control.maxLength = field.type === 'identifier' ? 120 : 200;
                control.autocomplete = 'off';
                if (field.type === 'identifier') { control.placeholder = 'Identificador no Asaas'; }
            }
            control.id = identifier;
            control.dataset.reportFilter = field.key;
            // The widget is also nested in Perfex's settings form. These fields are never settings.
            control.setAttribute('form', '');
            control.required = !!field.required;
            if (value !== undefined && value !== null) { control.value = String(value); }
            wrapper.appendChild(control);
            filters.appendChild(wrapper);
        }
        function buildFilters() {
            var definition = definitions[kind.value];
            if (!definition) { return; }
            clear(filters);
            root.querySelector('[data-report-description]').textContent = definition.description;
            definition.filters.forEach(function (field) { addFilter(field, definition.defaults[field.key]); });
            var dates = definition.dates && Object.keys(definition.dates);
            if (dates && dates.length) {
                addFilter({key: 'date_field', label: 'Data de referência', type: 'select', options: definition.dates, required: true}, dates[0]);
                var from = '', to = '';
                if (definition.id === 'statement') {
                    var today = new Date();
                    var month = String(today.getMonth() + 1).padStart(2, '0');
                    from = today.getFullYear() + '-' + month + '-01';
                    to = today.getFullYear() + '-' + month + '-' + String(today.getDate()).padStart(2, '0');
                }
                addFilter({key: 'date_from', label: 'De (opcional)', type: 'date'}, from);
                addFilter({key: 'date_to', label: 'Até (opcional)', type: 'date'}, to);
            }
            state.result = null;
            state.history = [];
            state.dirty = true;
            results.hidden = true;
            notify('', false);
            buttons();
        }
        function changed() {
            if (state.result) { notify('Os filtros foram alterados. Clique em Consultar para atualizar o resultado.', false); }
            state.dirty = true;
            results.hidden = true;
            buttons();
        }
        function parameters(offset) {
            var valid = true;
            var params = new URLSearchParams({report: kind.value, offset: String(offset), per_page: perPage.value});
            filters.querySelectorAll('[data-report-filter]').forEach(function (input) {
                if (!input.checkValidity()) {
                    if (valid) { input.reportValidity(); input.focus(); }
                    valid = false;
                }
                if (input.value !== '') { params.set(input.dataset.reportFilter, input.value.trim()); }
            });
            return valid ? params : null;
        }
        function url(path, params) {
            var value = new URL(endpoint.href.replace(/\/?$/, '/') + path);
            value.search = params.toString();
            return value.href;
        }
        async function request(path, params) {
            var response = await window.fetch(url(path, params), {method: 'GET', credentials: 'same-origin', cache: 'no-store', mode: 'same-origin', headers: {Accept: path === 'export' ? 'text/csv, application/json' : 'application/json'}});
            if (path === 'export' && response.ok && (response.headers.get('Content-Type') || '').toLowerCase().indexOf('text/csv') === 0) { return response; }
            var payload;
            try { payload = await response.json(); }
            catch (error) { throw new Error('Não foi possível ler a resposta. Confira sua sessão no CRM e tente novamente.'); }
            if (!response.ok || !payload || payload.ok !== true) {
                throw new Error(payload && typeof payload.message === 'string' ? payload.message.slice(0, 1000) : 'Não foi possível concluir a consulta.');
            }
            if (path === 'export') { throw new Error('A exportação não retornou um arquivo completo. Tente novamente.'); }
            return payload;
        }
        function range(report) {
            if (report.returned === 0) { return 'Nenhum registro encontrado'; }
            return 'Registros ' + (report.offset + 1) + '–' + (report.offset + report.returned)
                + (integer(report.total) ? ' de ' + report.total : ' · total não informado pela API');
        }
        function render(report) {
            if (!report || report.id !== kind.value || !Array.isArray(report.rows) || !integer(report.offset)
                || !integer(report.returned) || typeof report.has_more !== 'boolean'
                || (report.has_more && (!integer(report.next_offset) || report.next_offset <= report.offset))) {
                throw new Error('O relatório retornou uma paginação inválida. Atualize a consulta.');
            }
            var columns = definitions[report.id].columns;
            var visible = columns.filter(function (column) { return column.visible !== false; });
            var extra = columns.filter(function (column) { return column.visible === false; });
            clear(head); clear(body); detail.hidden = true;
            var titles = node('tr');
            visible.forEach(function (column) { titles.appendChild(node('th', column.label)); });
            if (extra.length) { titles.appendChild(node('th', 'Detalhes')); }
            head.appendChild(titles);
            report.rows.forEach(function (record, index) {
                var row = node('tr');
                visible.forEach(function (column) {
                    row.appendChild(node('td', display(record[column.key], column), column.type === 'money' ? 'asaas-reports-money' : ''));
                });
                if (extra.length) {
                    var cell = node('td');
                    var button = node('button', 'Ver detalhes', 'btn btn-default btn-xs');
                    button.type = 'button'; button.dataset.reportRow = String(index);
                    button.setAttribute('aria-controls', 'asaas-reports-detail'); button.setAttribute('aria-expanded', 'false');
                    cell.appendChild(button); row.appendChild(cell);
                }
                body.appendChild(row);
            });
            if (!report.rows.length) {
                var emptyRow = node('tr');
                var empty = node('td', 'Nenhum registro encontrado para os filtros escolhidos.', 'text-muted asaas-reports-empty');
                empty.colSpan = visible.length + (extra.length ? 1 : 0); emptyRow.appendChild(empty); body.appendChild(emptyRow);
            }
            root.querySelector('[data-report-title]').textContent = definitions[report.id].title;
            root.querySelector('[data-report-scope]').textContent = text(report.filter_description);
            root.querySelector('[data-report-environment]').textContent = report.environment === 'sandbox' ? 'Homologação' : 'Produção';
            root.querySelector('[data-report-count]').textContent = integer(report.total) ? report.total + ' registro(s) na consulta' : 'Total não informado pela API';
            var generated = new Date(report.generated_at);
            root.querySelector('[data-report-generated]').textContent = 'Consultado em: ' + (Number.isNaN(generated.getTime()) ? text(report.generated_at) : generated.toLocaleString('pt-BR'));
            root.querySelector('[data-report-page]').textContent = range(report);
            results.hidden = false;
        }
        function showDetail(index) {
            if (state.busy || state.dirty || !state.result || !state.result.rows[index]) { return; }
            var record = state.result.rows[index];
            var list = root.querySelector('[data-report-detail-values]');
            clear(list);
            definitions[state.result.id].columns.forEach(function (column) {
                var group = node('div');
                group.appendChild(node('dt', column.label)); group.appendChild(node('dd', display(record[column.key], column))); list.appendChild(group);
            });
            root.querySelector('[data-report-detail-title]').textContent = 'Detalhes do registro ' + (state.result.offset + index + 1);
            body.querySelectorAll('[data-report-row]').forEach(function (button) { button.setAttribute('aria-expanded', Number(button.dataset.reportRow) === index ? 'true' : 'false'); });
            detail.hidden = false;
        }
        async function query(offset, direction) {
            if (state.busy) { return; }
            var params = parameters(offset);
            if (!params) { return; }
            state.busy = true; buttons(); notify('Consultando o Asaas…', false);
            try {
                var payload = await request('data', params);
                render(payload.report);
                if (direction === 'next' && state.result) { state.history.push(state.result.offset); }
                else if (direction === 'previous') { state.history.pop(); }
                else { state.history = []; }
                state.result = payload.report;
                state.dirty = false;
                notify('', false);
            } catch (error) {
                state.dirty = true;
                results.hidden = true;
                notify(error.message || 'Não foi possível consultar o relatório.', true);
            } finally { state.busy = false; buttons(); }
        }
        async function download() {
            if (state.busy || !canExport) { return; }
            var params = parameters(0);
            if (!params) { return; }
            state.busy = true; buttons(); notify('Preparando o CSV com todas as páginas dos filtros escolhidos…', false);
            try {
                var response = await request('export', params);
                var file = await response.blob();
                if (file.size > 67108864) { throw new Error('O arquivo ultrapassou o volume seguro. Use filtros mais específicos.'); }
                var header = response.headers.get('Content-Disposition') || '';
                var name = header.match(/filename="(asaas-[a-z0-9_-]+\.csv)"/);
                var objectUrl = URL.createObjectURL(file);
                var anchor = node('a');
                anchor.href = objectUrl; anchor.download = name ? name[1] : 'asaas-relatorio.csv';
                document.body.appendChild(anchor); anchor.click(); anchor.remove();
                window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 30000);
                var rows = response.headers.get('X-Asaas-Report-Rows');
                notify('CSV completo gerado' + (rows && /^[0-9]+$/.test(rows) ? ' com ' + rows + ' registro(s)' : '') + '.', false);
            } catch (error) { notify(error.message || 'Não foi possível concluir a exportação.', true); }
            finally { state.busy = false; buttons(); }
        }
        function printPage() {
            if (!canExport || state.busy || state.dirty || !state.result) { return; }
            var old = document.getElementById('asaas-reports-print');
            if (old) { old.remove(); }
            var frame = node('iframe');
            frame.id = 'asaas-reports-print'; frame.title = 'Impressão do relatório Asaas';
            frame.style.cssText = 'position:fixed;left:-10000px;width:1px;height:1px;border:0';
            document.body.appendChild(frame);
            var doc = frame.contentDocument;
            doc.open();
            doc.write('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório Asaas</title><style>@page{size:A4 landscape;margin:12mm}body{font:11px Arial,sans-serif;color:#172b4d}h1{font-size:20px;margin:0 0 8px}p{margin:5px 0}table{width:100%;border-collapse:collapse;margin-top:16px;font-size:9px}th,td{border:1px solid #ccd5df;padding:5px;vertical-align:top;overflow-wrap:anywhere}th{background:#f1f5f9;text-align:left}thead{display:table-header-group}tr{break-inside:avoid}details{display:block}summary{display:none}dt{font-weight:bold;margin-top:4px}dd{margin:0}a{color:inherit;text-decoration:none}</style></head><body></body></html>');
            doc.close();
            function line(tag, value) { var element = doc.createElement(tag); element.textContent = value; doc.body.appendChild(element); }
            line('h1', definitions[state.result.id].title);
            line('p', 'Asaas · ' + state.result.environment_label + ' · ' + root.querySelector('[data-report-generated]').textContent);
            line('p', state.result.filter_description);
            line('p', 'Página exibida: ' + range(state.result) + '. O CSV completo reúne todas as páginas dos filtros escolhidos.');
            var table = root.querySelector('.asaas-reports-table table').cloneNode(true);
            if (table.querySelector('[data-report-row]')) {
                table.querySelectorAll('tr').forEach(function (row) { row.lastElementChild.remove(); });
            }
            doc.body.appendChild(doc.importNode(table, true));
            if (!detail.hidden) {
                line('h2', root.querySelector('[data-report-detail-title]').textContent);
                doc.body.appendChild(doc.importNode(root.querySelector('[data-report-detail-values]'), true));
            }
            frame.contentWindow.focus(); frame.contentWindow.print();
        }

        kind.addEventListener('change', buildFilters);
        perPage.addEventListener('change', changed);
        filters.addEventListener('input', changed);
        filters.addEventListener('change', changed);
        filters.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target.tagName === 'INPUT') { event.preventDefault(); event.stopPropagation(); query(0); }
        });
        root.addEventListener('click', function (event) {
            var record = event.target.closest('[data-report-row]');
            if (record && root.contains(record)) { event.preventDefault(); showDetail(Number(record.dataset.reportRow)); return; }
            var button = event.target.closest('[data-report-action]');
            if (!button || !root.contains(button) || button.disabled) { return; }
            event.preventDefault();
            switch (button.dataset.reportAction) {
                case 'query': query(0); break;
                case 'next': if (state.result) { query(state.result.next_offset, 'next'); } break;
                case 'previous': if (state.history.length) { query(state.history[state.history.length - 1], 'previous'); } break;
                case 'export': download(); break;
                case 'print': printPage(); break;
                case 'close-detail':
                    detail.hidden = true;
                    body.querySelectorAll('[data-report-row]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
                    break;
            }
        });
        buildFilters();
    }

    function ready() { document.querySelectorAll('.asaas-reports').forEach(initialize); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', ready); }
    else { ready(); }
}());
