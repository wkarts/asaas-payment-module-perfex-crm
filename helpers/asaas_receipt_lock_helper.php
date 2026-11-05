<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Request-owned named locks, independent of SQL transactions and savepoints. */
function &asaas_receipt_lock_state(): array
{
    static $state = [];
    return $state;
}

function asaas_receipt_lock_database(): ?object
{
    $ci = function_exists('get_instance') ? get_instance() : null;
    return is_object($ci) && isset($ci->db) && is_object($ci->db) ? $ci->db : null;
}

function asaas_receipt_lock_supported(?object $db): bool
{
    if ($db === null) {
        return false;
    }
    $driver = strtolower((string) ($db->dbdriver ?? ''));
    $subdriver = strtolower((string) ($db->subdriver ?? ''));
    return in_array($driver, ['mysql', 'mysqli'], true) || ($driver === 'pdo' && $subdriver === 'mysql');
}

/** Prefer the actual connection handle; separate wrappers can share one connection. */
function asaas_receipt_lock_connection(object $db): string
{
    $connection = $db->conn_id ?? null;
    if (is_object($connection)) {
        return 'object:' . spl_object_id($connection);
    }
    if (is_resource($connection)) {
        return 'resource:' . get_resource_id($connection);
    }
    return 'database:' . spl_object_id($db);
}

function asaas_receipt_lock_name(object $db, int $invoiceId): string
{
    $identity = serialize([(string) ($db->hostname ?? ''), (string) ($db->database ?? ''), $invoiceId]);
    return 'asaas_receipt_' . substr(hash('sha256', $identity), 0, 50);
}

function asaas_receipt_lock_quiet(object $db, callable $operation)
{
    $hasDebug = property_exists($db, 'db_debug');
    $debug = $hasDebug ? $db->db_debug : null;
    try {
        if ($hasDebug) {
            $db->db_debug = false;
        }
        return $operation();
    } finally {
        if ($hasDebug) {
            $db->db_debug = $debug;
        }
    }
}

function asaas_receipt_lock_diagnostic(string $message, ?int $invoiceId = null): void
{
    try {
        if (function_exists('asaas_diagnostic_log')) {
            asaas_diagnostic_log('error', $message, $invoiceId === null ? [] : ['invoice_id' => $invoiceId]);
        } elseif (function_exists('log_message')) {
            log_message('error', '[Asaas] ' . $message);
        } else {
            error_log('[Asaas] ' . $message);
        }
    } catch (Throwable $ignored) {
        // Cleanup diagnostics must not replace a payment or database failure.
    }
}

/** Acquire once on this connection: repeated GET_LOCK calls would increase MySQL's reference count. */
function asaas_receipt_lock(int $invoiceId): void
{
    if ($invoiceId <= 0) {
        throw new RuntimeException('Informe uma fatura válida para bloquear o recebimento.');
    }
    $db = asaas_receipt_lock_database();
    if (!asaas_receipt_lock_supported($db)) {
        return;
    }
    $state = &asaas_receipt_lock_state();
    if (!empty($state['shutdown_done'])) {
        throw new RuntimeException('Não é possível iniciar um recebimento após o encerramento da integração.');
    }
    $name = asaas_receipt_lock_name($db, $invoiceId);
    $key = asaas_receipt_lock_connection($db) . ':' . $name;
    if (isset($state['locks'][$key])) {
        return;
    }
    if (!empty($state['acquiring'][$key])) {
        throw new RuntimeException('O bloqueio deste recebimento já está sendo adquirido.');
    }
    $state['acquiring'][$key] = true;
    try {
        $result = asaas_receipt_lock_quiet($db, static fn () => $db->query('SELECT GET_LOCK(?, 5) AS acquired', [$name]));
        if (!is_object($result) || !method_exists($result, 'row_array')) {
            throw new RuntimeException('Invalid lock result.');
        }
        $row = $result->row_array();
        $acquired = is_array($row) ? ($row['acquired'] ?? null) : null;
        if ($acquired !== 1 && $acquired !== '1') {
            throw new RuntimeException('Lock was not acquired.');
        }
        // Some CI drivers initialize the connection while executing the first query.
        $connection = asaas_receipt_lock_connection($db);
        $state['locks'][$connection . ':' . $name] = [
            'db' => $db, 'connection' => $connection, 'invoice_id' => $invoiceId, 'name' => $name,
        ];
    } catch (Throwable $error) {
        // Never expose SQL, host/database names or driver error messages to the caller.
        throw new RuntimeException('Não foi possível obter o bloqueio de recebimento da fatura em até 5 segundos. Tente novamente.');
    } finally {
        unset($state['acquiring'][$key]);
    }
}

