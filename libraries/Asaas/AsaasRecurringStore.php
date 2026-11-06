<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_receipt_lock_helper.php';
require_once __DIR__ . '/../../helpers/asaas_lifecycle_helper.php';

/** Isolated persistence: no native invoice schema changes or raw card data. */
class AsaasRecurringStore
{
    private object $db;
    private string $profiles;
    private string $jobs;
    private array $locks = [];

    public function __construct(?object $db = null)
    {
        $this->db = $db ?? get_instance()->db;
        $prefix = db_prefix();
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('recurring_storage_unavailable'); }
        $this->profiles = $prefix . 'asaas_recurring_authorizations';
        $this->jobs = $prefix . 'asaas_recurring_jobs';
    }

    public function ready(): bool
    {
        if (!\asaas_receipt_lock_supported($this->db) || !is_callable([$this->db, 'table_exists'])) { return false; }
        foreach ([$this->profiles, $this->jobs] as $table) {
            $exists = \asaas_receipt_lock_quiet($this->db, fn () => $this->db->table_exists($table));
            if (!$exists) {
                $error = is_callable([$this->db, 'error']) ? $this->db->error() : [];
                if (!empty($error['code'])) { throw new \RuntimeException('storage_unavailable'); }
                return false;
            }
        }
        return true;
    }

    public function transactionDepth(): int { return \asaas_atomic_payment_depth($this->db); }
    /** Autoloaded active flags may be cached by Perfex for the whole cron request. */
    public function freshGatewayActive(): bool
    {
        if (class_exists('CompanySettingsRuntime', false) && \CompanySettingsRuntime::active()) {
            // Preserve the custom core's issuer-aware settings contract.
            return (string) get_option('paymentmethod_asaas_active') === '1'
                && (string) get_option('paymentmethod_asaas_recurring_active') === '1';
        }
        $values = [];
        foreach ($this->rows('SELECT name, value FROM ' . db_prefix() . 'options WHERE name IN (?, ?)',
            ['paymentmethod_asaas_active', 'paymentmethod_asaas_recurring_active']) as $row) { $values[$row['name']] = (string) $row['value']; }
        return ($values['paymentmethod_asaas_active'] ?? '') === '1'
            && ($values['paymentmethod_asaas_recurring_active'] ?? '') === '1';
    }
    public function invoice(int $id): ?object { return \asaas_lifecycle_invoice($id) ?: null; }
    public function cache(int $id): array { return \asaas_lifecycle_cached_payment(\asaas_lifecycle_map($id)); }
    public function balance(object $invoice): string
    {
        return \asaas_card_money(\asaas_payment_amount_to_cents(get_invoice_total_left_to_pay((int) $invoice->id, $invoice->total)));
    }
    public function maxInvoiceId(): int
    {
        return (int) ($this->one('SELECT MAX(id) AS id FROM ' . db_prefix() . 'invoices')['id'] ?? 0);
    }
    public function contactBelongs(int $contactId, int $clientId): bool
    {
        return $contactId > 0 && $this->one('SELECT id FROM ' . db_prefix()
            . 'contacts WHERE id = ? AND userid = ? AND active = 1 LIMIT 1', [$contactId, $clientId]) !== [];
    }

    /** One lock for enrollment, revocation and workers; separate from the invoice receipt lock. */
    public function lock(int $clientId, string $scopeKey): string
    {
        if (!$this->ready()) { throw new \RuntimeException('storage_unavailable'); }
        $identity = [(string) ($this->db->hostname ?? ''), (string) ($this->db->database ?? ''), $clientId, $scopeKey];
        $name = 'asaas_recurring_' . substr(hash('sha256', serialize($identity)), 0, 47);
        if (isset($this->locks[$name])) {
            ++$this->locks[$name]['depth'];
            $this->assertLock($name);
            return $name;
        }
        $row = $this->one('SELECT GET_LOCK(?, 5) AS acquired', [$name]);
        if (!in_array($row['acquired'] ?? null, [1, '1'], true)) { throw new \RuntimeException('busy'); }
        $this->locks[$name] = ['depth' => 1, 'connection' => \asaas_receipt_lock_connection($this->db)];
        return $name;
    }

    public function assertLock(string $name): void
    {
        if (!isset($this->locks[$name]) || $this->locks[$name]['connection'] !== \asaas_receipt_lock_connection($this->db)) {
            throw new \RuntimeException('busy');
        }
        $row = $this->one('SELECT (IS_USED_LOCK(?) = CONNECTION_ID()) AS owned', [$name]);
        if (!in_array($row['owned'] ?? null, [1, '1'], true)) { throw new \RuntimeException('busy'); }
    }

    public function unlock(string $name): void
    {
        if (!isset($this->locks[$name])) { return; }
        if (--$this->locks[$name]['depth'] > 0) { return; }
        try {
            if ($this->locks[$name]['connection'] === \asaas_receipt_lock_connection($this->db)) {
                $this->one('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        } finally { unset($this->locks[$name]); }
    }

    public function __destruct()
    {
        foreach (array_keys($this->locks) as $name) {
            try { $this->locks[$name]['depth'] = 1; $this->unlock($name); } catch (\Throwable $ignored) {}
        }
    }

    public function authorization(int $clientId, string $scopeKey): array
    {
        return $this->one('SELECT * FROM ' . $this->profiles . ' WHERE client_id = ? AND scope_key = ? LIMIT 1', [$clientId, $scopeKey]);
    }
    public function authorizationById(int $id): array
    {
        return $this->one('SELECT * FROM ' . $this->profiles . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /** The caller holds the profile lock. Revision CAS still protects against stale callers. */
    public function saveAuthorization(array $data, ?int $expectedRevision): array
    {
        $fields = ['client_id', 'scope_key', 'environment', 'company_id', 'customer_id', 'revision', 'state', 'token_ciphertext',
            'contact_id', 'consent_version', 'authorized_at', 'cutoff_invoice_id', 'pricing_policy_hash', 'reason_code',
            'revoked_at', 'updated_at'];
        $data = array_intersect_key($data, array_flip($fields));
        if ($expectedRevision === null) {
            $data['created_at'] = $data['updated_at'];
            $this->insert($this->profiles, $data);
        } else {
            $affected = $this->update($this->profiles, $data,
                'client_id = ? AND scope_key = ? AND revision = ?', [$data['client_id'], $data['scope_key'], $expectedRevision]);
            if ($affected !== 1) { throw new \RuntimeException('authorization_changed'); }
        }
        $saved = $this->authorization((int) $data['client_id'], $data['scope_key']);
        if ($saved === [] || (int) $saved['revision'] !== (int) $data['revision']) { throw new \RuntimeException('storage_unavailable'); }
        return $saved;
    }

    public function stopAuthorization(array $authorization, string $state, string $reason): void
    {
        $this->update($this->profiles, ['state' => $state, 'token_ciphertext' => null, 'reason_code' => $reason,
            'revoked_at' => $state === 'revoked' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ? AND revision = ? AND state IN (?, ?)', [(int) $authorization['id'], (int) $authorization['revision'], 'active', 'suspended']);
        $this->update($this->jobs, ['state' => 'skipped', 'reason_code' => $reason, 'updated_at' => date('Y-m-d H:i:s')],
            'authorization_id = ? AND authorization_revision = ? AND state = ?', [(int) $authorization['id'], (int) $authorization['revision'], 'queued']);
    }

    public function job(int $invoiceId): array
    {
        return $this->one('SELECT * FROM ' . $this->jobs . ' WHERE invoice_id = ? LIMIT 1', [$invoiceId]);
    }
    public function jobByPayment(string $paymentId): array
    {
        return $this->one('SELECT * FROM ' . $this->jobs . ' WHERE payment_id = ? LIMIT 1', [$paymentId]);
    }
    public function hasBlockingJob(int $authorizationId, int $revision, int $exceptInvoiceId): bool
    {
        $bindings = [$authorizationId, $exceptInvoiceId];
        if ($revision > 0) { $bindings[] = $revision; }
        return $this->one('SELECT invoice_id FROM ' . $this->jobs
            . " WHERE authorization_id = ? AND invoice_id <> ? AND state IN ('processing', 'uncertain')"
            . ($revision > 0 ? ' AND authorization_revision = ?' : '') . ' LIMIT 1', $bindings) !== [];
    }
    public function insertJob(array $data): bool
    {
        try { $this->insert($this->jobs, $data); return true; }
        catch (\Throwable $error) {
            // A competing after-hook may have enqueued the same invoice. Never replace its attempt.
            if ($this->job((int) $data['invoice_id']) !== []) { return false; }
            throw new \RuntimeException('storage_unavailable');
        }
    }

    public function updateJob(int $invoiceId, array $expectedStates, array $changes): bool
    {
        $changes = array_intersect_key($changes, array_flip(['authorization_id', 'authorization_revision', 'scope_key', 'customer_id',
            'explicit_current', 'state', 'phase', 'payment_id', 'reason_code', 'next_check_at', 'started_at', 'updated_at']));
        $changes['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($this->jobs, $changes, 'invoice_id = ? AND state IN ('
            . implode(', ', array_fill(0, count($expectedStates), '?')) . ')', array_merge([$invoiceId], $expectedStates)) === 1;
    }

    public function dueJobs(int $limit): array
    {
        return $this->rows('SELECT invoice_id FROM ' . $this->jobs
            . " WHERE state IN ('queued', 'processing', 'uncertain') AND (next_check_at IS NULL OR next_check_at <= ?)"
            . ' ORDER BY COALESCE(next_check_at, created_at), invoice_id LIMIT ' . max(1, min(10, $limit)), [date('Y-m-d H:i:s')]);
    }

    /** Repairs only newly emitted, explicitly eligible invoices; never enrolls historical debt. */
    public function missingJobs(int $limit): array
    {
        return $this->rows('SELECT i.id, MIN(a.id) AS authorization_id FROM ' . db_prefix() . 'invoices i JOIN ' . $this->profiles
            . " a ON a.client_id = i.clientid AND a.state = 'active' AND i.id > a.cutoff_invoice_id"
            . ' LEFT JOIN ' . $this->jobs . ' j ON j.invoice_id = i.id'
            . " WHERE j.invoice_id IS NULL AND i.status NOT IN (2, 5, 6) AND i.allowed_payment_modes LIKE ?"
            . ' GROUP BY i.id ORDER BY i.id LIMIT ' . max(1, min(40, $limit)), ['%asaas_recurring%']);
    }

    /** CodeIgniter's authenticated encryption; the JSON envelope binds ciphertext to its owner. */
    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        $cipher = $this->encryption()->encrypt($plaintext);
        if (!is_string($cipher) || $cipher === '' || $cipher === $plaintext) { throw new \RuntimeException('vault_unavailable'); }
        return $cipher;
    }
    public function unseal(#[\SensitiveParameter] string $ciphertext): string
    {
        $plain = $this->encryption()->decrypt($ciphertext);
        if (!is_string($plain) || $plain === '') { throw new \RuntimeException('vault_unavailable'); }
        return $plain;
    }
    private function encryption(): object
    {
        $ci = get_instance();
        if (!isset($ci->encryption)) { $ci->load->library('encryption'); }
        if (!isset($ci->encryption) || !is_callable([$ci->encryption, 'encrypt']) || !is_callable([$ci->encryption, 'decrypt'])) {
            throw new \RuntimeException('vault_unavailable');
        }
        return $ci->encryption;
    }

    private function one(string $sql, array $bindings = []): array { return $this->rows($sql, $bindings)[0] ?? []; }
    private function rows(string $sql, array $bindings = []): array
    {
        try {
            $query = \asaas_receipt_lock_quiet($this->db, fn () => $this->db->query($sql, $bindings));
            if (!is_object($query) || !is_array($rows = $query->result_array())) { throw new \RuntimeException('storage_unavailable'); }
            return $rows;
        } catch (\Throwable $error) { throw new \RuntimeException('storage_unavailable'); }
    }
    private function insert(string $table, array $data): void
    {
        $this->execute('INSERT INTO ' . $table . ' (`' . implode('`, `', array_keys($data)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
    }
    private function update(string $table, array $data, string $where, array $bindings): int
    {
        $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', array_map(static fn ($field): string => '`' . $field . '` = ?', array_keys($data)))
            . ' WHERE ' . $where, array_merge(array_values($data), $bindings));
        return (int) $this->db->affected_rows();
    }
    private function execute(string $sql, array $bindings): void
    {
        try {
            if (\asaas_receipt_lock_quiet($this->db, fn () => $this->db->query($sql, $bindings)) === false) {
                throw new \RuntimeException('storage_unavailable');
            }
        } catch (\Throwable $error) { throw new \RuntimeException('storage_unavailable'); }
    }
}
