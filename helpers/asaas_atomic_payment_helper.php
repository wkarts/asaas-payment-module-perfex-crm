<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_payment_validation_helper.php';
require_once __DIR__ . '/../libraries/Asaas/InvoiceTransaction.php';

/**
 * Compatibility with the detected custom core that owns its payment transaction.
 * Native Perfex continues using the ordinary lifecycle transaction. This handoff
 * never wraps InvoicePaymentWriter in an outer transaction and never calls it.
 * The caller commits its accepted Asaas operation/map before arming the handoff.
 */
function asaas_atomic_payment_writer_detected(?object $model = null): bool
{
    if ($model === null) {
        $ci = &get_instance();
        if (!isset($ci->payments_model)) {
            $ci->load->model('payments_model');
        }
        $model = $ci->payments_model ?? null;
    }
    if (!is_object($model) || !method_exists($model, 'insert_payment_atomically')) {
        return false;
    }
    $method = new ReflectionMethod($model, 'insert_payment_atomically');
    return $method->isPrivate() && class_exists('app\\services\\InvoicePaymentWriter');
}

/** @return array<string, array> */
function &asaas_atomic_payment_state(): array
{
    static $operations = [];
    static $registered = false;
    if (!$registered) {
        register_shutdown_function('asaas_atomic_payment_shutdown');
        $registered = true;
    }
    return $operations;
}

/** Capture the pre-insert state; the only changed native field is an empty reference. */
function asaas_atomic_payment_prepare(array &$data): string
{
    $id = $data['invoiceid'] ?? null;
    if ((!is_int($id) && !is_string($id)) || (string) (int) $id !== (string) $id || (int) $id <= 0) {
        throw new InvalidArgumentException('Fatura inválida para acompanhar o recebimento atômico.');
    }
    $mode = $data['paymentmode'] ?? null;
    if ((!is_string($mode) && !is_int($mode)) || (string) $mode === '') {
        throw new InvalidArgumentException('Forma de pagamento inválida para acompanhar o recebimento atômico.');
    }
    $amount = asaas_payment_amount_to_cents($data['amount'] ?? null);
    if ($amount <= 0) {
        throw new InvalidArgumentException('O valor do recebimento deve ser positivo.');
    }
    if (isset($data['transactionid']) && !is_string($data['transactionid']) && !is_int($data['transactionid'])) {
        throw new InvalidArgumentException('Referência inválida para acompanhar o recebimento atômico.');
    }

    $ci = &get_instance();
    $operationId = bin2hex(random_bytes(16));
    $baseline = asaas_atomic_payment_receipts($ci->db, (int) $id);
    if (!isset($data['transactionid']) || $data['transactionid'] === '') {
        $data['transactionid'] = 'asaas-manual-' . $operationId;
    }
    $operations = &asaas_atomic_payment_state();
    $operations[$operationId] = [
        'operation_id' => $operationId,
        'invoice_id' => (int) $id,
        'transaction_id' => (string) $data['transactionid'],
        'payment_mode' => (string) $mode,
        'amount_cents' => $amount,
        'baseline' => $baseline,
        'db' => $ci->db,
        'state' => 'prepared',
        'callbacks' => [],
        'map_before' => [],
        'map_after' => [],
    ];
    return $operationId;
}

/** Call only after the caller's transaction commit was positively confirmed. */
function asaas_atomic_payment_handoff(string $operationId, array $compensations, array $mapBefore, array $mapAfter): void
{
    $operations = &asaas_atomic_payment_state();
    if (!isset($operations[$operationId]) || $operations[$operationId]['state'] !== 'prepared') {
        throw new LogicException('O recebimento atômico não foi preparado ou já foi transferido ao núcleo.');
    }
    foreach ($compensations as $compensation) {
        if (!is_callable($compensation)) {
            throw new LogicException('A compensação do recebimento atômico precisa ser executável.');
        }
    }
    $operation = &$operations[$operationId];
    if (asaas_atomic_payment_depth($operation['db']) !== 0) {
        throw new RuntimeException('O núcleo precisa iniciar sua própria transação de recebimento; a transação anterior ainda está aberta.');
    }
    $marker = asaas_atomic_payment_map_marker($mapAfter);
    if ($compensations !== [] && (($marker['operation_id'] ?? '') !== $operationId || ($marker['state'] ?? '') !== 'pending')) {
        throw new LogicException('O vínculo da cobrança precisa registrar a operação pendente antes de liberar o recebimento ao núcleo.');
    }
    if (($mapBefore !== [] && (int) ($mapBefore['invoice_id'] ?? 0) !== $operation['invoice_id'])
        || ($mapAfter !== [] && (int) ($mapAfter['invoice_id'] ?? 0) !== $operation['invoice_id'])) {
        throw new LogicException('O vínculo da cobrança não corresponde à fatura do recebimento.');
    }
    $operation['callbacks'] = array_values($compensations);
    $operation['map_before'] = $mapBefore;
    $operation['map_after'] = $mapAfter;
    $operation['state'] = 'armed';
}

