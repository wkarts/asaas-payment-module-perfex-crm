<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_diagnostics_helper.php';
require_once __DIR__ . '/asaas_payment_validation_helper.php';
require_once __DIR__ . '/asaas_atomic_payment_helper.php';
require_once __DIR__ . '/asaas_receipt_lock_helper.php';
require_once __DIR__ . '/../libraries/Asaas/InvoiceTransaction.php';

/** A rejected remote operation must stop the native writer, not return an ignored hook value. */
class AsaasInvoiceSyncException extends RuntimeException
{
}

function &asaas_lifecycle_state(): array
{
    static $state = [];
    return $state;
}

function asaas_lifecycle_gateway()
{
    $ci = &get_instance();
    $ci->load->library('asaas_gateway');
    return $ci->asaas_gateway;
}

function asaas_lifecycle_option(string $name, string $default = '1'): string
{
    $value = get_option('paymentmethod_asaas_' . $name);
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

function asaas_lifecycle_has_atomic_payment_writer(): bool
{
    return asaas_atomic_payment_writer_detected();
}

function asaas_lifecycle_transaction(): \Asaas\InvoiceTransaction
{
    $state = &asaas_lifecycle_state();
    if (!isset($state['transaction'])) {
        $ci = &get_instance();
        $transaction = new \Asaas\InvoiceTransaction($ci->db);
        $transaction->assertTransactionalTables(array_map(static function (string $name): string {
            return db_prefix() . $name;
        }, ['invoices', 'itemable', 'item_tax', 'customfieldsvalues', 'taggables', 'invoicepaymentrecords', 'asaas_payments_map', 'asaas_webhook_events']));
        $state['transaction'] = $transaction;
    }
    return $state['transaction'];
}

function asaas_lifecycle_begin(string $operation): void
{
    asaas_lifecycle_transaction()->begin();
    $state = &asaas_lifecycle_state();
    $state['operations'][$operation] = true;
}

/** Acquire before BEGIN/FOR UPDATE so edits and cancellation also respect a manual handoff. */
function asaas_lifecycle_lock_operation(int $invoiceId): void
{
    asaas_receipt_lock($invoiceId);
    $state = &asaas_lifecycle_state();
    $state['operation_locks'][$invoiceId] = true;
}

/** Serializes lifecycle writes and callbacks for the same invoice on native MySQL. */
function asaas_lifecycle_lock_invoice(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $ci = &get_instance();
    $driver = (string) ($ci->db->dbdriver ?? '');
    $subdriver = (string) ($ci->db->subdriver ?? '');
    if (!in_array($driver, ['mysql', 'mysqli'], true) && !($driver === 'pdo' && $subdriver === 'mysql')) {
        return;
    }
    $table = db_prefix() . 'invoices';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)
        || $ci->db->query('SELECT id FROM ' . $table . ' WHERE id = ? FOR UPDATE', [$id]) === false) {
        throw new RuntimeException('Não foi possível bloquear a fatura durante a sincronização Asaas.');
    }
}

function asaas_lifecycle_finish(string $operation): void
{
    $state = &asaas_lifecycle_state();
    unset($state['operations'][$operation]);
    if (empty($state['operations']) && isset($state['transaction']) && $state['transaction']->isActive()) {
        $state['transaction']->commit();
        $state['compensations'] = [];
    }
    if (empty($state['operations'])) {
        foreach (array_keys($state['operation_locks'] ?? []) as $invoiceId) {
            asaas_receipt_unlock((int) $invoiceId);
        }
        $state['operation_locks'] = [];
    }
}

function asaas_lifecycle_finish_or_reject(string $operation, int $invoiceId = 0): void
{
    try {
        asaas_lifecycle_finish($operation);
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $invoiceId);
    }
}

function asaas_lifecycle_remote_payment(callable $callback)
{
    $state = &asaas_lifecycle_state();
    $state['remote_payment_depth'] = ($state['remote_payment_depth'] ?? 0) + 1;
    try {
        return $callback();
    } finally {
        $state['remote_payment_depth']--;
    }
}

