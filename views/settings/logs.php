<?php

defined('BASEPATH') or exit('No direct script access allowed');
if (!is_admin()) {
    return;
}
$asaasLogsCi = &get_instance();
$asaasLogsSecurity = $asaasLogsCi->security;
?>
<section id="asaas-logs" class="asaas-logs mtop20"
         data-endpoint="<?php echo html_escape(admin_url('asaas/asaas/logs_data')); ?>"
         data-csrf-name="<?php echo html_escape($asaasLogsSecurity->get_csrf_token_name()); ?>"
         data-csrf-hash="<?php echo html_escape($asaasLogsSecurity->get_csrf_hash()); ?>"
         aria-label="Registros e diagnóstico da integração Asaas">
    <div class="asaas-logs-heading">
        <div>
            <h4 class="no-margin">Registros da integração</h4>
            <p class="text-muted mtop5 mbot0">Últimos 100 registros, do mais recente para o mais antigo. Chaves, tokens e dados pessoais são ocultados.</p>
        </div>
        <button type="button" class="btn btn-default btn-sm" data-logs-action="refresh">Atualizar registros de log</button>
    </div>
    <p class="help-block mtop10">O diagnóstico abaixo usa as configurações salvas. Consulte o resultado das operações sem sair desta guia.</p>
    <div data-logs-feedback class="alert alert-info" role="status" aria-live="polite">Abra a guia Logs para consultar o diagnóstico.</div>
    <div data-logs-content hidden>
        <div data-logs-database class="alert alert-warning" role="status" hidden></div>
        <div class="alert alert-info asaas-logs-file">
            <p><strong>Log em arquivo: <span data-logs-file-enabled>—</span>.</strong></p>
            <div data-logs-file-details hidden>
                <p>Arquivo do dia: <code data-logs-file-path></code></p>
                <p data-logs-file-exists></p>
                <p data-logs-directory-writable></p>
            </div>
            <p data-logs-path-unavailable hidden>O diretório local de logs não pôde ser determinado. Revise a configuração <code>log_path</code> do CodeIgniter.</p>
            <p>O arquivo usa proteção PHP contra acesso direto. Preserve também a proteção do diretório de logs no servidor. Os arquivos padrão do CodeIgniter dependem do <code>log_threshold</code> configurado.</p>
        </div>
        <div data-logs-source class="alert alert-info" hidden></div>
        <p data-logs-count class="text-muted"></p>
        <div data-logs-empty class="alert alert-info" hidden>Nenhum registro foi encontrado. Após uma nova tentativa de integração, atualize os registros para consultar o resultado.</div>
        <div data-logs-table class="table-responsive asaas-logs-table" hidden>
            <table class="table table-striped table-hover">
                <thead><tr><th>Data</th><th>Nível</th><th>Diagnóstico</th><th>Correlação</th></tr></thead>
                <tbody data-logs-rows></tbody>
            </table>
        </div>
    </div>
    <noscript><p class="alert alert-info">Use “Ver logs da integração” para abrir o visualizador completo quando o JavaScript estiver desabilitado.</p></noscript>
</section>
