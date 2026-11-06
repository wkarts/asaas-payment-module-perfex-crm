<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../../helpers/asaas_checkout_display_helper.php';

$asaasCheckoutEnvironment = isset($environment) && is_string($environment) ? trim($environment) : '';
$asaasCheckoutHint = isset($asaas_environment_hint) && is_string($asaas_environment_hint) ? $asaas_environment_hint : '';
?>
<?php if (asaas_checkout_show_environment($asaasCheckoutEnvironment)) { ?>
    <div class="col-md-12 mtop10">
        <div class="alert alert-info mbot0">
            <strong>Ambiente ativo:</strong>
            <span class="label label-<?php echo strtoupper($asaasCheckoutEnvironment) === 'PRODUCTION' ? 'success' : 'warning'; ?>">
                <?php echo html_escape(asaas_checkout_environment_label($asaasCheckoutEnvironment)); ?>
            </span>
            <?php if ($asaasCheckoutHint !== '') { ?>
                <span class="text-muted mleft10"><?php echo html_escape($asaasCheckoutHint); ?></span>
            <?php } ?>
        </div>
    </div>
<?php } ?>