function asaas_lifecycle_checkout_begin(int $invoiceId): void
{
    if ($invoiceId <= 0) {
        throw new RuntimeException('Fatura inválida para iniciar o pagamento Asaas.');
    }
    asaas_receipt_lock($invoiceId);
    asaas_lifecycle_begin('checkout:' . $invoiceId);
    asaas_lifecycle_lock_invoice($invoiceId);
    $invoice = asaas_lifecycle_invoice($invoiceId);
    if (!$invoice || in_array((int) ($invoice->status ?? 1), [2, 5, 6], true)) {
        throw new RuntimeException('Esta fatura não está disponível para pagamento no Asaas.');
    }
    // Each checkout builds a different payload; the Adapter validates its actual discount and installments.
    $state = &asaas_lifecycle_state();
    $state['checkout_invoice'] = $invoiceId;
    $state['checkout_previous'] = [];
}

function asaas_lifecycle_checkout_remember($payment): void
{
    $state = &asaas_lifecycle_state();
    $payment = is_object($payment) ? get_object_vars($payment) : (is_array($payment) ? $payment : []);
    if (!empty($state['checkout_invoice']) && !empty($payment['id'])) {
        $state['checkout_previous'][(string) $payment['id']] = $payment;
    }
}

function asaas_lifecycle_checkout_previous(string $paymentId): ?array
{
    $state = &asaas_lifecycle_state();
    if (empty($state['checkout_invoice']) || !empty($state['compensating'])) {
        return null;
    }
    $payment = $state['checkout_previous'][$paymentId] ?? asaas_lifecycle_gateway()->request('GET', '/payments/' . rawurlencode($paymentId));
    if (!is_array($payment) || (string) ($payment['id'] ?? '') !== $paymentId) {
        throw new RuntimeException('Não foi possível conferir a cobrança anterior ao pagamento.');
    }
    return $payment;
}

function asaas_lifecycle_checkout_record(array $payment, ?array $previous = null): void
{
    $state = &asaas_lifecycle_state();
    $invoiceId = (int) ($state['checkout_invoice'] ?? 0);
    if ($invoiceId <= 0 || !empty($state['compensating'])) {
        return;
    }
    if ($previous === null) {
        asaas_lifecycle_record_created($invoiceId, $payment);
    } else {
        asaas_lifecycle_record_updated($invoiceId, $payment, $previous);
    }
}

function asaas_lifecycle_checkout_finish(int $invoiceId): void
{
    $state = &asaas_lifecycle_state();
    unset($state['checkout_invoice'], $state['checkout_previous']);
    asaas_lifecycle_finish_or_reject('checkout:' . $invoiceId, $invoiceId);
    asaas_receipt_unlock($invoiceId);
}

function asaas_lifecycle_invoice(int $id)
{
    $ci = &get_instance();
    $ci->load->model('invoices_model');
    return $ci->invoices_model->get($id);
}

function asaas_lifecycle_map(int $invoiceId): array
{
    $ci = &get_instance();
    $table = db_prefix() . 'asaas_payments_map';
    if (!$ci->db->table_exists($table)) {
        return [];
    }
    $row = $ci->db->get_where($table, ['invoice_id' => $invoiceId])->row_array();
    return is_array($row) ? $row : [];
}

function asaas_lifecycle_cached_payment(array $map): array
{
    $cache = json_decode((string) ($map['payload_cache'] ?? ''), true);
    return is_array($cache) ? $cache : [];
}

function asaas_lifecycle_is_associated($invoice): bool
{
    return $invoice && (in_array('asaas', asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []), true)
        || asaas_lifecycle_map((int) ($invoice->id ?? 0)) !== []);
}

