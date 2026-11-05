<?php

declare(strict_types=1);

/**
 * Controlled Perfex interfaces for the lifecycle contract tests.
 * This is a stateful model of the documented hook order, not the Perfex core.
 * Actual module hooks, lifecycle validation and InvoiceTransaction remain real.
 */

final class LifecycleResult
{
    public function __construct(private array $rows = []) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function row(): ?object { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
    public function result_array(): array { return $this->rows; }
    public function result(): array { return array_map(static fn (array $row): object => (object) $row, $this->rows); }
    public function num_rows(): int { return count($this->rows); }
}

final class LifecycleDatabase
{
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public string $dbdriver = '';
    public string $subdriver = '';
    public string $hostname = 'fixture-database';
    public string $database = 'fixture-perfex';
    public array $engines = [];
    public array $tables = [];
    public array $faults = [];
    public array $savepoints = [];
    public bool $closed = false;
    private ?array $snapshot = null;
    private bool $healthy = true;
    private array $sequences = [];
    private int $lastId = 0;
    private int $affected = 0;
    private array $conditions = [];
    private array $inConditions = [];
    private string $source = '';
    private string $selection = '*';
    private ?array $sum = null;
    private ?int $rowLimit = null;

    public function __construct()
    {
        foreach (['invoices', 'itemable', 'item_tax', 'invoicepaymentrecords', 'customfieldsvalues', 'taggables',
            'clients', 'contacts', 'credits', 'asaas_customers_map', 'asaas_payments_map', 'asaas_logs'] as $table) {
            $this->tables['tbl' . $table] = [];
        }
    }

    private function fault(string $operation): bool
    {
        if (!array_key_exists($operation, $this->faults)) { return false; }
        $failure = $this->faults[$operation];
        unset($this->faults[$operation]);
        lifecycleTrace('db.failure', ['operation' => $operation]);
        $this->healthy = false;
        if ($failure instanceof Throwable) { throw $failure; }
        return (bool) $failure;
    }

