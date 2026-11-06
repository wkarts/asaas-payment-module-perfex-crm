<?php
defined('BASEPATH') or exit('No direct script access allowed');

$cardHolderDefaults = isset($card_holder_defaults) && is_array($card_holder_defaults) ? $card_holder_defaults : [];
$cardInstallmentOptions = isset($installment_options) && is_array($installment_options) ? $installment_options : [];
$cardCheckoutBlocked = !empty($card_checkout_blocked) || $cardInstallmentOptions === [];
$cardHolderValue = static function (string $name) use ($cardHolderDefaults): string {
    return isset($cardHolderDefaults[$name]) && is_scalar($cardHolderDefaults[$name])
        ? html_escape((string) $cardHolderDefaults[$name])
        : '';
};
?>
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

                        <?php
                        $asaas_environment_hint = $cardCheckoutBlocked ? '' : 'Informe os dados do cartão para concluir o pagamento.';
                        include __DIR__ . '/partials/payment_environment.php';
                        ?>

                        <div class="panel-body">
        <div class="col-md-10 col-md-offset-1">
            <div class="row mtop20">
                <div class="col-md-6 col-sm-6 transaction-html-info-col-left">
                    <h4 class="bold invoice-html-number"><?php echo format_invoice_number($invoice->id); ?></h4>
                    <address class="invoice-html-company-info">
                        <?php echo format_organization_info(); ?>
                    </address>
                </div>
                <div class="col-sm-6 text-right transaction-html-info-col-right"><span
                        class="bold invoice-html-bill-to"><?php echo _l('invoice_bill_to'); ?>:</span>
                    <address class="invoice-html-customer-billing-info">
                        <?php echo format_customer_info($invoice, 'invoice', 'billing'); ?>
                    </address>
                    <!-- shipping details -->
                    <?php if ($invoice->include_shipping == 1 && $invoice->show_shipping_on_invoice == 1) { ?>
                        <span class="bold invoice-html-ship-to"><?php echo _l('ship_to'); ?>:</span>
                        <address class="invoice-html-customer-shipping-info">
                            <?php echo format_customer_info($invoice, 'invoice', 'shipping'); ?>
                        </address>
                    <?php } ?>
                    <p class="no-mbot invoice-html-date"><span class="bold"> <?php echo _l('invoice_data_date'); ?> </span> <?php echo _d($invoice->date); ?>
                    </p>
                    <?php if (!empty($invoice->duedate)) { ?>
                        <p class="no-mbot invoice-html-duedate"><span
                                class="bold"><?php echo _l('invoice_data_duedate'); ?></span> <?php echo _d($invoice->duedate); ?> </p>
                    <?php } ?>
                    <?php if ($invoice->sale_agent != 0 && get_option('show_sale_agent_on_invoices') == 1) { ?>
                        <p class="no-mbot invoice-html-sale-agent"><span
                                class="bold"><?php echo _l('sale_agent_string'); ?>:</span> <?php echo get_staff_full_name($invoice->sale_agent); ?> </p>
                    <?php } ?>
                    <?php if ($invoice->project_id != 0 && get_option('show_project_on_invoice') == 1) { ?>
                        <p class="no-mbot invoice-html-project"><span
                                class="bold"><?php echo _l('project'); ?>:</span> <?php echo get_project_name_by_id($invoice->project_id); ?> </p>
                    <?php } ?>
                    <?php $pdf_custom_fields = get_custom_fields('invoice', array('show_on_pdf' => 1, 'show_on_client_portal' => 1));
                    foreach ($pdf_custom_fields as $field) {
                        $value = get_custom_field_value($invoice->id, $field['id'], 'invoice');
                        if ($value == '') {
                            continue;
                        } ?>
                        <p class="no-mbot"><span class="bold"><?php echo $field['name']; ?>: </span> <?php echo $value; ?> </p>
                    <?php } ?>
                </div>
            </div>
                <div class="row">
                    <div class="col-md-6 col-md-offset-6">
                        <table class="table text-right">
                            <tbody>
                            <tr id="subtotal">
                                <td><span class="bold"><?php echo _l('invoice_subtotal'); ?></span></td>
                                <td class="subtotal"><?php echo app_format_money($invoice->subtotal, $invoice->currency_name); ?></td>
                            </tr>
                            <?php if (is_sale_discount_applied($invoice)) { ?>
                                <tr>
                                    <td><span class="bold"><?php echo _l('invoice_discount'); ?>
                                            <?php if (is_sale_discount($invoice, 'percent')) { ?>
                                                (<?php echo app_format_number($invoice->discount_percent, true); ?>%)
                                            <?php } ?>
                    </span></td>
                                    <td class="discount"><?php echo '-' . app_format_money($invoice->discount_total, $invoice->currency_name); ?></td>
                                </tr>
                            <?php } ?>
                            <?php

                            ?>
                            <?php if ((int)$invoice->adjustment != 0) { ?>
                                <tr>
                                    <td><span class="bold"><?php echo _l('invoice_adjustment'); ?></span></td>
                                    <td class="adjustment"><?php echo app_format_money($invoice->adjustment, $invoice->currency_name); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><span class="bold"><?php echo _l('invoice_total'); ?></span></td>
                                <td class="total"><?php echo app_format_money($invoice->total, $invoice->currency_name); ?></td>
                            </tr>
                            <?php if (count($invoice->payments) > 0 && get_option('show_total_paid_on_invoice') == 1) { ?>
                                <tr>
                                    <td><span class="bold"><?php echo _l('invoice_total_paid'); ?></span></td>
                                    <td><?php echo '-' . app_format_money(sum_from_table(db_prefix() . 'invoicepaymentrecords', array('field' => 'amount', 'where' => array('invoiceid' => $invoice->id))), $invoice->currency_name); ?></td>
                                </tr>
                            <?php } ?>
                            <?php if (get_option('show_credits_applied_on_invoice') == 1 && $credits_applied = total_credits_applied_to_invoice($invoice->id)) { ?>
                                <tr>
                                    <td><span class="bold"><?php echo _l('applied_credits'); ?></span></td>
                                    <td><?php echo '-' . app_format_money($credits_applied, $invoice->currency_name); ?></td>
                                </tr>
                            <?php } ?>
                            <?php if (get_option('show_amount_due_on_invoice') == 1 && $invoice->status != Invoices_model::STATUS_CANCELLED) { ?>
                                <tr>
                                    <td>
                                        <span class="<?= ($invoice->total_left_to_pay > 0) ? 'text-danger ' : ''; ?> bold">
                                            <?php echo _l('invoice_amount_due'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?= ($invoice->total_left_to_pay > 0) ? 'text-danger' : ''; ?>"> 
                                            <?php echo app_format_money($invoice->total_left_to_pay, $invoice->currency_name); ?> 
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-8 col-md-offset-2">
                        <?php if (!empty($mensagem_erro)) { ?>
                            <div class="alert alert-danger text-center" role="alert">ERRO: <?php echo html_escape($mensagem_erro); ?></div>
                        <?php } ?>
                        <?php if (!empty($mensagem_info)) { ?>
                            <div class="alert alert-info text-center" role="status"><?php echo html_escape($mensagem_info); ?></div>
                        <?php } elseif ($cardCheckoutBlocked) { ?>
                            <div class="alert alert-info text-center" role="status">O pagamento por cartão não está disponível para esta cobrança. Volte à fatura para consultar a situação do pagamento.</div>
                        <?php } ?>
                        <?php if (!$cardCheckoutBlocked) { ?>
                        <form id="asaas-card-checkout" action="" method="post">
                        <input type="hidden" name="<?php echo html_escape($this->security->get_csrf_token_name()); ?>" value="<?php echo html_escape($this->security->get_csrf_hash()); ?>">
                        <h3 class="text-center">Informe os dados do cartão</h3>
                        <div class="form-group">
                            <label class="control-label" for="holderName">Nome do titular</label>
                            <input id="holderName" name="holderName" type="text" placeholder="NOME DO TITULAR" class="form-control" autocomplete="cc-name" maxlength="100" value="<?php echo $cardHolderValue('holderName'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="cardNumber">Número do cartão</label>
                            <input id="cardNumber" name="cardNumber" type="text" placeholder="NUMERO DO CARTÃO" class="form-control"
                                   autocomplete="cc-number" inputmode="numeric" pattern="[ ]*(?:[0-9][ ]*){13,19}" maxlength="37" required>
                        </div>
                        <div class="row">
                            <div class="col-xs-6 col-md-3">
                                <div class="form-group">
                                    <label class="control-label" for="expirationMonth">Validade: mês</label>
                                    <input id="expirationMonth" name="expirationMonth" type="text" placeholder="MM" class="form-control" autocomplete="cc-exp-month" inputmode="numeric" pattern="(?:0?[1-9]|1[0-2])" maxlength="2" required>
                                </div>
                            </div>
                            <div class="col-xs-6 col-md-3">
                                <div class="form-group">
                                    <label class="control-label" for="expirationYear">Validade: ano</label>
                                    <input id="expirationYear" name="expirationYear" type="text" placeholder="AAAA" class="form-control" autocomplete="cc-exp-year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                                </div>
                            </div>
                            <div class="col-xs-2 col-md-2">
                                <div class="form-group">
                                    <label class="control-label" for="securityCode">CVV</label>
                                    <input id="securityCode" name="securityCode" type="password" placeholder="CVV" class="form-control" autocomplete="cc-csc" inputmode="numeric" pattern="[0-9]{3,4}" minlength="3" maxlength="4" required>
                                </div>
                            </div>
                            <div class="col-xs-4 col-md-4 pull-right">
                                <div class="form-group">
                                    <label class="control-label" for="installmentCount">Número de parcelas</label>
                                    <select id="installmentCount" class="form-control" name="installmentCount" required>
                                        <?php foreach ($cardInstallmentOptions as $cardInstallmentOption) { ?>
                                            <option value="<?php echo (int) $cardInstallmentOption['count']; ?>"><?php echo html_escape($cardInstallmentOption['label']); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <h4>Dados do titular do cartão</h4>
                        <div class="form-group">
                            <label class="control-label" for="holderEmail">E-mail do titular</label>
                            <input id="holderEmail" name="holderEmail" type="email" class="form-control" autocomplete="email" maxlength="254" value="<?php echo $cardHolderValue('holderEmail'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="holderDocument">CPF ou CNPJ do titular</label>
                            <input id="holderDocument" name="holderDocument" type="text" class="form-control" inputmode="numeric" autocomplete="off" maxlength="18" value="<?php echo $cardHolderValue('holderDocument'); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="form-group">
                                    <label class="control-label" for="holderPostalCode">CEP do titular</label>
                                    <input id="holderPostalCode" name="holderPostalCode" type="text" class="form-control" inputmode="numeric" autocomplete="postal-code" maxlength="9" value="<?php echo $cardHolderValue('holderPostalCode'); ?>" required>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-group">
                                    <label class="control-label" for="holderAddressNumber">Número do endereço</label>
                                    <input id="holderAddressNumber" name="holderAddressNumber" type="text" placeholder="Número ou S/N" class="form-control" autocomplete="off" maxlength="30" value="<?php echo $cardHolderValue('holderAddressNumber'); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="holderPhone">Telefone do titular com DDD</label>
                            <input id="holderPhone" name="holderPhone" type="tel" class="form-control" autocomplete="tel" inputmode="tel" maxlength="20" value="<?php echo $cardHolderValue('holderPhone'); ?>" required>
                        </div>
                        <p id="asaas-card-checkout-status" class="alert alert-info hidden" role="status" aria-live="polite" aria-atomic="true"></p>
                        <div class="form-group">
                            <button id="payment" class="subscribe btn btn-success btn-lg btn-block" type="submit">Confirmar</button>
                        </div>
                        </form>
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
<?php if (!$cardCheckoutBlocked) { ?>
    <script src="<?php echo html_escape(site_url('modules/asaas/assets/js/asaas-card-checkout.js')); ?>"></script>
<?php } ?>