function asaas_lifecycle_should_ignore_payment(int $invoiceId, string $paymentId): bool
{
    $map = asaas_lifecycle_map($invoiceId);
    $metadata = asaas_lifecycle_cached_payment($map)['_asaas_lifecycle'] ?? [];
    if (in_array($paymentId, $metadata['retired_payment_ids'] ?? [], true)) {
        return true;
    }
    if ((string) ($map['asaas_payment_id'] ?? '') === $paymentId
        && in_array($metadata['state'] ?? '', ['deleted', 'cancelled', 'manual_received', 'manual_deleted'], true)) {
        return true;
    }
    if (!empty($metadata['cash_receipt_reverted'])
        && (string) ($map['asaas_payment_id'] ?? '') === $paymentId) {
        $current = asaas_lifecycle_gateway()->request('GET', '/payments/' . rawurlencode($paymentId));
        if ((string) ($current['id'] ?? '') !== $paymentId) {
            throw new RuntimeException('Não foi possível conferir o estado atual do recebimento Asaas.');
        }
        return !empty($current['deleted'])
            || !in_array($current['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true);
    }
    return false;
}

function asaas_lifecycle_store_payment(int $invoiceId, array $payment, string $status = 'active', array $extra = []): void
{
    $oldMap = asaas_lifecycle_map($invoiceId);
    $old = asaas_lifecycle_cached_payment($oldMap);
    $metadata = is_array($old['_asaas_lifecycle'] ?? null) ? $old['_asaas_lifecycle'] : [];
    $retired = $metadata['retired_payment_ids'] ?? [];
    $oldId = (string) ($oldMap['asaas_payment_id'] ?? '');
    if ($oldId !== '' && $oldId !== (string) ($payment['id'] ?? '')
        && in_array($metadata['state'] ?? '', ['deleted', 'cancelled', 'manual_received', 'manual_deleted'], true)) {
        $retired[] = $oldId;
    }
    if ($oldId !== (string) ($payment['id'] ?? '')) {
        unset($metadata['atomic_handoff'], $metadata['cash_receipt_reverted'], $metadata['manual_received_value']);
    }
    if (in_array($status, ['manual_received', 'manual_deleted'], true)) {
        $metadata['cash_receipt_reverted'] = false;
    }
    $invoice = asaas_lifecycle_invoice($invoiceId);
    if ($invoice && $status === 'active') {
        $metadata['invoice_snapshot'] = array_intersect_key(get_object_vars($invoice), array_flip(['status', 'sent', 'number', 'formatted_number']));
    }
    $payment['_asaas_lifecycle'] = array_merge($metadata, $extra, [
        'state' => $status,
        'retired_payment_ids' => array_values(array_unique($retired)),
    ]);
    if (empty($payment['id']) || !asaas_lifecycle_gateway()->upsert_payment_map($invoiceId, $payment)) {
        throw new RuntimeException('Não foi possível gravar o vínculo da cobrança Asaas. Confira as migrations e o banco do módulo.');
    }
}

function asaas_lifecycle_reject(Throwable $error, int $invoiceId = 0): void
{
    if ($error instanceof AsaasInvoiceSyncException) {
        throw $error;
    }
    $state = &asaas_lifecycle_state();
    if (isset($state['transaction'])) {
        try {
            $state['transaction']->rollback();
        } catch (Throwable $rollbackError) {
            asaas_diagnostic_log('error', 'Falha ao reverter a gravação da fatura; operação interrompida.', [
                'invoice_id' => $invoiceId, 'message' => $rollbackError->getMessage(),
            ]);
        }
    }
    $state['operations'] = [];
    $state['preflight'] = [];
    $state['compensations'] = [];
    $state['pending_manual_invoices'] = [];
    asaas_atomic_payment_shutdown();
    asaas_receipt_unlock_all();
    $state['operation_locks'] = [];
    $message = (string) asaas_diagnostic_sanitize($error->getMessage());
    asaas_diagnostic_log('error', 'Operação da fatura bloqueada; consulte os registros de transação e conciliação.', [
        'invoice_id' => $invoiceId,
        'exception' => get_class($error),
        'message' => $message,
        'status' => $error->getCode(),
    ]);
    $message = 'O salvamento foi bloqueado pela integração Asaas. ' . $message;
    if (PHP_SAPI === 'cli' || defined('CRON')) {
        throw new AsaasInvoiceSyncException($message, (int) $error->getCode(), $error);
    }
    $ci = &get_instance();
    if (function_exists('set_alert') && function_exists('is_staff_logged_in') && is_staff_logged_in()) {
        set_alert('danger', $message);
    }
    if (isset($ci->input) && $ci->input->is_ajax_request()) {
        $ci->output->set_status_header(422)->set_content_type('application/json')
            ->set_output(json_encode(['success' => false, 'message' => $message, 'invoice_id' => $invoiceId]));
        $ci->output->_display();
        exit;
    }
    show_error(html_escape($message) . '<p>Volte ao formulário para corrigir os dados. A cobrança não será repetida automaticamente.</p>', 422, 'Não foi possível concluir a operação');
    exit;
}

function asaas_lifecycle_refresh_snapshot(int $invoiceId): void
{
    $map = asaas_lifecycle_map($invoiceId);
    $payment = asaas_lifecycle_cached_payment($map);
    if ($payment !== [] && ($payment['_asaas_lifecycle']['state'] ?? '') === 'active') {
        asaas_lifecycle_store_payment($invoiceId, $payment);
    }
}

/** Begin before native custom fields/tags and the post-only cancellation hooks. */
function asaas_admin_invoice_transaction(): void
{
    $ci = &get_instance();
    if (!isset($ci->router, $ci->input)) {
        return;
    }
    $controller = strtolower((string) $ci->router->fetch_class());
    $method = strtolower((string) $ci->router->fetch_method());
    $writes = ['invoice', 'delete', 'mark_as_cancelled', 'unmark_as_cancelled', 'bulk_action'];
    if ($controller !== 'invoices' || !in_array($method, $writes, true)) {
        return;
    }
    if (in_array($method, ['invoice', 'bulk_action'], true) && !$ci->input->post()) {
        return;
    }
    try {
        $post = $ci->input->post();
        $post = is_array($post) ? $post : [];
        $id = (int) ($post['invoiceid'] ?? 0);
        if (isset($ci->uri) && method_exists($ci->uri, 'segment_array')) {
            $segments = $ci->uri->segment_array();
            $id = $id ?: (int) end($segments);
        }
        // Eligibility reads happen before BEGIN, so unrelated payment methods are untouched.
        $invoice = $id > 0 ? asaas_lifecycle_invoice($id) : null;
        $associated = asaas_lifecycle_is_associated($invoice)
            || in_array('asaas', asaas_invoice_payment_modes($post['allowed_payment_modes'] ?? []), true);
        if (!$associated) {
            return;
        }
        if ($id > 0) {
            asaas_lifecycle_lock_operation($id);
        }
        asaas_lifecycle_transaction()->begin();
        $state = &asaas_lifecycle_state();
        $state['request_transaction'] = true;
        if ($id > 0) {
            asaas_lifecycle_lock_invoice($id);
            $invoice = asaas_lifecycle_invoice($id);
            if ($invoice) {
                $state['snapshots'][$id] = clone $invoice;
            }
        }
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error);
    }
}

/** Minimum validation follows the payload sent by each existing gateway path. */
function asaas_lifecycle_validate_invoice($invoice, bool $updating = false): void
{
    $gateway = asaas_lifecycle_gateway();
    $type = (int) $gateway->getSetting('discount_type') === 1 ? 'FIXED' : 'PERCENTAGE';
    if ($updating && is_bool(strpos((string) ($invoice->adminnote ?? ''), '{sem_desconto}'))) {
        // Preserve the current update payload; do not change discount business rules here.
        $type = 'PERCENTAGE';
    }
    asaas_payment_validate_payload([
        'billingType' => 'BOLETO',
        'value' => $invoice->total ?? 0,
        'discount' => ['type' => $type, 'value' => $gateway->getSetting('discount_value') ?: 0],
    ]);
}

function asaas_before_invoice_added(array $hook): array
{
    $invoice = (object) ($hook['data'] ?? []);
    if (!get_option('paymentmethod_asaas_billet_only') || empty($invoice->duedate)
        || !in_array('asaas', asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []), true)
        || in_array((int) ($invoice->status ?? 1), [2, 5, 6], true)) {
        return $hook;
    }
    try {
        asaas_lifecycle_validate_invoice($invoice);
        asaas_lifecycle_begin('create:' . (string) ($invoice->hash ?? ''));
        $state = &asaas_lifecycle_state();
        $state['creating'][(string) ($invoice->hash ?? '')] = true;
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error);
    }
    return $hook;
}