    public function trans_begin(): bool
    {
        lifecycleTrace('db.begin', ['depth' => $this->_trans_depth]);
        if ($this->fault('begin')) { return false; }
        if ($this->_trans_depth === 0) { $this->snapshot = $this->tables; $this->healthy = true; }
        ++$this->_trans_depth;
        return true;
    }
    public function trans_commit(): bool
    {
        lifecycleTrace('db.commit', ['depth' => $this->_trans_depth]);
        if ($this->fault('commit')) { return false; }
        if ($this->_trans_depth < 1) { return false; }
        --$this->_trans_depth;
        if ($this->_trans_depth === 0) { $this->snapshot = null; $this->savepoints = []; }
        return true;
    }
    public function trans_rollback(): bool
    {
        lifecycleTrace('db.rollback', ['depth' => $this->_trans_depth]);
        if ($this->fault('rollback')) { return false; }
        if ($this->_trans_depth < 1) { return false; }
        --$this->_trans_depth;
        if ($this->_trans_depth === 0) {
            if ($this->snapshot !== null) { $this->tables = $this->snapshot; }
            $this->snapshot = null; $this->savepoints = []; $this->healthy = true;
        }
        return true;
    }
    public function trans_status(): bool { return $this->healthy; }
    public function trans_active(): bool { return $this->_trans_depth > 0; }
    public function trans_depth(): int { return $this->_trans_depth; }
    public function close(): void
    {
        lifecycleTrace('db.close');
        if ($this->snapshot !== null) { $this->tables = $this->snapshot; }
        $this->snapshot = null; $this->savepoints = []; $this->_trans_depth = 0; $this->closed = true;
    }
    public function query(string $sql, array $bindings = [])
    {
        lifecycleTrace('db.query', ['sql' => $sql, 'bindings' => $bindings]);
        if ($this->fault('query')) { return false; }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return new LifecycleResult(array_map(fn (string $table): array => ['table_name' => $table,
                'engine' => $this->engines[$table] ?? 'InnoDB'],
                array_values(array_filter($bindings, fn (string $table): bool => $this->table_exists($table)))));
        }
        if ($sql === 'SELECT GET_LOCK(?, 5) AS acquired') {
            return new LifecycleResult([['acquired' => 1]]);
        }
        if ($sql === 'SELECT RELEASE_LOCK(?) AS released') {
            return new LifecycleResult([['released' => 1]]);
        }
        if (preg_match('/^SAVEPOINT\s+([a-zA-Z0-9_]+)$/i', trim($sql), $match)) {
            if ($this->_trans_depth < 1) { throw new LogicException('SAVEPOINT without an outer transaction.'); }
            $this->savepoints[$match[1]] = $this->tables;
            return true;
        }
        if (preg_match('/^ROLLBACK TO(?: SAVEPOINT)?\s+([a-zA-Z0-9_]+)$/i', trim($sql), $match)) {
            if (!isset($this->savepoints[$match[1]])) { throw new LogicException('Unknown savepoint.'); }
            $this->tables = $this->savepoints[$match[1]]; $this->healthy = true;
            return true;
        }
        if (preg_match('/^RELEASE SAVEPOINT\s+([a-zA-Z0-9_]+)$/i', trim($sql), $match)) {
            unset($this->savepoints[$match[1]]); return true;
        }
        if (preg_match('/^UPDATE (tblasaas_payments_map) SET (.+) WHERE invoice_id = \? AND asaas_payment_id = \? AND payload_cache = \?$/', $sql, $match)) {
            $assignments = explode(', ', $match[2]);
            if (count($bindings) !== count($assignments) + 3) { throw new LogicException('Incorrect map update bindings.'); }
            $changes = [];
            foreach ($assignments as $index => $assignment) {
                if (!preg_match('/^([a-z_]+) = \?$/', $assignment, $column)) { throw new LogicException('Unexpected map assignment.'); }
                $changes[$column[1]] = $bindings[$index];
            }
            $where = array_combine(['invoice_id', 'asaas_payment_id', 'payload_cache'], array_slice($bindings, -3));
            return $this->where($where)->update($match[1], $changes);
        }
        if ($sql === 'DELETE FROM tblasaas_payments_map WHERE invoice_id = ? AND asaas_payment_id = ? AND payload_cache = ?') {
            if (count($bindings) !== 3) { throw new LogicException('Incorrect map delete bindings.'); }
            return $this->where(array_combine(['invoice_id', 'asaas_payment_id', 'payload_cache'], $bindings))->delete('tblasaas_payments_map');
        }
        if (preg_match('/^SELECT\s+.*?FROM\s+`?(tbl\w+)`?\s+WHERE\s+`?(\w+)`?\s*=\s*\?/i', trim($sql), $match)) {
            return $this->get_where($match[1], [$match[2] => $bindings[0] ?? null]);
        }
        throw new LogicException('Unexpected SQL in lifecycle fixture: ' . $sql);
    }
    public function table_exists(string $table): bool { return array_key_exists($table, $this->tables); }
    public function list_fields(string $table): array
    {
        if ($table === 'tblasaas_logs') { return ['id', 'level', 'message', 'context_json', 'correlation_id', 'created_at']; }
        if ($table === 'tblasaas_payments_map') { return ['id', 'invoice_id', 'asaas_payment_id', 'billing_type', 'status', 'value', 'due_date', 'payload_cache', 'created_at', 'updated_at', 'last_sync_at']; }
        return array_keys(current($this->tables[$table] ?? []) ?: []);
    }
    public function field_exists(string $field, string $table): bool { return in_array($field, $this->list_fields($table), true); }
    public function select(string $fields, ?bool $escape = null): self { $this->selection = $fields; return $this; }
    public function select_sum(string $field, string $alias = ''): self { $this->sum = [$field, $alias ?: $field]; return $this; }
    public function from(string $table): self { $this->source = $table; return $this; }
    public function join(string $table, string $on, string $type = ''): self
    {
        lifecycleTrace('db.join', ['table' => $table, 'on' => $on, 'type' => $type]); return $this;
    }
    public function where($field, $value = null, ?bool $escape = null): self
    {
        foreach (is_array($field) ? $field : [$field => $value] as $key => $item) { $this->conditions[$key] = $item; }
        return $this;
    }
    public function where_in(string $field, array $values): self { $this->inConditions[$field] = $values; return $this; }
    public function order_by(string $field, string $direction = 'ASC'): self { return $this; }
    public function limit(int $limit): self { $this->rowLimit = $limit; return $this; }
    private function cleanField(string $field): string { $parts = explode('.', trim($field, '` ')); return trim((string) end($parts), '` '); }
    private function matches(array $row): bool
    {
        foreach ($this->conditions as $field => $expected) {
            $operator = '=';
            if (preg_match('/^(.+?)\s+(!=|<>|>=|<=|>|<)$/', $field, $match)) { $field = $match[1]; $operator = $match[2]; }
            $actual = $row[$this->cleanField($field)] ?? null;
            $match = match ($operator) {
                '!=', '<>' => $actual != $expected, '>' => $actual > $expected, '<' => $actual < $expected,
                '>=' => $actual >= $expected, '<=' => $actual <= $expected, default => $actual == $expected,
            };
            if (!$match) { return false; }
        }
        foreach ($this->inConditions as $field => $values) {
            if (!in_array($row[$this->cleanField($field)] ?? null, $values, false)) { return false; }
        }
        return true;
    }
    private function resetQuery(): void { $this->conditions = []; $this->inConditions = []; $this->source = ''; $this->selection = '*'; $this->sum = null; $this->rowLimit = null; }
    public function get(?string $table = null): LifecycleResult
    {
        $table = explode(' ', $table ?? $this->source)[0];
        if (!array_key_exists($table, $this->tables)) { throw new LogicException('Unexpected table: ' . $table); }
        $rows = array_values(array_filter($this->tables[$table], fn (array $row): bool => $this->matches($row)));
        if ($table === 'tblclients' && str_contains($this->selection, 'email')) {
            foreach ($rows as &$row) {
                $primary = array_values(array_filter($this->tables['tblcontacts'], static fn (array $contact): bool => (int) $contact['userid'] === (int) $row['userid'] && (int) $contact['is_primary'] === 1));
                $row['email'] = $primary[0]['email'] ?? null;
            }
            unset($row);
        }
        if ($this->sum !== null) { $rows = [[$this->sum[1] => array_sum(array_column($rows, $this->sum[0]))]]; }
        elseif (preg_match('/SUM\((?:[^.]+\.)?([a-z_]+)\)\s+(?:AS\s+)?([a-z_]+)/i', $this->selection, $match)) {
            $rows = [[$match[2] => array_sum(array_column($rows, $match[1]))]];
        }
        if ($this->rowLimit !== null) { $rows = array_slice($rows, 0, $this->rowLimit); }
        $this->resetQuery();
        return new LifecycleResult($rows);
    }
    public function get_where(string $table, array $where): LifecycleResult { return $this->where($where)->get($table); }
    public function insert(string $table, array $data): bool
    {
        lifecycleTrace('db.insert', ['table' => $table, 'data' => $data]);
        $this->resetQuery(); $this->affected = 0; $this->lastId = 0;
        if ($this->fault('insert:' . $table)) { return false; }
        $key = $table === 'tblclients' ? 'userid' : 'id';
        $id = isset($data[$key]) ? (int) $data[$key] : ($this->sequences[$table] ?? 0) + 1;
        $this->sequences[$table] = max($id, $this->sequences[$table] ?? 0);
        $data[$key] = $id;
        if (isset($this->tables[$table][$id])) { throw new LogicException('Duplicate primary key in fixture.'); }
        if ($table === 'tblasaas_payments_map') {
            foreach ($this->tables[$table] as $row) {
                if ((int) $row['invoice_id'] === (int) $data['invoice_id']) { throw new LogicException('Duplicate invoice payment map.'); }
            }
        }
        $this->tables[$table][$id] = $data; $this->lastId = $id; $this->affected = 1;
        return true;
    }
    public function update(string $table, array $data): bool
    {
        lifecycleTrace('db.update', ['table' => $table, 'data' => $data, 'where' => $this->conditions]);
        $ids = array_keys(array_filter($this->tables[$table], fn (array $row): bool => $this->matches($row)));
        $this->resetQuery(); $this->affected = 0;
        if ($this->fault('update:' . $table)) { return false; }
        foreach ($ids as $id) { $this->tables[$table][$id] = array_replace($this->tables[$table][$id], $data); ++$this->affected; }
        return true;
    }
    public function delete(string $table): bool
    {
        lifecycleTrace('db.delete', ['table' => $table, 'where' => $this->conditions]);
        $ids = array_keys(array_filter($this->tables[$table], fn (array $row): bool => $this->matches($row)));
        $this->resetQuery(); $this->affected = 0;
        if ($this->fault('delete:' . $table)) { return false; }
        foreach ($ids as $id) { unset($this->tables[$table][$id]); ++$this->affected; }
        return true;
    }
    public function insert_id(): int { return $this->lastId; }
    public function affected_rows(): int { return $this->affected; }
    public function error(): array { return $this->healthy ? ['code' => 0, 'message' => ''] : ['code' => 1, 'message' => 'Controlled storage failure']; }
    public function escape($value): string { return "'" . str_replace("'", "''", (string) $value) . "'"; }
}

