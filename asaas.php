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
Version: 1.5.6
Requires at least: 2.9.*
Author URI: https://t.me/wkartspro
*/

define('ASAAS_MODULE_NAME', 'asaas');

require_once __DIR__ . '/helpers/asaas_webhook_helper.php';
require_once __DIR__ . '/helpers/asaas_diagnostics_helper.php';
require_once __DIR__ . '/helpers/asaas_lifecycle_helper.php';
require_once __DIR__ . '/helpers/asaas_settings_helper.php';
require_once __DIR__ . '/helpers/asaas_recurring_hooks_helper.php';

hooks()->add_action('before_render_payment_gateway_settings', 'asaas_before_render_payment_gateway_settings');
hooks()->add_action('app_admin_footer', 'asaas_settings_tab_footer');
hooks()->add_filter('before_settings_updated', 'asaas_validate_webhook_settings');
hooks()->add_filter('before_settings_updated', 'asaas_validate_environment_settings');
hooks()->add_filter('before_settings_updated', 'asaas_validate_card_settings');
hooks()->add_action('admin_init', 'asaas_admin_invoice_transaction');
hooks()->add_filter('before_invoice_added', 'asaas_recurring_before_invoice_added', 5);
hooks()->add_filter('before_invoice_added', 'asaas_before_invoice_added');
hooks()->add_action('after_invoice_added', 'asaas_after_invoice_added');
hooks()->add_action('after_invoice_added', 'asaas_recurring_invoice_added', 20);
hooks()->add_action('after_total_summary_invoicehtml', 'asaas_recurring_invoice_option');
if (function_exists('do_action_deprecated')) {
    hooks()->add_filter('before_update_invoice', 'asaas_before_update_invoice', 10, 2);
    hooks()->add_action('invoice_updated', 'asaas_after_invoice_updated');
} else {
    // Fallback para versões legadas do Perfex que ainda não expõem invoice_updated.
    hooks()->add_filter('before_invoice_updated', 'asaas_before_update_invoice', 10, 2);
    hooks()->add_action('after_invoice_updated', 'asaas_after_invoice_updated');
}
hooks()->add_action('before_invoice_deleted', 'asaas_before_invoice_deleted');
hooks()->add_action('after_invoice_deleted', 'asaas_after_invoice_deleted');
hooks()->add_action('invoice_marked_as_cancelled', 'asaas_invoice_marked_as_cancelled');
hooks()->add_action('invoice_unmarked_as_cancelled', 'asaas_invoice_unmarked_as_cancelled');
hooks()->add_filter('before_payment_recorded', 'asaas_before_payment_recorded');
hooks()->add_action('after_payment_added', 'asaas_after_payment_added');
hooks()->add_action('after_cron_run', 'asaas_reconcile_after_cron');
hooks()->add_action('after_cron_run', 'asaas_recurring_after_cron', 20);

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

foreach (['asaas_gateway', 'asaas_recurring_gateway'] as $asaasGatewayClass) {
    if (function_exists('app_payment_gateways')) {
        $gateways = app_payment_gateways();
        if (is_object($gateways)) {
            if (method_exists($gateways, 'add_gateway')) {
                $gateways->add_gateway($asaasGatewayClass);
            } elseif (method_exists($gateways, 'addGateway')) {
                $gateways->addGateway($asaasGatewayClass);
            } else {
                register_payment_gateway($asaasGatewayClass, ASAAS_MODULE_NAME);
            }
        } else {
            register_payment_gateway($asaasGatewayClass, ASAAS_MODULE_NAME);
        }
    } else {
        register_payment_gateway($asaasGatewayClass, ASAAS_MODULE_NAME);
    }
}
unset($asaasGatewayClass);

function asaas_before_render_payment_gateway_settings($gateway)
{
    if (is_array($gateway) && ($gateway['id'] ?? null) === ASAAS_MODULE_NAME) {
        require __DIR__ . '/views/settings/tabs.php';
    }
    return $gateway;
}

