<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="panel panel-default mtop20" data-asaas-recurring-invoice-option>
    <div class="panel-body">
        <h4 class="no-margin">Cartão de crédito recorrente</h4>
        <p class="text-muted mtop10"><?php echo (int) $invoice->status === 2
            ? 'Consulte ou cancele a autorização das próximas cobranças automáticas deste cliente.'
            : 'Autorize o pagamento desta fatura e das próximas faturas Asaas deste cliente. Você pode cancelar a autorização pela sua conta.'; ?></p>
        <a class="btn btn-default" href="<?php echo html_escape(site_url('asaas/recurring/index/' . rawurlencode((string) $invoice->hash))); ?>">
            <?php echo (int) $invoice->status === 2 ? 'Gerenciar autorização recorrente' : 'Cadastrar cartão recorrente'; ?>
        </a>
        <p class="text-muted mtop10 mbot0">O cadastro da autorização exige acesso à conta do cliente. O pagamento avulso continua disponível nos meios da fatura.</p>
    </div>
</div>