final class LifecycleHooks
{
    public array $actions = [];
    public array $filters = [];
    public function add_action(string $event, callable $callback, int $priority = 10, int $arguments = 1): void { $this->actions[$event][$priority][] = $callback; }
    public function add_filter(string $event, callable $callback, int $priority = 10, int $arguments = 1): void { $this->filters[$event][$priority][] = $callback; }
    private function callbacks(array $events, string $event): array
    {
        $list = $events[$event] ?? []; ksort($list);
        return $list === [] ? [] : array_merge(...array_values($list));
    }
    public function do_action(string $event, ...$arguments): void
    {
        lifecycleTrace('hook.action', ['event' => $event]);
        foreach ($this->callbacks($this->actions, $event) as $callback) { $callback(...$arguments); }
    }
    public function apply_filters(string $event, $value, ...$arguments)
    {
        lifecycleTrace('hook.filter', ['event' => $event]);
        foreach ($this->callbacks($this->filters, $event) as $callback) { $value = $callback($value, ...$arguments); }
        return $value;
    }
}

final class LifecycleInput
{
    public array $posted = [];
    public string $verb = 'post';
    public function method(bool $upper = false): string { return $upper ? strtoupper($this->verb) : strtolower($this->verb); }
    public function post(?string $key = null, ?bool $xss = null) { return $key === null ? $this->posted : ($this->posted[$key] ?? null); }
    public function get(?string $key = null, ?bool $xss = null) { return null; }
    public function is_ajax_request(): bool { return false; }
}
final class LifecycleRouter
{
    public string $class = 'invoices';
    public string $method = 'invoice';
    public function fetch_class(): string { return $this->class; }
    public function fetch_method(): string { return $this->method; }
}
final class LifecycleUri
{
    public array $segments = [1 => 'admin', 2 => 'invoices', 3 => 'invoice', 4 => '42'];
    public function segment(int $number, $default = null) { return $this->segments[$number] ?? $default; }
    public function rsegment(int $number, $default = null) { return $this->segments[$number + 1] ?? $default; }
    public function segment_array(): array { return $this->segments; }
    public function uri_string(): string { return implode('/', $this->segments); }
}
final class LifecycleLoader
{
    public bool $gatewayUnavailable = false;
    public function library(string $name): void
    {
        lifecycleTrace('load.library', ['name' => $name]);
        if ($this->gatewayUnavailable && str_contains($name, 'asaas_gateway')) { throw new RuntimeException('SDK indisponível na fixture.'); }
    }
    public function model(string $name): void { lifecycleTrace('load.model', ['name' => $name]); }
    public function helper($name): void {}
}
final class LifecycleConfig
{
    public function item(string $name) { return $name === 'log_path' ? LIFECYCLE_TEMP_ROOT . '/logs/' : null; }
}

