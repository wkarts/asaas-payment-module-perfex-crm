<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_automatic_pix_helper.php';

function asaas_automatic_pix_before_invoice_added(array $hook): array
{
    if (!is_array($hook['data'] ?? null)) { return $hook; }
    $data = $hook['data']; $original = $data['allowed_payment_modes'] ?? [];
    $modes = asaas_invoice_payment_modes($original);
    if (array_intersect($modes, ['asaas', 'asaas_automatic_pix']) === []) { return $hook; }
    try {
        if (in_array('asaas_automatic_pix', $modes, true)) { if (!in_array('asaas', $modes, true)) { $modes[] = 'asaas'; } }
        elseif (asaas_automatic_pix_service()->authorizesNewInvoice($data)) { $modes[] = 'asaas_automatic_pix'; }
        if ($modes !== asaas_invoice_payment_modes($original)) { $hook['data']['allowed_payment_modes'] = is_array($original) ? $modes : serialize($modes); }
    } catch (Throwable) { asaas_automatic_pix_diagnostic('review_pending'); }
    return $hook;
}
function asaas_automatic_pix_invoice_added($invoiceId): void
{
    if (!is_scalar($invoiceId) || !ctype_digit((string) $invoiceId) || (int) $invoiceId < 1
        || (string) get_option('paymentmethod_asaas_automatic_pix_active') !== '1') { return; }
    try { asaas_automatic_pix_service()->enqueue((int) $invoiceId); }
    catch (Throwable) { asaas_automatic_pix_diagnostic('review_pending', (int) $invoiceId); }
}
function asaas_automatic_pix_after_cron($manually = false): void
{
    try { \Asaas\AsaasAutomaticPix::runCron(); }
    catch (Throwable) { asaas_automatic_pix_diagnostic('review_pending'); }
}
function asaas_automatic_pix_invoice_option($invoice): void
{
    if (!is_object($invoice) || empty($invoice->id) || !is_string($invoice->hash ?? null)
        || (string) get_option('paymentmethod_asaas_automatic_pix_active') !== '1'
        || (string) get_option('paymentmethod_asaas_active') !== '1' || in_array((int) ($invoice->status ?? 0), [5, 6], true)) { return; }
    $modes = asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []);
    if (array_intersect($modes, ['asaas', 'asaas_automatic_pix']) === []) { return; }
    if (in_array('asaas_automatic_pix', $modes, true) && (int) ($invoice->status ?? 0) !== 2) { return; }
    if ((int) ($invoice->status ?? 0) === 2 && (!function_exists('is_client_logged_in') || !is_client_logged_in()
        || !function_exists('get_client_user_id') || (int) get_client_user_id() !== (int) $invoice->clientid)) { return; }
    get_instance()->load->view('asaas/partials/automatic_pix_option', ['invoice' => $invoice]);
}
function asaas_automatic_pix_validate_settings(array $data): array
{
    $name = 'paymentmethod_asaas_automatic_pix_additional_holidays';
    if (!array_key_exists($name, $data['settings'] ?? [])) { return $data; }
    $value = $data['settings'][$name];
    try {
        if (!is_string($value) || strlen($value) > 4000) { throw new InvalidArgumentException(); }
        $days = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $calendar = new \Asaas\AsaasAutomaticPixCalendar();
        foreach ($days as $day) { $calendar->date($day); }
        $data['settings'][$name] = implode(', ', array_values(array_unique($days)));
    } catch (Throwable) {
        $data['settings'][$name] = (string) get_option($name);
        if (function_exists('set_alert')) { set_alert('warning', 'Os feriados do Pix Automático devem usar datas válidas em AAAA-MM-DD. O valor anterior foi preservado.'); }
    }
    return $data;
}
