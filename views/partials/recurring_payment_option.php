<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<link rel="stylesheet" href="<?php echo html_escape(site_url('modules/asaas/assets/css/asaas-recurring-invoice.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/asaas-recurring-invoice.css')); ?>">
<div class="asaas-recurring-invoice-option" data-asaas-recurring-invoice-option>
    <div class="asaas-recurring-invoice-card">
        <div class="asaas-recurring-invoice-content">
            <h4>Cartão de crédito recorrente</h4>
            <p class="text-muted"><?php echo (int) $invoice->status === 2
                ? 'Consulte ou desative a autorização para as próximas cobranças automáticas.'
                : 'Pague esta fatura e as próximas cobranças Asaas automaticamente no cartão.'; ?></p>
            <p class="text-muted asaas-recurring-invoice-help"><?php echo (int) $invoice->status === 2
                ? 'A desativação vale para cobranças futuras; os pagamentos já realizados são mantidos.'
                : 'Entre na sua conta para autorizar. Se preferir, pague somente esta fatura pelos meios abaixo.'; ?></p>
        </div>
        <a class="btn btn-default asaas-recurring-invoice-action" href="<?php echo html_escape(site_url('asaas/recurring/index/' . rawurlencode((string) $invoice->hash))); ?>">
            <?php echo (int) $invoice->status === 2 ? 'Gerenciar autorização recorrente' : 'Cadastrar cartão recorrente'; ?>
        </a>
    </div>
</div>