class Invoices_model
{
    public const STATUS_UNPAID = 1;
    public const STATUS_PAID = 2;
    public const STATUS_PARTIALLY = 3;
    public const STATUS_OVERDUE = 4;
    public const STATUS_CANCELLED = 5;
    public const STATUS_DRAFT = 6;
    public function get($id): ?object
    {
        lifecycleTrace('native.invoice.read', ['id' => $id]);
        $ci = get_instance(); $row = $ci->db->tables['tblinvoices'][(int) $id] ?? null;
        if ($row === null) { return null; }
        $invoice = (object) $row;
        $client = $ci->db->tables['tblclients'][(int) $invoice->clientid] ?? [];
        $contacts = array_filter($ci->db->tables['tblcontacts'], static fn (array $contact): bool => (int) $contact['userid'] === (int) $invoice->clientid && (int) $contact['is_primary'] === 1);
        $client['email'] = current($contacts)['email'] ?? null;
        $invoice->client = (object) $client;
        $invoice->items = array_values(array_filter($ci->db->tables['tblitemable'], static fn (array $item): bool => (int) $item['rel_id'] === (int) $id));
        return $invoice;
    }
    public function get_invoice_payments($id): array { return get_instance()->payments_model->get_invoice_payments($id); }
    public function get_applied_credits($id): array { return array_values(array_filter(get_instance()->db->tables['tblcredits'], static fn (array $row): bool => (int) $row['invoice_id'] === (int) $id)); }
}
class LifecyclePaymentsModel
{
    public function get($id): ?object { return isset(get_instance()->db->tables['tblinvoicepaymentrecords'][(int) $id]) ? (object) get_instance()->db->tables['tblinvoicepaymentrecords'][(int) $id] : null; }
    public function get_invoice_payments($id): array { return array_values(array_filter(get_instance()->db->tables['tblinvoicepaymentrecords'], static fn (array $row): bool => (int) $row['invoiceid'] === (int) $id)); }
}

