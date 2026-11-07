<?php

defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('asaas_staff_can') || !asaas_staff_can('view', 'asaas_reports')) { return; }
require_once __DIR__ . '/../../libraries/Asaas/AsaasReportCatalog.php';
$asaasReportsCatalog = \Asaas\AsaasReportCatalog::publicDefinitions();
$asaasReportsCanExport = asaas_staff_can('export', 'asaas_reports');
$asaasReportsGroups = [];
foreach ($asaasReportsCatalog as $asaasReport) { $asaasReportsGroups[$asaasReport['group']][] = $asaasReport; }
?>
<section id="asaas-reports" class="asaas-reports"
         data-endpoint="<?php echo html_escape(admin_url('asaas/reports/')); ?>"
         data-can-export="<?php echo $asaasReportsCanExport ? '1' : '0'; ?>"
         aria-label="Relatórios financeiros Asaas">
    <div class="asaas-reports-heading">
        <div>
            <h4 class="no-margin">Relatórios financeiros</h4>
            <p class="text-muted mtop5 mbot0">Consulte a conta Asaas, confira os resultados e exporte os dados para análise.</p>
        </div>
        <?php if (empty($asaasReportsStandalone)) { ?>
            <a class="btn btn-default btn-sm" href="<?php echo html_escape(admin_url('asaas/reports')); ?>">Abrir tela completa</a>
        <?php } ?>
    </div>
    <div class="asaas-reports-selection mtop20">
        <div class="form-group">
            <label for="asaas-reports-kind">Relatório</label>
            <select id="asaas-reports-kind" class="form-control" form="" data-report-kind>
                <?php foreach ($asaasReportsGroups as $asaasReportsGroup => $asaasReportsOptions) { ?>
                    <optgroup label="<?php echo html_escape($asaasReportsGroup); ?>">
                        <?php foreach ($asaasReportsOptions as $asaasReport) { ?>
                            <option value="<?php echo html_escape($asaasReport['id']); ?>"><?php echo html_escape($asaasReport['title']); ?></option>
                        <?php } ?>
                    </optgroup>
                <?php } ?>
            </select>
        </div>
        <div class="form-group asaas-reports-page-size">
            <label for="asaas-reports-per-page">Registros por página</label>
            <select id="asaas-reports-per-page" class="form-control" form="" data-report-per-page>
                <option value="25">25</option><option value="50">50</option><option value="100">100</option>
            </select>
        </div>
    </div>
    <p data-report-description class="help-block"></p>
    <div data-report-filters class="asaas-reports-filters" aria-label="Filtros do relatório"></div>
    <div class="asaas-reports-actions">
        <button type="button" class="btn btn-info" data-report-action="query"><i class="fa fa-search" aria-hidden="true"></i> Consultar</button>
        <?php if ($asaasReportsCanExport) { ?>
            <button type="button" class="btn btn-default" data-report-action="export"><i class="fa fa-download" aria-hidden="true"></i> Exportar CSV completo</button>
            <button type="button" class="btn btn-default" data-report-action="print" disabled><i class="fa fa-print" aria-hidden="true"></i> Imprimir página / PDF</button>
        <?php } ?>
    </div>
    <p class="help-block asaas-reports-export-help">
        A consulta usa a conta do ambiente ativo. Os filtros disponíveis seguem cada recurso da API.
        <?php if ($asaasReportsCanExport) { ?>O CSV inclui todas as páginas dos filtros escolhidos; a impressão inclui a página exibida.<?php } ?>
    </p>
    <div data-report-feedback class="alert alert-info" role="status" aria-live="polite" hidden></div>
    <div data-report-results hidden>
        <div class="asaas-reports-result-heading">
            <div><h5 data-report-title class="no-margin"></h5><p data-report-scope class="text-muted mtop5 mbot0"></p></div>
            <span data-report-environment class="label label-default"></span>
        </div>
        <div class="asaas-reports-meta text-muted">
            <span data-report-generated></span><span data-report-count></span>
        </div>
        <div class="table-responsive asaas-reports-table" tabindex="0" aria-label="Dados do relatório; deslize para consultar todas as colunas">
            <table class="table table-striped table-hover">
                <thead data-report-head></thead>
                <tbody data-report-rows></tbody>
            </table>
        </div>
        <section id="asaas-reports-detail" data-report-detail class="asaas-reports-detail" aria-labelledby="asaas-reports-detail-title" hidden>
            <div class="asaas-reports-heading">
                <h5 id="asaas-reports-detail-title" data-report-detail-title class="no-margin">Detalhes do registro</h5>
                <button type="button" class="btn btn-default btn-xs" data-report-action="close-detail">Fechar detalhes</button>
            </div>
            <dl data-report-detail-values></dl>
        </section>
        <div class="asaas-reports-pagination">
            <span data-report-page class="text-muted"></span>
            <div>
                <button type="button" class="btn btn-default btn-sm" data-report-action="previous" disabled>Anterior</button>
                <button type="button" class="btn btn-default btn-sm" data-report-action="next" disabled>Próxima</button>
            </div>
        </div>
    </div>
    <noscript><p class="alert alert-info">Ative o JavaScript para consultar e exportar os relatórios nesta área.</p></noscript>
    <script type="application/json" data-report-catalog><?php echo json_encode($asaasReportsCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
</section>
