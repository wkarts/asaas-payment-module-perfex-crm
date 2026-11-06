<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Add only the indexes used by monitoring, during installation/upgrade. */
function asaas_monitor_ensure_indexes($db): void
{
    $definitions = [
        'asaas_webhook_events' => [
            'asaas_monitor_invoice' => ['invoice_id', 'id'],
            'asaas_monitor_status' => ['process_status', 'id'],
        ],
        'asaas_logs' => [
            'asaas_monitor_level' => ['level', 'id'],
        ],
    ];
    $prefix = db_prefix();
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
        throw new RuntimeException('Prefixo de tabelas inválido para a atualização Asaas.');
    }
    $hasDebug = property_exists($db, 'db_debug');
    $previousDebug = $hasDebug ? $db->db_debug : null;
    try {
        if ($hasDebug) { $db->db_debug = false; }
        foreach ($definitions as $suffix => $indexes) {
            $table = $prefix . $suffix;
            if (!$db->table_exists($table)) { continue; }
            $result = $db->query('SHOW INDEX FROM `' . $table . '`');
            if ($result === false) {
                throw new RuntimeException('Não foi possível consultar os índices de monitoramento Asaas.');
            }
            $existing = [];
            foreach ($result->result_array() as $row) {
                if (isset($row['Key_name'], $row['Seq_in_index'], $row['Column_name'])) {
                    $existing[$row['Key_name']][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
                }
            }
            foreach ($existing as &$columns) { ksort($columns); $columns = array_values($columns); }
            unset($columns);
            $add = [];
            foreach ($indexes as $name => $columns) {
                $covered = false;
                foreach ($existing as $available) {
                    if (array_slice($available, 0, count($columns)) === $columns) { $covered = true; break; }
                }
                if ($covered) { continue; }
                if (isset($existing[$name])) {
                    // Never replace an operator's index or silently mark a conflicting upgrade as complete.
                    throw new RuntimeException('Já existe um índice Asaas com definição diferente da esperada.');
                }
                $add[] = 'ADD INDEX `' . $name . '` (`' . implode('`, `', $columns) . '`)';
            }
            if ($add !== [] && $db->query('ALTER TABLE `' . $table . '` ' . implode(', ', $add)) === false) {
                throw new RuntimeException('Não foi possível criar os índices de monitoramento Asaas.');
            }
        }
    } finally {
        if ($hasDebug) { $db->db_debug = $previousDebug; }
    }
}