/** Capability sentinel only; this fixture does not contain the custom Perfex writer. */
final class LifecycleInvoicePaymentWriterSentinel {}

/** Reproduces a core-owned transaction between the real before/after module hooks. */
final class LifecycleAtomicPaymentsModel extends LifecyclePaymentsModel
{
    public bool $throwAfterCommit = false;

    public function persist(array $data)
    {
        return $this->insert_payment_atomically($data);
    }

    private function insert_payment_atomically(array $data)
    {
        $db = get_instance()->db;
        lifecycleTrace('native.atomic.enter', ['handoffs' => array_column(asaas_atomic_payment_state(), 'state'),
            'owned_locks' => count(asaas_receipt_lock_state()['locks'] ?? [])]);
        if ($db->_trans_depth !== 0) { throw new LogicException('The custom core must receive transaction depth zero.'); }
        if (!$db->trans_begin()) { return false; }
        try {
            if (!$db->insert('tblinvoicepaymentrecords', $data)) {
                $db->trans_rollback();
                return false;
            }
            $id = $db->insert_id();
            update_invoice_status($data['invoiceid']);
            if (!$db->trans_commit()) {
                $db->trans_rollback();
                return false;
            }
        } catch (Throwable $error) {
            if ($db->_trans_depth > 0) { $db->trans_rollback(); }
            throw $error;
        }
        lifecycleTrace('native.atomic.committed', ['payment_id' => $id]);
        if ($this->throwAfterCommit) { throw new RuntimeException('Controlled notification failure after durable receipt.'); }
        return $id;
    }
}

