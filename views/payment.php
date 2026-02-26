<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="kt-container kt-grid__item kt-grid__item--fluid mt-3">
    <div class="col-lg-12">
        <div class="kt-portlet" id="kt_portlet">
            <div class="kt-portlet__body"><?php echo validation_errors('<div class="alert alert-danger text-center">', '</div>'); ?>
                <div class="mtop15 preview-top-wrapper">
                    <div class="top" data-sticky data-sticky-class="preview-sticky-header">
                        <?php if (is_invoice_overdue($invoice)) { ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="text-center text-white danger-bg">
                                        <h5><?php echo _l('overdue_by_days', get_total_days_overdue($invoice->duedate)) ?></h5>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>


                        <div class="col-md-12">
                            <div class="pull-left">
                                <h4 class="bold no-mtop invoice-html-number no-mbot float-left" style="margin-right: 20px">
                                    <?php echo format_invoice_number($invoice->id); ?>
                                </h4>
                                <h4 class="invoice-html-status float-left">
                                    <?php echo format_invoice_status($invoice->status, '', true); ?>
                                </h4>
                            </div>
                            <div class="visible-xs">
                                <div class="clearfix"></div>
                            </div>
                            <a href="<?= site_url('invoice/' . $invoice->id . '/' . $invoice->hash); ?>" class="btn btn-info pull-right mtop5 mright5 action-button go-to-portal">
                                Voltar a fatura
                            </a>
                            <!--
                            <?php if (is_client_logged_in() && has_contact_permission('invoices')) { ?>
                                <a href="<?php echo site_url('clients/invoices/'); ?>"
                                   class="btn btn-default pull-right mtop5 mright5 action-button go-to-portal"> <?php echo _l('client_go_to_dashboard'); ?> </a>
                            <?php } ?>
                            -->
                            <div class="clearfix"></div>
                        </div>

                        <div class="col-md-12 mtop10">
                            <div class="alert alert-info mbot0">
                                <strong>Ambiente ativo:</strong>
                                <span class="label label-<?php echo ($environment === 'SANDBOX') ? 'warning' : 'success'; ?>">
                                    <?php echo $environment; ?>
                                </span>
                                <span class="text-muted mleft10">Selecione a forma de pagamento abaixo.</span>
                            </div>
                        </div>

                        <p>&nbsp;</p>
                        <h2 class="bold invoice-html-number" style="text-align: center">Selecione umas das opções abaixo para realizar o pagamento</h2>
                        <p>&nbsp;</p>

                        <div class="row">
                            <?php if ($billet_only == 1) { ?>
                                <?php if ($pix_only == 1 && $card_only == 1) { ?>
                                    <div class="col-md-4">
                                    <?php } ?>
                                    <?php if ($pix_only == 0 && $card_only == 1) { ?>
                                        <div class="col-md-6">
                                        <?php } ?>
                                        <?php if ($pix_only == 0 && $card_only == 0) { ?>
                                            <div class="col-md-6 col-md-offset-3">
                                            <?php } ?>
                                            <a href="<?php echo admin_url('asaas/checkout/boleto/' . $hash) ?>">
                                                <div class="thumbnail  text-center" style="min-height:100px; color:#000000"><i class="fa fa-barcode fa-5x" style="font-size:200px"></i>
                                                    <p class="lead">Boleto</p>
                                                </div>
                                            </a>
                                            </div>
                                        <?php } ?>
                                        <?php if ($card_only == 1) { ?>
                                            <?php if ($billet_only == 1 && $pix_only == 1) { ?>
                                                <div class="col-md-4">
                                                <?php } ?>
                                                <?php if ($billet_only == 0 && $pix_only == 1) { ?>
                                                    <div class="col-md-6">
                                                    <?php } ?>
                                                    <?php if ($billet_only == 0 && $pix_only == 0) { ?>
                                                        <div class="col-md-6 col-md-offset-3">
                                                        <?php } ?>
                                                        <a href="<?php echo admin_url('asaas/checkout/cartao/' . $hash) ?>">
                                                            <div class="thumbnail  text-center" style="min-height:100px; color:#000000"><i class="fa fa-credit-card fa-5x" style="font-size:200px"></i>
                                                                <p class="lead">Cartão de crédito</p>
                                                            </div>
                                                        </a>
                                                        </div>
                                                    <?php } ?>
                                                    <?php if ($pix_only == 1) { ?>
                                                        <?php if ($billet_only == 1 && $card_only == 1) { ?>
                                                            <div class="col-md-4">
                                                            <?php } ?>
                                                            <?php if ($billet_only == 0 && $card_only == 1) { ?>
                                                                <div class="col-md-6">
                                                                <?php } ?>
                                                                <?php if ($billet_only == 0 && $card_only == 0) { ?>
                                                                    <div class="col-md-6 col-md-offset-3">
                                                                    <?php } ?>
                                                                    <a href="<?php echo admin_url('asaas/checkout/qrcode/' . $hash) ?>">
                                                                        <div class="thumbnail  text-center" style="min-height:100px; color:#000000">
                                                                            <i class="fa fa-qrcode fa-5x" style="font-size:200px"></i>
                                                                            <p class="lead">PIX</p>
                                                                        </div>
                                                                    </a>
                                                                    </div>
                                                                <?php } ?>
                                                                </div>
                                                            </div>
                                                    </div>
                                                </div>
                                        </div>
                                    </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
