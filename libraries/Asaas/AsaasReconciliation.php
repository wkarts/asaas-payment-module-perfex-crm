<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';
require_once __DIR__ . '/../../helpers/asaas_lifecycle_helper.php';
require_once __DIR__ . '/../../helpers/asaas_reconciliation_helper.php';

/**
 * Administrative observation and recovery of receipts. Remote operations are GETs only.
 * Receipt persistence remains owned by the existing gateway and its lifecycle locks.
 */
final class AsaasReconciliation
{
    public const CURSOR_OPTION = 'paymentmethod_asaas_sync_cursor';
    public const LAST_RUN_OPTION = 'paymentmethod_asaas_sync_last_run';
    public const LAST_STARTED_OPTION = 'paymentmethod_asaas_sync_last_started_at';
    public const RECENT_ATTEMPTS_OPTION = 'paymentmethod_asaas_sync_recent_attempts';
    private const RECEIVED = ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'];
    private const CLOSED = ['deleted', 'cancelled', 'manual_received', 'manual_deleted'];
    private object $ci;
    private ?object $gateway;
    private \Closure $clock;
    private array $columns = [];
    private array $customerIds = [];
    private ?array $redactionSecrets = null;
    private float $deadline = INF;
    private array $scopeSnapshots = [];
    private array $verifiedCustomers = [];

    public function __construct(object $ci, ?object $gateway = null, ?callable $clock = null)
    {
        $this->ci = $ci;
        $this->gateway = $gateway;
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
    }

    /** Local overview, invoices, webhook and log history do not bootstrap the SDK. */
    public function overview(): array
    {
        $data = [
            'linked_open_invoices' => 0, 'eligible_open_invoices' => 0, 'unlinked_open_invoices' => 0,
            'webhook_errors' => 0, 'webhook_event_errors' => 0, 'last_webhook_at' => null,
            'log_errors' => 0, 'storage_available' => [],
            'cron' => [
                'enabled' => $this->option('sync_enabled', '0') === '1',
                'days' => $this->settingInt('sync_days', 7, 1, 365),
                'limit' => $this->settingInt('sync_limit', 50, 1, 100),
                'minimum_interval_minutes' => 5, 'invoice_cooldown_minutes' => 30,
                'open_invoices_date_limit' => false, 'days_scope' => 'recent_receipts_on_paid_invoices',
                'last_run' => $this->lastRun(),
            ],
        ];
        if ($this->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id'])) {
            [$eligible, $bindings] = $this->eligibleSql();
            $counts = $this->one('SELECT COUNT(*) AS eligible, SUM(CASE WHEN m.asaas_payment_id IS NOT NULL'
                . " AND m.asaas_payment_id <> '' THEN 1 ELSE 0 END) AS linked FROM " . $this->table('invoices')
                . ' i LEFT JOIN ' . $this->table('asaas_payments_map') . ' m ON m.invoice_id = i.id'
                . ' WHERE i.status NOT IN (2,5,6) AND ' . $eligible, $bindings);
            $data['eligible_open_invoices'] = (int) ($counts['eligible'] ?? 0);
            $data['linked_open_invoices'] = (int) ($counts['linked'] ?? 0);
            $data['unlinked_open_invoices'] = $data['eligible_open_invoices'] - $data['linked_open_invoices'];
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
        [$eligible, $bindings] = $this->eligibleSql();
        $where = [$eligible];
        $status = (string) ($filters['status'] ?? 'open');
        if ($status !== 'all') {
            $where[] = 'i.status NOT IN (2,5,6)';
        }
        if ($status === 'suspected') {
            $where[] = 'm.status IN (?, ?, ?)';
            array_push($bindings, ...self::RECEIVED);
        }
        if ($status === 'missing') { $where[] = "(m.asaas_payment_id IS NULL OR m.asaas_payment_id = '')"; }
        $search = is_scalar($filters['q'] ?? null) ? trim((string) $filters['q']) : '';
        if ($search !== '') {
            $search = substr($search, 0, 100);
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where[] = "(i.id = ? OR i.number = ? OR c.company LIKE ? ESCAPE '!' OR m.asaas_payment_id LIKE ? ESCAPE '!')";
            $number = ctype_digit($search) ? (int) $search : 0;
            array_push($bindings, $number, $number, $like, $like);
        }
        $from = ' FROM ' . $this->table('invoices') . ' i LEFT JOIN ' . $this->table('asaas_payments_map')
            . ' m ON m.invoice_id = i.id LEFT JOIN ' . $this->table('clients') . ' c ON c.userid = i.clientid WHERE ' . implode(' AND ', $where);
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
            $linked = $this->identifier($row['asaas_payment_id']) !== '';
            $row = [
                'invoice_id' => $id,
                'invoice_number' => function_exists('format_invoice_number') ? $this->safeText((string) \format_invoice_number($numberInput), 100) : (string) $id,
                'client_name' => $this->safeText((string) ($row['client_name'] ?? ''), 200),
                'crm_status' => (int) $row['crm_status'], 'total' => $this->money($row['total']),
                'balance' => \asaas_card_money($balance), 'asaas_payment_id' => $this->identifier($row['asaas_payment_id']),
                'asaas_status' => $this->enumText($row['asaas_status']), 'billing_type' => $this->enumText($row['billing_type']),
                'last_sync_at' => $this->date($row['last_sync_at']),
                'binding_status' => $linked ? 'linked' : 'missing',
                'binding_message' => $linked ? 'Cobrança vinculada ao CRM.' : 'Compare a fatura para localizar e validar a cobrança no Asaas.',
                'last_webhook_at' => $this->date($events[$id]['received_at'] ?? null),
                'webhook_status' => isset($events[$id]) ? $this->enumText($events[$id]['process_status']) : null,
                'suspected_divergence' => $balance > 0 && in_array($row['asaas_status'], self::RECEIVED, true),
            ];
        }
        unset($row);
        return $this->page($rows, $total, $page, $limit);
    }

