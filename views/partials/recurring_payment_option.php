<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<link rel="stylesheet" href="<?php echo html_escape(site_url('modules/asaas/assets/css/asaas-recurring-invoice.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/asaas-recurring-invoice.css')); ?>">
<div class="asaas-recurring-invoice-option" data-asaas-recurring-invoice-option>
    <div class="asaas-recurring-invoice-card">
        <div class="asaas-recurring-invoice-content">
            <h4>Cartão de crédito recorrente</h4>
            <p class="text-muted"><?php echo (int) $invoice->status === 2
                ? 'Consulte ou cancele a autorização das próximas cobranças automáticas deste cliente.'
                : 'Autorize o pagamento desta fatura e das próximas faturas Asaas deste cliente. Você pode cancelar a autorização pela sua conta.'; ?></p>
            <p class="text-muted asaas-recurring-invoice-help">O cadastro da autorização exige acesso à conta do cliente. O pagamento avulso continua disponível nos meios da fatura.</p>
        </div>
        <a class="btn btn-default asaas-recurring-invoice-action" href="<?php echo html_escape(site_url('asaas/recurring/index/' . rawurlencode((string) $invoice->hash))); ?>">
            <?php echo (int) $invoice->status === 2 ? 'Gerenciar autorização recorrente' : 'Cadastrar cartão recorrente'; ?>
        </a>
    </div>
</div>
