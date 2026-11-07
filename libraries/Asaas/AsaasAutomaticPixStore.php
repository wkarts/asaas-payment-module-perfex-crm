<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/AsaasRecurringStore.php';

/** Separate tables; shared customer/invoice locks protect the existing recurring card. */
class AsaasAutomaticPixStore
{
    private object $db;
    private AsaasRecurringStore $shared;
    private string $authorizations;
    private string $jobs;
    private const AUTH_FIELDS = ['client_id', 'scope_key', 'environment', 'company_id', 'customer_id', 'initial_invoice_id',
        'initial_invoice_hash', 'initial_amount_cents', 'cutoff_invoice_id', 'contract_id', 'remote_id', 'state', 'remote_status',
        'frequency', 'start_date', 'contact_id', 'consent_version', 'authorized_at', 'qrcode_ciphertext', 'conciliation_identifier',
        'expires_at', 'cancel_requested_at', 'reason_code', 'scan_offset', 'payment_scan_offset', 'terminal_scan_started_at', 'next_check_at', 'created_at', 'updated_at'];
    private const JOB_FIELDS = ['invoice_id', 'invoice_hash', 'client_id', 'scope_key', 'authorization_id', 'kind', 'state', 'phase',
        'principal_cents', 'due_date', 'cycle_key', 'previous_payment_id', 'payment_id', 'instruction_id', 'instruction_status',
        'reason_code', 'next_check_at', 'started_at', 'created_at', 'updated_at'];

