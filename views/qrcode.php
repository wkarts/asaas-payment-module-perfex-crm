<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="kt-container kt-grid__item kt-grid__item--fluid mt-3">
    <div class="col-lg-12">
        <div class="kt-portlet" id="kt_portlet">
            <div class="kt-portlet__body">
                <?php echo validation_errors('<div class="alert alert-danger text-center">', '</div>'); ?>
                <div class="mtop15 preview-top-wrapper">
                    <div class="top" data-sticky data-sticky-class="preview-sticky-header">
                        <?php if (is_invoice_overdue($invoice)) { ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="text-center text-white danger-bg">
                                        <h5><?php echo _l('overdue_by_days', get_total_days_overdue($invoice->duedate) ?: 0) ?></h5>
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
                                    <?php echo format_invoice_status($invoice->status ?: '', '', true); ?>
                                </h4>
                            </div>
                            <div class="visible-xs">
                                <div class="clearfix"></div>
                            </div>
                            <a href="<?= site_url('invoice/' . $invoice->id . '/' . $invoice->hash); ?>" class="btn btn-info pull-right mtop5 mright5 action-button go-to-portal">
                                Voltar a fatura
                            </a>
                            <?php if (is_client_logged_in() && has_contact_permission('invoices')) { ?>
                                <a href="<?php echo site_url('clients/invoices/'); ?>" class="btn btn-default pull-right mtop5 mright5 action-button go-to-portal"> <?php echo _l('client_go_to_dashboard') ?: ''; ?> </a>
                            <?php } ?>
                            <div class="clearfix"></div>
                        </div>
                        <div class="col-md-12 mtop10">
                            <div class="alert alert-info mbot0">
                                <strong>Ambiente ativo:</strong>
                                <span class="label label-<?php echo ($environment === 'SANDBOX') ? 'warning' : 'success'; ?>">
                                    <?php echo $environment; ?>
                                </span>
                                <span class="text-muted mleft10">Use o QR Code abaixo para concluir o pagamento.</span>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="col-md-10 col-md-offset-1">
                                <div class="row mtop20">
                                    <div class="col-md-6 col-sm-6 transaction-html-info-col-left">
                                        <h4 class="bold invoice-html-number"><?php echo format_invoice_number($invoice->id ?: ''); ?></h4>
                                        <address class="invoice-html-company-info">
                                            <?php echo format_organization_info() ?: ''; ?>
                                        </address>
                                    </div>
                                    <div class="col-sm-6 text-right transaction-html-info-col-right">
                                        <table class="table text-right">
                                            <tbody>
                                                <tr id="subtotal">
                                                    <td><span class="bold"><?php echo _l('invoice_subtotal') ?: ''; ?></span></td>
                                                    <td class="subtotal"><?php echo app_format_money($invoice->subtotal ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                </tr>
                                                <?php if (is_sale_discount_applied($invoice)) { ?>
                                                    <tr>
                                                        <td><span class="bold"><?php echo _l('invoice_discount') ?: ''; ?>
                                                                <?php if (is_sale_discount($invoice, ['percent' => true])) { ?>
                                                                    (<?php echo app_format_number($invoice->discount_percent ?: 0, true); ?>%)
                                                                <?php } ?>
                                                            </span></td>
                                                        <td class="discount"><?php echo '-' . app_format_money($invoice->discount_total ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                    </tr>
                                                <?php } ?>
                                                <?php if ((int)$invoice->adjustment != 0) { ?>
                                                    <tr>
                                                        <td><span class="bold"><?php echo _l('invoice_adjustment') ?: ''; ?></span></td>
                                                        <td class="adjustment"><?php echo app_format_money($invoice->adjustment ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                    </tr>
                                                <?php } ?>
                                                <tr>
                                                    <td><span class="bold"><?php echo _l('invoice_total') ?: ''; ?></span></td>
                                                    <td class="total"><?php echo app_format_money($invoice->total ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                </tr>
                                                <?php if (isset($invoice->payments) && count($invoice->payments) > 0 && get_option('show_total_paid_on_invoice') == 1) { ?>
                                                    <tr>
                                                        <td><span class="bold"><?php echo _l('invoice_total_paid') ?: ''; ?></span></td>
                                                        <td><?php echo '-' . app_format_money(sum_from_table(db_prefix() . 'invoicepaymentrecords', array('field' => 'amount', 'where' => array('invoiceid' => $invoice->id))), isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                    </tr>
                                                <?php } ?>
                                                <?php if (get_option('show_credits_applied_on_invoice') == 1 && $credits_applied = total_credits_applied_to_invoice($invoice->id)) { ?>
                                                    <tr>
                                                        <td><span class="bold"><?php echo _l('applied_credits') ?: ''; ?></span></td>
                                                        <td><?php echo '-' . app_format_money($credits_applied ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?></td>
                                                    </tr>
                                                <?php } ?>
                                                <?php if (get_option('show_amount_due_on_invoice') == 1 && $invoice->status != 2) { ?>
                                                    <tr>
                                                        <td><span class="<?php if ($invoice->total_left_to_pay > 0) {
                                                                            echo 'text-danger ';
                                                                        } ?>bold"><?php echo _l('invoice_amount_due') ?: ''; ?></span></td>
                                                        <td><span class="<?php if ($invoice->total_left_to_pay > 0) {
                                                                            echo 'text-danger';
                                                                        } ?>"> <?php echo app_format_money($invoice->total_left_to_pay ?: 0, isset($invoice->currency_name) ? $invoice->currency_name : 'BRL'); ?> </span></td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6 col-md-offset-6">
                                </div>
                            </div>
                            <?php if ($invoice->status !== 2) { ?>
                                <div class="clearfix"></div>
                                <hr>
                                <div class="row mtop50 qrcode">
                                    <div class="col-md-6 ">
                                        <ul>
                                            <li class="sc-bWNSNh ijsSky how-to pixx">
                                                <span><strong>1. Abra o app do seu banco e acesse o ambiente Pix.</strong></span>
                                            </li>
                                            <li class="sc-bWNSNh ijsSky how-to pixx">
                                                <span><strong>2. escolha a opção pagar com qr code e escaneie o código ao lado</strong></span>
                                            </li>
                                            <li class="sc-bWNSNh ijsSky how-to pixx">
                                                <span><strong>3. Confirme as informações e finalize o pagamento no seu APP.</strong></span>
                                            </li>
                                            <li class="sc-bWNSNh ijsSky how-to pixx">
                                                <span style="color: red"><strong>4. O pagamento leva até 1 minutos para ser confirmado.</strong></span>
                                            </li>
                                        </ul>
                                        <div class="form-group">
                                            <textarea rows="6" class="form-control text-center" id="payload" readonly><?php echo $response->payload ?: ''; ?></textarea>
                                        </div>
                                        <div class="text-center">
                                            <button class="btn btn-success " data-clipboard-action="copy" data-clipboard-target="#payload" type="button">
                                                Copiar a chave <span class="btn btn-success copiedtext hidden" aria-hidden="true">Copiado!</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group"><img class="img-responsive" src="data:image/gif;base64,<?php echo $response->encodedImage ?: ''; ?>" style="height:auto" /></div>
                                    </div>
                                </div>
                                <div class="clearfix"></div>
                                <hr>
                                <div class="col-md-4 col-md-offset-4 text-center">
                                    <img class="img-responsive  center-block" src="<?php echo site_url('modules/asaas/assets/img/spinner.gif') ?: ''; ?>" style="height:120px" />
                                    <h4> Aguardando pagamento</h4>
                                </div>
                                <div class="clearfix"></div>
                                <hr>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo site_url('modules/asaas/assets/js/clipboard.min.js') ?>" type="text/javascript"></script>
<script>
    var clipboard = new ClipboardJS('.btn');

    clipboard.on('success', function(e) {
        console.log(e);
        alert_float("success", "Copiado!")
    });

    clipboard.on('error', function(e) {
        console.log(e);
        alert_float("danger", "Erro ao copiar")
    });
</script>
<script>
    var hash = '<?php echo $invoice->hash ?: ''; ?>';

    var invoiceId = '<?php echo $invoice->id ?: ''; ?>';

    /*jshint esversion: 6 */

    $('.btn-copy-qrcode').click(function() {
        const _this = $(this);
        _this.text('Copiado com sucesso.');
        setTimeout(() => {
            _this.text('COPIAR CHAVE');
        }, 1000);
        const editor = document.getElementsByClassName('input-get-qrcode');
        editor[0].select();
        editor[0].focus();
        editor[1].select();
        editor[1].focus();
        document.execCommand('copy');
    });

    $('.btn-copy-barcode').click(function() {
        const _this = $(this);
        _this.text('Copiado com sucesso.');
        setTimeout(() => {
            _this.text('COPIAR CHAVE');
        }, 1000);
        const editor = document.getElementsByClassName('input-get-barcode');
        editor[0].select();
        editor[0].focus();
        editor[1].select();
        editor[1].focus();
        document.execCommand('copy');
    });

    setTimeout(() => {
        setInterval(() => {
            $.get('<?= site_url(); ?>asaas/get_invoice_data/' + hash, function(response) {
                console.log(response);

                if (response == '1') {
                    $('.btn-pagar').attr('disabled', true);
                    /*	$('.qrcode').remove(); */
                    $('.invoice-status-1').css({
                        color: "#84c529",
                        border: "1px solid #84c529",
                        background: "0 0"
                    }).text('Pagamento confirmado');

                    $('.invoice-html-status').find("span").removeClass('invoice-status-1 label-danger').addClass('invoice-status-2 label-success').html('PAGO');

                    /*	 alert_float("danger", "danger"); */
                    window.location.replace('<?= site_url(); ?>invoice/' + invoiceId + '/' + hash);
                }
            });
        }, 2000);
    }, 10000);
</script>