    /** Explicit administrative API browsing. This method never creates bindings or receipts. */
    public function payments(array $filters = []): array
    {
        [$page, $limit, $offset] = $this->pagination($filters + ['per_page' => 50]);
        $status = $filters['status'] ?? 'all';
        $dateField = $filters['date_field'] ?? 'payment';
        if (!is_string($status) || !in_array($status, array_merge(['all', 'PENDING', 'OVERDUE'], self::RECEIVED), true)) {
            throw new \InvalidArgumentException('Escolha uma situação válida para consultar as cobranças Asaas.');
        }
        $dates = ['payment' => 'paymentDate', 'due' => 'dueDate', 'created' => 'dateCreated'];
        if (!is_string($dateField) || !isset($dates[$dateField])) { throw new \InvalidArgumentException('Escolha uma data de pagamento, vencimento ou criação.'); }
        $query = ['limit' => $limit, 'offset' => $offset];
        if ($status !== 'all') { $query['status'] = $status; }
        $dateValues = [];
        foreach (['date_from' => '[ge]', 'date_to' => '[le]'] as $field => $operator) {
            $value = $filters[$field] ?? '';
            if ($value === null || $value === '') { continue; }
            if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new \InvalidArgumentException('Informe datas válidas no formato AAAA-MM-DD.');
            }
            $dateValues[$field] = $value;
            $query[$dates[$dateField] . $operator] = $value;
        }
        if (isset($dateValues['date_from'], $dateValues['date_to']) && $dateValues['date_from'] > $dateValues['date_to']) {
            throw new \InvalidArgumentException('A data inicial não pode ser posterior à data final.');
        }
        $this->deadline = ($this->clock)() + 45;
        $this->verifiedCustomers = [];
        try {
            $apiScope = $this->apiScope();
            $response = $this->remote('/payments', $query);
            if (!is_array($response['data'] ?? null) || !is_bool($response['hasMore'] ?? null) || count($response['data']) > $limit) {
                throw new \RuntimeException('Incomplete recovery payment list.');
            }
            $total = $response['totalCount'] ?? ($offset + count($response['data']) + ($response['hasMore'] ? 1 : 0));
            $returned = count($response['data']);
            if (filter_var($total, FILTER_VALIDATE_INT) === false || (int) $total < 0
                || ($returned > 0 && (int) $total < $offset + $returned)
                || ($response['hasMore'] && ($returned === 0 || (int) $total <= $offset + $returned))
                || (!$response['hasMore'] && (int) $total > $offset + $returned)) {
                throw new \RuntimeException('Incomplete recovery payment list.');
            }
            $hashes = [];
            $pagePayments = [];
            foreach ($response['data'] as $payment) {
                if (!is_array($payment) || $this->identifier($payment['id'] ?? null) === '' || isset($pagePayments[$payment['id']])) {
                    throw new \RuntimeException('Incomplete recovery payment list.');
                }
                $pagePayments[$payment['id']] = $payment;
                if (is_string($payment['externalReference'] ?? null) && $payment['externalReference'] !== '' && strlen($payment['externalReference']) <= 200) {
                    $hashes[$payment['externalReference']] = $payment['externalReference'];
                }
            }
            $matches = $hashes === [] ? [] : $this->rows('SELECT hash, MIN(id) AS invoice_id, COUNT(*) AS matches FROM ' . $this->table('invoices')
                . ' WHERE hash IN (' . $this->placeholders(count($hashes)) . ') GROUP BY hash', array_values($hashes));
            $byHash = array_column($matches, null, 'hash');
            $ids = array_map('intval', array_column(array_filter($matches, static fn (array $row): bool => (int) $row['matches'] === 1), 'invoice_id'));
            $invoices = $ids === [] ? [] : array_column($this->rows('SELECT * FROM ' . $this->table('invoices')
                . ' WHERE id IN (' . $this->placeholders(count($ids)) . ')', $ids), null, 'id');
            $maps = $ids === [] || !$this->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id', 'payload_cache']) ? []
                : array_column($this->rows('SELECT * FROM ' . $this->table('asaas_payments_map')
                    . ' WHERE invoice_id IN (' . $this->placeholders(count($ids)) . ')', $ids), null, 'invoice_id');
            $previews = [];
            $items = [];
            $limited = false;
            foreach ($response['data'] as $payment) {
                $item = ['payment_id' => $payment['id'], 'status' => $this->enumText($payment['status'] ?? null),
                    'billing_type' => $this->enumText($payment['billingType'] ?? null),
                    'value' => $this->money($payment['value'] ?? null), 'original_value' => isset($payment['originalValue']) ? $this->money($payment['originalValue']) : null,
                    'payment_date' => $this->date($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? null),
                    'due_date' => $this->date($payment['dueDate'] ?? null), 'date_created' => $this->date($payment['dateCreated'] ?? null),
                    'installment' => $this->identifier($payment['installment'] ?? null) ?: null,
                    'installment_number' => filter_var($payment['installmentNumber'] ?? null, FILTER_VALIDATE_INT) ?: null,
                    'invoice_id' => null, 'invoice_number' => null, 'binding_status' => 'unmatched',
                    'binding_message' => 'Nenhuma fatura foi localizada com a referência exata desta cobrança.', 'action_eligible' => false];
                $hash = is_string($payment['externalReference'] ?? null) ? $payment['externalReference'] : '';
                if (isset($byHash[$hash])) {
                    $match = $byHash[$hash];
                    if ((int) $match['matches'] !== 1) {
                        $item['binding_status'] = 'ambiguous';
                        $item['binding_message'] = 'A referência corresponde a mais de uma fatura no CRM. Confira a duplicidade antes de conciliar.';
                    } elseif (isset($invoices[(int) $match['invoice_id']])) {
                        $id = (int) $match['invoice_id'];
                        $invoice = (object) $invoices[$id];
                        $item['invoice_id'] = $id;
                        $item['invoice_number'] = function_exists('format_invoice_number') ? $this->safeText((string) \format_invoice_number($invoice), 100) : (string) $id;
                        if (!isset($previews[$id])) {
                            $map = $maps[$id] ?? [];
                            $cache = \asaas_lifecycle_cached_payment($map);
                            try {
                                if (!$this->eligibleInvoice($invoice, $map) || $this->blocked($invoice, $cache)) {
                                    $previews[$id] = ['status' => 'blocked', 'message' => 'A fatura não está elegível para conciliação Asaas ou foi encerrada no CRM.', 'ids' => []];
                                } else {
                                    $mapped = (string) ($map['asaas_payment_id'] ?? '');
                                    $discover = $mapped === '' && !\asaas_card_attempt_blocks($cache);
                                    $this->beginScope($invoice, $cache, $discover);
                                    if ($mapped === '' && !$discover) {
                                        $previews[$id] = ['status' => 'blocked', 'message' => 'Há uma tentativa de cartão em recuperação. Use Comparar na guia Faturas para confirmar seu resultado.', 'ids' => []];
                                    } elseif ($discover) {
                                        $found = $this->discoverPayments($invoice, $map, $cache);
                                        $previews[$id] = $found === null ? ['status' => 'unmatched', 'message' => 'A cobrança não pôde ser localizada novamente pela referência da fatura.', 'ids' => []]
                                            : ['status' => 'recoverable', 'message' => 'Cliente, referência e valores conferem. Comparar ou conciliar validará novamente e recuperará o vínculo local.', 'ids' => array_column($found[1], 'id')];
                                    } else {
                                        $bound = $pagePayments[$mapped] ?? $this->remote('/payments/' . rawurlencode($mapped));
                                        $this->validatePayment($invoice, $map, $cache, $bound, $mapped);
                                        $members = $this->paymentGroup($invoice, $map, $cache, $bound);
                                        $pricing = \asaas_card_receipt_pricing($cache);
                                        if ($pricing !== null) { \asaas_card_allocate_pricing($pricing, $members); }
                                        if ($pricing === null && $this->amount($bound) > \asaas_payment_amount_to_cents($invoice->total)) {
                                            throw new \RuntimeException(($bound['billingType'] ?? '') === 'CREDIT_CARD'
                                                ? 'Recovery card pricing is missing.' : 'Recovery amount does not match.');
                                        }
                                        $this->assertScope($invoice);
                                        $previews[$id] = ['status' => 'linked', 'message' => 'Cobrança vinculada à fatura. A ação consulta novamente o Asaas antes de registrar recebimentos.', 'ids' => array_column($members, 'id')];
                                    }
                                }
                            } catch (\Throwable $error) {
                                $failure = $this->failure($error);
                                if (in_array($failure['http_status'], [401, 403, 429, 500, 502, 503, 504], true) || $failure['code'] === 'transport') { throw $error; }
                                $limited = $limited || $failure['code'] === 'lookup_deadline';
                                $previews[$id] = ['status' => in_array($failure['code'], ['reference_ambiguous', 'payment_ambiguous'], true) ? 'ambiguous' : 'blocked',
                                    'message' => $failure['message'], 'error' => $failure, 'ids' => []];
                            }
                        }
                        $preview = $previews[$id];
                        $item['binding_status'] = $preview['status'];
                        $item['binding_message'] = $preview['message'];
                        if (in_array($preview['status'], ['linked', 'recoverable'], true)) {
                            $item['action_eligible'] = in_array($payment['id'], $preview['ids'], true);
                            if (!$item['action_eligible']) {
                                $item['binding_status'] = 'blocked';
                                $item['binding_message'] = 'Esta cobrança não pertence ao vínculo ou ao grupo validado da fatura. Nenhum vínculo existente será substituído.';
                            }
                        }
                        if (isset($preview['error'])) { $item['error_code'] = $preview['error']['code']; $item['http_status'] = $preview['error']['http_status']; }
                    }
                }
                $items[] = $item;
            }
            if ($apiScope !== $this->apiScope()) { throw new \RuntimeException('Recovery scope changed during lookup.'); }
            $result = $this->page($items, (int) $total, $page, $limit);
            $result['pagination']['has_more'] = $response['hasMore'];
            if ($limited) { $result['message'] = 'As cobranças foram listadas, mas parte dos vínculos exige nova conferência por limite de tempo.'; }
            return $result;
        } catch (\Throwable $error) {
            $failure = $this->failure($error);
            \asaas_diagnostic_log('error', $failure['message'], ['source' => 'reconciliation', 'operation' => 'list_payments',
                'error_code' => $failure['code'], 'http_status' => $failure['http_status']]);
            return ['ok' => false, 'data' => [], 'message' => $failure['message'], 'error' => $failure];
        }
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
                'http_status', 'status', 'remote_status', 'result', 'error_code', 'message', 'reason', 'reason_code', 'details', 'receipts_added', 'receipt_verification_pending', 'processed', 'duplicated', 'correlation_id', 'exception'] as $key) {
                if (!array_key_exists($key, $raw)) { continue; }
                if ($key === 'payment_ids') {
                    $safe[$key] = array_values(array_filter(array_map(fn ($id): string => $this->identifier($id), array_slice(is_array($raw[$key]) ? $raw[$key] : [], 0, 100))));
                } elseif (is_scalar($raw[$key]) || $raw[$key] === null) {
                    $safe[$key] = is_string($raw[$key]) ? $this->safeText($raw[$key], in_array($key, ['message', 'reason', 'details'], true) ? 1000 : 250) : $raw[$key];
                }
            }
            $source = in_array($raw['source'] ?? '', ['api', 'webhook', 'reconciliation', 'recurring'], true) ? $raw['source']
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
        $this->deadline = $deadline;
        $this->verifiedCustomers = [];
        $results = [];
        $remaining = [];
        $summary = ['processed' => 0, 'reconciled' => 0, 'divergent' => 0, 'errors' => 0, 'receipts_added' => 0];
        $interrupted = false;
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
            if (in_array($result['http_status'] ?? 0, [401, 403, 429, 500, 502, 503, 504], true)
                || in_array($result['error_code'] ?? '', ['transport', 'lookup_deadline'], true)) {
                $remaining = array_slice($invoiceIds, $index + 1);
                $interrupted = true;
                break;
            }
        }
        return ['ok' => true, 'data' => $results, 'summary' => $summary, 'remaining_ids' => $remaining, 'interrupted' => $interrupted];
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
            if (!$this->eligibleInvoice($invoice, $map)) { return $this->outcome($result, 'not_linked', 'Esta fatura não está associada ao meio de pagamento Asaas.'); }
            if ($this->blocked($invoice, $cache)) { return $this->outcome($result, 'blocked', 'A fatura está cancelada, em rascunho ou sua cobrança foi encerrada pelo ciclo de vida.'); }
            $pricing = \asaas_card_receipt_pricing($cache);
            $gateway = $this->gateway();
            $discover = ($map === [] || (string) ($map['asaas_payment_id'] ?? '') === '') && !\asaas_card_attempt_blocks($cache);
            $this->beginScope($invoice, $cache, $discover);
            if (\asaas_card_attempt_blocks($cache)) {
                $attemptPrincipal = $pricing['principal_cents'] ?? \asaas_payment_amount_to_cents($cache['_asaas_lifecycle']['card_attempt']['amount'] ?? null);
                if ($attemptPrincipal > \asaas_payment_amount_to_cents($invoice->total)) {
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
            } elseif ($discover) {
                $recovered = $this->discoverPayments($invoice, $map, $cache);
                if ($recovered === null) {
                    return $this->outcome($result, 'not_linked', 'Nenhuma cobrança ativa foi localizada no Asaas com a referência exata desta fatura. Nenhuma cobrança foi criada.');
                }
                [$payment, $payments, $recoveredCache] = $recovered;
                $map = $this->persistRecovery($invoice, $map, $cache, $payment, $payments, $recoveredCache);
                $cache = \asaas_lifecycle_cached_payment($map);
                $result['binding_recovered'] = true;
                $result['binding_status'] = 'linked';
            } else {
                $paymentId = (string) ($map['asaas_payment_id'] ?? '');
                if ($paymentId === '' || $this->retired($cache, $paymentId)) {
                    return $this->outcome($result, 'pending', 'A vinculação da cobrança precisa ser confirmada antes do recebimento.');
                }
                if ($this->identifier($paymentId) !== $paymentId) { throw new \RuntimeException('Invalid payment identifier.'); }
                $payment = $this->remote('/payments/' . rawurlencode($paymentId));
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
                if ($fresh) { $this->assertScope($fresh); }
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
                    $receiptPrincipal = $this->principal($receipt, $freshCache);
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
                            || \asaas_payment_amount_to_cents($records[$receipt['id']][0]['amount']) !== $receiptPrincipal) {
                            $result['error_code'] = 'receipt_amount';
                            return $this->outcome($result, 'amount_mismatch', 'O identificador da cobrança possui recibos duplicados ou um valor diferente no CRM. Confira os recibos existentes; nenhum ajuste automático foi realizado.');
                        }
                        continue;
                    }
                    $unrecorded[] = $receipt;
                    $sum += $receiptPrincipal;
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
                    if ($fresh) { $this->assertScope($fresh); }
                    if (!$fresh || $this->blocked($fresh, $freshCache) || (string) ($freshMap['asaas_payment_id'] ?? '') !== (string) $map['asaas_payment_id']
                        || (string) $fresh->hash !== (string) $invoice->hash || (int) $fresh->clientid !== (int) $invoice->clientid) {
                        throw new \RuntimeException('Invoice changed during reconciliation.');
                    }
                    $this->validatePayment($fresh, $freshMap, $freshCache, $receipt);
                    $receiptPrincipal = $this->principal($receipt, $freshCache);
                    $balance = $this->balance($fresh);
                    $result['balance'] = \asaas_card_money($balance);
                    $result['crm_status'] = (int) $fresh->status;
                    $existing = $this->receipts([$receipt['id']]);
                    if (isset($existing[$receipt['id']])) {
                        foreach ($existing[$receipt['id']] as $record) {
                            if ((int) $record['invoiceid'] !== $id) { throw new \RuntimeException('Receipt belongs to another invoice.'); }
                        }
                        if (count($existing[$receipt['id']]) !== 1
                            || \asaas_payment_amount_to_cents($existing[$receipt['id']][0]['amount']) !== $receiptPrincipal) {
                            $result['error_code'] = 'receipt_amount';
                            return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'amount_mismatch',
                                'O identificador da cobrança possui recibos duplicados ou um valor diferente no CRM. Confira os recibos existentes antes de continuar.');
                        }
                        continue;
                    }
                    if ($receiptPrincipal > $balance || (int) $fresh->status === 2) {
                        return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'amount_mismatch',
                            'O saldo mudou durante a conciliação. Confira os recebimentos já registrados antes de continuar.');
                    }
                    // Do not wrap this in remote_payment_depth: addPayment must own its existing deduplication/transaction.
                    $receiptData = [
                        'amount' => \asaas_card_money($receiptPrincipal), 'invoiceid' => $id,
                        'paymentmode' => 'Asaas', 'paymentmethod' => $receipt['billingType'], 'transactionid' => $receipt['id'],
                    ];
                    $note = \asaas_card_receipt_note($receipt, $freshCache);
                    if ($note !== '') { $receiptData['note'] = $note; }
                    $result['receipt_verification_pending'] = true;
                    $success = $gateway->addPayment($receiptData);
                    $persisted = $this->receipts([$receipt['id']]);
                    $result['receipt_verification_pending'] = false;
                    if (!empty($persisted[$receipt['id']])) {
                        ++$result['receipts_added'];
                        if (count($persisted[$receipt['id']]) !== 1 || (int) $persisted[$receipt['id']][0]['invoiceid'] !== $id
                            || \asaas_payment_amount_to_cents($persisted[$receipt['id']][0]['amount']) !== $receiptPrincipal) {
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
            $reason = $this->failure($error);
            $result['error_code'] = $reason['code'];
            if ($reason['http_status'] !== null) { $result['http_status'] = $reason['http_status']; }
            if ($result['receipts_added'] > 0 || !empty($result['receipt_verification_pending'])) {
                try {
                    $fresh = \asaas_lifecycle_invoice($id);
                    if ($fresh) { $result['balance'] = \asaas_card_money($this->balance($fresh)); $result['crm_status'] = (int) $fresh->status; }
                } catch (\Throwable $ignored) {}
            }
            if (!empty($result['receipt_verification_pending'])) {
                $result['error_code'] = 'receipt_verification_pending';
                $reason['message'] = 'O resultado do registro local não pôde ser confirmado. Confira os recibos da fatura antes de repetir a operação.';
            }
            return $this->outcome($result, $result['receipts_added'] > 0 ? 'partial_reconciled' : 'error',
                $result['receipts_added'] > 0 && empty($result['receipt_verification_pending'])
                    ? 'Parte dos recebimentos foi registrada. Confira o histórico e repita a conferência para os restantes.'
                    : $reason['message']);
        }
    }

    private function failure(\Throwable $error): array
    {
        $reasons = [
                'Invalid payment binding.' => ['payment_binding', 'A cobrança retornada não corresponde ao vínculo da fatura ou foi excluída no Asaas.'],
                'Payment belongs to another customer.' => ['customer_binding', 'O cliente da cobrança não corresponde ao cadastro vinculado à fatura.'],
                'Customer binding is not available.' => ['customer_missing', 'O vínculo do cliente com o Asaas não está disponível para confirmar o recebimento.'],
                'Incomplete installment list.' => ['installment_incomplete', 'A consulta não retornou todas as parcelas. A baixa foi impedida para evitar um recebimento incorreto.'],
                'Installment sum does not match.' => ['installment_amount', 'O total das parcelas não corresponde ao valor esperado. Confira o parcelamento no Asaas.'],
                'Invalid installment membership.' => ['installment_binding', 'Uma parcela não corresponde ao cliente ou ao parcelamento da fatura.'],
                'Persisted receipt amount does not match.' => ['receipt_amount', 'O recibo local precisa de conferência antes de continuar a conciliação.'],
                'Incomplete recovery payment list.' => ['lookup_incomplete', 'A consulta ao Asaas ficou incompleta ou mudou durante a paginação. Nenhum vínculo ou baixa foi deduzido desse resultado. Faça uma nova conferência.'],
                'Recovery payment list exceeded limit.' => ['lookup_limit', 'Esta referência retornou mais de 500 cobranças. Confira as referências duplicadas no Asaas antes de conciliar.'],
                'Recovery lookup deadline exceeded.' => ['lookup_deadline', 'O tempo disponível para a conferência foi atingido. As faturas restantes não foram processadas; faça uma nova consulta.'],
                'Recovery invoice reference is ambiguous.' => ['reference_ambiguous', 'Há mais de uma fatura no CRM com esta referência. Corrija a duplicidade antes de vincular qualquer recebimento.'],
                'Recovery payment reference is ambiguous.' => ['payment_ambiguous', 'Há mais de uma cobrança ou parcelamento independente para esta fatura no Asaas. Escolha e confira o vínculo antes da baixa.'],
                'Recovery amount does not match.' => ['recovery_amount', 'O total encontrado não corresponde ao total da fatura nem ao saldo e recibos desse grupo. Confira valores, descontos e pagamentos manuais.'],
                'Recovery card pricing is missing.' => ['card_pricing_missing', 'A cobrança de cartão inclui valores além do principal, mas sua composição de encargos não foi encontrada. Recupere o histórico antes de conciliar.'],
                'Recovery customer conflicts with local identity.' => ['customer_conflict', 'O cliente possui identificadores Asaas conflitantes no CRM. Confira o cadastro antes de recuperar a cobrança.'],
                'Recovery customer document is missing.' => ['customer_document_missing', 'O CPF/CNPJ do cliente no CRM não está disponível para comprovar o vínculo legado. Confira o cadastro do cliente.'],
                'Recovery customer identity is unconfirmed.' => ['customer_identity', 'O identificador, a referência ou o CPF/CNPJ do cliente no Asaas não corresponde ao cadastro do CRM. Nenhum cadastro foi alterado.'],
                'Recovery scope is unavailable.' => ['scope_unavailable', 'Não foi possível confirmar o ambiente e a configuração da conta Asaas para esta consulta.'],
                'Recovery issuer scope is unavailable.' => ['scope_unavailable', 'Esta fatura possui um emissor específico cuja conta Asaas não pode ser determinada por esta instalação. Confira o contexto da empresa antes de recuperar o vínculo.'],
                'Recovery environment changed.' => ['environment_mismatch', 'O vínculo foi recuperado em outro ambiente Asaas. Confira Homologação/Produção antes de conciliar.'],
                'Recovery scope changed during lookup.' => ['scope_changed', 'A fatura, o ambiente ou a chave da conta mudou durante a consulta. Faça uma nova conferência antes de vincular ou receber.'],
                'Recovery binding changed during lookup.' => ['binding_changed', 'O vínculo ou seu histórico mudou durante a consulta. Foi preservado o registro mais recente; faça uma nova conferência.'],
                'Recovery map could not be saved.' => ['binding_storage', 'A cobrança foi localizada, mas o banco não confirmou o vínculo local. Confira os registros da integração antes de repetir.'],
                'Diagnostic database operation failed.' => ['database', 'Não foi possível consultar ou atualizar os registros locais da integração. Confira o banco de dados e as migrations do módulo.'],
            ];
        $reason = $reasons[$error->getMessage()] ?? ['unconfirmed', 'Não foi possível confirmar a cobrança e sua vinculação com segurança. Consulte o diagnóstico da integração.'];
        if ($error instanceof \AsaasCardReceiptException) { $reason = ['card_' . $error->reason(), $error->getMessage()]; }
        $status = (int) $error->getCode();
        $httpReasons = [
            400 => ['api_rejected', 'O Asaas recusou os parâmetros da consulta. Confira os filtros ou a referência da cobrança e consulte o detalhe no diagnóstico da API.'],
            401 => ['api_authentication', 'A chave de API não foi aceita pelo Asaas. Confira a chave do ambiente em uso; as demais consultas do lote foram interrompidas.'],
            403 => ['api_permission', 'A conta Asaas não autorizou esta consulta. Confira as permissões da chave e da conta.'],
            404 => ['api_not_found', 'A cobrança, o parcelamento ou o cliente não foi encontrado na conta e no ambiente consultados. Um vínculo existente não foi substituído.'],
            429 => ['api_rate_limit', 'O Asaas limitou temporariamente as consultas. Aguarde antes de tentar novamente; não foi feita repetição automática do lote.'],
            500 => ['api_unavailable', 'O Asaas apresentou uma falha temporária. As consultas restantes foram interrompidas; tente novamente mais tarde.'],
            502 => ['api_unavailable', 'O Asaas está temporariamente indisponível. Tente novamente mais tarde.'],
            503 => ['api_unavailable', 'O Asaas está temporariamente indisponível. Tente novamente mais tarde.'],
            504 => ['api_unavailable', 'O Asaas não respondeu dentro do prazo. Tente novamente mais tarde.'],
        ];
        if (isset($httpReasons[$status])) { $reason = $httpReasons[$status]; }
        for ($previous = $error; $previous !== null; $previous = $previous->getPrevious()) {
            if ($status === 0 && ($previous instanceof \Asaas\Sdk\Exception\TransportException || $previous instanceof \GuzzleHttp\Exception\ConnectException)) {
                $reason = ['transport', 'Não foi possível concluir a conexão com o Asaas. Confira a conectividade e tente novamente; a resposta não foi presumida como pagamento.'];
                break;
            }
        }
        return ['code' => $reason[0], 'message' => $reason[1], 'http_status' => $status >= 400 && $status <= 599 ? $status : null];
    }

    private function paymentGroup(object $invoice, array $map, array $cache, array $payment): array
    {
        if (empty($payment['installment'])) { return [$payment]; }
        $groupId = (string) $payment['installment'];
        if ($this->identifier($groupId, 120) !== $groupId) { throw new \RuntimeException('Invalid installment identifier.'); }
        $attempt = $cache['_asaas_lifecycle']['card_attempt'] ?? [];
        $pricing = \asaas_card_receipt_pricing($cache);
        if (!empty($attempt['installment_count']) && isset($attempt['amount'])) {
            $expectedCount = (int) $attempt['installment_count'];
            $expectedTotal = \asaas_payment_amount_to_cents($attempt['amount']);
        } else {
            $group = $this->remote('/installments/' . rawurlencode($groupId));
            if (($group['id'] ?? null) !== $groupId || !empty($group['deleted']) || ($group['customer'] ?? null) !== $payment['customer']
                || ($group['billingType'] ?? null) !== $payment['billingType']) { throw new \RuntimeException('Invalid installment binding.'); }
            $expectedCount = (int) ($group['installmentCount'] ?? 0);
            $expectedTotal = \asaas_payment_amount_to_cents($group['value'] ?? null);
        }
        $expectedPrincipal = $pricing['principal_cents'] ?? $expectedTotal;
        if ($expectedCount < 2 || $expectedCount > 100 || $expectedTotal <= 0
            || $expectedPrincipal > \asaas_payment_amount_to_cents($invoice->total)) { throw new \RuntimeException('Invalid installment total.'); }
        $members = $this->remotePages('/installments/' . rawurlencode($groupId) . '/payments', [], $expectedCount);
        $ids = [];
        $numbers = [];
        $sum = 0;
        foreach ($members as $parcel) {
            if (!is_array($parcel)) { throw new \RuntimeException('Invalid installment response.'); }
            $this->validatePayment($invoice, $map, $cache, $parcel);
            if (($parcel['installment'] ?? null) !== $groupId || $parcel['customer'] !== $payment['customer']
                || $parcel['billingType'] !== $payment['billingType'] || in_array($parcel['id'], $ids, true)) {
                throw new \RuntimeException('Invalid installment membership.');
            }
            $ids[] = $parcel['id'];
            $number = filter_var($parcel['installmentNumber'] ?? null, FILTER_VALIDATE_INT);
            $amount = $this->amount($parcel);
            if ($number === false || $number < 1 || $number > $expectedCount || isset($numbers[$number]) || $amount > $expectedTotal - $sum) {
                throw new \RuntimeException('Invalid installment membership.');
            }
            $numbers[$number] = true;
            $sum += $amount;
        }
        if ($sum !== $expectedTotal || !in_array($payment['id'], $ids, true)) { throw new \RuntimeException('Installment sum does not match.'); }
        return $members;
    }

    /** Returns a completely validated candidate without changing any local or remote record. */
    private function discoverPayments(object $invoice, array $map, array $cache): ?array
    {
        if (!$this->has('asaas_payments_map', ['invoice_id', 'asaas_payment_id', 'payload_cache'])) {
            throw new \RuntimeException('Recovery map could not be saved.');
        }
        $hash = $invoice->hash ?? null;
        if (!is_string($hash) || $hash === '' || strlen($hash) > 200
            || $this->count('SELECT COUNT(*) AS total FROM ' . $this->table('invoices') . ' WHERE hash = ?', [$hash]) !== 1) {
            throw new \RuntimeException('Recovery invoice reference is ambiguous.');
        }
        $candidates = $this->remotePages('/payments', ['externalReference' => $hash]);
        $groups = [];
        $active = [];
        foreach ($candidates as $payment) {
            if (($payment['externalReference'] ?? null) !== $hash) { throw new \RuntimeException('Invalid payment binding.'); }
            if (!empty($payment['deleted']) || $this->retired($cache, $payment['id'])) { continue; }
            $groupId = $payment['installment'] ?? '';
            if (!is_string($groupId) && $groupId !== null) { throw new \RuntimeException('Invalid installment membership.'); }
            if ($groupId !== '' && $groupId !== null && $this->identifier($groupId, 120) !== $groupId) { throw new \RuntimeException('Invalid installment membership.'); }
            $groups[$groupId ? 'installment:' . $groupId : 'payment:' . $payment['id']] = true;
            $active[] = $payment;
        }
        if ($active === []) { return null; }
        if (count($groups) !== 1) { throw new \RuntimeException('Recovery payment reference is ambiguous.'); }
        usort($active, static fn (array $a, array $b): int => ((int) ($a['installmentNumber'] ?? 1) <=> (int) ($b['installmentNumber'] ?? 1))
            ?: strcmp($a['id'], $b['id']));
        $payment = $active[0];
        if (!is_string($payment['customer'] ?? null)) { throw new \RuntimeException('Invalid payment binding.'); }
        $this->proveCustomer($invoice, $payment['customer']);
        if (!empty($cache['customer']) && $cache['customer'] !== $payment['customer']) { throw new \RuntimeException('Recovery customer conflicts with local identity.'); }
        $validationCache = $cache;
        $validationCache['customer'] = $payment['customer'];
        $this->validatePayment($invoice, $map, $validationCache, $payment);
        $payments = $this->paymentGroup($invoice, $map, $validationCache, $payment);
        $memberIds = array_column($payments, 'id');
        foreach ($active as $candidate) {
            if (!in_array($candidate['id'], $memberIds, true)) { throw new \RuntimeException('Recovery payment reference is ambiguous.'); }
        }
        $this->validateRecoveryAmount($invoice, $payments, $validationCache);
        $metadata = $cache['_asaas_lifecycle'] ?? [];
        if (!is_array($metadata)) { throw new \RuntimeException('Invalid payment binding.'); }
        $pricing = \asaas_card_receipt_pricing($cache);
        if ($pricing !== null) { $metadata['card_attempt']['pricing'] = \asaas_card_allocate_pricing($pricing, $payments); }
        $metadata['state'] = $metadata['state'] ?? 'active';
        $metadata['recovery_context'] = ['environment' => $this->scopeSnapshots[(int) $invoice->id]['api']['environment'],
            'recovered_at' => date('Y-m-d H:i:s')];
        $safePayment = array_intersect_key($payment, array_flip(['id', 'customer', 'externalReference', 'billingType', 'status', 'value',
            'originalValue', 'dueDate', 'paymentDate', 'clientPaymentDate', 'dateCreated', 'installment', 'installmentNumber',
            'invoiceUrl', 'bankSlipUrl', 'deleted']));
        $safePayment['_asaas_lifecycle'] = $metadata;
        return [$payment, $payments, $safePayment];
    }

    private function validateRecoveryAmount(object $invoice, array $payments, array $cache): void
    {
        $total = 0;
        foreach ($payments as $payment) {
            $amount = $this->amount($payment);
            if ($amount <= 0 || $amount > PHP_INT_MAX - $total) { throw new \RuntimeException('Recovery amount does not match.'); }
            $total += $amount;
        }
        $invoiceTotal = \asaas_payment_amount_to_cents($invoice->total);
        $pricing = \asaas_card_receipt_pricing($cache);
        if ($pricing !== null) {
            if ($total !== $pricing['charged_cents'] || $pricing['principal_cents'] > $invoiceTotal
                || count($payments) !== $pricing['installment_count']) { throw new \RuntimeException('Recovery amount does not match.'); }
            \asaas_card_allocate_pricing($pricing, $payments);
            return;
        }
        if ($total > $invoiceTotal) {
            throw new \RuntimeException(($payments[0]['billingType'] ?? '') === 'CREDIT_CARD'
                ? 'Recovery card pricing is missing.' : 'Recovery amount does not match.');
        }
        if ($total === $invoiceTotal) { return; }
        $covered = $this->balance($invoice);
        foreach ($this->receipts(array_column($payments, 'id')) as $records) {
            if (count($records) !== 1 || (int) $records[0]['invoiceid'] !== (int) $invoice->id) { throw new \RuntimeException('Recovery amount does not match.'); }
            $amount = \asaas_payment_amount_to_cents($records[0]['amount']);
            if ($amount > PHP_INT_MAX - $covered) { throw new \RuntimeException('Recovery amount does not match.'); }
            $covered += $amount;
        }
        if ($total !== $covered) { throw new \RuntimeException('Recovery amount does not match.'); }
    }

    /** One map write under the same invoice mutex used by webhooks and native receipts. */
    private function persistRecovery(object $invoice, array $oldMap, array $oldCache, array $payment, array $payments, array $cache): array
    {
        $id = (int) $invoice->id;
        \asaas_receipt_lock($id);
        try {
            $fresh = \asaas_lifecycle_invoice($id);
            $map = \asaas_lifecycle_map($id);
            $current = \asaas_lifecycle_cached_payment($map);
            if (!$fresh || $this->blocked($fresh, $current) || !$this->eligibleInvoice($fresh, $map)) { throw new \RuntimeException('Recovery binding changed during lookup.'); }
            $this->assertScope($fresh);
            if ((string) ($map['asaas_payment_id'] ?? '') !== (string) ($oldMap['asaas_payment_id'] ?? '')
                || $current !== $oldCache) {
                throw new \RuntimeException('Recovery binding changed during lookup.');
            }
            if ((string) ($map['asaas_payment_id'] ?? '') !== '') { throw new \RuntimeException('Recovery binding changed during lookup.'); }
            unset($this->customerIds[(int) $fresh->clientid]);
            $this->proveCustomer($fresh, $payment['customer'], false);
            foreach ($payments as $member) { $this->validatePayment($fresh, $map, $cache, $member); }
            $this->validateRecoveryAmount($fresh, $payments, $cache);
            $this->assertScope($fresh);
            if (!$this->gateway()->upsert_payment_map($id, $cache)) { throw new \RuntimeException('Recovery map could not be saved.'); }
            $stored = \asaas_lifecycle_map($id);
            if (($stored['asaas_payment_id'] ?? null) !== $payment['id']) { throw new \RuntimeException('Recovery map could not be saved.'); }
            return $stored;
        } finally {
            \asaas_receipt_unlock($id);
        }
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
            $invoice = \asaas_lifecycle_invoice($invoiceId);
            if ($invoice) { $this->assertScope($invoice); }
            if ((string) ($map['asaas_payment_id'] ?? '') !== $paymentId || $this->retired($cache, $paymentId)
                || in_array($cache['_asaas_lifecycle']['state'] ?? '', self::CLOSED, true)) { return; }
            $this->query('UPDATE ' . $this->table('asaas_payments_map')
                . ' SET status = ?, last_sync_at = ? WHERE invoice_id = ? AND asaas_payment_id = ?',
                [$this->enumText($payment['status']), date('Y-m-d H:i:s'), $invoiceId, $paymentId]);
        } finally {
            \asaas_receipt_unlock($invoiceId);
        }
    }

    /** All open invoices are eligible; the lookback only limits rechecks of recently paid invoices. */
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
            $recent = json_decode($service->option('sync_recent_attempts', ''), true);
            $recent = is_array($recent) ? $recent : [];
            foreach ($recent as $id => $at) {
                if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || (int) $id > 2147483647
                    || !is_int($at) || $at <= $started - 1800 || $at > $started + 300) { unset($recent[$id]); }
            }
            arsort($recent, SORT_NUMERIC);
            $recent = array_slice($recent, 0, 1000, true);
            [$eligible, $bindings] = $service->eligibleSql();
            $states = 'i.status NOT IN (2,5,6)';
            if ($service->has('invoicepaymentrecords', ['invoiceid', 'date', 'paymentmode', 'transactionid'])) {
                $states = '(' . $states . ' OR (i.status = 2 AND EXISTS (SELECT 1 FROM '
                    . $service->table('invoicepaymentrecords') . ' paid WHERE paid.invoiceid = i.id'
                    . ' AND paid.date >= ? AND paid.paymentmode IN (?, ?) AND paid.transactionid REGEXP ?)))';
                array_push($bindings, $from, 'Asaas', 'asaas', '^pay_[A-Za-z0-9_-]+$');
            }
            $select = 'SELECT i.id FROM ' . $service->table('invoices') . ' i LEFT JOIN ' . $service->table('asaas_payments_map')
                . ' m ON m.invoice_id = i.id WHERE i.id > ? AND ' . $eligible . ' AND ' . $states
                . ' AND (m.last_sync_at IS NULL OR m.last_sync_at < ?)';
            $bindings[] = $cooldown;
            if ($recent !== []) {
                $select .= ' AND i.id NOT IN (' . $service->placeholders(count($recent)) . ')';
                array_push($bindings, ...array_map('intval', array_keys($recent)));
            }
            $select .= ' ORDER BY i.id ASC LIMIT ' . $limit;
            $rows = $service->rows($select, array_merge([$cursor], $bindings));
            if ($rows === [] && $cursor > 0) {
                $cursor = 0;
                $rows = $service->rows($select, array_merge([0], $bindings));
            }
            $ids = array_map('intval', array_column($rows, 'id'));
            $summary = ['processed' => 0, 'reconciled' => 0, 'errors' => 0];
            $deadline = microtime(true) + 45;
            $processed = 0;
            $interrupted = false;
            $failure = null;
            foreach (array_chunk($ids, 20) as $chunk) {
                $budget = (int) floor($deadline - microtime(true));
                if ($budget < 1) { break; }
                $batch = $service->process($chunk, true, 'cron', $budget);
                foreach (['processed', 'reconciled', 'errors'] as $key) { $summary[$key] += $batch['summary'][$key]; }
                foreach ($batch['data'] as $item) {
                    $cursor = (int) $item['invoice_id'];
                    $recent[$cursor] = time();
                    ++$processed;
                    if (isset($item['error_code'])) { $failure = $item; }
                }
                $interrupted = !empty($batch['interrupted']);
                if ($batch['remaining_ids'] !== [] || $interrupted) { break; }
            }
            $remaining = count($ids) - $processed;
            if ($remaining === 0 && count($ids) < $limit && !$interrupted) { $cursor = 0; }
            arsort($recent, SORT_NUMERIC);
            $service->saveOption(self::RECENT_ATTEMPTS_OPTION, json_encode(array_slice($recent, 0, 1000, true)));
            $service->saveOption(self::CURSOR_OPTION, (string) $cursor);
            $state = [
                'started_at' => date('Y-m-d H:i:s', $started), 'finished_at' => date('Y-m-d H:i:s'),
                'status' => $remaining > 0 || $interrupted ? 'partial' : 'completed',
                'processed' => $summary['processed'], 'reconciled' => $summary['reconciled'], 'errors' => $summary['errors'],
                'remaining' => $remaining, 'next_cursor' => $cursor,
                'interrupted' => $interrupted,
            ];
            if ($failure !== null) {
                $state['error_code'] = $failure['error_code'];
                $state['http_status'] = $failure['http_status'] ?? null;
                $state['message'] = $failure['message'];
            }
            $service->saveOption(self::LAST_RUN_OPTION, json_encode($state, JSON_UNESCAPED_UNICODE));
            return $state;
        } catch (\Throwable $error) {
            $failure = $service->failure($error);
            $state = ['started_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s'), 'status' => 'error',
                'processed' => 0, 'reconciled' => 0, 'errors' => 1, 'remaining' => 0, 'next_cursor' => 0,
                'error_code' => $failure['code'], 'http_status' => $failure['http_status'], 'message' => $failure['message']];
            try { $service->saveOption(self::LAST_RUN_OPTION, json_encode($state)); } catch (\Throwable $ignored) {}
            \asaas_diagnostic_log('error', $failure['message'],
                ['source' => 'reconciliation', 'operation' => 'cron', 'trigger' => 'cron', 'result' => 'error',
                    'error_code' => $failure['code'], 'http_status' => $failure['http_status']]);
            return $state;
        } finally {
            if ($acquired) {
                try { $service->one('SELECT RELEASE_LOCK(?) AS released', [$lock]); } catch (\Throwable $ignored) {}
            }
        }
    }

    private function eligibleSql(): array
    {
        $parts = ['m.invoice_id IS NOT NULL'];
        $bindings = [];
        if ($this->has('invoices', ['allowed_payment_modes'])) {
            [$modes, $values] = \asaas_reconciliation_modes_sql('i.allowed_payment_modes');
            $parts[] = $modes;
            array_push($bindings, ...$values);
        }
        if ($this->has('invoicepaymentrecords', ['invoiceid', 'paymentmode', 'transactionid'])) {
            $parts[] = 'EXISTS (SELECT 1 FROM ' . $this->table('invoicepaymentrecords')
                . ' history WHERE history.invoiceid = i.id AND history.paymentmode IN (?, ?) AND history.transactionid REGEXP ?)';
            array_push($bindings, 'Asaas', 'asaas', '^pay_[A-Za-z0-9_-]+$');
        }
        return ['(' . implode(' OR ', $parts) . ')', $bindings];
    }

    private function eligibleInvoice(object $invoice, array $map): bool
    {
        if ($map !== [] || in_array('asaas', \asaas_reconciliation_payment_modes($invoice->allowed_payment_modes ?? []), true)) { return true; }
        if (!$this->has('invoicepaymentrecords', ['invoiceid', 'paymentmode', 'transactionid'])) { return false; }
        return $this->one('SELECT invoiceid FROM ' . $this->table('invoicepaymentrecords')
            . ' WHERE invoiceid = ? AND paymentmode IN (?, ?) AND transactionid REGEXP ? LIMIT 1',
            [(int) $invoice->id, 'Asaas', 'asaas', '^pay_[A-Za-z0-9_-]+$']) !== [];
    }

    /** A short-lived key fingerprint detects mid-request account changes without persisting a secret. */
    private function apiScope(?int $companyId = null): array
    {
        $gateway = $this->gateway();
        if (!is_callable([$gateway, 'getProvider'])) { throw new \RuntimeException('Recovery scope is unavailable.'); }
        $provider = $gateway->getProvider();
        $environment = $provider->env($companyId);
        $key = $provider->apiKey($companyId);
        if (!in_array($environment, ['sandbox', 'production'], true) || !is_string($key) || $key === '') {
            throw new \RuntimeException('Recovery scope is unavailable.');
        }
        return ['environment' => $environment, 'key_fingerprint' => hash('sha256', $key)];
    }

    private function invoiceScope(object $invoice): array
    {
        $scope = ['id' => (int) $invoice->id, 'hash' => (string) ($invoice->hash ?? ''), 'clientid' => (int) ($invoice->clientid ?? 0),
            'total' => $this->money($invoice->total), 'currency' => (string) ($invoice->currency ?? '')];
        // Optional issuer extensions do not expose a common credential resolver in this module.
        foreach (['company_id', 'companyid', 'issuer_id', 'emitter_id', 'issuer_company_id'] as $field) {
            if (isset($invoice->$field) && !in_array($invoice->$field, ['', 0, '0'], true)) { $scope[$field] = (string) $invoice->$field; }
        }
        return $scope;
    }

    private function beginScope(object $invoice, array $cache, bool $discover): void
    {
        $local = $this->invoiceScope($invoice);
        $scope = $this->apiScope();
        if ($discover) { $this->assertDiscoveryIssuer($local, $scope); }
        $stored = $cache['_asaas_lifecycle']['recovery_context']['environment'] ?? null;
        if ($stored !== null && $stored !== $scope['environment']) { throw new \RuntimeException('Recovery environment changed.'); }
        $this->scopeSnapshots[(int) $invoice->id] = ['api' => $scope, 'invoice' => $local, 'discover' => $discover];
    }

    private function assertScope(object $invoice): void
    {
        $previous = $this->scopeSnapshots[(int) $invoice->id] ?? null;
        if (!is_array($previous) || $previous['api'] !== $this->apiScope() || $previous['invoice'] !== $this->invoiceScope($invoice)) {
            throw new \RuntimeException('Recovery scope changed during lookup.');
        }
        if ($previous['discover']) { $this->assertDiscoveryIssuer($previous['invoice'], $previous['api']); }
    }

    /** Only known company aliases resolving to the very same account are safe for discovery. */
    private function assertDiscoveryIssuer(array $local, array $scope): void
    {
        foreach (['issuer_id', 'emitter_id', 'issuer_company_id'] as $field) {
            if (isset($local[$field])) { throw new \RuntimeException('Recovery issuer scope is unavailable.'); }
        }
        $company = null;
        foreach (['company_id', 'companyid'] as $field) {
            if (!isset($local[$field])) { continue; }
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $local[$field]) || (int) $local[$field] > 2147483647
                || ($company !== null && $company !== (int) $local[$field])) {
                throw new \RuntimeException('Recovery issuer scope is unavailable.');
            }
            $company = (int) $local[$field];
        }
        if ($company !== null && $scope !== $this->apiScope($company)) { throw new \RuntimeException('Recovery issuer scope is unavailable.'); }
    }

    private function remote(string $path, array $query = []): array
    {
        if (($this->clock)() >= $this->deadline) { throw new \RuntimeException('Recovery lookup deadline exceeded.'); }
        $gateway = $this->gateway();
        return is_callable([$gateway, 'reconciliation_request']) ? $gateway->reconciliation_request($path, $query)
            : $gateway->request('GET', $path, $query);
    }

    /** Complete bounded pagination; an incomplete result can never establish a binding. */
    private function remotePages(string $path, array $query, ?int $expectedCount = null): array
    {
        $items = [];
        $ids = [];
        $total = null;
        for ($page = 0, $offset = 0; $page < 5; ++$page) {
            $response = $this->remote($path, $query + ['limit' => 100, 'offset' => $offset]);
            if (!is_array($response['data'] ?? null) || !is_bool($response['hasMore'] ?? null) || count($response['data']) > 100) {
                throw new \RuntimeException('Incomplete recovery payment list.');
            }
            if (isset($response['totalCount'])) {
                $currentTotal = filter_var($response['totalCount'], FILTER_VALIDATE_INT);
                if ($currentTotal === false || $currentTotal < 0 || $currentTotal > 500 || ($total !== null && $currentTotal !== $total)) {
                    throw new \RuntimeException('Incomplete recovery payment list.');
                }
                $total = $currentTotal;
            }
            foreach ($response['data'] as $payment) {
                if (!is_array($payment) || $this->identifier($payment['id'] ?? null) === '' || isset($ids[$payment['id']])) {
                    throw new \RuntimeException('Incomplete recovery payment list.');
                }
                $ids[$payment['id']] = true;
                $items[] = $payment;
            }
            if (!$response['hasMore']) {
                if (($total !== null && count($items) !== $total) || ($expectedCount !== null && count($items) !== $expectedCount)) {
                    throw new \RuntimeException('Incomplete recovery payment list.');
                }
                return $items;
            }
            if ($response['data'] === [] || ($expectedCount !== null && count($items) >= $expectedCount)
                || ($total !== null && count($items) >= $total)) { throw new \RuntimeException('Incomplete recovery payment list.'); }
            $offset += count($response['data']);
        }
        throw new \RuntimeException('Recovery payment list exceeded limit.');
    }

    /** Recovery never creates or updates a customer; it only proves an existing identity. */
    private function proveCustomer(object $invoice, string $customerId, bool $allowRemote = true): void
    {
        if ($this->identifier($customerId) !== $customerId || $customerId === '') { throw new \RuntimeException('Invalid payment binding.'); }
        $clientId = (int) $invoice->clientid;
        $known = [];
        if ($this->has('asaas_customers_map', ['perfex_client_id', 'asaas_customer_id'])) {
            $known = array_column($this->rows('SELECT asaas_customer_id FROM ' . $this->table('asaas_customers_map')
                . ' WHERE perfex_client_id = ?', [$clientId]), 'asaas_customer_id');
        }
        $client = $this->one('SELECT * FROM ' . $this->table('clients') . ' WHERE userid = ? LIMIT 1', [$clientId]);
        if (!empty($client['asaas_customer_id'])) { $known[] = $client['asaas_customer_id']; }
        foreach ($known as $id) {
            if ($id !== null && $id !== '' && $id !== $customerId) { throw new \RuntimeException('Recovery customer conflicts with local identity.'); }
        }
        if (array_filter($known, static fn ($id): bool => is_string($id) && $id !== '') !== []) { return; }
        $document = preg_replace('/[.\/\s-]/', '', (string) ($client['vat'] ?? ''));
        if (!preg_match('/^(?:[0-9]{11}|[0-9]{14})$/D', $document)) { throw new \RuntimeException('Recovery customer document is missing.'); }
        $key = hash('sha256', json_encode([$this->apiScope(), $customerId], JSON_THROW_ON_ERROR));
        if (!isset($this->verifiedCustomers[$key])) {
            if (!$allowRemote) { throw new \RuntimeException('Recovery customer identity is unconfirmed.'); }
            $this->verifiedCustomers[$key] = $this->remote('/customers/' . rawurlencode($customerId));
        }
        $remote = $this->verifiedCustomers[$key];
        $remoteDocument = preg_replace('/[.\/\s-]/', '', is_string($remote['cpfCnpj'] ?? null) ? $remote['cpfCnpj'] : '');
        if (($remote['id'] ?? null) !== $customerId || !empty($remote['deleted']) || isset($remote['errors']) || isset($remote['error'])
            || (!is_string($remote['externalReference'] ?? null) && !is_int($remote['externalReference'] ?? null))
            || (string) $remote['externalReference'] !== (string) $clientId || $remoteDocument !== $document) {
            throw new \RuntimeException('Recovery customer identity is unconfirmed.');
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

    private function principal(array $payment, array $cache): int
    {
        return \asaas_card_receipt_principal($payment, $cache) ?? $this->amount($payment);
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
        if (in_array($source, ['api', 'webhook', 'reconciliation', 'recurring'], true)) {
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
        if (isset($stored['error_code'])) { $result['error_code'] = $this->enumText($stored['error_code']); }
        if (isset($stored['http_status'])) { $result['http_status'] = max(0, min(599, (int) $stored['http_status'])); }
        if (isset($stored['message'])) { $result['message'] = $this->safeText((string) $stored['message'], 1000); }
        $result['interrupted'] = !empty($stored['interrupted']);
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
