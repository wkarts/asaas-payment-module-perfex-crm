<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Search the native invoice formats without formatting/fetching every invoice in PHP. */
function asaas_monitor_invoice_search(string $search): array
{
    $search = trim(substr($search, 0, 100));
    if ($search === '') { return ['1 = 1', []]; }
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $clauses = ["c.company LIKE ? ESCAPE '!'", "m.asaas_payment_id LIKE ? ESCAPE '!'"];
    $bindings = [$like, $like];
    if (ctype_digit($search) && strlen(ltrim($search, '0')) <= 10) {
        $clauses[] = '(i.id = ? OR i.number = ?)';
        array_push($bindings, (int) $search, (int) $search);
    } else {
        $candidates = [];
        // Keep all numeric characters together: a padded sequence must not become part
        // of the prefix while the regexp tries to reinterpret it as a year.
        if (preg_match('~^(.*?[^0-9])([0-9]{1,10})/([0-9]{1,2})/([0-9]{4})$~D', $search, $parts)) {
            $candidates[] = [$parts[1], (int) $parts[2], (int) $parts[4], (int) $parts[3], 4];
        } elseif (preg_match('~^(.*?[^0-9])([0-9]{1,10})/([0-9]{1,10})$~D', $search, $parts)) {
            // Both parts can look like years. The stored native number format decides.
            if (strlen($parts[2]) === 4) { $candidates[] = [$parts[1], (int) $parts[3], (int) $parts[2], null, 2]; }
            if (strlen($parts[3]) === 4) { $candidates[] = [$parts[1], (int) $parts[2], (int) $parts[3], null, 3]; }
        } elseif (preg_match('~^(.*?[^0-9])([0-9]{1,10})$~D', $search, $parts)) {
            $candidates[] = [$parts[1], (int) $parts[2], null, null, null];
        }
        foreach ($candidates as [$prefix, $number, $year, $month, $format]) {
            if ($number < 1 || ($year !== null && ($year < 1000 || $year > 9998))
                || ($month !== null && ($month < 1 || $month > 12))) { continue; }
            $numberClause = '(i.prefix = ? AND i.number = ?';
            array_push($bindings, $prefix, $number);
            if ($format !== null) { $numberClause .= ' AND i.number_format = ?'; $bindings[] = $format; }
            if ($year !== null) {
                $from = sprintf('%04d-%02d-01', $year, $month ?? 1);
                $until = (new DateTimeImmutable($from))->modify($month === null ? '+1 year' : '+1 month')->format('Y-m-d');
                $numberClause .= ' AND i.date >= ? AND i.date < ?';
                array_push($bindings, $from, $until);
            }
            $clauses[] = $numberClause . ')';
        }
    }
    return ['(' . implode(' OR ', $clauses) . ')', $bindings];
}