/** Release only a lock acquired by this helper, on the same physical connection. */
function asaas_receipt_release_owned_lock(string $key): void
{
    $state = &asaas_receipt_lock_state();
    if (!isset($state['locks'][$key])) {
        return;
    }
    $lock = $state['locks'][$key];
    $db = $lock['db'];
    if (asaas_receipt_lock_connection($db) !== $lock['connection']) {
        // The old connection was replaced; never release a lock on its replacement.
        unset($state['locks'][$key]);
        return;
    }
    try {
        $result = asaas_receipt_lock_quiet($db, static fn () => $db->query('SELECT RELEASE_LOCK(?) AS released', [$lock['name']]));
        if (!is_object($result) || !method_exists($result, 'row_array')) {
            throw new RuntimeException('Invalid release result.');
        }
        $row = $result->row_array();
        if (!is_array($row) || !array_key_exists('released', $row)
            || !in_array($row['released'], [1, '1', 0, '0', null], true)) {
            throw new RuntimeException('Invalid release status.');
        }
        // 0/null mean that this connection no longer owns the named lock.
        unset($state['locks'][$key]);
    } catch (Throwable $error) {
        // Retain ownership metadata so shutdown can retry a failed explicit release.
        asaas_receipt_lock_diagnostic('Não foi possível liberar o bloqueio de recebimento; a liberação será tentada novamente no encerramento.', (int) $lock['invoice_id']);
    }
}

function asaas_receipt_unlock(int $invoiceId): void
{
    $db = asaas_receipt_lock_database();
    if ($invoiceId <= 0 || !asaas_receipt_lock_supported($db)) {
        return;
    }
    $key = asaas_receipt_lock_connection($db) . ':' . asaas_receipt_lock_name($db, $invoiceId);
    asaas_receipt_release_owned_lock($key);
}

function asaas_receipt_unlock_all(): void
{
    $state = &asaas_receipt_lock_state();
    foreach (array_keys($state['locks'] ?? []) as $key) {
        asaas_receipt_release_owned_lock($key);
    }
}

/** Keep the named lock through rollback, remote compensation and atomic-core cleanup. */
function asaas_receipt_lock_shutdown(): void
{
    $state = &asaas_receipt_lock_state();
    if (!empty($state['shutdown_running']) || !empty($state['shutdown_done'])) {
        return;
    }
    $state['shutdown_running'] = true;
    try {
        try {
            if (function_exists('asaas_lifecycle_state')) {
                $lifecycle = &asaas_lifecycle_state();
                if (isset($lifecycle['transaction']) && is_object($lifecycle['transaction'])
                    && method_exists($lifecycle['transaction'], 'shutdown')) {
                    $lifecycle['transaction']->shutdown();
                }
            }
        } catch (Throwable $error) {
            asaas_receipt_lock_diagnostic('Falha ao encerrar a transação da fatura antes de liberar o bloqueio de recebimento.');
        }
        try {
            if (function_exists('asaas_atomic_payment_shutdown')) {
                asaas_atomic_payment_shutdown();
            }
        } catch (Throwable $error) {
            asaas_receipt_lock_diagnostic('Falha ao concluir o recebimento atômico antes de liberar o bloqueio da fatura.');
        }
    } finally {
        asaas_receipt_unlock_all();
        $state['shutdown_running'] = false;
        $state['shutdown_done'] = true;
    }
}

register_shutdown_function('asaas_receipt_lock_shutdown');
