<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Monitoring also accepts integrations that persist a flat JSON array of gateway IDs. */
function asaas_reconciliation_payment_modes($value): array
{
    if (is_array($value)) { return $value; }
    if (!is_string($value) || $value === '') { return []; }
    if (substr(ltrim($value), 0, 1) === '[') {
        $decoded = json_decode($value, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return is_array($decoded) ? $decoded : [];
}

/**
 * SQL candidate selection for Perfex's flat serialized mode IDs or a flat JSON array.
 * Delimited, case-sensitive tokens avoid matching gateway names such as "notasaas".
 * The PHP decoder is used again before any recovery/receipt operation.
 */
function asaas_reconciliation_modes_sql(string $column): array
{
    if (!preg_match('/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/D', $column)) {
        throw new InvalidArgumentException('Invalid payment mode column.');
    }
    $serialized = '^a:[0-9]+:\\{(i:[0-9]+;(s:[0-9]+:"[A-Za-z0-9_-]*";|i:[0-9]+;))*\\}$';
    $scalar = '("[A-Za-z0-9_-]+"|0|[1-9][0-9]*)';
    $json = '^[[:space:]]*\\[[[:space:]]*' . $scalar
        . '([[:space:]]*,[[:space:]]*' . $scalar . ')*[[:space:]]*\\][[:space:]]*$';
    return ['((' . $column . ' REGEXP ? AND LENGTH(REPLACE(' . $column . ', ?, ?)) < LENGTH(' . $column . '))'
        . ' OR (' . $column . ' REGEXP ? AND LENGTH(REPLACE(' . $column . ', ?, ?)) < LENGTH(' . $column . ')))',
        [$serialized, ';s:5:"asaas";', '', $json, '"asaas"', '']];
}