/** A receipt can already be durable even if a later notification throws. */
function asaas_atomic_payment_complete($paymentId): void
{
    $id = is_numeric($paymentId) ? (int) $paymentId : 0;
    if ($id <= 0) {
        return;
    }
    $operations = &asaas_atomic_payment_state();
    foreach (array_keys($operations) as $operationId) {
        if ($operations[$operationId]['state'] === 'armed') {
            asaas_atomic_payment_resolve($operationId, $id, false);
        }
    }
}

/** Never compensate on an unavailable database, changed map or uncertain local result. */
function asaas_atomic_payment_shutdown(): void
{
    $operations = &asaas_atomic_payment_state();
    foreach (array_keys($operations) as $operationId) {
        if ($operations[$operationId]['state'] === 'armed') {
            asaas_atomic_payment_resolve($operationId, null, true);
        }
    }
}

function asaas_atomic_payment_resolve(string $operationId, ?int $paymentId, bool $mayCompensate): void
{
    $operations = &asaas_atomic_payment_state();
    $operation = &$operations[$operationId];
    if ($operation['state'] !== 'armed') {
        return;
    }
    $transaction = null;
    try {
        $db = $operation['db'];
        if (asaas_atomic_payment_depth($db) !== 0) {
            throw new RuntimeException('A transação do núcleo ainda não foi concluída.');
        }
        $transaction = new \Asaas\InvoiceTransaction($db);
        $transaction->begin();
        asaas_atomic_payment_lock($db, $operation['invoice_id']);
        $receipts = asaas_atomic_payment_receipts($db, $operation['invoice_id']);
        $matches = [];
        foreach ($receipts as $id => $receipt) {
            if (!isset($operation['baseline'][$id])
                && $receipt['transaction_id'] === $operation['transaction_id']
                && $receipt['payment_mode'] === $operation['payment_mode']
                && $receipt['amount_cents'] === $operation['amount_cents']) {
                $matches[$id] = $receipt;
            }
        }
        if ($paymentId !== null && !isset($matches[$paymentId])) {
            // Another payment's after-hook may run while a batch still has pending entries.
            $transaction->commit();
            return;
        }
        $map = asaas_atomic_payment_map($db, $operation['invoice_id']);
        if ($matches !== []) {
            // A confirmed receipt wins over notification/map-finalization failures.
            $operation['state'] = 'completed';
            $operation['callbacks'] = [];
            if (count($matches) === 1 && asaas_atomic_payment_maps_equal($map, $operation['map_after'])) {
                $marker = asaas_atomic_payment_map_marker($map);
                if (($marker['operation_id'] ?? '') === $operationId) {
                    $cache = json_decode($map['payload_cache'], true, 512, JSON_THROW_ON_ERROR);
                    $cache['_asaas_lifecycle']['atomic_handoff']['state'] = 'confirmed';
                    $cache['_asaas_lifecycle']['atomic_handoff']['payment_id'] = (int) array_key_first($matches);
                    asaas_atomic_payment_update_map($db, $map, ['payload_cache' => json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
            } else {
                asaas_atomic_payment_diagnostic('Recebimento gravado; o vínculo Asaas requer conciliação. Nenhuma compensação remota foi executada.', $operation);
            }
            $transaction->commit();
            return;
        }
        if (!$mayCompensate) {
            $transaction->commit();
            return;
        }
        if ($receipts !== $operation['baseline'] || !asaas_atomic_payment_maps_equal($map, $operation['map_after'])) {
            throw new RuntimeException('O estado financeiro ou o vínculo da cobrança mudou após a transferência ao núcleo.');
        }

        // All absence/state checks succeeded under a fresh local lock. Consume once.
        $operation['state'] = 'resolving';
        $failed = false;
        foreach (array_reverse($operation['callbacks']) as $compensation) {
            try {
                $compensation();
            } catch (Throwable $error) {
                $failed = true;
                asaas_atomic_payment_diagnostic('Falha na compensação do recebimento atômico; conciliação manual necessária.', $operation, $error);
            }
        }
        $operation['callbacks'] = [];
        if ($failed) {
            $operation['state'] = 'needs_review';
        } else {
            asaas_atomic_payment_restore_map($db, $map, $operation['map_before']);
            $operation['state'] = 'compensated';
        }
        $transaction->commit();
    } catch (Throwable $error) {
        if ($transaction !== null && $transaction->isActive()) {
            try {
                $transaction->rollback();
            } catch (Throwable $rollbackError) {
                asaas_atomic_payment_diagnostic('Não foi possível confirmar o rollback da conciliação do recebimento.', $operation, $rollbackError);
            }
        }
        if ($operation['state'] !== 'completed') {
            $operation['state'] = 'needs_review';
        }
        $operation['callbacks'] = [];
        asaas_atomic_payment_diagnostic('Recebimento atômico pendente de conciliação; não repetir nem desfazer a cobrança sem conferir o recibo local e o Asaas.', $operation, $error);
    }
}

function asaas_atomic_payment_depth(object $db): int
{
    $reflection = new ReflectionObject($db);
    if (!$reflection->hasProperty('_trans_depth')) {
        throw new RuntimeException('Não foi possível confirmar o estado transacional do núcleo.');
    }
    $property = $reflection->getProperty('_trans_depth');
    $property->setAccessible(true);
    $depth = $property->getValue($db);
    if (!is_int($depth) || $depth < 0) {
        throw new RuntimeException('O núcleo informou uma profundidade transacional inválida.');
    }
    return $depth;
}

function asaas_atomic_payment_table(string $name): string
{
    $table = db_prefix() . $name;
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table)) {
        throw new RuntimeException('Prefixo inválido para consultar o recebimento atômico.');
    }
    return $table;
}

function asaas_atomic_payment_query(object $db, string $sql, array $bindings = [])
{
    $debug = $db->db_debug;
    try {
        $db->db_debug = false;
        $query = $db->query($sql, $bindings);
        if ($query === false) {
            throw new RuntimeException('O banco não confirmou a consulta do recebimento atômico.');
        }
        return $query;
    } finally {
        $db->db_debug = $debug;
    }
}

function asaas_atomic_payment_rows(object $db, string $sql, array $bindings): array
{
    $query = asaas_atomic_payment_query($db, $sql, $bindings);
    if (!is_object($query) || !method_exists($query, 'result_array') || !is_array($rows = $query->result_array())) {
        throw new RuntimeException('O banco retornou uma confirmação inválida do recebimento atômico.');
    }
    return $rows;
}

function asaas_atomic_payment_receipts(object $db, int $invoiceId): array
{
    $rows = asaas_atomic_payment_rows($db, 'SELECT id, invoiceid, amount, paymentmode, transactionid, date FROM '
        . asaas_atomic_payment_table('invoicepaymentrecords') . ' WHERE invoiceid = ? ORDER BY id', [$invoiceId]);
    $receipts = [];
    foreach ($rows as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || (int) ($row['invoiceid'] ?? 0) !== $invoiceId) {
            throw new RuntimeException('O banco retornou um recibo inválido para a fatura.');
        }
        $receipts[(int) $row['id']] = [
            'amount_cents' => asaas_payment_amount_to_cents($row['amount'] ?? null),
            'transaction_id' => (string) ($row['transactionid'] ?? ''),
            'payment_mode' => (string) ($row['paymentmode'] ?? ''),
            'date' => (string) ($row['date'] ?? ''),
        ];
    }
    ksort($receipts);
    return $receipts;
}