class Asaas_gateway
{
    public array $remote = [];
    public array $calls = [];
    public array $responses = [];
    private int $sequence = 0;
    public function getSetting(string $name) { return get_option('paymentmethod_asaas_' . $name); }
    public function getApiKey(): string { return 'fixture-api-key-never-real'; }
    public function getUrlBase(): string { return 'https://offline.invalid/v3'; }
    public function isFileLogEnabled(): bool { return false; }
    private function call(string $operation, array $arguments, callable $success): array
    {
        $this->calls[] = ['operation' => $operation, 'arguments' => $arguments];
        $trace = $arguments;
        if (function_exists('asaas_atomic_payment_state')) { $trace['handoffs'] = array_column(asaas_atomic_payment_state(), 'state'); }
        lifecycleTrace('remote.' . $operation, $trace);
        if (!empty($this->responses[$operation])) {
            $response = array_shift($this->responses[$operation]);
            if ($response instanceof Throwable) { throw $response; }
            return $response;
        }
        return $success();
    }
    public function charge_billet($invoice): array
    {
        return $this->create([
            'billingType' => 'BOLETO', 'value' => $invoice->total, 'dueDate' => $invoice->duedate,
            'externalReference' => $invoice->hash, 'customer' => 'cus_fixture',
        ]);
    }
    private function create(array $payload): array
    {
        return $this->call('create', ['payload' => $payload], function () use ($payload): array {
            $id = 'pay_lifecycle_' . ++$this->sequence;
            return $this->remote[$id] = array_replace($payload, ['id' => $id, 'status' => 'PENDING', 'deleted' => false]);
        });
    }
    public function create_charge($url, $key, $data): string { return json_encode($this->create(is_array($data) ? $data : json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR); }
    public function get_charge2($hash): array
    {
        $this->calls[] = ['operation' => 'list', 'arguments' => ['hash' => $hash]];
        lifecycleTrace('remote.list', ['hash' => $hash]);
        if (!empty($this->responses['list'])) {
            $response = array_shift($this->responses['list']);
            if ($response instanceof Throwable) { throw $response; }
            return array_map(static fn ($row): object => (object) $row, $response);
        }
        return array_map(static fn (array $row): object => (object) $row,
            array_values(array_filter($this->remote, static fn (array $row): bool => ($row['externalReference'] ?? null) === $hash && empty($row['deleted']))));
    }
    public function update_charge($id, $data): array
    {
        $payload = is_array($data) ? $data : json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR);
        return $this->call('update', ['id' => $id, 'payload' => $payload], function () use ($id, $payload): array {
            if (!isset($this->remote[$id])) { throw new RuntimeException('Cobrança não encontrada.', 404); }
            return $this->remote[$id] = array_replace($this->remote[$id], $payload);
        });
    }
    public function delete_charge($id): array
    {
        return $this->call('delete', ['id' => $id], function () use ($id): array {
            if (!isset($this->remote[$id])) { throw new RuntimeException('Cobrança não encontrada.', 404); }
            $this->remote[$id]['deleted'] = true;
            return ['id' => $id, 'deleted' => true];
        });
    }
    public function restore_charge($id): array
    {
        return $this->call('restore', ['id' => $id], function () use ($id): array {
            $this->remote[$id]['deleted'] = false;
            return $this->remote[$id];
        });
    }
    public function receive_charge_in_cash($id, $date, $value, $notify = false): array
    {
        return $this->call('cash', ['id' => $id, 'date' => $date, 'value' => $value, 'notify' => $notify], function () use ($id, $date, $value): array {
            $this->remote[$id]['status'] = 'RECEIVED_IN_CASH';
            $this->remote[$id]['paymentDate'] = $date;
            $this->remote[$id]['receivedValue'] = $value;
            return $this->remote[$id];
        });
    }
    public function undo_receive_in_cash($id): array
    {
        return $this->call('undo_cash', ['id' => $id], function () use ($id): array {
            $this->remote[$id]['status'] = 'PENDING';
            unset($this->remote[$id]['paymentDate'], $this->remote[$id]['receivedValue']);
            return $this->remote[$id];
        });
    }
    public function request(string $method, string $path, array $query = [], array $headers = [], ?array $payload = null): array
    {
        if ($method === 'GET' && preg_match('~/payments/([^/]+)$~', $path, $match)) {
            return $this->call('get', ['id' => $match[1]], function () use ($match): array {
                if (!isset($this->remote[$match[1]])) { throw new RuntimeException('Cobrança não encontrada.', 404); }
                return $this->remote[$match[1]];
            });
        }
        throw new LogicException('Unexpected gateway request: ' . $method . ' ' . $path);
    }
    public function ensure_customer_from_client($client): array
    {
        lifecycleTrace('gateway.customer', ['client' => (array) $client]);
        return ['customerId' => 'cus_fixture', 'created' => false, 'updated' => false, 'raw' => []];
    }
    public function upsert_payment_map(int $invoiceId, $charge): bool
    {
        $db = get_instance()->db; $data = (array) $charge;
        $existing = $db->get_where('tblasaas_payments_map', ['invoice_id' => $invoiceId])->row_array();
        $payload = ['invoice_id' => $invoiceId, 'asaas_payment_id' => (string) ($data['id'] ?? ''),
            'billing_type' => $data['billingType'] ?? null, 'status' => $data['status'] ?? null,
            'value' => $data['value'] ?? null, 'due_date' => $data['dueDate'] ?? null,
            'payload_cache' => json_encode($data, JSON_THROW_ON_ERROR)];
        return $existing ? $db->where('invoice_id', $invoiceId)->update('tblasaas_payments_map', $payload) : $db->insert('tblasaas_payments_map', $payload);
    }
}

