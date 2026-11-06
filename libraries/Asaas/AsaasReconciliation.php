<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';
require_once __DIR__ . '/../../helpers/asaas_lifecycle_helper.php';

/**
 * Administrative observation and recovery of receipts. Remote operations are GETs only.
 * Receipt persistence remains owned by the existing gateway and its lifecycle locks.
 */
final class AsaasReconciliation
{
    public const CURSOR_OPTION = 'paymentmethod_asaas_sync_cursor';
    public const LAST_RUN_OPTION = 'paymentmethod_asaas_sync_last_run';
    public const LAST_STARTED_OPTION = 'paymentmethod_asaas_sync_last_started_at';
    private const RECEIVED = ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'];
    private const CLOSED = ['deleted', 'cancelled', 'manual_received', 'manual_deleted'];
    private object $ci;
    private ?object $gateway;
    private \Closure $clock;
    private array $columns = [];
    private array $customerIds = [];
    private ?array $redactionSecrets = null;

    public function __construct(object $ci, ?object $gateway = null, ?callable $clock = null)
    {
        $this->ci = $ci;
        $this->gateway = $gateway;
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
    }

    /** No SDK bootstrap or remote request is needed by any listing method. */
    public function overview(): array
    {
        $data = [
            'linked_open_invoices' => 0, 'webhook_errors' => 0, 'webhook_event_errors' => 0, 'last_webhook_at' => null,
            'log_errors' => 0, 'storage_available' => [],
            'cron' => [
                'enabled' => $this->option('sync_enabled', '0') === '1',
                'days' => $this->settingInt('sync_days', 7, 1, 365),
                'limit' => $this->settingInt('sync_limit', 50, 1, 100),
                'minimum_interval_minutes' => 5, 'invoice_cooldown_minutes' => 30,
                'last_run' => $this->lastRun(),
            ],
        ];
        if ($this->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id'])) {
            $data['linked_open_invoices'] = $this->count('SELECT COUNT(*) AS total FROM ' . $this->table('asaas_payments_map')
                . ' m INNER JOIN ' . $this->table('invoices') . ' i ON i.id = m.invoice_id WHERE i.status NOT IN (2,5,6)');
            $data['storage_available'][] = 'payments';
        }
        if ($this->has('asaas_webhook_events', ['id', 'process_status', 'received_at'])) {
            $table = $this->table('asaas_webhook_events');
            $row = $this->one('SELECT received_at FROM ' . $table . ' ORDER BY id DESC LIMIT 1');
            $data['webhook_event_errors'] = $this->count('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE process_status IN (?, ?, ?)', ['error', 'failed', 'failure']);
            $data['last_webhook_at'] = $this->date($row['received_at'] ?? null);
            $data['storage_available'][] = 'webhooks';
        }
        if ($this->has('asaas_logs', ['id', 'level', 'message', 'created_at'])) {
            $data['log_errors'] = $this->count('SELECT COUNT(*) AS total FROM ' . $this->table('asaas_logs') . ' WHERE level = ?', ['error']);
            [$where, $bindings] = $this->logFilter(['source' => 'webhook']);
            $data['webhook_errors'] = $this->count('SELECT COUNT(*) AS total FROM ' . $this->table('asaas_logs') . $where . ' AND level IN (?, ?)', array_merge($bindings, ['warning', 'error']));
            $data['storage_available'][] = 'logs';
        }
        return ['ok' => true, 'data' => $data];
    }