function asaas_atomic_payment_map(object $db, int $invoiceId): array
{
    $rows = asaas_atomic_payment_rows($db, 'SELECT * FROM ' . asaas_atomic_payment_table('asaas_payments_map') . ' WHERE invoice_id = ?', [$invoiceId]);
    if (count($rows) > 1 || ($rows !== [] && !is_array($rows[0]))) {
        throw new RuntimeException('O vínculo da cobrança retornou um resultado ambíguo.');
    }
    return $rows[0] ?? [];
}

function asaas_atomic_payment_lock(object $db, int $invoiceId): void
{
    $driver = strtolower((string) ($db->dbdriver ?? ''));
    $subdriver = strtolower((string) ($db->subdriver ?? ''));
    $suffix = in_array($driver, ['mysql', 'mysqli'], true) || ($driver === 'pdo' && $subdriver === 'mysql') ? ' FOR UPDATE' : '';
    $rows = asaas_atomic_payment_rows($db, 'SELECT id FROM ' . asaas_atomic_payment_table('invoices') . ' WHERE id = ?' . $suffix, [$invoiceId]);
    if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $invoiceId) {
        throw new RuntimeException('Não foi possível confirmar a fatura durante a conciliação do recebimento.');
    }
    asaas_atomic_payment_rows($db, 'SELECT invoice_id FROM ' . asaas_atomic_payment_table('asaas_payments_map') . ' WHERE invoice_id = ?' . $suffix, [$invoiceId]);
}

