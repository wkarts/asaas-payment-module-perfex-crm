<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<link rel="stylesheet" href="<?php echo html_escape(module_dir_url('asaas', 'assets/css/asaas-automatic-pix.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/asaas-automatic-pix.css')); ?>">
<div class="asaas-pix-invoice-option">
    <div class="asaas-pix-invoice-card">
    <div><h4>Pix Automático</h4><p class="text-muted"><?php echo (int) $invoice->status === 2 ? 'Consulte ou cancele a autorização dos próximos pagamentos.' : 'Pague esta fatura por Pix e autorize no seu banco os próximos pagamentos automáticos.'; ?></p></div>
    <a class="btn btn-default" href="<?php echo html_escape(site_url('asaas/automatic_pix/index/' . rawurlencode((string) $invoice->hash))); ?>"><?php echo (int) $invoice->status === 2 ? 'Gerenciar autorização Pix' : 'Autorizar Pix Automático'; ?></a>
    </div>
</div>
