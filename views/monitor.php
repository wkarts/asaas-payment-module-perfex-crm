<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__ . '/../helpers/asaas_staff_helper.php';
if (!asaas_staff_can('view', 'asaas_monitor')) { return; }
init_head();
?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="pull-right">
                            <?php if (asaas_staff_can('view', 'asaas_reports')) { ?>
                            <a class="btn btn-default btn-sm" href="<?php echo html_escape(admin_url('asaas/reports')); ?>">Relatórios financeiros</a>
                            <?php } ?>
                        </div>
                        <h4 class="no-margin">Asaas</h4>
                        <hr class="hr-panel-heading">
                        <?php $asaasMonitorStandalone = true; require __DIR__ . '/settings/monitor.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