    public function invoices(array $filters = []): array
    {
        [$page, $limit, $offset] = $this->pagination($filters);
        if (!$this->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id', 'status', 'billing_type', 'last_sync_at'])) {
            return $this->emptyPage($page, $limit, 'O histórico de cobranças ainda não está disponível nesta instalação.');
        }
        $where = ['m.invoice_id > 0'];
        $bindings = [];
        $status = (string) ($filters['status'] ?? 'open');
        if ($status !== 'all') {
            $where[] = 'i.status NOT IN (2,5,6)';
        }
        if ($status === 'suspected') {
            $where[] = 'm.status IN (?, ?, ?)';
            $bindings = self::RECEIVED;
        }
        $search = is_scalar($filters['q'] ?? null) ? trim((string) $filters['q']) : '';
        if ($search !== '') {
            $search = substr($search, 0, 100);
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where[] = "(i.id = ? OR i.number = ? OR c.company LIKE ? ESCAPE '!' OR m.asaas_payment_id LIKE ? ESCAPE '!')";
            $number = ctype_digit($search) ? (int) $search : 0;
            array_push($bindings, $number, $number, $like, $like);
        }
        $from = ' FROM ' . $this->table('asaas_payments_map') . ' m INNER JOIN ' . $this->table('invoices')
            . ' i ON i.id = m.invoice_id LEFT JOIN ' . $this->table('clients') . ' c ON c.userid = i.clientid WHERE ' . implode(' AND ', $where);
        $total = $this->count('SELECT COUNT(*) AS total' . $from, $bindings);
        $scoped = class_exists('app\\services\\InvoiceIssuerScope') || class_exists('app\\services\\ClientIssuerScope');
        // Issuer extensions can need additional invoice snapshot columns when formatting the number.
        $invoiceFields = $scoped ? 'i.*' : 'i.total, i.number, i.number_format, i.prefix, i.date';
        $rows = $this->rows('SELECT ' . $invoiceFields . ', i.id AS invoice_id, i.status AS crm_status, c.company AS client_name, '
            . 'm.asaas_payment_id, m.status AS asaas_status, m.billing_type, m.last_sync_at' . $from
            . ' ORDER BY i.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $bindings);
        // One bounded query for the page, rather than one webhook query per invoice.
        $events = $this->latestEvents(array_map('intval', array_column($rows, 'invoice_id')));
        $balances = $scoped ? [] : $this->pageBalances($rows);
        foreach ($rows as &$row) {
            $id = (int) $row['invoice_id'];
            $balance = $scoped ? $this->balance((object) ['id' => $id, 'total' => $row['total']]) : $balances[$id];
            $numberInput = (object) array_merge($row, ['id' => $id, 'status' => (int) $row['crm_status']]);
            $row = [
                'invoice_id' => $id,
                'invoice_number' => function_exists('format_invoice_number') ? $this->safeText((string) \format_invoice_number($numberInput), 100) : (string) $id,
                'client_name' => $this->safeText((string) ($row['client_name'] ?? ''), 200),
                'crm_status' => (int) $row['crm_status'], 'total' => $this->money($row['total']),
                'balance' => \asaas_card_money($balance), 'asaas_payment_id' => $this->identifier($row['asaas_payment_id']),
                'asaas_status' => $this->enumText($row['asaas_status']), 'billing_type' => $this->enumText($row['billing_type']),
                'last_sync_at' => $this->date($row['last_sync_at']),
                'last_webhook_at' => $this->date($events[$id]['received_at'] ?? null),
                'webhook_status' => isset($events[$id]) ? $this->enumText($events[$id]['process_status']) : null,
                'suspected_divergence' => $balance > 0 && in_array($row['asaas_status'], self::RECEIVED, true),
            ];
        }
        unset($row);
        return $this->page($rows, $total, $page, $limit);
    }

    public function events(array $filters = []): array
    {
        [$page, $limit, $offset] = $this->pagination($filters);
        if (!$this->has('asaas_webhook_events', ['id', 'event_id', 'event_type', 'asaas_payment_id', 'invoice_id', 'received_at', 'processed_at', 'process_status', 'error_message'])) {
            return $this->emptyPage($page, $limit, 'O histórico de webhooks ainda não está disponível nesta instalação.');
        }
        $where = '';
        $bindings = [];
        $status = (string) ($filters['status'] ?? 'all');
        $states = ['processed' => ['processed'], 'error' => ['error', 'failed', 'failure'], 'received' => ['received', 'pending', 'retry']];
        if (isset($states[$status])) {
            $where = ' WHERE process_status IN (' . $this->placeholders(count($states[$status])) . ')';
            $bindings = $states[$status];
        }
        $table = $this->table('asaas_webhook_events');
        $total = $this->count('SELECT COUNT(*) AS total FROM ' . $table . $where, $bindings);
        $rows = $this->rows('SELECT event_id, event_type, asaas_payment_id, invoice_id, received_at, processed_at, process_status, '
            . 'SUBSTR(error_message, 1, 2000) AS error_message FROM ' . $table . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $bindings);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'event_id' => $this->identifier($row['event_id'], 128), 'event_type' => $this->enumText($row['event_type']),
                'payment_id' => $this->identifier($row['asaas_payment_id']), 'invoice_id' => (int) ($row['invoice_id'] ?? 0) ?: null,
                'received_at' => $this->date($row['received_at']), 'processed_at' => $this->date($row['processed_at']),
                'process_status' => $this->enumText($row['process_status']), 'error_message' => $this->safeText((string) ($row['error_message'] ?? ''), 1000),
            ];
        }
        return $this->page($items, $total, $page, $limit);
    }

    public function logs(array $filters = []): array
    {
        [$page, $limit, $offset] = $this->pagination($filters);
        if (!$this->has('asaas_logs', ['id', 'level', 'message', 'created_at'])) {
            return $this->emptyPage($page, $limit, 'O histórico de diagnósticos ainda não está disponível nesta instalação.');
        }
        [$where, $bindings] = $this->logFilter($filters);
        $table = $this->table('asaas_logs');
        $context = $this->contextColumn();
        $correlation = in_array('correlation_id', $this->schema('asaas_logs'), true) ? 'SUBSTR(correlation_id, 1, 128)' : 'NULL';
        $total = $this->count('SELECT COUNT(*) AS total FROM ' . $table . $where, $bindings);
        $rows = $this->rows('SELECT id, level, SUBSTR(message, 1, 4000) AS message, created_at, '
            . $correlation . ' AS correlation_id, '
            . ($context ? 'SUBSTR(' . $context . ", 1, 16000)" : "''") . ' AS safe_context FROM ' . $table . $where
            . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $bindings);
        $items = [];
        foreach ($rows as $row) {
            $raw = json_decode((string) ($row['safe_context'] ?? ''), true);
            $raw = is_array($raw) ? $raw : [];
            $safe = [];
            foreach (['source', 'operation', 'trigger', 'invoice_id', 'payment_id', 'payment_ids', 'event_id', 'method', 'path',
                'http_status', 'status', 'remote_status', 'result', 'error_code', 'receipts_added', 'receipt_verification_pending', 'processed', 'duplicated', 'correlation_id', 'exception'] as $key) {
                if (!array_key_exists($key, $raw)) { continue; }
                if ($key === 'payment_ids') {
                    $safe[$key] = array_values(array_filter(array_map(fn ($id): string => $this->identifier($id), array_slice(is_array($raw[$key]) ? $raw[$key] : [], 0, 100))));
                } elseif (is_scalar($raw[$key]) || $raw[$key] === null) {
                    $safe[$key] = is_string($raw[$key]) ? $this->safeText($raw[$key], 250) : $raw[$key];
                }
            }
            $source = in_array($raw['source'] ?? '', ['api', 'webhook', 'reconciliation'], true) ? $raw['source']
                : (isset($raw['method']) || strpos((string) ($raw['operation'] ?? ''), 'card_') === 0 ? 'api' : 'other');
            $items[] = [
                'id' => (int) $row['id'], 'level' => $this->enumText($row['level']), 'message' => $this->safeText((string) $row['message'], 1000),
                'created_at' => $this->date($row['created_at']), 'source' => $source, 'context' => $safe,
                'correlation_id' => $this->identifier($row['correlation_id'] ?: ($raw['correlation_id'] ?? null)) ?: null,
            ];
        }
        return $this->page($items, $total, $page, $limit);
    }

    /** @return int[] Reject malformed or oversized requests rather than silently truncating them. */
    public static function invoiceIds($input): array
    {
        if (!is_array($input) || $input === [] || count($input) > 20) {
            throw new \InvalidArgumentException('Selecione de 1 a 20 faturas por solicitação.');
        }
        $ids = [];
        foreach ($input as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || (int) $id > 2147483647) {
                throw new \InvalidArgumentException('Informe somente identificadores válidos de faturas.');
            }
            $ids[(int) $id] = (int) $id;
        }
        return array_values($ids);
    }

    /** Compare records observations only. Reconcile additionally applies verified receipts locally. */
    public function process(array $invoiceIds, bool $apply = false, string $trigger = 'manual', int $budgetSeconds = 45): array
    {
        $invoiceIds = self::invoiceIds($invoiceIds);
        $trigger = in_array($trigger, ['manual', 'batch', 'cron'], true) ? $trigger : 'manual';
        $deadline = ($this->clock)() + max(1, min(45, $budgetSeconds));
        $results = [];
        $remaining = [];
        $summary = ['processed' => 0, 'reconciled' => 0, 'divergent' => 0, 'errors' => 0, 'receipts_added' => 0];
        foreach ($invoiceIds as $index => $invoiceId) {
            if (($this->clock)() >= $deadline) {
                $remaining = array_slice($invoiceIds, $index);
                break;
            }
            $result = $this->inspectInvoice($invoiceId, $apply);
            $results[] = $result;
            ++$summary['processed'];
            $summary['receipts_added'] += $result['receipts_added'];
            if (in_array($result['result'], ['reconciled', 'partial_reconciled'], true)) { ++$summary['reconciled']; }
            if ($result['result'] === 'divergent') { ++$summary['divergent']; }
            if (in_array($result['result'], ['error', 'amount_mismatch', 'partial_reconciled'], true)) { ++$summary['errors']; }
            $level = in_array($result['result'], ['error', 'partial_reconciled'], true) ? 'error'
                : (in_array($result['result'], ['blocked', 'amount_mismatch'], true) ? 'warning' : 'info');
            \asaas_diagnostic_log($level, ($apply ? 'Conciliação Asaas: ' : 'Conferência Asaas: ') . $result['message'], [
                'source' => 'reconciliation', 'operation' => $apply ? 'reconcile' : 'compare', 'trigger' => $trigger,
                'invoice_id' => $invoiceId, 'result' => $result['result'], 'remote_status' => $result['remote_status'],
                'payment_ids' => $result['payment_ids'], 'receipts_added' => $result['receipts_added'],
                'error_code' => $result['error_code'] ?? null, 'http_status' => $result['http_status'] ?? null,
                'receipt_verification_pending' => !empty($result['receipt_verification_pending']),
            ]);
        }
        return ['ok' => true, 'data' => $results, 'summary' => $summary, 'remaining_ids' => $remaining];
    }

    private function inspectInvoice(int $id, bool $apply): array
    {
        $result = ['invoice_id' => $id, 'result' => 'error', 'message' => 'Não foi possível conferir a cobrança. Tente novamente.',
            'crm_status' => null, 'balance' => null, 'remote_status' => null, 'remote_paid_amount' => '0.00', 'payment_ids' => [], 'receipts_added' => 0];
        try {
            $invoice = \asaas_lifecycle_invoice($id);
            if (!$invoice) { return $this->outcome($result, 'not_found', 'A fatura não foi encontrada no CRM.'); }
            $result['crm_status'] = (int) $invoice->status;
            $result['balance'] = \asaas_card_money($this->balance($invoice));
            $map = \asaas_lifecycle_map($id);
            $cache = \asaas_lifecycle_cached_payment($map);
            if ($map === []) { return $this->outcome($result, 'not_linked', 'Esta fatura não possui uma cobrança Asaas vinculada.'); }
            if ($this->blocked($invoice, $cache)) { return $this->outcome($result, 'blocked', 'A fatura está cancelada, em rascunho ou sua cobrança foi encerrada pelo ciclo de vida.'); }
            $gateway = $this->gateway();
            if (\asaas_card_attempt_blocks($cache)) {
                if (\asaas_payment_amount_to_cents($cache['_asaas_lifecycle']['card_attempt']['amount'] ?? null) > \asaas_payment_amount_to_cents($invoice->total)) {
                    return $this->outcome($result, 'amount_mismatch', 'O total da tentativa de cartão supera o total atual da fatura. Confira o vínculo antes de conciliar.');
                }
                // Reuse the existing GET-only recovery of ambiguous captures and installment validation.
                $payment = $gateway->card_checkout()->reconcile($invoice);
                if ($payment === null) { return $this->outcome($result, 'pending', 'A tentativa de cartão ainda não pôde ser confirmada. Nenhum recebimento foi registrado.'); }
                $map = \asaas_lifecycle_map($id);
                $cache = \asaas_lifecycle_cached_payment($map);
                $this->validatePayment($invoice, $map, $cache, $payment, (string) ($map['asaas_payment_id'] ?? ''));
                $payments = $payment['_asaas_card_receipts'] ?? [];
                if (!is_array($payments) || count($payments) > 100) { throw new \RuntimeException('Invalid receipt group.'); }
                foreach ($payments as $parcel) {
                    if (!is_array($parcel)) { throw new \RuntimeException('Invalid receipt.'); }
                    $this->validatePayment($invoice, $map, $cache, $parcel);
                    if (($parcel['installment'] ?? '') !== ($payment['installment'] ?? '')) { throw new \RuntimeException('Invalid installment.'); }
                }
            } else {
                $paymentId = (string) ($map['asaas_payment_id'] ?? '');
                if ($paymentId === '' || $this->retired($cache, $paymentId)) {
                    return $this->outcome($result, 'pending', 'A vinculação da cobrança precisa ser confirmada antes do recebimento.');
                }
                if ($this->identifier($paymentId) !== $paymentId) { throw new \RuntimeException('Invalid payment identifier.'); }
                $payment = $gateway->request('GET', '/payments/' . rawurlencode($paymentId));
                $this->validatePayment($invoice, $map, $cache, $payment, $paymentId);
                $payments = $this->paymentGroup($invoice, $map, $cache, $payment);
            }
            $result['remote_status'] = $this->enumText($payment['status']);
            $paid = array_values(array_filter($payments, 'asaas_card_is_received'));
            $ids = array_column($paid, 'id');
            if (count($ids) !== count(array_unique($ids))) { throw new \RuntimeException('Duplicate receipt identifiers.'); }
            $result['payment_ids'] = $ids;
            $result['remote_paid_amount'] = \asaas_card_money(array_sum(array_map(fn (array $p): int => $this->amount($p), $paid)));
            $this->snapshot($id, (string) $map['asaas_payment_id'], $payment);
            if ($paid === []) { return $this->outcome($result, 'pending', 'O Asaas ainda não confirmou um recebimento elegível para esta fatura.'); }
            // The remote read can be slow. Reacquire, reload and revalidate local state before applying anything.
            \asaas_receipt_lock($id);
            try {
                $fresh = \asaas_lifecycle_invoice($id);
                $freshMap = \asaas_lifecycle_map($id);
                $freshCache = \asaas_lifecycle_cached_payment($freshMap);
                if ($fresh) { unset($this->customerIds[(int) $fresh->clientid]); }
                if (!$fresh || $this->blocked($fresh, $freshCache) || (string) ($freshMap['asaas_payment_id'] ?? '') !== (string) $map['asaas_payment_id']
                    || (string) $fresh->hash !== (string) $invoice->hash || (int) $fresh->clientid !== (int) $invoice->clientid) {
                    return $this->outcome($result, 'blocked', 'A fatura ou sua vinculação mudou durante a consulta. Faça uma nova conferência.');
                }
                $balance = $this->balance($fresh);
                $result['balance'] = \asaas_card_money($balance);
                $result['crm_status'] = (int) $fresh->status;
                $records = $this->receipts($ids);
                $unrecorded = [];
                $sum = 0;
                foreach ($paid as $receipt) {
                    $this->validatePayment($fresh, $freshMap, $freshCache, $receipt);
                    if (\asaas_lifecycle_should_ignore_payment($id, $receipt['id'])) {
                        return $this->outcome($result, 'blocked', 'A cobrança foi substituída ou encerrada e não pode registrar este recebimento.');
                    }
                    if (isset($records[$receipt['id']])) {
                        foreach ($records[$receipt['id']] as $record) {
                            if ((int) $record['invoiceid'] !== $id) {
                                return $this->outcome($result, 'blocked', 'Este identificador de pagamento já está vinculado a outra fatura.');
                            }
                        }
                        if (count($records[$receipt['id']]) !== 1
                            || \asaas_payment_amount_to_cents($records[$receipt['id']][0]['amount']) !== $this->amount($receipt)) {
                            $result['error_code'] = 'receipt_amount';
                            return $this->outcome($result, 'amount_mismatch', 'O identificador da cobrança possui recibos duplicados ou um valor diferente no CRM. Confira os recibos existentes; nenhum ajuste automático foi realizado.');
                        }
                        continue;
                    }
                    $unrecorded[] = $receipt;
                    $sum += $this->amount($receipt);
                }
                if ($unrecorded === []) { return $this->outcome($result, 'already_reconciled', 'Os recebimentos confirmados já estão registrados no CRM.'); }
                if ($balance === 0 || (int) $fresh->status === 2) {
                    return $this->outcome($result, 'blocked', 'A fatura já está quitada no CRM por outros recebimentos. Confira o histórico financeiro.');
                }
                if ($sum > $balance) { return $this->outcome($result, 'amount_mismatch', 'O valor confirmado e ainda não registrado supera o saldo atual. A baixa automática foi impedida.'); }
                if (!$apply) { return $this->outcome($result, 'divergent', 'O Asaas confirmou recebimentos que ainda não constam no CRM. A fatura pode ser conciliada.'); }
            } finally {
                \asaas_receipt_unlock($id);
            }
            foreach ($unrecorded as $receipt) {
                \asaas_receipt_lock($id);
                try {
                    $fresh = \asaas_lifecycle_invoice($id);
                    $freshMap = \asaas_lifecycle_map($id);
                    $freshCache = \asaas_lifecycle_cached_payment($freshMap);
                    if (!$fresh || $this->blocked($fresh, $freshCache) || (string) ($freshMap['asaas_payment_id'] ?? '') !== (string) $map['asaas_payment_id']
                        || (string) $fresh->hash !== (string) $invoice->hash || (int) $fresh->clientid !== (int) $invoice->clientid) {
                        throw new \RuntimeException('Invoice changed during reconciliation.');
                    }
                    $this->validatePayment($fresh, $freshMap, $freshCache, $receipt);
                    $balance = $this->balance($fresh);
                    $result['balance'] = \asaas_card_money($balance);
                    $result['crm_status'] = (int) $fresh->status;
                    $existing = $this->receipts([$receipt['id']]);
                    if (isset($existing[$receipt['id']])) {
                        foreach ($existing[$receipt['id']] as $record) {
                            if ((int) $record['invoiceid'] !== $id) { throw new \RuntimeException('Receipt belongs to another invoice.'); }
                        }
                        if (count($existing[$receipt['id']]) !== 1
                            || \asaas_payment_amount_to_cents($existing[$receipt['id']][0]['amount']) !== $this->amount($receipt)) {
                            $result['error_code'] = 'receipt_amount';
                            return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'amount_mismatch',
                                'O identificador da cobrança possui recibos duplicados ou um valor diferente no CRM. Confira os recibos existentes antes de continuar.');
                        }
                        continue;
                    }
                    if ($this->amount($receipt) > $balance || (int) $fresh->status === 2) {
                        return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'amount_mismatch',
                            'O saldo mudou durante a conciliação. Confira os recebimentos já registrados antes de continuar.');
                    }
                    // Do not wrap this in remote_payment_depth: addPayment must own its existing deduplication/transaction.
                    $result['receipt_verification_pending'] = true;
                    $success = $gateway->addPayment([
                        'amount' => \asaas_card_money($this->amount($receipt)), 'invoiceid' => $id,
                        'paymentmode' => 'Asaas', 'paymentmethod' => $receipt['billingType'], 'transactionid' => $receipt['id'],
                    ]);
                    $persisted = $this->receipts([$receipt['id']]);
                    $result['receipt_verification_pending'] = false;
                    if (!empty($persisted[$receipt['id']])) {
                        ++$result['receipts_added'];
                        if (count($persisted[$receipt['id']]) !== 1 || (int) $persisted[$receipt['id']][0]['invoiceid'] !== $id
                            || \asaas_payment_amount_to_cents($persisted[$receipt['id']][0]['amount']) !== $this->amount($receipt)) {
                            throw new \RuntimeException('Persisted receipt amount does not match.');
                        }
                    }
                    if (!$success || empty($persisted[$receipt['id']])) { throw new \RuntimeException('Receipt persistence was not confirmed.'); }
                } finally {
                    // addPayment can release the same request-owned lock; this remains safe and idempotent.
                    \asaas_receipt_unlock($id);
                }
            }
            $fresh = \asaas_lifecycle_invoice($id);
            if ($fresh) {
                $result['balance'] = \asaas_card_money($this->balance($fresh));
                $result['crm_status'] = (int) $fresh->status;
            }
            return $this->outcome($result, $result['receipts_added'] > 0 ? 'reconciled' : 'already_reconciled',
                $result['receipts_added'] > 0 ? 'Os recebimentos confirmados foram registrados no CRM.' : 'Os recebimentos já foram registrados por outra execução.');
        } catch (\Throwable $error) {
            // Upstream exceptions can contain response bodies or credentials. Never return/persist their text here.
            $reasons = [
                'Invalid payment binding.' => ['payment_binding', 'A cobrança retornada não corresponde ao vínculo da fatura ou foi excluída no Asaas.'],
                'Payment belongs to another customer.' => ['customer_binding', 'O cliente da cobrança não corresponde ao cadastro vinculado à fatura.'],
                'Customer binding is not available.' => ['customer_missing', 'O vínculo do cliente com o Asaas não está disponível para confirmar o recebimento.'],
                'Incomplete installment list.' => ['installment_incomplete', 'A consulta não retornou todas as parcelas. A baixa foi impedida para evitar um recebimento incorreto.'],
                'Installment sum does not match.' => ['installment_amount', 'O total das parcelas não corresponde ao valor esperado. Confira o parcelamento no Asaas.'],
                'Invalid installment membership.' => ['installment_binding', 'Uma parcela não corresponde ao cliente ou ao parcelamento da fatura.'],
                'Persisted receipt amount does not match.' => ['receipt_amount', 'O recibo local precisa de conferência antes de continuar a conciliação.'],
            ];
            $reason = $reasons[$error->getMessage()] ?? ['unconfirmed', 'Não foi possível confirmar a cobrança e sua vinculação com segurança. Nenhum recebimento foi acrescentado por esta ação.'];
            $result['error_code'] = $reason[0];
            $status = (int) $error->getCode();
            if ($status >= 400 && $status <= 599) { $result['http_status'] = $status; }
            if ($result['receipts_added'] > 0 || !empty($result['receipt_verification_pending'])) {
                try {
                    $fresh = \asaas_lifecycle_invoice($id);
                    if ($fresh) { $result['balance'] = \asaas_card_money($this->balance($fresh)); $result['crm_status'] = (int) $fresh->status; }
                } catch (\Throwable $ignored) {}
            }
            if (!empty($result['receipt_verification_pending'])) {
                $result['error_code'] = 'receipt_verification_pending';
                $reason[1] = 'O resultado do registro local não pôde ser confirmado. Confira os recibos da fatura antes de repetir a operação.';
            }
            return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'error',
                $result['receipts_added'] > 0 && empty($result['receipt_verification_pending'])
                    ? 'Parte dos recebimentos foi registrada. Confira o histórico e repita a conferência para os restantes.'
                    : $reason[1]);
        }
    }

    private function paymentGroup(object $invoice, array $map, array $cache, array $payment): array
    {
        if (empty($payment['installment'])) { return [$payment]; }
        $groupId = (string) $payment['installment'];
        if ($this->identifier($groupId, 120) !== $groupId) { throw new \RuntimeException('Invalid installment identifier.'); }
        $attempt = $cache['_asaas_lifecycle']['card_attempt'] ?? [];
        if (!empty($attempt['installment_count']) && isset($attempt['amount'])) {
            $expectedCount = (int) $attempt['installment_count'];
            $expectedTotal = \asaas_payment_amount_to_cents($attempt['amount']);
        } else {
            $group = $this->gateway()->request('GET', '/installments/' . rawurlencode($groupId));
            if (($group['id'] ?? null) !== $groupId || !empty($group['deleted']) || ($group['customer'] ?? null) !== $payment['customer']
                || ($group['billingType'] ?? null) !== $payment['billingType']) { throw new \RuntimeException('Invalid installment binding.'); }
            $expectedCount = (int) ($group['installmentCount'] ?? 0);
            $expectedTotal = \asaas_payment_amount_to_cents($group['value'] ?? null);
        }
        if ($expectedCount < 2 || $expectedCount > 100 || $expectedTotal <= 0
            || $expectedTotal > \asaas_payment_amount_to_cents($invoice->total)) { throw new \RuntimeException('Invalid installment total.'); }
        $response = $this->gateway()->request('GET', '/installments/' . rawurlencode($groupId) . '/payments', ['limit' => 100]);
        if (!is_array($response['data'] ?? null) || !empty($response['hasMore']) || count($response['data']) !== $expectedCount) {
            throw new \RuntimeException('Incomplete installment list.');
        }
        $ids = [];
        $sum = 0;
        foreach ($response['data'] as $parcel) {
            if (!is_array($parcel)) { throw new \RuntimeException('Invalid installment response.'); }
            $this->validatePayment($invoice, $map, $cache, $parcel);
            if (($parcel['installment'] ?? null) !== $groupId || $parcel['customer'] !== $payment['customer']
                || $parcel['billingType'] !== $payment['billingType'] || in_array($parcel['id'], $ids, true)) {
                throw new \RuntimeException('Invalid installment membership.');
            }
            $ids[] = $parcel['id'];
            $sum += $this->amount($parcel);
        }
        if ($sum !== $expectedTotal || !in_array($payment['id'], $ids, true)) { throw new \RuntimeException('Installment sum does not match.'); }
        return $response['data'];
    }

    private function validatePayment(object $invoice, array $map, array $cache, array $payment, ?string $expectedId = null): void
    {
        $id = $payment['id'] ?? null;
        if (!is_string($id) || $id === '' || $this->identifier($id) !== $id || ($expectedId !== null && $id !== $expectedId)
            || !is_string($invoice->hash ?? null) || $invoice->hash === '' || ($payment['externalReference'] ?? null) !== $invoice->hash
            || !is_string($payment['customer'] ?? null) || $payment['customer'] === ''
            || !is_string($payment['status'] ?? null) || $this->enumText($payment['status']) !== $payment['status']
            || !in_array($payment['billingType'] ?? null, ['BOLETO', 'PIX', 'CREDIT_CARD', 'DEBIT_CARD', 'UNDEFINED'], true)
            || !empty($payment['deleted']) || isset($payment['errors']) || isset($payment['error']) || $this->retired($cache, $id)) {
            throw new \RuntimeException('Invalid payment binding.');
        }
        $expected = array_filter([$cache['customer'] ?? null, $cache['_asaas_lifecycle']['card_attempt']['customer_id'] ?? null]);
        $customer = $this->customerId((int) $invoice->clientid);
        if ($customer !== '') { $expected[] = $customer; }
        if ($expected === []) { throw new \RuntimeException('Customer binding is not available.'); }
        foreach ($expected as $customer) {
            if (!is_string($customer) || $customer !== $payment['customer']) { throw new \RuntimeException('Payment belongs to another customer.'); }
        }
        if ($this->amount($payment) <= 0) { throw new \RuntimeException('Invalid gross payment amount.'); }
    }

    /** Save only status/time on the same binding, preserving all existing lifecycle/card metadata. */
    private function snapshot(int $invoiceId, string $paymentId, array $payment): void
    {
        \asaas_receipt_lock($invoiceId);
        try {
            $map = \asaas_lifecycle_map($invoiceId);
            $cache = \asaas_lifecycle_cached_payment($map);
            if ((string) ($map['asaas_payment_id'] ?? '') !== $paymentId || $this->retired($cache, $paymentId)
                || in_array($cache['_asaas_lifecycle']['state'] ?? '', self::CLOSED, true)) { return; }
            $this->query('UPDATE ' . $this->table('asaas_payments_map')
                . ' SET status = ?, last_sync_at = ? WHERE invoice_id = ? AND asaas_payment_id = ?',
                [$this->enumText($payment['status']), date('Y-m-d H:i:s'), $invoiceId, $paymentId]);
        } finally {
            \asaas_receipt_unlock($invoiceId);
        }
    }

    /** Enabled cron uses a database lock, a fair cursor, a lookback and an invoice cooldown. */
    public static function runCron(?object $ci = null, ?object $gateway = null): array
    {
        $ci = $ci ?? \get_instance();
        $service = new self($ci, $gateway);
        if ($service->option('sync_enabled', '0') !== '1') { return ['status' => 'disabled', 'processed' => 0]; }
        if (!\asaas_receipt_lock_supported($ci->db ?? null)) { return ['status' => 'unsupported_lock', 'processed' => 0]; }
        $lock = 'asaas_reconcile_' . substr(hash('sha256', (string) ($ci->db->database ?? '') . ':' . \db_prefix()), 0, 44);
        $acquired = false;
        try {
            $row = $service->one('SELECT GET_LOCK(?, 0) AS acquired', [$lock]);
            $acquired = in_array($row['acquired'] ?? null, [1, '1'], true);
            if (!$acquired) { return ['status' => 'busy', 'processed' => 0]; }
            $started = time();
            $last = $service->option('sync_last_started_at', '0');
            if (ctype_digit($last) && (int) $last > $started - 300) { return ['status' => 'throttled', 'processed' => 0]; }
            $service->saveOption(self::LAST_STARTED_OPTION, (string) $started);
            if (!$service->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id', 'last_sync_at'])) {
                throw new \RuntimeException('Missing payment map.');
            }
            $cursor = max(0, (int) $service->option('sync_cursor', '0'));
            $limit = $service->settingInt('sync_limit', 50, 1, 100);
            $days = $service->settingInt('sync_days', 7, 1, 365);
            $from = date('Y-m-d', $started - $days * 86400);
            $cooldown = date('Y-m-d H:i:s', $started - 1800);
            $select = 'SELECT i.id FROM ' . $service->table('invoices') . ' i INNER JOIN ' . $service->table('asaas_payments_map')
                . ' m ON m.invoice_id = i.id WHERE i.id > ? AND i.status NOT IN (2,5,6)'
                . ' AND (i.date >= ? OR i.duedate >= ?) AND (m.last_sync_at IS NULL OR m.last_sync_at < ?) ORDER BY i.id ASC LIMIT ' . $limit;
            $rows = $service->rows($select, [$cursor, $from, $from, $cooldown]);
            if ($rows === [] && $cursor > 0) {
                $cursor = 0;
                $rows = $service->rows($select, [0, $from, $from, $cooldown]);
            }
            $ids = array_map('intval', array_column($rows, 'id'));
            $summary = ['processed' => 0, 'reconciled' => 0, 'errors' => 0];
            $deadline = microtime(true) + 45;
            $processed = 0;
            foreach (array_chunk($ids, 20) as $chunk) {
                $budget = (int) floor($deadline - microtime(true));
                if ($budget < 1) { break; }
                $batch = $service->process($chunk, true, 'cron', $budget);
                foreach (['processed', 'reconciled', 'errors'] as $key) { $summary[$key] += $batch['summary'][$key]; }
                foreach ($batch['data'] as $item) { $cursor = (int) $item['invoice_id']; ++$processed; }
                if ($batch['remaining_ids'] !== []) { break; }
            }
            $remaining = count($ids) - $processed;
            if ($remaining === 0 && count($ids) < $limit) { $cursor = 0; }
            $service->saveOption(self::CURSOR_OPTION, (string) $cursor);
            $state = [
                'started_at' => date('Y-m-d H:i:s', $started), 'finished_at' => date('Y-m-d H:i:s'),
                'status' => $remaining > 0 ? 'partial' : 'completed',
                'processed' => $summary['processed'], 'reconciled' => $summary['reconciled'], 'errors' => $summary['errors'],
                'remaining' => $remaining, 'next_cursor' => $cursor,
            ];
            $service->saveOption(self::LAST_RUN_OPTION, json_encode($state, JSON_UNESCAPED_UNICODE));
            return $state;
        } catch (\Throwable $error) {
            $state = ['started_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s'), 'status' => 'error',
                'processed' => 0, 'reconciled' => 0, 'errors' => 1, 'remaining' => 0, 'next_cursor' => 0];
            try { $service->saveOption(self::LAST_RUN_OPTION, json_encode($state)); } catch (\Throwable $ignored) {}
            \asaas_diagnostic_log('error', 'A conciliação automática não pôde ser concluída. Confira as configurações e os registros da integração.',
                ['source' => 'reconciliation', 'operation' => 'cron', 'trigger' => 'cron', 'result' => 'error']);
            return $state;
        } finally {
            if ($acquired) {
                try { $service->one('SELECT RELEASE_LOCK(?) AS released', [$lock]); } catch (\Throwable $ignored) {}
            }
        }
    }

    private function gateway(): object
    {
        if ($this->gateway === null) {
            $this->ci->load->library('asaas/asaas_gateway');
            $this->gateway = $this->ci->asaas_gateway;
        }
        return $this->gateway;
    }

    private function blocked(object $invoice, array $cache): bool
    {
        return in_array((int) ($invoice->status ?? 0), [5, 6], true) || in_array($cache['_asaas_lifecycle']['state'] ?? '', self::CLOSED, true);
    }

    private function retired(array $cache, string $id): bool
    {
        return in_array($id, array_merge((array) ($cache['_asaas_lifecycle']['retired_payment_ids'] ?? []),
            (array) ($cache['_asaas_lifecycle']['card_attempt']['replaced_ids'] ?? [])), true);
    }

    private function amount(array $payment): int
    {
        return \asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null);
    }

    private function balance(object $invoice): int
    {
        return \asaas_payment_amount_to_cents(\get_invoice_total_left_to_pay((int) $invoice->id, $invoice->total));
    }

    /** Match standard Perfex totals in two aggregate queries; custom issuer scopes retain native helpers. */
    private function pageBalances(array $rows): array
    {
        $ids = array_map('intval', array_column($rows, 'invoice_id'));
        if ($ids === []) { return []; }
        $balances = [];
        foreach ($rows as $row) { $balances[(int) $row['invoice_id']] = \asaas_payment_amount_to_cents($row['total']); }
        $groups = [['invoicepaymentrecords', 'invoiceid']];
        if ($this->has('credits', ['invoice_id', 'amount'])) { $groups[] = ['credits', 'invoice_id']; }
        foreach ($groups as [$table, $column]) {
            $paid = $this->rows('SELECT ' . $column . ' AS invoice_id, SUM(amount) AS paid FROM ' . $this->table($table)
                . ' WHERE ' . $column . ' IN (' . $this->placeholders(count($ids)) . ') GROUP BY ' . $column, $ids);
            foreach ($paid as $payment) { $balances[(int) $payment['invoice_id']] -= \asaas_payment_amount_to_cents($payment['paid']); }
        }
        return array_map(static fn (int $cents): int => max(0, $cents), $balances);
    }

    private function customerId(int $clientId): string
    {
        if (!array_key_exists($clientId, $this->customerIds)) {
            $row = [];
            if ($this->has('asaas_customers_map', ['perfex_client_id', 'asaas_customer_id'])) {
                $row = $this->one('SELECT asaas_customer_id FROM ' . $this->table('asaas_customers_map') . ' WHERE perfex_client_id = ? LIMIT 1', [$clientId]);
            }
            if (empty($row['asaas_customer_id']) && $this->has('clients', ['userid', 'asaas_customer_id'])) {
                $row = $this->one('SELECT asaas_customer_id FROM ' . $this->table('clients') . ' WHERE userid = ? LIMIT 1', [$clientId]);
            }
            $this->customerIds[$clientId] = is_string($row['asaas_customer_id'] ?? null) ? $row['asaas_customer_id'] : '';
        }
        return $this->customerIds[$clientId];
    }

    private function receipts(array $ids): array
    {
        if ($ids === []) { return []; }
        $rows = $this->rows('SELECT invoiceid, transactionid, amount FROM ' . $this->table('invoicepaymentrecords')
            . ' WHERE transactionid IN (' . $this->placeholders(count($ids)) . ')', $ids);
        $result = [];
        foreach ($rows as $row) { $result[(string) $row['transactionid']][] = $row; }
        return $result;
    }

    private function latestEvents(array $ids): array
    {
        if ($ids === [] || !$this->has('asaas_webhook_events', ['id', 'invoice_id', 'received_at', 'process_status'])) { return []; }
        $table = $this->table('asaas_webhook_events');
        $rows = $this->rows('SELECT e.invoice_id, e.received_at, e.process_status FROM ' . $table
            . ' e INNER JOIN (SELECT invoice_id, MAX(id) AS latest_id FROM ' . $table . ' WHERE invoice_id IN ('
            . $this->placeholders(count($ids)) . ') GROUP BY invoice_id) latest ON latest.latest_id = e.id', $ids);
        return array_column($rows, null, 'invoice_id');
    }

    private function logFilter(array $filters): array
    {
        $clauses = [];
        $bindings = [];
        $level = (string) ($filters['level'] ?? 'all');
        if (in_array($level, ['info', 'debug', 'warning', 'error'], true)) { $clauses[] = 'level = ?'; $bindings[] = $level; }
        $source = (string) ($filters['source'] ?? 'all');
        if (in_array($source, ['api', 'webhook', 'reconciliation'], true)) {
            $context = $this->contextColumn();
            if ($context === null) { $clauses[] = '1 = 0'; }
            else {
                // JSON generated by the diagnostic helper is compact. REPLACE also supports old pretty JSON.
                $expression = "REPLACE(" . $context . ", ' ', '')";
                $parts = [$expression . ' LIKE ?'];
                $bindings[] = '%"source":"' . $source . '"%';
                if ($source === 'api') {
                    $parts[] = '(' . $expression . " NOT LIKE ? AND (" . $expression . ' LIKE ? OR ' . $expression . " LIKE ? ESCAPE '!'))";
                    array_push($bindings, '%"source":%', '%"method":%', '%"operation":"card!_%');
                }
                $clauses[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function contextColumn(): ?string
    {
        foreach (['context_json', 'context'] as $name) { if (in_array($name, $this->schema('asaas_logs'), true)) { return $name; } }
        return null;
    }

    private function lastRun(): ?array
    {
        $stored = json_decode($this->option('sync_last_run', ''), true);
        if (!is_array($stored) || !in_array($stored['status'] ?? '', ['completed', 'partial', 'error'], true)) { return null; }
        $result = ['started_at' => $this->date($stored['started_at'] ?? null), 'finished_at' => $this->date($stored['finished_at'] ?? null), 'status' => $stored['status']];
        foreach (['processed', 'reconciled', 'errors', 'remaining', 'next_cursor'] as $key) { $result[$key] = max(0, (int) ($stored[$key] ?? 0)); }
        return $result;
    }

    private function saveOption(string $name, string $value): void
    {
        $current = \get_option($name);
        if ($current === false || $current === null || $current === '') {
            // Perfex versions differ on the missing-option sentinel; add_option is idempotent.
            if (!\add_option($name, $value, 0)) { \update_option($name, $value); }
        } else { \update_option($name, $value); }
    }

    private function option(string $name, string $default): string
    {
        $value = function_exists('get_option') ? \get_option('paymentmethod_asaas_' . $name) : false;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private function settingInt(string $name, int $default, int $min, int $max): int
    {
        $value = $this->option($name, (string) $default);
        return ctype_digit($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private function outcome(array $result, string $outcome, string $message): array
    {
        $result['result'] = $outcome;
        $result['message'] = $message;
        return $result;
    }

    private function pagination(array $filters): array
    {
        $page = is_scalar($filters['page'] ?? null) ? max(1, min(1000000, (int) $filters['page'])) : 1;
        $limit = is_scalar($filters['per_page'] ?? null) ? max(1, min(50, (int) $filters['per_page'])) : 25;
        return [$page, $limit, ($page - 1) * $limit];
    }

    private function page(array $data, int $total, int $page, int $limit): array
    {
        return ['ok' => true, 'data' => $data, 'pagination' => ['page' => $page, 'per_page' => $limit, 'total' => $total, 'has_more' => $page * $limit < $total]];
    }

    private function emptyPage(int $page, int $limit, string $message): array
    {
        return $this->page([], 0, $page, $limit) + ['message' => $message, 'storage_available' => false];
    }

    private function table(string $name): string
    {
        $table = \db_prefix() . $name;
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new \RuntimeException('Invalid table prefix.'); }
        return $table;
    }

    private function schema(string $name): array
    {
        if (!array_key_exists($name, $this->columns)) {
            $table = $this->table($name);
            $this->columns[$name] = \asaas_diagnostic_quiet_db($this->ci->db, fn (): array => $this->ci->db->table_exists($table) ? (array) $this->ci->db->list_fields($table) : []);
        }
        return $this->columns[$name];
    }

    private function has(string $name, array $columns): bool { return array_diff($columns, $this->schema($name)) === []; }
    private function placeholders(int $count): string { return implode(',', array_fill(0, $count, '?')); }
    private function count(string $sql, array $bindings = []): int { return (int) ($this->one($sql, $bindings)['total'] ?? 0); }
    private function one(string $sql, array $bindings = []): array { return $this->query($sql, $bindings)->row_array() ?: []; }
    private function rows(string $sql, array $bindings = []): array { return $this->query($sql, $bindings)->result_array(); }

    private function query(string $sql, array $bindings = [])
    {
        $result = \asaas_diagnostic_quiet_db($this->ci->db, fn () => $this->ci->db->query($sql, $bindings));
        if ($result === false) { throw new \RuntimeException('Diagnostic database operation failed.'); }
        return $result;
    }

    private function identifier($value, int $limit = 128): string
    {
        return is_string($value) && strlen($value) <= $limit && preg_match('/^[A-Za-z0-9_-]+$/D', $value) ? $value : '';
    }

    private function enumText($value): string { return is_string($value) && preg_match('/^[A-Za-z_]{1,64}$/D', $value) ? $value : ''; }
    private function date($value): ?string { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/D', $value) ? $value : null; }
    private function money($value): string { return \asaas_card_money(\asaas_payment_amount_to_cents($value)); }

    private function safeText(string $value, int $limit): string
    {
        $this->redactionSecrets = $this->redactionSecrets ?? \asaas_diagnostic_secrets();
        $value = (string) \asaas_diagnostic_clean($value, $this->redactionSecrets, 0);
        // The legacy viewer may encounter records created before card-data redaction existed.
        $value = (string) preg_replace('/(?<![A-Za-z0-9_])(?:\d[ -]?){13,19}(?![A-Za-z0-9_])/', '[REDACTED]', $value);
        $value = (string) preg_replace('/((?:cvv|ccv|securitycode|creditcard|cardnumber)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;\}\]]+)/i', '$1[REDACTED]', $value);
        $value = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
        return substr($value, 0, $limit);
    }
}
