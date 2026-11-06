<?php

defined('BASEPATH') or exit('No direct script access allowed');
if (!is_admin()) {
    return;
}
$asaasMonitorCi = &get_instance();
$asaasMonitorSecurity = $asaasMonitorCi->security;
?>
<section id="asaas-monitor" class="asaas-monitor"
         data-endpoint="<?php echo html_escape(admin_url('asaas/monitor/')); ?>"
         data-invoice-base="<?php echo html_escape(admin_url('invoices/list_invoices/')); ?>"
         data-csrf-name="<?php echo html_escape($asaasMonitorSecurity->get_csrf_token_name()); ?>"
         data-csrf-hash="<?php echo html_escape($asaasMonitorSecurity->get_csrf_hash()); ?>"
         aria-label="Monitoramento da integração Asaas">
    <div class="asaas-monitor-heading">
        <div>
            <h4 class="no-margin">Monitoramento e conciliação</h4>
            <p class="text-muted mtop5 mbot0">Acompanhe os registros de faturas, webhooks e operações da integração.</p>
        </div>
        <button type="button" class="btn btn-default btn-sm" data-monitor-action="refresh">Atualizar registros</button>
    </div>
    <p class="help-block mtop10"><strong>Comparar</strong> consulta agora o pagamento no Asaas, sem dar baixa. <strong>Conciliar</strong> consulta novamente e registra no CRM somente os recebimentos confirmados que faltam, com verificação de duplicidade.</p>
    <div data-monitor-feedback class="alert alert-info" role="status" aria-live="polite" hidden></div>

    <div class="asaas-monitor-summary" aria-label="Resumo da integração">
        <div class="asaas-monitor-card"><span>Faturas elegíveis em aberto</span><strong data-monitor-metric="eligible_open_invoices">—</strong><small>Com vínculo: <span data-monitor-metric="linked_open_invoices">—</span> · A recuperar: <span data-monitor-metric="unlinked_open_invoices">—</span></small></div>
        <div class="asaas-monitor-card"><span>Falhas de entrega do webhook</span><strong data-monitor-metric="webhook_errors">—</strong><small>Histórico de falhas. Eventos com erro de processamento: <span data-monitor-metric="webhook_event_errors">—</span></small><button type="button" class="btn btn-default btn-xs mtop10" data-monitor-action="show-errors" data-monitor-source="webhook">Ver falhas do webhook</button></div>
        <div class="asaas-monitor-card"><span>Erros no diagnóstico</span><strong data-monitor-metric="log_errors">—</strong><small>Registros históricos da integração.</small><button type="button" class="btn btn-default btn-xs mtop10" data-monitor-action="show-errors" data-monitor-source="all">Ver erros</button></div>
        <div class="asaas-monitor-card"><span>Sincronização automática</span><strong data-monitor-metric="cron_enabled">—</strong></div>
    </div>
    <div class="asaas-monitor-overview-details text-muted">
        <p><strong>Último webhook:</strong> <span data-monitor-metric="last_webhook_at">—</span></p>
        <p><strong>Última sincronização:</strong> <span data-monitor-metric="cron_last_run">—</span></p>
        <p data-monitor-metric="cron_configuration">Abra esta guia para consultar os registros.</p>
        <p>Quando habilitada, a conciliação automática verifica as faturas elegíveis em aberto, inclusive antigas e sem vínculo local, e registra somente recebimentos confirmados. O limite vale para cada execução; as demais seguem nas próximas rodadas. A janela em dias se aplica às faturas quitadas recentes. Pagamentos pendentes continuam abertos; falhas ficam registradas para conferência.</p>
    </div>

    <div class="asaas-monitor-tabs" role="tablist" aria-label="Registros da integração">
        <button id="asaas-monitor-tab-invoices" type="button" class="btn btn-default btn-sm active" role="tab" data-monitor-tab="invoices" aria-selected="true" aria-controls="asaas-monitor-invoices">Faturas</button>
        <button id="asaas-monitor-tab-payments" type="button" class="btn btn-default btn-sm" role="tab" data-monitor-tab="payments" aria-selected="false" aria-controls="asaas-monitor-payments" tabindex="-1">Cobranças no Asaas</button>
        <button id="asaas-monitor-tab-events" type="button" class="btn btn-default btn-sm" role="tab" data-monitor-tab="events" aria-selected="false" aria-controls="asaas-monitor-events" tabindex="-1">Webhooks</button>
        <button id="asaas-monitor-tab-logs" type="button" class="btn btn-default btn-sm" role="tab" data-monitor-tab="logs" aria-selected="false" aria-controls="asaas-monitor-logs" tabindex="-1">API e conciliações</button>
    </div>

    <div id="asaas-monitor-invoices" data-monitor-panel="invoices" role="tabpanel" aria-labelledby="asaas-monitor-tab-invoices">
        <div class="asaas-monitor-filters">
            <div class="form-group asaas-monitor-search">
                <label for="asaas-monitor-query">Buscar fatura ou cliente</label>
                <input id="asaas-monitor-query" type="search" class="form-control input-sm" maxlength="100" autocomplete="off" placeholder="Número da fatura ou nome do cliente">
            </div>
            <div class="form-group">
                <label for="asaas-monitor-invoice-status">Situação no CRM</label>
                <select id="asaas-monitor-invoice-status" class="form-control input-sm">
                    <option value="open">Em aberto</option>
                    <option value="suspected">Possíveis divergências</option>
                    <option value="missing">Vínculo a recuperar</option>
                    <option value="all">Todas</option>
                </select>
            </div>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="filter" data-monitor-kind="invoices">Filtrar</button>
        </div>
        <p id="asaas-monitor-payment-help" class="help-block">O pagamento no Asaas mostra o <strong>último estado conhecido</strong>; use <strong>Comparar</strong> para consultar agora, inclusive quando o vínculo precisa ser recuperado. <strong>Ainda não consultada</strong> não significa que o cliente pagou ou deixou de pagar. Um webhook processado não comprova pagamento. Um recebimento confirmado pode corresponder a uma parcela; confira o saldo da fatura.</p>
        <div class="asaas-monitor-batch-actions">
            <span data-monitor-selected-count class="text-muted">Nenhuma fatura selecionada.</span>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="compare-selected" disabled>Comparar selecionadas</button>
            <button type="button" class="btn btn-info btn-sm" data-monitor-action="reconcile-selected" disabled>Conciliar selecionadas</button>
        </div>
        <div class="table-responsive asaas-monitor-table">
            <table class="table table-striped table-hover" aria-describedby="asaas-monitor-payment-help">
                <thead><tr>
                    <th class="asaas-monitor-check"><input id="asaas-monitor-select-all" type="checkbox" aria-label="Selecionar faturas desta página"></th>
                    <th>Fatura / cliente</th><th>CRM</th><th>Pagamento no Asaas</th><th>Valor / saldo</th><th>Últimos registros</th><th>Ações</th>
                </tr></thead>
                <tbody data-monitor-list="invoices"><tr><td colspan="7" class="text-muted">Abra Monitoramento para consultar as faturas.</td></tr></tbody>
            </table>
        </div>
        <div class="asaas-monitor-pagination">
            <span data-monitor-page="invoices" class="text-muted">Página 1</span>
            <div><button type="button" class="btn btn-default btn-sm" data-monitor-action="previous" data-monitor-kind="invoices" disabled>Anterior</button> <button type="button" class="btn btn-default btn-sm" data-monitor-action="next" data-monitor-kind="invoices" disabled>Próxima</button></div>
        </div>
    </div>

    <div id="asaas-monitor-payments" data-monitor-panel="payments" role="tabpanel" aria-labelledby="asaas-monitor-tab-payments" hidden>
        <p id="asaas-monitor-remote-help" class="help-block">Consulte diretamente as cobranças da conta Asaas do ambiente ativo. Esta lista não cria vínculos nem registra pagamentos. <strong>Pendente</strong> ou <strong>Vencido</strong> não confirma recebimento; <strong>Confirmado</strong> e <strong>Recebido</strong> indicam pagamento. Em parcelamentos, cada linha pode representar uma parcela.</p>
        <div class="asaas-monitor-filters asaas-monitor-remote-filters">
            <div class="form-group">
                <label for="asaas-monitor-payment-status">Situação no Asaas</label>
                <select id="asaas-monitor-payment-status" class="form-control input-sm">
                    <option value="all">Todas</option><option value="PENDING">Pendente</option><option value="OVERDUE">Vencido</option><option value="CONFIRMED">Confirmado</option><option value="RECEIVED">Recebido</option><option value="RECEIVED_IN_CASH">Recebido em dinheiro</option>
                </select>
            </div>
            <div class="form-group">
                <label for="asaas-monitor-payment-date-field">Filtrar por data de</label>
                <select id="asaas-monitor-payment-date-field" class="form-control input-sm"><option value="payment">Pagamento</option><option value="due">Vencimento</option><option value="created">Criação</option></select>
            </div>
            <?php // Read-only date filters must not participate in the native settings form validation. ?>
            <div class="form-group"><label for="asaas-monitor-payment-date-from">De (opcional)</label><input id="asaas-monitor-payment-date-from" type="date" form="" class="form-control input-sm"></div>
            <div class="form-group"><label for="asaas-monitor-payment-date-to">Até (opcional)</label><input id="asaas-monitor-payment-date-to" type="date" form="" class="form-control input-sm"></div>
            <div class="form-group">
                <label for="asaas-monitor-payment-per-page">Por página</label>
                <select id="asaas-monitor-payment-per-page" class="form-control input-sm"><option value="25">25 cobranças</option><option value="50">50 cobranças</option></select>
            </div>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="filter" data-monitor-kind="payments">Consultar Asaas</button>
        </div>
        <p class="help-block">Sem datas, a consulta não limita o período. Comparar e Conciliar ficam disponíveis somente quando a cobrança corresponde com segurança a uma fatura deste CRM. As ações de uma parcela conferem a fatura e o parcelamento completo.</p>
        <div class="asaas-monitor-batch-actions">
            <span data-monitor-payment-selected-count class="text-muted">Nenhuma fatura selecionada.</span>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="compare-selected" data-monitor-kind="payments" disabled>Comparar selecionadas</button>
            <button type="button" class="btn btn-info btn-sm" data-monitor-action="reconcile-selected" data-monitor-kind="payments" disabled>Conciliar selecionadas</button>
        </div>
        <div class="table-responsive asaas-monitor-table">
            <table class="table table-striped table-hover" aria-describedby="asaas-monitor-remote-help">
                <thead><tr><th class="asaas-monitor-check"><input id="asaas-monitor-payment-select-all" type="checkbox" aria-label="Selecionar faturas elegíveis das cobranças desta página"></th><th>Cobrança</th><th>Pagamento no Asaas</th><th>Fatura no CRM</th><th>Valor</th><th>Datas</th><th>Ações</th></tr></thead>
                <tbody data-monitor-list="payments"><tr><td colspan="7" class="text-muted">Selecione Cobranças no Asaas para consultar a conta.</td></tr></tbody>
            </table>
        </div>
        <div class="asaas-monitor-pagination">
            <span data-monitor-page="payments" class="text-muted">Página 1</span>
            <div><button type="button" class="btn btn-default btn-sm" data-monitor-action="previous" data-monitor-kind="payments" disabled>Anterior</button> <button type="button" class="btn btn-default btn-sm" data-monitor-action="next" data-monitor-kind="payments" disabled>Próxima</button></div>
        </div>
    </div>

    <div id="asaas-monitor-events" data-monitor-panel="events" role="tabpanel" aria-labelledby="asaas-monitor-tab-events" hidden>
        <p class="help-block">Esta lista mostra as notificações recebidas; processar um evento não significa que houve pagamento. Falhas de entrega, como token recusado, podem ser consultadas em API e conciliações, com origem Webhook.</p>
        <div class="asaas-monitor-filters">
            <div class="form-group">
                <label for="asaas-monitor-event-status">Processamento</label>
                <select id="asaas-monitor-event-status" class="form-control input-sm">
                    <option value="all">Todos</option><option value="processed">Processados</option><option value="error">Com erro</option><option value="received">Recebidos / pendentes</option>
                </select>
            </div>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="filter" data-monitor-kind="events">Filtrar</button>
        </div>
        <div class="table-responsive asaas-monitor-table">
            <table class="table table-striped"><thead><tr><th>Evento</th><th>Fatura / cobrança</th><th>Recebido / processado</th><th>Situação</th><th>Diagnóstico</th></tr></thead>
                <tbody data-monitor-list="events"><tr><td colspan="5" class="text-muted">Selecione Webhooks para consultar os eventos.</td></tr></tbody>
            </table>
        </div>
        <div class="asaas-monitor-pagination">
            <span data-monitor-page="events" class="text-muted">Página 1</span>
            <div><button type="button" class="btn btn-default btn-sm" data-monitor-action="previous" data-monitor-kind="events" disabled>Anterior</button> <button type="button" class="btn btn-default btn-sm" data-monitor-action="next" data-monitor-kind="events" disabled>Próxima</button></div>
        </div>
    </div>

    <div id="asaas-monitor-logs" data-monitor-panel="logs" role="tabpanel" aria-labelledby="asaas-monitor-tab-logs" hidden>
        <div class="asaas-monitor-filters">
            <div class="form-group"><label for="asaas-monitor-log-level">Nível</label>
                <select id="asaas-monitor-log-level" class="form-control input-sm"><option value="all">Todos</option><option value="error">Erro</option><option value="warning">Aviso</option><option value="info">Informação</option><option value="debug">Diagnóstico</option></select>
            </div>
            <div class="form-group"><label for="asaas-monitor-log-source">Origem</label>
                <select id="asaas-monitor-log-source" class="form-control input-sm"><option value="all">Todas</option><option value="api">API</option><option value="webhook">Webhook</option><option value="reconciliation">Conciliação</option></select>
            </div>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="filter" data-monitor-kind="logs">Filtrar</button>
        </div>
        <div class="table-responsive asaas-monitor-table">
            <table class="table table-striped"><thead><tr><th>Data</th><th>Nível</th><th>Diagnóstico</th><th>Correlação</th></tr></thead>
                <tbody data-monitor-list="logs"><tr><td colspan="4" class="text-muted">Selecione API e conciliações para consultar o diagnóstico.</td></tr></tbody>
            </table>
        </div>
        <div class="asaas-monitor-pagination">
            <span data-monitor-page="logs" class="text-muted">Página 1</span>
            <div><button type="button" class="btn btn-default btn-sm" data-monitor-action="previous" data-monitor-kind="logs" disabled>Anterior</button> <button type="button" class="btn btn-default btn-sm" data-monitor-action="next" data-monitor-kind="logs" disabled>Próxima</button></div>
        </div>
    </div>

    <section data-monitor-task class="asaas-monitor-task" aria-label="Resultado da comparação ou conciliação" hidden>
        <div class="asaas-monitor-heading">
            <h5 data-monitor-task-title class="no-margin">Resultado das operações</h5>
            <button type="button" class="btn btn-default btn-sm" data-monitor-action="stop" disabled>Parar lote</button>
        </div>
        <p data-monitor-task-message class="mtop10" role="status" aria-live="polite"></p>
        <div class="progress asaas-monitor-progress"><div data-monitor-progress class="progress-bar progress-bar-info" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%"></div></div>
        <div class="table-responsive asaas-monitor-table">
            <table class="table table-striped"><thead><tr><th>Fatura</th><th>Resultado</th><th>CRM / Asaas</th><th>Recebimentos</th><th>Mensagem</th></tr></thead><tbody data-monitor-results></tbody></table>
        </div>
    </section>
    <noscript><p class="alert alert-info mtop15">Ative o JavaScript para consultar os registros e executar a conciliação nesta guia.</p></noscript>
</section>
