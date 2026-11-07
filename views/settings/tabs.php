<?php
defined('BASEPATH') or exit('No direct script access allowed');

$asaasSettingsGroups = [
    'general' => ['Geral', 'Identificação do meio de pagamento e informações exibidas na fatura.'],
    'environment' => ['Ambiente', 'Selecione o ambiente das operações e configure as credenciais correspondentes.'],
    'payments' => ['Pagamentos', 'Formas de pagamento disponíveis para o cliente.'],
    'charges' => ['Cobranças', 'Vencimento, encargos, descontos e opções de parcelamento do cartão.'],
    'webhook' => ['Webhook', 'Autenticação e endereço de recebimento dos eventos do Asaas.'],
    'sync' => ['Sincronização', 'Atualizações das faturas, recebimentos manuais e conciliação automática.'],
    'logs' => ['Logs', 'Histórico e diagnóstico da integração.'],
    'monitoring' => ['Monitoramento', 'Acompanhe eventos, chamadas à API e divergências de recebimentos.'],
    'reports' => ['Relatórios', 'Extrato, cobranças e informações financeiras da conta Asaas, com filtros e exportação.'],
];
?>
<div id="asaas-settings-groups" class="asaas-settings-groups hide" hidden>
    <div class="horizontal-scrollable-tabs asaas-settings-navigation">
        <button type="button" class="asaas-settings-scroll asaas-settings-scroll-left btn btn-default btn-icon"
                data-asaas-settings-scroll="backward" aria-label="Rolar guias para a esquerda" hidden>
            <i class="fa fa-angle-left" aria-hidden="true"></i>
        </button>
        <div class="horizontal-tabs asaas-settings-tab-scroll">
            <ul class="nav nav-tabs nav-tabs-horizontal" role="tablist" aria-label="Configurações do Asaas">
                <?php foreach ($asaasSettingsGroups as $asaasGroupId => [$asaasGroupLabel, $asaasGroupDescription]) { ?>
                <li role="presentation" class="<?php echo $asaasGroupId === 'general' ? 'active' : ''; ?>">
                    <a id="asaas-settings-tab-<?php echo html_escape($asaasGroupId); ?>"
                       href="#asaas-settings-<?php echo html_escape($asaasGroupId); ?>"
                       aria-controls="asaas-settings-<?php echo html_escape($asaasGroupId); ?>"
                       role="tab" data-toggle="tab" data-asaas-settings-group="<?php echo html_escape($asaasGroupId); ?>"
                       aria-selected="<?php echo $asaasGroupId === 'general' ? 'true' : 'false'; ?>"
                       tabindex="<?php echo $asaasGroupId === 'general' ? '0' : '-1'; ?>"><?php echo html_escape($asaasGroupLabel); ?></a>
                </li>
                <?php } ?>
            </ul>
        </div>
        <button type="button" class="asaas-settings-scroll asaas-settings-scroll-right btn btn-default btn-icon"
                data-asaas-settings-scroll="forward" aria-label="Rolar guias para a direita" hidden>
            <i class="fa fa-angle-right" aria-hidden="true"></i>
        </button>
    </div>
    <div class="tab-content asaas-settings-content mtop20">
        <?php foreach ($asaasSettingsGroups as $asaasGroupId => [$asaasGroupLabel, $asaasGroupDescription]) { ?>
        <div id="asaas-settings-<?php echo html_escape($asaasGroupId); ?>"
             class="tab-pane<?php echo $asaasGroupId === 'general' ? ' active' : ''; ?>"
             role="tabpanel" data-asaas-settings-panel="<?php echo html_escape($asaasGroupId); ?>"
             aria-labelledby="asaas-settings-tab-<?php echo html_escape($asaasGroupId); ?>"
             <?php echo $asaasGroupId === 'general' ? '' : 'hidden'; ?>>
            <p class="help-block asaas-settings-description"><?php echo html_escape($asaasGroupDescription); ?></p>
            <?php if ($asaasGroupId === 'charges' && is_file(__DIR__ . '/charges.php')) {
                require __DIR__ . '/charges.php';
            } elseif ($asaasGroupId === 'monitoring' && is_file(__DIR__ . '/monitor.php')) {
                require __DIR__ . '/monitor.php';
            } elseif ($asaasGroupId === 'logs' && is_file(__DIR__ . '/logs.php')) {
                require __DIR__ . '/logs.php';
            } elseif ($asaasGroupId === 'reports' && is_file(__DIR__ . '/reports.php')) {
                require __DIR__ . '/reports.php';
            } ?>
        </div>
        <?php } ?>
    </div>
</div>