final class LifecycleCi
{
    public LifecycleDatabase $db;
    public LifecycleLoader $load;
    public LifecycleInput $input;
    public LifecycleRouter $router;
    public LifecycleUri $uri;
    public LifecycleConfig $config;
    public Invoices_model $invoices_model;
    public LifecyclePaymentsModel $payments_model;
    public Asaas_gateway $asaas_gateway;
    public function __construct()
    {
        $this->db = new LifecycleDatabase(); $this->load = new LifecycleLoader();
        $this->input = new LifecycleInput(); $this->router = new LifecycleRouter(); $this->uri = new LifecycleUri();
        $this->config = new LifecycleConfig(); $this->invoices_model = new Invoices_model();
        $this->payments_model = new LifecyclePaymentsModel(); $this->asaas_gateway = new Asaas_gateway();
    }
}

function lifecycleTrace(string $operation, array $details = []): void
{
    $details['transaction_depth'] = $GLOBALS['lifecycle_ci']->db->_trans_depth ?? 0;
    $GLOBALS['lifecycle_trace'][] = ['operation' => $operation, 'details' => $details];
}
function &get_instance(): LifecycleCi { return $GLOBALS['lifecycle_ci']; }
function hooks(): LifecycleHooks { return $GLOBALS['lifecycle_hooks']; }
function get_option(string $name) { return $GLOBALS['lifecycle_options'][$name] ?? ''; }
function db_prefix(): string { return 'tbl'; }
function is_staff_logged_in(): bool { return true; }
function is_admin(): bool { return true; }
function set_alert(string $type, string $message): void { $GLOBALS['lifecycle_alerts'][] = [$type, $message]; }
function log_message(string $level, string $message): void { $GLOBALS['lifecycle_logs'][] = [$level, $message]; }
function log_activity(string $message): void { lifecycleTrace('activity', ['message' => $message]); }
function register_activation_hook(string $module, callable $callback): void {}
function register_payment_gateway(string $gateway, string $module): void {}
function do_action_deprecated(string $event = '', array $arguments = [], string $version = '', string $replacement = ''): void {}
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function site_url(string $path = ''): string { return 'https://crm.offline.invalid/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function format_invoice_number($id): string { return 'INV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function to_sql_date(string $date, bool $datetime = false): string { return $date; }
function _l(string $key, ...$arguments): string { return $key; }
function get_invoice_total_left_to_pay($id, $total = null): float
{
    lifecycleTrace('native.balance.read', ['id' => $id]);
    $ci = get_instance(); $invoice = $ci->db->tables['tblinvoices'][(int) $id] ?? [];
    $payments = array_sum(array_column($ci->payments_model->get_invoice_payments($id), 'amount'));
    $credits = array_sum(array_column($ci->invoices_model->get_applied_credits($id), 'amount'));
    return max(0.0, (float) ($total ?? ($invoice['total'] ?? 0)) - $payments - $credits);
}
function update_invoice_status($id, bool $force = false): int
{
    $ci = get_instance(); $invoice = $ci->db->tables['tblinvoices'][(int) $id];
    $balance = get_invoice_total_left_to_pay($id);
    $status = $balance <= 0 ? Invoices_model::STATUS_PAID : ($balance < (float) $invoice['total'] ? Invoices_model::STATUS_PARTIALLY : Invoices_model::STATUS_UNPAID);
    $ci->db->where('id', $id)->update('tblinvoices', ['status' => $status]);
    return $status;
}
