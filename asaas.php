<?php

/*
//Debug Mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

/**
 * Ensures that the module init file can't be accessed directly, only within the application.
 */
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Asaas - Módulo de Pagamento
Description: Integração com Sistema Financeiro Asaas com a função de recebimento via catão de crédito, Boleto e Pix.
Author: WWSoft -> Wallace Kleiton - Fix QRCode | Enable Módule | Nova Asaas SDK 0.1.3
Version: 1.3.4
Requires at least: 2.9.*
Author URI: https://t.me/wkartspro
*/

define('ASAAS_MODULE_NAME', 'asaas');

hooks()->add_action('before_render_payment_gateway_settings', 'asaas_before_render_payment_gateway_settings');
hooks()->add_action('app_admin_footer', 'asaas_settings_tab_footer');
hooks()->add_action('after_invoice_added', 'asaas_after_invoice_added');
hooks()->add_action('after_invoice_updated', 'asaas_after_invoice_updated');
hooks()->add_action('before_invoice_deleted', 'asaas_before_invoice_deleted');

$CI = &get_instance();

register_activation_hook(ASAAS_MODULE_NAME, 'asaas_module_activation_hook');

function asaas_module_activation_hook() {
    $CI = &get_instance();
    $mediaPath = FCPATH . $CI->app->get_media_folder() . '/' . ASAAS_MODULE_NAME;

    if (!file_exists($mediaPath)) {
        mkdir($mediaPath, 0755, true);
        fopen(rtrim($mediaPath, '/') . '/' . 'index.html', 'w');
    }

    require_once(__DIR__ . '/install.php');
}

if (function_exists('app_payment_gateways')) {
    $gateways = app_payment_gateways();
    if (is_object($gateways)) {
        if (method_exists($gateways, 'add_gateway')) {
            $gateways->add_gateway('asaas_gateway');
        } elseif (method_exists($gateways, 'addGateway')) {
            $gateways->addGateway('asaas_gateway');
        } else {
            register_payment_gateway('asaas_gateway', ASAAS_MODULE_NAME);
        }
    } else {
        register_payment_gateway('asaas_gateway', ASAAS_MODULE_NAME);
    }
} else {
    register_payment_gateway('asaas_gateway', ASAAS_MODULE_NAME);
}

function asaas_before_render_payment_gateway_settings($gateway)
{
    return $gateway;
}

function asaas_settings_tab_footer()
{
?>
    <script>
        $(document).ready(function() {

            $(".form-control datepicker").attr("required", "true");

            function validate_invoice_form(e) {
                e = void 0 === e ? "#invoice-form" : e,
                    appValidateForm($(e), {
                        clientid: {
                            required: {
                                depends: function() {
                                    return !$("select#clientid").hasClass("customer-removed")
                                }
                            }
                        },
                        date: "required",
                        currency: "required",
                        repeat_every_custom: {
                            min: 1
                        },
                        number: {
                            required: !0
                        }
                    }), $("body").find('input[name="number"]').rules("add", {
                        remote: {
                            url: admin_url + "invoices/validate_invoice_number",
                            type: "post",
                            data: {
                                number: function() {
                                    return $('input[name="number"]').val()
                                },
                                isedit: function() {
                                    return $('input[name="number"]').data("isedit")
                                },
                                original_number: function() {
                                    return $('input[name="number"]').data("original-number")
                                },
                                date: function() {
                                    return $('input[name="date"]').val()
                                }
                            }
                        },
                        messages: {
                            remote: app.lang.invoice_number_exists
                        }
                    })
            }
        });

        $("#online_payments_asaas_tab > div:nth-child(13) > div:nth-child(2) > label").html("Valor fixo");

        $("#online_payments_asaas_tab > div:nth-child(13) > div:nth-child(3) > label").html("Porcentagem");

        $("#y_opt_1_Tipo\\ de\\ desconto").change(function() {

            $("#online_payments_asaas_tab > div:nth-child(13) > label").empty();
            $("#online_payments_asaas_tab > div:nth-child(13) > label").html("Valor desconto ");

        });

        $("#y_opt_2_Tipo\\ de\\ desconto").change(function() {

            // console.log( asaas);
            $("#online_payments_asaas_tab > div:nth-child(13) > label").empty();
            $("#online_payments_asaas_tab > div:nth-child(13) > label").html("Valor desconto (Informar porcentagem)");

        });
    </script>
<?php
}

function asaas_after_invoice_added($invoice_id)
{
    if (get_option('paymentmethod_asaas_billet_only')) {
        $CI = &get_instance();
        $CI->load->library('asaas_gateway');
        $CI->load->model('invoices_model');
        $invoice = $CI->invoices_model->get($invoice_id);

        if ($invoice) {
            if ($invoice->duedate) {

                $allowed_payment_modes = isset($invoice->allowed_payment_modes) ? unserialize($invoice->allowed_payment_modes) : array();

                if (in_array(ASAAS_MODULE_NAME, $allowed_payment_modes)) {
                    $billet = $CI->asaas_gateway->charge_billet($invoice);
                }
            }
        }
        return $invoice_id;
    }
}

