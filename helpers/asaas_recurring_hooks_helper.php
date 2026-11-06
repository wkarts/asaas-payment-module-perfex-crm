<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_recurring_helper.php';

/** Native recurring invoice templates may still carry only the original Asaas mode. */
function asaas_recurring_before_invoice_added(array $hook): array
{
    if (!is_array($hook['data'] ?? null)) { return $hook; }
    $data = $hook['data'];
    $original = $data['allowed_payment_modes'] ?? [];
    $modes = asaas_invoice_payment_modes($original);
    if (!in_array('asaas', $modes, true) && !in_array('asaas_recurring', $modes, true)) { return $hook; }
    try {
        if (in_array('asaas_recurring', $modes, true)) {
            // The customer's one-time fallback remains available in the same native invoice.
            if (!in_array('asaas', $modes, true)) { $modes[] = 'asaas'; }
        } elseif (asaas_recurring_authorizes_new_invoice($data)) {
            $modes[] = 'asaas_recurring';
        }
        if ($modes !== asaas_invoice_payment_modes($original)) {
            $hook['data']['allowed_payment_modes'] = is_array($original) ? $modes : serialize($modes);
        }
    } catch (Throwable) {
        // Failure to read a mandate must not convert issuance into a card payment or rollback it.
        asaas_recurring_diagnostic('invoice_scope_unavailable', 0);
    }
    return $hook;
}

/** Enqueue within the native transaction when present; capture only after it commits. */
function asaas_recurring_invoice_added($invoiceId): void
{
    if (!is_scalar($invoiceId) || !ctype_digit((string) $invoiceId) || (int) $invoiceId < 1) { return; }
    if ((string) get_option('paymentmethod_asaas_recurring_active') !== '1') { return; }
    try { asaas_recurring_service()->enqueue((int) $invoiceId); }
    catch (Throwable) { asaas_recurring_diagnostic('enqueue_failed', (int) $invoiceId); }
}

function asaas_recurring_after_cron($manually = false): void
{
    try {
        require_once __DIR__ . '/../libraries/Asaas/AsaasRecurring.php';
        \Asaas\AsaasRecurring::runCron();
    } catch (Throwable) { asaas_recurring_diagnostic('cron_failed', 0); }
}

/** Existing invoices keep their stored modes; offer explicit enrollment on their invoice page. */
function asaas_recurring_invoice_option($invoice): void
{
    if (!is_object($invoice) || empty($invoice->id) || !is_string($invoice->hash ?? null)
        || (string) get_option('paymentmethod_asaas_recurring_active') !== '1'
        || (string) get_option('paymentmethod_asaas_active') !== '1'
        || in_array((int) ($invoice->status ?? 0), [5, 6], true)) { return; }
    $modes = asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []);
    if (array_intersect($modes, ['asaas', 'asaas_recurring']) === []) { return; }
    // For new open invoices, the additional payment gateway already has its native radio option.
    if (in_array('asaas_recurring', $modes, true) && (int) ($invoice->status ?? 0) !== 2) { return; }
    if ((int) ($invoice->status ?? 0) === 2 && (!function_exists('is_client_logged_in') || !is_client_logged_in()
        || !function_exists('get_client_user_id') || (int) get_client_user_id() !== (int) $invoice->clientid)) { return; }
    $CI = &get_instance();
    $CI->load->view('asaas/partials/recurring_payment_option', ['invoice' => $invoice]);
}

/** Called only after the authenticated existing webhook path has released its receipt lock. */
function asaas_recurring_observe_webhook(array $payload): void
{
    if (!is_string($payload['event'] ?? null) || !is_array($payload['payment'] ?? null)) { return; }
    try {
        $payment = array_intersect_key($payload['payment'], array_flip([
            'id', 'customer', 'externalReference', 'billingType', 'status', 'value', 'originalValue',
            'installment', 'deleted', 'paymentDate', 'clientPaymentDate',
        ]));
        asaas_recurring_service()->observePayment($payment, $payload['event']);
    } catch (Throwable) {
        // The successful native webhook/receipt must not be rolled back by this optional observer.
        asaas_recurring_diagnostic('webhook_review_pending', 0);
    }
}