function asaas_before_update_invoice(array $hook, $id = null): array
{
    $id = (int) ($hook['id'] ?? $id ?? 0);
    $state = &asaas_lifecycle_state();
    if ($id <= 0 || !empty($state['preflight'][$id])) {
        return $hook;
    }
    try {
        $original = asaas_lifecycle_invoice($id);
        if (!$original) {
            return $hook;
        }
        $invoice = (object) array_merge(get_object_vars($original), $hook['data'] ?? []);
        if (empty($invoice->duedate)
            || !in_array('asaas', asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []), true)
            || in_array((int) ($invoice->status ?? 1), [2, 5, 6], true)) {
            return $hook;
        }
        asaas_lifecycle_validate_invoice($invoice, true);
        asaas_lifecycle_lock_operation($id);
        asaas_lifecycle_begin('update:' . $id);
        asaas_lifecycle_lock_invoice($id);
        $original = asaas_lifecycle_invoice($id);
        if (!$original) {
            throw new RuntimeException('A fatura deixou de estar disponível durante a sincronização.');
        }
        $invoice = (object) array_merge(get_object_vars($original), $hook['data'] ?? []);
        $state['snapshots'][$id] = $state['snapshots'][$id] ?? clone $original;
        asaas_after_invoice_updated(['invoice_id' => $id, '_asaas_preview' => $invoice]);
        $state['preflight'][$id] = true;
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
    return $hook;
}