function asaas_after_invoice_updated($id)
{
    $CI = &get_instance();

    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');
    $debug = $CI->asaas_gateway->getSetting('debug');
    $api_key = $CI->asaas_gateway->getApiKey();
    $api_url = $CI->asaas_gateway->getUrlBase();
    $description      = $CI->asaas_gateway->getSetting('description');
    $interest         = $CI->asaas_gateway->getSetting('interest_value');
    $fine             = $CI->asaas_gateway->getSetting('fine_value');
    $discount_value   = $CI->asaas_gateway->getSetting('discount_value');
    $dueDateLimitDays = $CI->asaas_gateway->getSetting('discount_days');
    $billet_only      = $CI->asaas_gateway->getSetting('billet_only');
    $billet_check     = $CI->asaas_gateway->getSetting('billet_check');
    $discount_type    = $CI->asaas_gateway->getSetting('discount_type');
    $update_charge    = $CI->asaas_gateway->getSetting('update_charge');

    $disable_charge_notification = $CI->asaas_gateway->getSetting('disable_charge_notification');

    if ($disable_charge_notification == '1') {
        $notificationDisabled = true;
    } else {
        $notificationDisabled = false;
    }


    if ($update_charge == 1) {

        $invoice = $CI->invoices_model->get($id);

        if ($invoice) {

            $sem_desconto = strpos($invoice->adminnote, "{sem_desconto}", 0);


            if ($discount_type == 1) {

                $discount = [
                    'type' => 'FIXED',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if ($discount_type == 2) {

                $discount = [
                    'type' => 'PERCENTAGE',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }


            if (is_bool($sem_desconto)) {
                $discount =  [
                    'type' => 'PERCENTAGE',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            $invoice_number = $invoice->prefix . str_pad($invoice->number, 6, "0", STR_PAD_LEFT);
            $description = mb_convert_encoding(str_replace("{invoice_number}", $invoice_number, $description), 'UTF-8');

            if ($invoice->duedate) {

                $clientid = $invoice->clientid;
                $CI->db->select('c.*, cc.email');
                $CI->db->from(db_prefix() . 'clients c');
                $CI->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
                $CI->db->where('c.userid', $invoice->clientid);
                $client = $CI->db->get()->row();

                $document = isset($client->vat) ? str_replace(['/', '-', '.'], '', $client->vat) : '';

                $customer = $CI->asaas_gateway->search_customer($api_url, $api_key, $document);

                if ($customer && $customer['totalCount'] == "0") {
                    $post_data = json_encode([
                        "name" => $client->company,
                        "email" => $client->email,
                        "cpfCnpj" => $client->vat,
                        "postalCode" => $client->zip,
                        "address" => $client->address,
                        "addressNumber" => $client->numero,
                        "complement" => "",
                        "phone" => $client->phonenumber,
                        "mobilePhone" => $client->phonenumber,
                        "externalReference" => $invoice,
                        "notificationDisabled" => $notificationDisabled,
                    ]);
                    $cliente_create = $CI->asaas_gateway->create_customer($api_url, $api_key, $post_data);
                    $cliente_id = $cliente_create['id'];
                    log_activity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
                } else {
                    // se existir recupera os dados para cobranca
                    $cliente_id = isset($customer['data'][0]['id']) ? $customer['data'][0]['id'] : null;
                }

                $charges = $CI->asaas_gateway->get_charge2($invoice->hash);

                if ($charges && is_array($charges)) {
                    foreach ($charges as $charge) {

                        $allowed_payment_modes = isset($invoice->allowed_payment_modes) ? unserialize($invoice->allowed_payment_modes) : array();

                        if (in_array('asaas', $allowed_payment_modes)) { 
                            $billingType = isset($charge->billingType) ? $charge->billingType : ''; 
                            $post_data = json_encode([
                                "customer" => $cliente_id,
                                "billingType" => $billingType,
                                "dueDate" => $invoice->duedate,
                                "value" => $invoice->total,
                                "description" => $description,
                                "postalService" => false
                            ]);
                            $update_charge = $CI->asaas_gateway->update_charge($charge->id, $post_data);
                        }
                    }
                } else {
                    $allowed_payment_modes = isset($invoice->allowed_payment_modes) ? unserialize($invoice->allowed_payment_modes) : array();

                    $data['invoice'] = $invoice;
                    if (in_array('asaas', $allowed_payment_modes)) {
                        $charge = $CI->asaas_gateway->create_charge($api_url, $api_key, $post_data);
                    }
                }
            }
        }
    }
    return $id;
}

function asaas_before_invoice_deleted($id)
{
    $CI = &get_instance();
    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');

    $delete_charge = $CI->asaas_gateway->getSetting('delete_charge');

    if ($delete_charge == 1) {

        $invoice = $CI->invoices_model->get($id);

        $charges = $CI->asaas_gateway->get_charge2($invoice->hash);

        if ($charges) {
            foreach ($charges as $charge) {

                $response = $CI->asaas_gateway->delete_charge($charge->id);

                log_activity('Cobrança removida Asaas [Fatura ID: ' . $charge->id . ']');
            }
        }
    }
    return $id;
}