function asaas_settings_tab_footer()
{
    foreach (['asaas-settings.css', 'asaas-monitor.css', 'asaas-logs.css'] as $stylesheet) {
        $asset = 'assets/css/' . $stylesheet;
        if (is_file(__DIR__ . '/' . $asset)) {
            echo '<link rel="stylesheet" href="' . html_escape(module_dir_url(ASAAS_MODULE_NAME, $asset) . '?v=' . filemtime(__DIR__ . '/' . $asset)) . '">';
        }
    }
    foreach (['asaas-settings.js', 'asaas-webhook-settings.js', 'asaas-monitor.js', 'asaas-logs.js'] as $script) {
        $asset = 'assets/js/' . $script;
        if (is_file(__DIR__ . '/' . $asset)) {
            echo '<script src="' . html_escape(module_dir_url(ASAAS_MODULE_NAME, $asset) . '?v=' . filemtime(__DIR__ . '/' . $asset)) . '"></script>';
        }
    }
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

        var asaasDiscountType = $('#online_payments_asaas_tab input[name="settings[paymentmethod_asaas_discount_type]"]');
        asaasDiscountType.filter('[value="1"]').siblings('label').text('Valor fixo');
        asaasDiscountType.filter('[value="0"]').siblings('label').text('Porcentagem');
        asaasDiscountType.change(function() {
            $('#online_payments_asaas_tab input[name="settings[paymentmethod_asaas_discount_value]"]')
                .closest('.form-group').children('label')
                .text(this.value === '1' ? 'Valor desconto' : 'Valor desconto (Informar porcentagem)');
        });
    </script>
<?php
}

/** Keep optional reconciliation failures isolated from the native CRM cron. */
function asaas_reconcile_after_cron($manually = false)
{
    try {
        if ((string) get_option('paymentmethod_asaas_sync_enabled') !== '1') {
            return;
        }
        require_once __DIR__ . '/libraries/Asaas/AsaasReconciliation.php';
        \Asaas\AsaasReconciliation::runCron();
    } catch (\Throwable $exception) {
        asaas_diagnostic_log('error', 'Não foi possível executar a conciliação automática Asaas.', [
            'source' => 'reconciliation', 'operation' => 'cron', 'trigger' => 'cron',
            'exception' => get_class($exception),
        ]);
    }
}

function asaas_after_invoice_added($invoice_id)
{
    $id = is_numeric($invoice_id) ? (int) $invoice_id : 0;
    $operation = 'create:';

    try {
        if (!get_option('paymentmethod_asaas_billet_only')) {
            asaas_diagnostic_log('info', 'invoice_added: envio automático não executado; boleto desabilitado.', ['invoice_id' => $id]);
            return $invoice_id;
        }

        $CI = &get_instance();
        $CI->load->model('invoices_model');
        $invoice = $CI->invoices_model->get($invoice_id);

        if (!$invoice) {
            asaas_diagnostic_log('warning', 'invoice_added: fatura não encontrada.', ['invoice_id' => $id]);
            throw new RuntimeException('Fatura não encontrada durante a sincronização.');
        }
        $operation = 'create:' . (string) $invoice->hash;

        if (in_array((int) ($invoice->status ?? 1), [2, 5, 6], true)) {
            return $invoice_id;
        }

        if (empty($invoice->duedate)) {
            asaas_diagnostic_log('info', 'invoice_added: envio não executado; fatura sem vencimento.', ['invoice_id' => $id]);
            return $invoice_id;
        }

        $allowed_payment_modes = asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? null);
        if (!in_array(ASAAS_MODULE_NAME, $allowed_payment_modes, true)) {
            asaas_diagnostic_log('info', 'invoice_added: envio não executado; forma de pagamento Asaas não permitida.', ['invoice_id' => $id]);
            return $invoice_id;
        }

        $CI->load->library('asaas_gateway');
        asaas_lifecycle_validate_invoice($invoice);
        asaas_lifecycle_begin($operation);
        $state = &asaas_lifecycle_state();
        if (!empty($state['created_invoices'][$id])) {
            return $invoice_id;
        }

        asaas_diagnostic_log('info', 'invoice_added: iniciando envio da cobrança.', ['invoice_id' => $id]);
        $billet = $CI->asaas_gateway->charge_billet($invoice);
        if (!is_array($billet) || empty($billet['id']) || isset($billet['errors']) || isset($billet['error'])) {
            asaas_diagnostic_log('error', 'invoice_added: Asaas não confirmou a criação da cobrança.', [
                'invoice_id' => $id,
                'errors' => is_array($billet) ? ($billet['errors'] ?? $billet['error'] ?? null) : null,
            ]);
            throw new RuntimeException('Asaas não confirmou a criação da cobrança.');
        }

        asaas_lifecycle_record_created($id, $billet);
        $state['created_invoices'][$id] = (string) $billet['id'];
        asaas_diagnostic_log('info', 'invoice_added: cobrança criada.', ['invoice_id' => $id, 'payment_id' => $billet['id']]);
    } catch (Throwable $e) {
        asaas_diagnostic_log('error', 'invoice_added: falha ao enviar cobrança.', [
            'invoice_id' => $id,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'status' => $e->getCode(),
        ]);
        asaas_lifecycle_reject($e, $id);
    } finally {
        asaas_lifecycle_finish_or_reject($operation, $id);
    }

    return $invoice_id;
}