function asaas_lifecycle_record_created(int $invoiceId, array $payment): void
{
    $gateway = asaas_lifecycle_gateway();
    asaas_lifecycle_compensation($invoiceId, (string) $payment['id'], 'remove_created', static function () use ($gateway, $payment): void {
        if (in_array($payment['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
            throw new RuntimeException('A cobrança já foi recebida; concilie o recebimento antes de repetir a operação. Nenhum estorno automático foi solicitado.');
        }
        $current = $gateway->request('GET', '/payments/' . rawurlencode((string) $payment['id']));
        if ((string) ($current['id'] ?? '') !== (string) $payment['id']) {
            throw new RuntimeException('Não foi possível conferir a cobrança antes de desfazer sua criação.');
        }
        if (!empty($current['deleted'])) {
            return;
        }
        if (!in_array($current['status'] ?? '', ['PENDING', 'OVERDUE'], true)) {
            throw new RuntimeException('A cobrança mudou de estado; confira o recebimento no Asaas antes de removê-la.');
        }
        $gateway->delete_charge((string) $payment['id']);
    });
    asaas_lifecycle_store_payment($invoiceId, $payment);
}

function asaas_lifecycle_record_updated(int $invoiceId, array $payment, $previous): void
{
    $previous = is_object($previous) ? get_object_vars($previous) : (array) $previous;
    $restore = array_intersect_key($previous, array_flip([
        'billingType', 'value', 'dueDate', 'description', 'externalReference', 'discount', 'fine', 'interest', 'postalService',
    ]));
    $gateway = asaas_lifecycle_gateway();
    asaas_lifecycle_compensation($invoiceId, (string) $payment['id'], 'restore_updated', static function () use ($gateway, $payment, $restore): void {
        $gateway->update_charge((string) $payment['id'], json_encode($restore));
    });
    asaas_lifecycle_store_payment($invoiceId, $payment);
}

function asaas_lifecycle_compensation(int $invoiceId, string $paymentId, string $action, callable $callback): void
{
    $compensation = static function () use ($invoiceId, $paymentId, $action, $callback): void {
        $state = &asaas_lifecycle_state();
        $wasCompensating = $state['compensating'] ?? false;
        $state['compensating'] = true;
        try {
            $callback();
            asaas_diagnostic_log('info', 'Operação Asaas desfeita após rollback local.', [
                'invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'action' => $action,
            ]);
        } catch (Throwable $error) {
            asaas_diagnostic_log('error', 'Conciliação manual necessária; não repita a cobrança antes de consultar o Asaas.', [
                'invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'action' => $action,
                'message' => $error->getMessage(),
            ]);
            throw $error;
        } finally {
            $state['compensating'] = $wasCompensating;
        }
    };
    asaas_lifecycle_transaction()->afterRollback($compensation);
    $state = &asaas_lifecycle_state();
    $state['compensations'][] = $compensation;
}

function asaas_lifecycle_charges($invoice): array
{
    $gateway = asaas_lifecycle_gateway();
    $map = asaas_lifecycle_map((int) $invoice->id);
    $cache = asaas_lifecycle_cached_payment($map);
    $state = $cache['_asaas_lifecycle']['state'] ?? '';
    if (in_array($state, ['deleted', 'cancelled', 'manual_deleted'], true)) {
        return [];
    }
    $charges = $gateway->get_charge2((string) $invoice->hash);
    $result = [];
    foreach (is_array($charges) ? $charges : [] as $charge) {
        $charge = is_object($charge) ? get_object_vars($charge) : (array) $charge;
        if (empty($charge['id']) || !empty($charge['deleted'])) {
            continue;
        }
        if (isset($charge['externalReference']) && (string) $charge['externalReference'] !== (string) $invoice->hash) {
            throw new RuntimeException('A cobrança retornada não pertence à referência desta fatura.');
        }
        $result[(string) $charge['id']] = $charge;
    }
    $mappedId = (string) ($map['asaas_payment_id'] ?? '');
    if ($mappedId !== '' && !isset($result[$mappedId])) {
        $charge = $gateway->request('GET', '/payments/' . rawurlencode($mappedId));
        if (empty($charge['id']) || (string) $charge['id'] !== $mappedId) {
            throw new RuntimeException('Não foi possível confirmar a cobrança vinculada à fatura.');
        }
        if (isset($charge['externalReference']) && (string) $charge['externalReference'] !== (string) $invoice->hash) {
            throw new RuntimeException('A cobrança vinculada não pertence à referência desta fatura.');
        }
        if (empty($charge['deleted'])) {
            $result[$mappedId] = $charge;
        }
    }
    return array_values($result);
}

function asaas_lifecycle_remove($invoice, string $reason): void
{
    $gateway = asaas_lifecycle_gateway();
    $charges = asaas_lifecycle_charges($invoice);
    foreach ($charges as $charge) {
        if ($reason === 'manual_deleted'
            && in_array($charge['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
            // The manual-delete setting closes only an outstanding charge, never a confirmed receipt.
            continue;
        }
        $id = (string) $charge['id'];
        $gateway->delete_charge($id);
        asaas_lifecycle_compensation((int) $invoice->id, $id, 'restore_removed', static function () use ($gateway, $id, $charge): void {
            $gateway->restore_charge($id);
            $restore = array_intersect_key($charge, array_flip(['billingType', 'value', 'dueDate', 'description', 'discount', 'fine', 'interest']));
            if ($restore !== []) {
                $gateway->update_charge($id, json_encode($restore));
            }
        });
        $charge['deleted'] = true;
        $charge['status'] = 'DELETED';
        $state = &asaas_lifecycle_state();
        asaas_lifecycle_store_payment((int) $invoice->id, $charge, $reason, [
            'previous_invoice_status' => (int) ($state['snapshots'][(int) $invoice->id]->status ?? 1),
        ]);
        asaas_diagnostic_log('info', 'Cobrança removida do Asaas.', [
            'invoice_id' => (int) $invoice->id, 'payment_id' => $id, 'reason' => $reason,
        ]);
    }
}

function asaas_after_invoice_deleted($id): void
{
    asaas_lifecycle_finish_or_reject('delete:' . (int) $id, (int) $id);
}

function asaas_invoice_marked_as_cancelled($id): void
{
    $id = (int) $id;
    try {
        $invoice = asaas_lifecycle_invoice($id);
        if (asaas_lifecycle_option('sync_invoice_lifecycle') !== '1' || !asaas_lifecycle_is_associated($invoice)) {
            asaas_lifecycle_finish('cancel:' . $id);
            return;
        }
        $hadTransaction = asaas_lifecycle_transaction()->isActive();
        asaas_lifecycle_lock_operation($id);
        asaas_lifecycle_begin('cancel:' . $id);
        asaas_lifecycle_lock_invoice($id);
        if (!$hadTransaction) {
            asaas_lifecycle_restore_status_on_failure($invoice, false);
        }
        asaas_lifecycle_remove($invoice, 'cancelled');
        asaas_lifecycle_finish('cancel:' . $id);
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
}

function asaas_invoice_unmarked_as_cancelled($id): void
{
    $id = (int) $id;
    try {
        $invoice = asaas_lifecycle_invoice($id);
        if (asaas_lifecycle_option('sync_invoice_lifecycle') !== '1' || !asaas_lifecycle_is_associated($invoice)) {
            asaas_lifecycle_finish('reactivate:' . $id);
            return;
        }
        $hadTransaction = asaas_lifecycle_transaction()->isActive();
        asaas_lifecycle_lock_operation($id);
        asaas_lifecycle_begin('reactivate:' . $id);
        asaas_lifecycle_lock_invoice($id);
        if (!$hadTransaction) {
            asaas_lifecycle_restore_status_on_failure($invoice, true);
        }
        asaas_lifecycle_validate_invoice($invoice, true);
        asaas_after_invoice_updated(['invoice_id' => $id, '_asaas_preview' => $invoice, '_asaas_reactivate' => true]);
        asaas_lifecycle_finish('reactivate:' . $id);
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
}

/** Native model calls outside an HTTP controller only expose a post-status action. */
function asaas_lifecycle_restore_status_on_failure($invoice, bool $reactivating): void
{
    $id = (int) $invoice->id;
    $cache = asaas_lifecycle_cached_payment(asaas_lifecycle_map($id));
    $snapshot = $cache['_asaas_lifecycle']['invoice_snapshot'] ?? [];
    if ($reactivating) {
        $restore = ['status' => 5];
    } else {
        $balance = asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($id, $invoice->total));
        $total = asaas_payment_amount_to_cents($invoice->total);
        if ($balance <= 0) {
            $status = 2;
        } elseif ($balance < $total) {
            $status = 3;
        } elseif (!empty($invoice->duedate) && (string) $invoice->duedate < date('Y-m-d')) {
            $status = 4;
        } else {
            $status = 1;
        }
        // A post-action cannot prove an old number is still current; never overwrite it.
        $restore = array_intersect_key((array) $snapshot, ['sent' => true]);
        $restore['status'] = $status;
    }
    asaas_lifecycle_transaction()->afterRollback(static function () use ($id, $restore): void {
        $ci = &get_instance();
        if ($ci->db->where('id', $id)->update(db_prefix() . 'invoices', $restore) === false) {
            throw new RuntimeException('Não foi possível restaurar o estado da fatura após falha da sincronização Asaas.');
        }
    });
}

function asaas_before_payment_recorded(array $data): array
{
    $state = &asaas_lifecycle_state();
    $id = (int) ($data['invoiceid'] ?? 0);
    if ($id <= 0 || !empty($state['remote_payment_depth']) || asaas_lifecycle_option('manual_payment_sync') !== '1') {
        return $data;
    }
    try {
        $invoice = asaas_lifecycle_invoice($id);
        if (!asaas_lifecycle_is_associated($invoice)) {
            return $data;
        }
        if (!empty($state['pending_manual_invoices'][$id])) {
            throw new RuntimeException('Registre os recebimentos desta fatura Asaas individualmente antes de iniciar outro recebimento da mesma fatura.');
        }
        asaas_receipt_lock($id);
        $atomicWriter = asaas_lifecycle_has_atomic_payment_writer();
        asaas_lifecycle_begin('payment:' . $id);
        asaas_lifecycle_lock_invoice($id);
        $invoice = asaas_lifecycle_invoice($id);
        if (!$invoice) {
            throw new RuntimeException('A fatura deixou de estar disponível durante o recebimento.');
        }
        $amount = asaas_payment_amount_to_cents($data['amount'] ?? 0);
        $balance = asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($id, $invoice->total));
        if ($amount <= 0 || $balance <= 0 || $amount < $balance) {
            asaas_diagnostic_log('info', 'Recebimento parcial: cobrança Asaas mantida aberta.', ['invoice_id' => $id]);
            $state['manual_payment_invoice'] = $id;
            $state['pending_manual_invoices'][$id] = true;
            if ($atomicWriter) {
                asaas_lifecycle_finish('payment:' . $id);
            }
            return $data;
        }
        if ($atomicWriter && $amount > $balance) {
            throw new RuntimeException('O recebimento informado ultrapassa o saldo atual da fatura. Confira o valor antes de confirmar no Asaas.');
        }
        $atomicOperation = $atomicWriter ? asaas_atomic_payment_prepare($data) : null;
        $mapBefore = $atomicWriter ? asaas_lifecycle_map($id) : [];
        $compensationOffset = count($state['compensations'] ?? []);
        $receivedInCash = false;
        $gateway = asaas_lifecycle_gateway();
        if (asaas_lifecycle_option('manual_payment_receive_in_cash') !== '1') {
            asaas_lifecycle_remove($invoice, 'manual_deleted');
        } else {
            $charges = asaas_lifecycle_charges($invoice);
            $ci = &get_instance();
            $records = $ci->db->get_where(db_prefix() . 'invoicepaymentrecords', ['invoiceid' => $id])->result_array();
            $received = $amount;
            foreach ($records as $record) {
                $received += asaas_payment_amount_to_cents($record['amount'] ?? 0);
            }
            $date = substr((string) ($data['date'] ?? date('Y-m-d')), 0, 10);
            if (count($charges) > 1) {
                throw new RuntimeException('Há mais de uma cobrança ativa para esta fatura. Concilie os vínculos antes de confirmar o recebimento manual.');
            }
            foreach ($charges as $charge) {
                if (in_array($charge['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                    continue;
                }
                $paymentId = (string) $charge['id'];
                if ($atomicWriter && $mapBefore === []) {
                    // Keep a durable correlation even when a legacy charge had no local map.
                    asaas_lifecycle_store_payment($id, $charge);
                    $mapBefore = asaas_lifecycle_map($id);
                }
                $result = $gateway->receive_charge_in_cash($paymentId, $date, $received / 100, false);
                asaas_lifecycle_compensation($id, $paymentId, 'undo_manual_receipt', static function () use ($gateway, $paymentId, $id, $atomicWriter): void {
                    $restored = $gateway->undo_receive_in_cash($paymentId);
                    if (!$atomicWriter) {
                        asaas_lifecycle_store_payment($id, $restored, 'active', ['cash_receipt_reverted' => true]);
                    }
                });
                asaas_lifecycle_store_payment($id, $result, 'manual_received', ['manual_received_value' => $received / 100]);
                $receivedInCash = true;
                asaas_diagnostic_log('info', 'Recebimento manual confirmado no Asaas.', ['invoice_id' => $id, 'payment_id' => $paymentId]);
            }
        }
        $state['manual_payment_invoice'] = $id;
        $state['pending_manual_invoices'][$id] = true;
        if ($atomicWriter) {
            $compensations = array_slice($state['compensations'] ?? [], $compensationOffset);
            $mapAfter = asaas_lifecycle_map($id);
            if ($compensations !== []) {
                $payment = asaas_lifecycle_cached_payment($mapAfter);
                $payment['_asaas_lifecycle']['atomic_handoff'] = ['operation_id' => $atomicOperation, 'state' => 'pending'];
                if (!$gateway->upsert_payment_map($id, $payment)) {
                    throw new RuntimeException('Não foi possível acompanhar o recebimento antes de transferi-lo ao núcleo.');
                }
                $mapAfter = asaas_lifecycle_map($id);
            }
            if ($receivedInCash && $mapBefore !== []) {
                $previous = asaas_lifecycle_cached_payment($mapBefore);
                $previous['_asaas_lifecycle']['cash_receipt_reverted'] = true;
                $mapBefore['payload_cache'] = json_encode($previous, JSON_UNESCAPED_UNICODE);
            }
            // The custom core requires depth zero to own and commit its receipt transaction.
            asaas_lifecycle_finish('payment:' . $id);
            asaas_atomic_payment_handoff($atomicOperation, $compensations, $mapBefore, $mapAfter);
        }
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
    return $data;
}

function asaas_after_payment_added($paymentId): void
{
    $state = &asaas_lifecycle_state();
    if (!empty($state['remote_payment_depth'])) {
        return;
    }
    if (empty($state['pending_manual_invoices']) && empty($state['manual_payment_invoice'])) {
        return;
    }
    $id = (int) ($state['manual_payment_invoice'] ?? 0);
    try {
        $ci = &get_instance();
        $record = $ci->db->get_where(db_prefix() . 'invoicepaymentrecords', ['id' => (int) $paymentId])->row_array();
        $id = (int) ($record['invoiceid'] ?? $id);
        unset($state['manual_payment_invoice'], $state['pending_manual_invoices'][$id]);
        asaas_atomic_payment_complete($paymentId);
        asaas_lifecycle_finish('payment:' . $id);
        asaas_receipt_unlock($id);
    } catch (Throwable $error) {
        asaas_lifecycle_reject($error, $id);
    }
}