    public function __construct(?object $db = null)
    {
        $this->db = $db ?? get_instance()->db;
        $prefix = db_prefix();
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('storage_unavailable'); }
        $this->shared = new AsaasRecurringStore($this->db);
        $this->authorizations = $prefix . 'asaas_pix_authorizations';
        $this->jobs = $prefix . 'asaas_pix_jobs';
    }
    public function ready(): bool
    {
        if (!\asaas_receipt_lock_supported($this->db)) { return false; }
        foreach ([$this->authorizations, $this->jobs] as $table) {
            $exists = \asaas_receipt_lock_quiet($this->db, fn () => $this->db->table_exists($table));
            if (!$exists) {
                $error = is_callable([$this->db, 'error']) ? $this->db->error() : [];
                if (!empty($error['code'])) { throw new \RuntimeException('storage_unavailable'); }
                return false;
            }
        }
        return true;
    }
    public function enabled(): bool
    {
        if (class_exists('CompanySettingsRuntime', false) && \CompanySettingsRuntime::active()) {
            return (string) get_option('paymentmethod_asaas_active') === '1' && (string) get_option('paymentmethod_asaas_automatic_pix_active') === '1';
        }
        $values = [];
        foreach ($this->rows('SELECT name, value FROM ' . db_prefix() . 'options WHERE name IN (?, ?)',
            ['paymentmethod_asaas_active', 'paymentmethod_asaas_automatic_pix_active']) as $row) { $values[$row['name']] = (string) $row['value']; }
        return ($values['paymentmethod_asaas_active'] ?? '') === '1' && ($values['paymentmethod_asaas_automatic_pix_active'] ?? '') === '1';
    }
    public function transactionDepth(): int { return $this->shared->transactionDepth(); }
    public function invoice(int $id): ?object { return $this->shared->invoice($id); }
    public function cache(int $id): array { return $this->shared->cache($id); }
    public function balance(object $invoice): string { return $this->shared->balance($invoice); }
    public function maxInvoiceId(): int { return $this->shared->maxInvoiceId(); }
    public function contactBelongs(int $contact, int $client): bool { return $this->shared->contactBelongs($contact, $client); }
    public function lock(int $client, string $scope): string { return $this->shared->lock($client, $scope); }
    public function assertLock(string $name): void { $this->shared->assertLock($name); }
    public function unlock(string $name): void { $this->shared->unlock($name); }
    public function seal(string $value): string { return $this->shared->seal($value); }
    public function unseal(string $value): string { return $this->shared->unseal($value); }
    public function receiptExists(int $invoice, string $payment, ?int $principalCents = null): bool
    {
        $rows = $this->rows('SELECT invoiceid, amount FROM ' . db_prefix() . 'invoicepaymentrecords WHERE transactionid = ?', [$payment]);
        if ($rows === []) { return false; }
        if (count($rows) !== 1 || (int) $rows[0]['invoiceid'] !== $invoice
            || ($principalCents !== null && \asaas_payment_amount_to_cents($rows[0]['amount'] ?? null) !== $principalCents)) { throw new \RuntimeException('receipt_conflict'); }
        return true;
    }
    public function cardBlocks(int $client, string $scope): bool
    {
        $authorization = $this->shared->authorization($client, $scope);
        if (in_array($authorization['state'] ?? '', ['active', 'suspended'], true)) { return true; }
        return $authorization !== [] && $this->shared->hasBlockingJob((int) $authorization['id'], 0, 0);
    }
    public function clientBlocks(int $client, string $scope): bool
    {
        return $this->one('SELECT id FROM ' . $this->authorizations
            . " WHERE client_id = ? AND scope_key = ? AND state IN ('preparing','creating','created','active','cancelling','uncertain') LIMIT 1", [$client, $scope]) !== []
            || $this->one('SELECT invoice_id FROM ' . $this->jobs
            . " WHERE client_id = ? AND scope_key = ? AND state IN ('preparing','creating','waiting','uncertain') LIMIT 1", [$client, $scope]) !== [];
    }
    public function authorization(int $client, string $scope): array
    {
        return $this->one('SELECT * FROM ' . $this->authorizations . ' WHERE client_id = ? AND scope_key = ? ORDER BY id DESC LIMIT 1', [$client, $scope]);
    }
    public function authorizationById(int $id): array { return $this->one('SELECT * FROM ' . $this->authorizations . ' WHERE id = ? LIMIT 1', [$id]); }
    public function authorizationByRemote(string $id): array { return $this->one('SELECT * FROM ' . $this->authorizations . ' WHERE remote_id = ? LIMIT 1', [$id]); }
    public function authorizationByConciliation(string $id): array { return $this->one('SELECT * FROM ' . $this->authorizations . ' WHERE conciliation_identifier = ? LIMIT 1', [$id]); }
    public function authorizationByContract(string $id): array { return $this->one('SELECT * FROM ' . $this->authorizations . ' WHERE contract_id = ? LIMIT 1', [$id]); }
    public function insertAuthorization(array $data): array
    {
        $this->insert($this->authorizations, array_intersect_key($data, array_flip(self::AUTH_FIELDS)));
        $row = $this->authorizationByContract($data['contract_id']);
        if ($row === []) { throw new \RuntimeException('storage_unavailable'); }
        return $row;
    }
    public function updateAuthorization(int $id, array $states, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(['remote_id', 'state', 'remote_status', 'qrcode_ciphertext', 'conciliation_identifier',
            'expires_at', 'cancel_requested_at', 'reason_code', 'scan_offset', 'payment_scan_offset', 'terminal_scan_started_at', 'next_check_at']));
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($this->authorizations, $data, 'id = ? AND state IN (' . $this->marks($states) . ')', array_merge([$id], $states)) === 1;
    }
    public function job(int $invoice): array { return $this->one('SELECT * FROM ' . $this->jobs . ' WHERE invoice_id = ? LIMIT 1', [$invoice]); }
    public function jobByPayment(string $payment): array { return $this->one('SELECT * FROM ' . $this->jobs . ' WHERE payment_id = ? LIMIT 1', [$payment]); }
    public function jobForCycle(int $authorization, string $cycle): array
    {
        return $this->one('SELECT * FROM ' . $this->jobs . ' WHERE authorization_id = ? AND cycle_key = ? LIMIT 1', [$authorization, $cycle]);
    }
    public function insertJob(array $data): bool
    {
        try { $this->insert($this->jobs, array_intersect_key($data, array_flip(self::JOB_FIELDS))); return true; }
        catch (\Throwable $error) {
            if ($this->job((int) $data['invoice_id']) !== [] || (!empty($data['cycle_key']) && $this->jobForCycle((int) $data['authorization_id'], $data['cycle_key']) !== [])) { return false; }
            throw new \RuntimeException('storage_unavailable');
        }
    }
    public function updateJob(int $invoice, array $states, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(['authorization_id', 'scope_key', 'kind', 'state', 'phase', 'principal_cents', 'due_date', 'cycle_key',
            'previous_payment_id', 'payment_id', 'instruction_id', 'instruction_status', 'reason_code', 'next_check_at', 'started_at']));
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($this->jobs, $data, 'invoice_id = ? AND state IN (' . $this->marks($states) . ')', array_merge([$invoice], $states)) === 1;
    }
    public function scheduleAuthorization(int $id): void
    {
        $this->update($this->authorizations, ['next_check_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $this->update($this->jobs, ['next_check_at' => date('Y-m-d H:i:s')], "authorization_id = ? AND state IN ('queued','preparing','creating','waiting','uncertain')", [$id]);
    }
    public function dueAuthorizations(int $limit): array
    {
        return $this->rows('SELECT * FROM ' . $this->authorizations
            . " WHERE state IN ('preparing','creating','created','active','cancelling','uncertain')"
            . ' AND (next_check_at IS NULL OR next_check_at <= ?) ORDER BY COALESCE(next_check_at, created_at), id LIMIT ' . max(1, min(5, $limit)), [date('Y-m-d H:i:s')]);
    }
    public function dueJobs(int $limit): array
    {
        return $this->rows('SELECT invoice_id FROM ' . $this->jobs
            . " WHERE state IN ('queued','preparing','creating','waiting','uncertain') AND (next_check_at IS NULL OR next_check_at <= ?)"
            . ' ORDER BY COALESCE(next_check_at, created_at), invoice_id LIMIT ' . max(1, min(10, $limit)), [date('Y-m-d H:i:s')]);
    }
    public function missingJobs(int $limit): array
    {
        $cursor = max(0, (int) ($this->one('SELECT value FROM ' . db_prefix() . 'options WHERE name = ?', ['asaas_pix_scan_cursor'])['value'] ?? 0));
        $sql = 'SELECT i.id, MIN(a.id) AS authorization_id FROM ' . db_prefix() . 'invoices i JOIN ' . $this->authorizations
            . " a ON a.client_id = i.clientid AND a.state = 'active' AND a.cancel_requested_at IS NULL AND i.id > a.cutoff_invoice_id"
            . ' LEFT JOIN ' . $this->jobs . ' j ON j.invoice_id = i.id'
            . " WHERE j.invoice_id IS NULL AND i.status NOT IN (2,5,6) AND i.id > ? AND i.allowed_payment_modes LIKE ?"
            . ' GROUP BY i.id ORDER BY i.id LIMIT ' . max(1, min(40, $limit));
        $rows = $this->rows($sql, [$cursor, '%asaas_automatic_pix%']);
        if ($rows === [] && $cursor > 0) {
            $this->advanceMissingCursor(0);
            $rows = $this->rows($sql, [0, '%asaas_automatic_pix%']);
        }
        return $rows;
    }
    /** Keyset progress prevents ineligible old scopes from monopolizing every backfill batch. */
    public function advanceMissingCursor(int $invoiceId): void
    {
        $name = 'asaas_pix_scan_cursor';
        if ($this->one('SELECT value FROM ' . db_prefix() . 'options WHERE name = ?', [$name]) === []) {
            try { $this->insert(db_prefix() . 'options', ['name' => $name, 'value' => (string) max(0, $invoiceId)]); return; }
            catch (\Throwable) { /* Another worker may have created the cursor; the update is idempotent. */ }
        }
        $this->update(db_prefix() . 'options', ['value' => (string) max(0, $invoiceId)], 'name = ?', [$name]);
    }
    private function marks(array $values): string
    {
        if ($values === []) { throw new \RuntimeException('storage_unavailable'); }
        return implode(',', array_fill(0, count($values), '?'));
    }
    private function one(string $sql, array $bind = []): array { return $this->rows($sql, $bind)[0] ?? []; }
    private function rows(string $sql, array $bind = []): array
    {
        try {
            $result = \asaas_receipt_lock_quiet($this->db, fn () => $this->db->query($sql, $bind));
            if (!is_object($result) || !is_array($rows = $result->result_array())) { throw new \RuntimeException('storage_unavailable'); }
            return $rows;
        } catch (\Throwable $error) { throw new \RuntimeException('storage_unavailable'); }
    }
    private function insert(string $table, array $data): void
    {
        $this->execute('INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . $this->marks($data) . ')', array_values($data));
    }
    private function update(string $table, array $data, string $where, array $bind): int
    {
        $this->execute('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn ($key): string => '`' . $key . '` = ?', array_keys($data)))
            . ' WHERE ' . $where, array_merge(array_values($data), $bind));
        return (int) $this->db->affected_rows();
    }
    private function execute(string $sql, array $bind): void
    {
        try {
            if (\asaas_receipt_lock_quiet($this->db, fn () => $this->db->query($sql, $bind)) === false) { throw new \RuntimeException('storage_unavailable'); }
        } catch (\Throwable $error) { throw new \RuntimeException('storage_unavailable'); }
    }
}
