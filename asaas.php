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
Author: WWSoft -> Wallace Kleiton - argws/asaas-sdk-php v0.2.61
Version: 1.4.2
Requires at least: 2.9.*
Author URI: https://t.me/wkartspro
*/

define('ASAAS_MODULE_NAME', 'asaas');

hooks()->add_action('before_render_payment_gateway_settings', 'asaas_before_render_payment_gateway_settings');
hooks()->add_action('app_admin_footer', 'asaas_settings_tab_footer');
hooks()->add_action('after_invoice_added', 'asaas_after_invoice_added');
if (function_exists('do_action_deprecated')) {
    hooks()->add_action('invoice_updated', 'asaas_after_invoice_updated');
} else {
    // Fallback para versões legadas do Perfex que ainda não expõem invoice_updated.
    hooks()->add_action('after_invoice_updated', 'asaas_after_invoice_updated');
}
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

function asaas_after_invoice_updated($hookData = null)
{
    $CI = &get_instance();

    $args = func_get_args();
    $id = 0;

    if (isset($hookData['invoice_id'])) {
        $id = (int) $hookData['invoice_id'];
    } elseif (isset($hookData['id'])) {
        $id = (int) $hookData['id'];
    } elseif (is_numeric($hookData)) {
        $id = (int) $hookData;
    } elseif (isset($args[1])) {
        if (is_array($args[1]) && isset($args[1]['invoice_id'])) {
            $id = (int) $args[1]['invoice_id'];
        } elseif (is_numeric($args[1])) {
            $id = (int) $args[1];
        }
    }

    if ($id <= 0) {
        log_message('error', '[Asaas] invoice_updated: invoice_id inválido.');
        return $id;
    }

    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');

    $description = (string) $CI->asaas_gateway->getSetting('description');
    $discount_value = (float) $CI->asaas_gateway->getSetting('discount_value');
    $dueDateLimitDays = (int) $CI->asaas_gateway->getSetting('discount_days');
    $discount_type = (int) $CI->asaas_gateway->getSetting('discount_type');
    $update_charge = (int) $CI->asaas_gateway->getSetting('update_charge');

    if ($update_charge !== 1) {
        log_message('info', '[Asaas] invoice_updated: update_charge desabilitado para invoice_id=' . $id);
        return $id;
    }

    $invoice = $CI->invoices_model->get($id);
    if (!$invoice) {
        log_message('error', '[Asaas] invoice_updated: invoice não encontrada para invoice_id=' . $id);
        return $id;
    }

    if (empty($invoice->duedate)) {
        log_message('info', '[Asaas] invoice_updated: sem dueDate para invoice_id=' . $id);
        return $id;
    }

    $allowed_payment_modes = isset($invoice->allowed_payment_modes) ? unserialize($invoice->allowed_payment_modes) : array();
    if (!is_array($allowed_payment_modes) || !in_array('asaas', $allowed_payment_modes, true)) {
        log_message('info', '[Asaas] invoice_updated: forma de pagamento asaas não permitida para invoice_id=' . $id);
        return $id;
    }

    $adminnote = isset($invoice->adminnote) && is_string($invoice->adminnote) ? $invoice->adminnote : '';
    $sem_desconto = strpos($adminnote, '{sem_desconto}', 0);

    $discount = [
        'type' => 'PERCENTAGE',
        'value' => $discount_value,
        'dueDateLimitDays' => $dueDateLimitDays,
    ];

    if ($discount_type === 1) {
        $discount['type'] = 'FIXED';
    }

    if (is_bool($sem_desconto)) {
        $discount['type'] = 'PERCENTAGE';
    }

    $invoice_number = $invoice->prefix . str_pad((string) $invoice->number, 6, '0', STR_PAD_LEFT);
    $description = mb_convert_encoding(str_replace('{invoice_number}', $invoice_number, $description), 'UTF-8');

    $CI->db->select('c.*, cc.email');
    $CI->db->from(db_prefix() . 'clients c');
    $CI->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
    $CI->db->where('c.userid', $invoice->clientid);
    $client = $CI->db->get()->row();

    if (!$client) {
        log_message('error', '[Asaas] invoice_updated: cliente não encontrado para invoice_id=' . $id);
        return $id;
    }

    $customerSync = $CI->asaas_gateway->ensure_customer_from_client($client);
    log_message('info', '[Asaas] invoice_updated: ensure_customer_from_client response=' . json_encode($customerSync, JSON_UNESCAPED_UNICODE));

    $cliente_id = isset($customerSync['customerId']) ? (string) $customerSync['customerId'] : '';
    if ($cliente_id === '') {
        log_message('error', '[Asaas] invoice_updated: cliente Asaas não identificado para invoice_id=' . $id . ' response=' . json_encode($customerSync, JSON_UNESCAPED_UNICODE));
        return $id;
    }

    $chargePayloadArray = [
        'customer' => $cliente_id,
        'billingType' => 'UNDEFINED',
        'dueDate' => $invoice->duedate,
        'value' => $invoice->total,
        'description' => $description,
        'externalReference' => (string) $invoice->hash,
        'postalService' => false,
        'discount' => $discount,
    ];
    $chargePayload = json_encode($chargePayloadArray);

    $charges = $CI->asaas_gateway->get_charge2($invoice->hash);

    if ($charges && is_array($charges)) {
        foreach ($charges as $charge) {
            $billingType = isset($charge->billingType) ? $charge->billingType : 'UNDEFINED';
            $payloadArray = [
                'customer' => $cliente_id,
                'billingType' => $billingType,
                'dueDate' => $invoice->duedate,
                'value' => $invoice->total,
                'description' => $description,
                'externalReference' => (string) $invoice->hash,
                'postalService' => false,
                'discount' => $discount,
            ];
            $payload = json_encode($payloadArray);
            log_message('info', '[Asaas] invoice_updated: update_charge payload=' . json_encode($payloadArray, JSON_UNESCAPED_UNICODE));
            $updateResponse = $CI->asaas_gateway->update_charge($charge->id, $payload);
            log_message('info', '[Asaas] invoice_updated: update_charge response=' . json_encode($updateResponse, JSON_UNESCAPED_UNICODE));

            if (!is_array($updateResponse) || isset($updateResponse['errors']) || isset($updateResponse['error'])) {
                log_message('error', '[Asaas] invoice_updated: erro ao atualizar cobrança charge_id=' . $charge->id . ' response=' . json_encode($updateResponse, JSON_UNESCAPED_UNICODE));
            }
        }

        return $id;
    }

    log_message('info', '[Asaas] invoice_updated: create_charge payload=' . json_encode($chargePayloadArray, JSON_UNESCAPED_UNICODE));
    $createResponseRaw = $CI->asaas_gateway->create_charge($CI->asaas_gateway->getUrlBase(), $CI->asaas_gateway->getApiKey(), $chargePayload);
    $createResponse = is_string($createResponseRaw) ? json_decode($createResponseRaw, true) : $createResponseRaw;
    log_message('info', '[Asaas] invoice_updated: create_charge response=' . json_encode($createResponse, JSON_UNESCAPED_UNICODE));

    if (!is_array($createResponse) || isset($createResponse['errors']) || isset($createResponse['error'])) {
        log_message('error', '[Asaas] invoice_updated: erro ao criar cobrança para invoice_id=' . $id . ' response=' . json_encode($createResponse, JSON_UNESCAPED_UNICODE));
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