function asaas_atomic_payment_map_marker(array $map): array
{
    $cache = json_decode((string) ($map['payload_cache'] ?? ''), true);
    $marker = is_array($cache) ? ($cache['_asaas_lifecycle']['atomic_handoff'] ?? []) : [];
    return is_array($marker) ? $marker : [];
}

/** JSON key order/whitespace and SQL scalar types are not financial changes. */
function asaas_atomic_payment_maps_equal(array $left, array $right): bool
{
    $normalize = static function (array $map): array {
        foreach ($map as $key => $value) {
            if ($key === 'payload_cache' && $value !== null && $value !== '') {
                $map[$key] = asaas_atomic_payment_sort_json(json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR));
            } else {
                $map[$key] = $value === null ? null : (string) $value;
            }
        }
        ksort($map);
        return $map;
    };
    return $normalize($left) === $normalize($right);
}

function asaas_atomic_payment_sort_json($value)
{
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as &$item) {
        $item = asaas_atomic_payment_sort_json($item);
    }
    unset($item);
    if (!array_is_list($value)) {
        ksort($value);
    }
    return $value;
}

function asaas_atomic_payment_update_map(object $db, array $current, array $changes): void
{
    $allowed = ['asaas_payment_id', 'billing_type', 'status', 'value', 'due_date', 'last_sync_at', 'payload_cache', 'created_at', 'updated_at'];
    if ($changes === [] || array_diff(array_keys($changes), $allowed) !== []) {
        throw new LogicException('Campos inválidos para conciliar o vínculo Asaas.');
    }
    $sql = 'UPDATE ' . asaas_atomic_payment_table('asaas_payments_map') . ' SET '
        . implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($changes)))
        . ' WHERE invoice_id = ? AND asaas_payment_id = ? AND payload_cache = ?';
    asaas_atomic_payment_query($db, $sql, array_merge(array_values($changes), [$current['invoice_id'], $current['asaas_payment_id'], $current['payload_cache']]));
    if ((int) $db->affected_rows() !== 1) {
        throw new RuntimeException('O vínculo Asaas mudou antes da confirmação da conciliação.');
    }
}

function asaas_atomic_payment_restore_map(object $db, array $current, array $before): void
{
    if ($current === []) {
        if ($before !== []) {
            throw new RuntimeException('O vínculo original da cobrança não está disponível para restauração segura.');
        }
        return;
    }
    if ($before === []) {
        asaas_atomic_payment_query($db, 'DELETE FROM ' . asaas_atomic_payment_table('asaas_payments_map')
            . ' WHERE invoice_id = ? AND asaas_payment_id = ? AND payload_cache = ?', [$current['invoice_id'], $current['asaas_payment_id'], $current['payload_cache']]);
        if ((int) $db->affected_rows() !== 1) {
            throw new RuntimeException('O novo vínculo Asaas mudou antes da restauração.');
        }
        return;
    }
    unset($before['id'], $before['invoice_id']);
    asaas_atomic_payment_update_map($db, $current, $before);
}

function asaas_atomic_payment_diagnostic(string $message, array $operation, ?Throwable $error = null): void
{
    try {
        $context = [
            'operation_id' => $operation['operation_id'],
            'invoice_id' => $operation['invoice_id'],
            'payment_id' => $operation['map_after']['asaas_payment_id'] ?? $operation['map_before']['asaas_payment_id'] ?? null,
            'state' => $operation['state'],
        ];
        if ($error !== null) {
            $context['exception'] = get_class($error);
        }
        if (function_exists('asaas_diagnostic_log')) {
            asaas_diagnostic_log('error', $message, $context);
        } else {
            error_log('[Asaas] ' . $message . ' ' . json_encode($context));
        }
    } catch (Throwable $ignored) {
        // A failed diagnostic cannot justify deleting or reversing a remote payment.
    }
}
