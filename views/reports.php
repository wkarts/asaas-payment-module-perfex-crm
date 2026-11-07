<?php

defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('asaas_staff_can') || !asaas_staff_can('view', 'asaas_reports')) { access_denied('asaas_reports'); return; }
init_head();
?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <?php $this->load->view('asaas/settings/reports', ['asaasReportsStandalone' => true]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