/** Perfex stores serialized modes, while integrations may already provide the array. */
function asaas_invoice_payment_modes($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return [];
    }

    $modes = @unserialize($value, ['allowed_classes' => false]);
    return is_array($modes) ? $modes : [];
}

/** Kept for callers of the previous diagnostic helper. */
function asaas_invoice_dispatch_alert(int $invoiceId): void
{
    if (!defined('CRON') && function_exists('is_staff_logged_in') && is_staff_logged_in()
        && function_exists('set_alert')) {
        set_alert('danger', 'A fatura #' . $invoiceId . ' teve a sincronização bloqueada. Consulte Asaas > Ver logs da integração.');
    }
}

function asaas_after_invoice_updated($hookData = null)
{
    $args = func_get_args();
    $id = 0;

    if (is_array($hookData) && isset($hookData['invoice_id'])) {
        $id = (int) $hookData['invoice_id'];
    } elseif (is_array($hookData) && isset($hookData['id'])) {
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
        asaas_diagnostic_log('warning', 'invoice_updated: invoice_id inválido.');
        return $id;
    }

    $preview = is_array($hookData) && isset($hookData['_asaas_preview']) ? $hookData['_asaas_preview'] : null;
    $reactivating = is_array($hookData) && !empty($hookData['_asaas_reactivate']);

    try {
        $state = &asaas_lifecycle_state();
        if (!$preview && !empty($state['preflight'][$id])) {
            asaas_lifecycle_refresh_snapshot($id);
            unset($state['preflight'][$id]);
            return $id;
        }
        $CI = &get_instance();
        $CI->load->model('invoices_model');

        $invoice = $preview ?: $CI->invoices_model->get($id);
        if (!$invoice) {
            asaas_diagnostic_log('warning', 'invoice_updated: fatura não encontrada.', ['invoice_id' => $id]);
            return $id;
        }

        $cardCache = asaas_lifecycle_cached_payment(asaas_lifecycle_map($id));
        if (!$reactivating && (asaas_card_attempt_blocks($cardCache) || !empty($cardCache['installment']))) {
            // Do not send the whole invoice total to each installment, or mutate an uncertain capture.
            // Financial edits have already been checked in the before-update filter.
            return $id;
        }

        if (in_array((int) ($invoice->status ?? 1), [2, 5, 6], true)) {
            return $id;
        }

        if (empty($invoice->duedate)) {
            asaas_diagnostic_log('info', 'invoice_updated: envio não executado; fatura sem vencimento.', ['invoice_id' => $id]);
            return $id;
        }

        $allowed_payment_modes = asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? null);
        if (!in_array('asaas', $allowed_payment_modes, true)) {
            asaas_diagnostic_log('info', 'invoice_updated: envio não executado; forma de pagamento Asaas não permitida.', ['invoice_id' => $id]);
            return $id;
        }

        $CI->load->library('asaas_gateway');
        $description = (string) $CI->asaas_gateway->getSetting('description');
        $discount_value = (float) $CI->asaas_gateway->getSetting('discount_value');
        $dueDateLimitDays = (int) $CI->asaas_gateway->getSetting('discount_days');
        $discount_type = (int) $CI->asaas_gateway->getSetting('discount_type');
        $update_charge = (int) $CI->asaas_gateway->getSetting('update_charge');

        if ($update_charge !== 1) {
            asaas_diagnostic_log('info', 'invoice_updated: atualização desabilitada; criação continua permitida quando não houver cobrança.', ['invoice_id' => $id]);
        }

        asaas_lifecycle_validate_invoice($invoice, true);
        if (!$preview) {
            asaas_lifecycle_begin('update:' . $id);
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
            asaas_diagnostic_log('error', 'invoice_updated: cliente não encontrado.', ['invoice_id' => $id]);
            throw new RuntimeException('Cliente não encontrado para sincronizar a cobrança.');
        }

        $customerSync = $CI->asaas_gateway->ensure_customer_from_client($client);

        $cliente_id = isset($customerSync['customerId']) ? (string) $customerSync['customerId'] : '';
        if ($cliente_id === '') {
            asaas_diagnostic_log('error', 'invoice_updated: cliente Asaas não identificado.', ['invoice_id' => $id]);
            throw new RuntimeException('Não foi possível identificar o cliente no Asaas.');
        }

        asaas_diagnostic_log('info', 'invoice_updated: cliente Asaas identificado.', ['invoice_id' => $id, 'customer_id' => $cliente_id]);

        $basePayloadArray = [
            'customer' => $cliente_id,
            'billingType' => 'UNDEFINED',
            'dueDate' => $invoice->duedate,
            'value' => $invoice->total,
            'description' => $description,
            'externalReference' => (string) $invoice->hash,
            'postalService' => false,
            'discount' => $discount,
        ];

        $charges = $CI->asaas_gateway->get_charge2($invoice->hash);
        $charges = is_array($charges) ? $charges : [];

        if ($charges !== []) {
            if ($update_charge === 1 && !$reactivating) {
                foreach ($charges as $charge) {
                    $billingType = isset($charge->billingType) ? $charge->billingType : 'UNDEFINED';
                    $payloadArray = $basePayloadArray;
                    $payloadArray['billingType'] = $billingType;
                    $payload = json_encode($payloadArray);

                    asaas_diagnostic_log('info', 'invoice_updated: enviando atualização da cobrança.', ['invoice_id' => $id, 'payment_id' => $charge->id]);
                    $updateResponse = $CI->asaas_gateway->update_charge($charge->id, $payload);

                    if (!is_array($updateResponse) || empty($updateResponse['id']) || isset($updateResponse['errors']) || isset($updateResponse['error'])) {
                        asaas_diagnostic_log('error', 'invoice_updated: Asaas não confirmou a atualização da cobrança.', [
                            'invoice_id' => $id,
                            'payment_id' => $charge->id,
                            'errors' => is_array($updateResponse) ? ($updateResponse['errors'] ?? $updateResponse['error'] ?? null) : null,
                        ]);
                        throw new RuntimeException('Asaas não confirmou a atualização da cobrança.');
                    }

                    asaas_lifecycle_record_updated($id, $updateResponse, $charge);
                    asaas_diagnostic_log('info', 'invoice_updated: cobrança atualizada.', ['invoice_id' => $id, 'payment_id' => $updateResponse['id']]);
                }
            } else {
                asaas_diagnostic_log('info', 'invoice_updated: cobrança existente preservada; atualização desabilitada.', ['invoice_id' => $id, 'charges' => count($charges)]);
                foreach ($charges as $charge) {
                    asaas_lifecycle_store_payment($id, is_object($charge) ? get_object_vars($charge) : (array) $charge);
                }
            }

            return $id;
        }

        $createPayload = json_encode($basePayloadArray);
        asaas_diagnostic_log('info', 'invoice_updated: enviando criação da cobrança.', ['invoice_id' => $id]);
        $createResponseRaw = $CI->asaas_gateway->create_charge($CI->asaas_gateway->getUrlBase(), $CI->asaas_gateway->getApiKey(), $createPayload);
        $createResponse = is_string($createResponseRaw) ? json_decode($createResponseRaw, true) : $createResponseRaw;

        if (!is_array($createResponse) || empty($createResponse['id']) || isset($createResponse['errors']) || isset($createResponse['error'])) {
            asaas_diagnostic_log('error', 'invoice_updated: Asaas não confirmou a criação da cobrança.', [
                'invoice_id' => $id,
                'errors' => is_array($createResponse) ? ($createResponse['errors'] ?? $createResponse['error'] ?? null) : null,
            ]);
            throw new RuntimeException('Asaas não confirmou a criação da cobrança.');
        }

        asaas_lifecycle_record_created($id, $createResponse);
        asaas_diagnostic_log('info', 'invoice_updated: cobrança criada.', ['invoice_id' => $id, 'payment_id' => $createResponse['id']]);
    } catch (Throwable $e) {
        asaas_diagnostic_log('error', 'invoice_updated: falha ao sincronizar cobrança.', [
            'invoice_id' => $id,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'status' => $e->getCode(),
        ]);
        asaas_lifecycle_reject($e, $id);
    } finally {
        if (!$preview) {
            asaas_lifecycle_finish_or_reject('update:' . $id, $id);
        }
    }

    return $id;
}

function asaas_before_invoice_deleted($id)
{
    $id = (int) $id;
    try {
        $invoice = asaas_lifecycle_invoice($id);
        if (!$invoice || !asaas_lifecycle_is_associated($invoice)) {
            return $id;
        }
        $enabled = asaas_lifecycle_option('sync_invoice_lifecycle') === '1'
            || asaas_lifecycle_option('delete_charge', '0') === '1';
        if (!$enabled) {
            return $id;
        }
        asaas_lifecycle_lock_operation($id);
        asaas_lifecycle_begin('delete:' . $id);
        asaas_lifecycle_lock_invoice($id);
        $invoice = asaas_lifecycle_invoice($id);
        if (!$invoice) {
            throw new RuntimeException('A fatura deixou de estar disponível durante a exclusão.');
        }
        asaas_lifecycle_remove($invoice, 'deleted');
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
    return $id;
}
