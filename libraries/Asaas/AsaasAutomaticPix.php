<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_card_helper.php';
require_once __DIR__ . '/../../helpers/asaas_automatic_pix_helper.php';
require_once __DIR__ . '/AsaasAutomaticPixStore.php';
require_once __DIR__ . '/AsaasAutomaticPixApi.php';
require_once __DIR__ . '/AsaasAutomaticPixCalendar.php';

/** Additive MANUAL Pix mandate. The CRM continues to own issuance and receipt accounting. */
final class AsaasAutomaticPix
{
    public const CONSENT_VERSION = '2026-11-pix-v1';
    public const FREQUENCIES = ['WEEKLY' => 'Semanal', 'MONTHLY' => 'Mensal', 'QUARTERLY' => 'Trimestral', 'SEMIANNUALLY' => 'Semestral', 'ANNUALLY' => 'Anual'];
    private const AUTH_STATES = ['preparing', 'creating', 'created', 'active', 'cancelling', 'cancelled', 'refused', 'expired', 'uncertain', 'failed'];
    private const JOB_STATES = ['queued', 'preparing', 'creating', 'waiting', 'uncertain', 'confirmed', 'failed', 'skipped', 'cancelled'];
    private object $store;
    private ?object $api;
    private \Closure $clock;
    private ?float $deadline = null;

    public function __construct(private object $gateway, ?object $store = null, ?object $api = null, ?callable $clock = null)
    {
        $this->store = $store ?? new AsaasAutomaticPixStore(); $this->api = $api;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
    }
    private function api(): object { $this->budget(); return $this->api ??= new AsaasAutomaticPixApi($this->gateway->getProvider()); }
    private function now(): \DateTimeImmutable { return ($this->clock)()->setTimezone(new \DateTimeZone('America/Sao_Paulo')); }
    private function later(int $seconds = 300): string { return date('Y-m-d H:i:s', time() + $seconds); }
    private function budget(): void { if ($this->deadline !== null && microtime(true) >= $this->deadline) { throw new \RuntimeException('review_pending'); } }
    private function scope(object $invoice): array { return \asaas_automatic_pix_scope($invoice, $this->gateway); }
    private function enabled(): bool { return $this->store->enabled() && (string) $this->gateway->getSetting('webhook_secret') !== ''; }
    private function open(object $invoice): bool
    {
        return (int) ($invoice->id ?? 0) > 0 && !in_array((int) ($invoice->status ?? 0), [2, 5, 6], true)
            && \asaas_payment_amount_to_cents($this->store->balance($invoice)) > 0;
    }
    private function brl(object $invoice): bool
    {
        $currency = strtoupper((string) ($invoice->currency_name ?? ''));
        if ($currency === '' && function_exists('get_currency') && isset($invoice->currency)) {
            $record = get_currency($invoice->currency); $currency = strtoupper((string) (is_object($record) ? ($record->name ?? '') : ($record['name'] ?? '')));
        }
        return $currency === 'BRL';
    }
    private function modes(object $invoice): array { return \asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []); }
    public function available(object $invoice): bool
    {
        return $this->store->ready() && $this->enabled() && $this->brl($invoice) && $this->open($invoice)
            && array_intersect($this->modes($invoice), ['asaas', 'asaas_automatic_pix']) !== [];
    }

    /** The read model never initiates HTTP or financial work. The controller enforces ownership. */
    public function page(object $invoice): array
    {
        $page = ['state' => 'unavailable', 'message' => \asaas_automatic_pix_message('unavailable'), 'can_enroll' => false,
            'can_cancel' => false, 'can_refresh' => false, 'can_pay_now' => false, 'payload' => '', 'encoded_image' => '',
            'expires_at' => '', 'quote' => '', 'amount' => '', 'frequency' => 'MONTHLY', 'consent_version' => self::CONSENT_VERSION,
            'consent_text' => 'Autorizo o pagamento inicial pelo valor exibido e o agendamento das novas faturas Asaas deste cliente na frequência escolhida. '
                . 'A cobrança em aberto desta fatura será substituída pelo QR Code de adesão. A autorização deve ser confirmada no aplicativo do meu banco. '
                . 'Faturas anteriores não serão incluídas automaticamente. Posso solicitar o cancelamento; operações já enviadas dependem da confirmação do banco.'];
        try {
            if (!$this->store->ready()) { throw new \RuntimeException('storage_unavailable'); }
            $scope = $this->scope($invoice);
            $authorization = $this->store->authorization((int) $invoice->clientid, $scope['key']);
            $job = $this->store->job((int) $invoice->id);
            $blocked = in_array($job['state'] ?? '', ['preparing', 'creating', 'waiting', 'uncertain'], true);
            $page['state'] = $authorization['state'] ?? 'inactive';
            $page['can_refresh'] = $authorization !== [];
            $page['can_cancel'] = $authorization !== [] && !empty($authorization['remote_id'])
                && empty($authorization['cancel_requested_at']) && in_array($authorization['state'], ['created', 'active', 'uncertain'], true);
            $page['can_pay_now'] = $this->open($invoice) && !$blocked;
            $page['frequency'] = $authorization['frequency'] ?? 'MONTHLY';
            $page['amount'] = \asaas_card_money(\asaas_payment_amount_to_cents($this->store->balance($invoice)));
            $page['quote'] = $this->snapshot($invoice);
            $page['can_enroll'] = $this->available($invoice) && !$this->store->clientBlocks((int) $invoice->clientid, $scope['key'])
                && !$this->store->cardBlocks((int) $invoice->clientid, $scope['key']) && !\asaas_card_attempt_blocks($this->store->cache((int) $invoice->id));
            if ($authorization !== [] && (int) $authorization['initial_invoice_id'] === (int) $invoice->id
                && $authorization['initial_invoice_hash'] === (string) $invoice->hash && $authorization['state'] === 'created'
                && empty($authorization['cancel_requested_at']) && !empty($authorization['qrcode_ciphertext'])
                && $this->validUntil((string) $authorization['expires_at'])) {
                $qr = json_decode($this->store->unseal($authorization['qrcode_ciphertext']), true, 8, JSON_THROW_ON_ERROR);
                if (($qr['contract_id'] ?? '') !== $authorization['contract_id'] || ($qr['invoice_hash'] ?? '') !== (string) $invoice->hash) { throw new \RuntimeException('charge_mismatch'); }
                $page['payload'] = (string) ($qr['payload'] ?? ''); $page['encoded_image'] = (string) ($qr['image'] ?? '');
                $page['expires_at'] = (string) $authorization['expires_at'];
                $page['amount'] = \asaas_card_money((int) $authorization['initial_amount_cents']);
            }
            $reason = $page['state'];
            if ($blocked && !($page['state'] === 'created' && $page['payload'] !== '')) { $reason = $job['reason_code'] ?: $job['state']; }
            if (($job['state'] ?? '') === 'queued') { $reason = $job['reason_code'] ?: 'queued'; }
            if (($job['state'] ?? '') === 'confirmed') { $reason = 'confirmed'; }
            if ($authorization === [] && $this->store->cardBlocks((int) $invoice->clientid, $scope['key'])) { $reason = 'other_mandate'; }
            $page['message'] = \asaas_automatic_pix_message($reason);
        } catch (\Throwable $error) {
            $page['can_enroll'] = $page['can_cancel'] = $page['can_pay_now'] = false;
            $page['message'] = \asaas_automatic_pix_message($this->reason($error));
        }
        return $page;
    }

    /** Explicit customer consent creates a short-lived QR, not an active mandate or a receipt. */
    public function enroll(object $invoice, array $consent): array
    {
        $lock = null; $profile = []; $invoiceId = (int) ($invoice->id ?? 0); $phase = 'validate';
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            if (!$this->available($invoice)) { throw new \RuntimeException('unavailable'); }
            if (function_exists('is_https') && !is_https()) { throw new \RuntimeException('https_required'); }
            if (($consent['accepted'] ?? false) !== true || ($consent['terms_version'] ?? '') !== self::CONSENT_VERSION) { throw new \RuntimeException('invalid_consent'); }
            $frequency = $consent['frequency'] ?? '';
            if (!is_string($frequency) || !isset(self::FREQUENCIES[$frequency])) { throw new \RuntimeException('invalid_frequency'); }
            if (!$this->store->contactBelongs((int) ($consent['contact_id'] ?? 0), (int) $invoice->clientid)) { throw new \RuntimeException('contact_mismatch'); }
            $scope = $this->scope($invoice); $lock = $this->store->lock((int) $invoice->clientid, $scope['key']);
            \asaas_receipt_lock($invoiceId);
            $invoice = $this->fresh($invoice);
            if (!$this->available($invoice) || $scope !== $this->scope($invoice)) { throw new \RuntimeException('invoice_changed'); }
            if (!is_string($consent['quote'] ?? null) || !hash_equals($this->snapshot($invoice), $consent['quote'])) { throw new \RuntimeException('quote_changed'); }
            if ($this->store->clientBlocks((int) $invoice->clientid, $scope['key'])) { throw new \RuntimeException('existing_authorization'); }
            if ($this->store->cardBlocks((int) $invoice->clientid, $scope['key']) || \asaas_card_attempt_blocks($this->store->cache($invoiceId))) { throw new \RuntimeException('other_mandate'); }
            $oldJob = $this->store->job($invoiceId);
            if ($oldJob !== [] && !in_array($oldJob['state'], ['failed', 'skipped', 'cancelled'], true)) { throw new \RuntimeException('existing_authorization'); }
            $amount = \asaas_payment_amount_to_cents($this->store->balance($invoice));
            \asaas_payment_validate_payload(['billingType' => 'PIX', 'value' => \asaas_card_money($amount)]);
            $charges = $this->charges($invoice);
            if (count($charges) > 1) { throw new \RuntimeException('charge_mismatch'); }
            $charge = $charges[0] ?? [];
            if ($charge !== []) { $this->assertReplaceable($invoice, $charge, $amount); $customer = $charge['customer']; }
            else {
                $created = $this->gateway->ensure_customer_from_client($invoice->client ?? (object) ['userid' => $invoice->clientid]);
                $customer = (string) ($created['customerId'] ?? '');
            }
            if (!preg_match('/^cus_[A-Za-z0-9_-]{1,100}$/D', $customer)) { throw new \RuntimeException('charge_mismatch'); }
            $this->store->assertLock($lock);
            $profile = $this->store->insertAuthorization([
                'client_id' => (int) $invoice->clientid, 'scope_key' => $scope['key'], 'environment' => $scope['environment'], 'company_id' => $scope['company_id'],
                'customer_id' => $customer, 'initial_invoice_id' => $invoiceId, 'initial_invoice_hash' => (string) $invoice->hash,
                'initial_amount_cents' => $amount, 'cutoff_invoice_id' => $this->store->maxInvoiceId(), 'contract_id' => 'pf' . bin2hex(random_bytes(16)),
                'state' => 'preparing', 'frequency' => $frequency, 'start_date' => $this->now()->format('Y-m-d'),
                'contact_id' => (int) $consent['contact_id'], 'consent_version' => self::CONSENT_VERSION, 'authorized_at' => date('Y-m-d H:i:s'),
                'next_check_at' => $this->later(), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $job = $this->jobData($invoice, $profile, 'initial');
            $job['state'] = 'preparing'; $job['previous_payment_id'] = $charge['id'] ?? null;
            if ($oldJob === []) { $saved = $this->store->insertJob($job); }
            else { $saved = $this->store->updateJob($invoiceId, [$oldJob['state']], $job); }
            if (!$saved) { throw new \RuntimeException('storage_unavailable'); }
            $phase = 'remove';
            if ($charge !== []) { $this->removePrevious($invoice, $charge, $job); }
            $this->store->assertLock($lock);
            if (!$this->enabled() || !hash_equals($this->snapshot($this->fresh($invoice)), $consent['quote'])) { throw new \RuntimeException('invoice_changed'); }
            if (!$this->store->updateAuthorization((int) $profile['id'], ['preparing'], ['state' => 'creating'])
                || !$this->store->updateJob($invoiceId, ['preparing'], ['state' => 'creating', 'phase' => 'authorizing'])) { throw new \RuntimeException('storage_unavailable'); }
            $phase = 'authorization';
            $response = $this->api()->createAuthorization([
                'customerId' => $customer, 'frequency' => $frequency, 'contractId' => $profile['contract_id'],
                'startDate' => $profile['start_date'], 'description' => 'Fatura e pagamentos recorrentes', 'paymentCreationMode' => 'MANUAL', 'retryPolicy' => 'NOT_ALLOWED',
                'immediateQrCode' => ['expirationSeconds' => 600, 'originalValue' => \asaas_card_money($amount)],
            ]);
            $profile = $this->store->authorizationById((int) $profile['id']);
            $this->acceptAuthorization($profile, $response);
            $this->store->updateJob($invoiceId, ['creating'], ['state' => 'waiting', 'phase' => 'initial_qr', 'reason_code' => 'created']);
            \asaas_automatic_pix_diagnostic('created', $invoiceId);
            return $this->result(true, 'created', 'created');
        } catch (\Throwable $error) {
            $reason = $this->reason($error);
            if ($profile !== []) {
                $knownRejected = $error instanceof AsaasAutomaticPixRequestException && $error->rejected();
                $state = ($phase === 'validate' || $knownRejected) ? 'failed' : 'uncertain';
                $this->safeStop($profile, $invoiceId, $state, $reason);
            }
            \asaas_automatic_pix_diagnostic($reason, $invoiceId);
            return $this->result(false, $profile === [] ? 'unavailable' : ($state ?? 'uncertain'), $reason);
        } finally {
            \asaas_receipt_unlock($invoiceId);
            if ($lock !== null) { $this->release($lock); }
        }
    }

    /** Revocation is journaled before DELETE; an uncertain result is only polled afterward. */
    public function cancel(object $invoice): array
    {
        $lock = null; $profile = []; $submitted = false; $mutationCompleted = false;
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            $invoice = $this->fresh($invoice); $scope = $this->scope($invoice);
            $lock = $this->store->lock((int) $invoice->clientid, $scope['key']);
            $profile = $this->store->authorization((int) $invoice->clientid, $scope['key']);
            if ($profile === [] || empty($profile['remote_id'])) { throw new \RuntimeException('authorization_changed'); }
            if (!empty($profile['cancel_requested_at'])) { return $this->refreshLocked($invoice, $profile); }
            if (!in_array($profile['state'], ['created', 'active', 'uncertain'], true)) { return $this->result(true, $profile['state'], $profile['state']); }
            if (!$this->store->updateAuthorization((int) $profile['id'], [$profile['state']], [
                'state' => 'cancelling', 'cancel_requested_at' => date('Y-m-d H:i:s'), 'next_check_at' => $this->later(),
            ])) { throw new \RuntimeException('busy'); }
            $this->store->assertLock($lock);
            $submitted = true;
            $this->api()->cancelAuthorization($profile['remote_id']);
            $mutationCompleted = true;
            return $this->refreshLocked($invoice, $this->store->authorizationById((int) $profile['id']));
        } catch (\Throwable $error) {
            if ($submitted && !$mutationCompleted && $profile !== [] && $error instanceof AsaasAutomaticPixRequestException && $error->rejected()) {
                $this->store->updateAuthorization((int) $profile['id'], ['cancelling'], [
                    'state' => $profile['state'], 'cancel_requested_at' => null, 'reason_code' => 'request_rejected', 'next_check_at' => $this->later(),
                ]);
                return $this->result(false, $profile['state'], 'request_rejected');
            }
            \asaas_automatic_pix_diagnostic('cancelling', (int) $invoice->id);
            return $this->result(false, 'cancelling', 'cancelling');
        } finally { if ($lock !== null) { $this->release($lock); } }
    }

    /** A customer refresh performs only reads at Asaas; queued payments remain for the worker. */
    public function refresh(object $invoice): array
    {
        $lock = null;
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            $invoice = $this->fresh($invoice); $scope = $this->scope($invoice);
            $lock = $this->store->lock((int) $invoice->clientid, $scope['key']);
            $profile = $this->store->authorization((int) $invoice->clientid, $scope['key']);
            if ($profile === []) { throw new \RuntimeException('unavailable'); }
            $outcome = $this->refreshLocked($invoice, $profile);
        } catch (\Throwable $error) { return $this->result(false, 'uncertain', $this->reason($error)); }
        finally { if ($lock !== null) { $this->release($lock); } }
        $job = $this->store->job((int) $invoice->id);
        return $job !== [] && !in_array($job['state'], ['queued', 'confirmed', 'failed', 'skipped', 'cancelled'], true)
            ? $this->processInvoice((int) $invoice->id, true) : $outcome;
    }

    private function refreshLocked(object $invoice, array $profile): array
    {
        if ($profile['scope_key'] !== $this->scope($invoice)['key']) { throw new \RuntimeException('scope_changed'); }
        $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['next_check_at' => $this->later()]);
        if (!empty($profile['remote_id'])) { $remote = $this->api()->authorization($profile['remote_id']); }
        else {
            // A timed-out POST is never repeated. Search its persisted unique contract identifier.
            $page = $this->api()->authorizations(['customerId' => $profile['customer_id'], 'offset' => (int) $profile['scan_offset'], 'limit' => 50]);
            $rows = $this->listing($page, true, (int) $profile['scan_offset']); $matches = array_values(array_filter($rows, static fn ($row): bool => is_array($row) && ($row['contractId'] ?? '') === $profile['contract_id']));
            if (count($matches) > 1) { throw new \RuntimeException('charge_mismatch'); }
            $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['scan_offset' => $page['hasMore'] ? (int) $profile['scan_offset'] + count($rows) : 0]);
            if ($matches === []) { return $this->result(false, 'uncertain', 'uncertain'); }
            $remote = $this->api()->authorization((string) $matches[0]['id']);
        }
        $this->acceptAuthorization($profile, $remote);
        $current = $this->store->authorizationById((int) $profile['id']);
        return $this->result(true, $current['state'], $current['state']);
    }

    private function acceptAuthorization(array $profile, array $remote): void
    {
        if (!is_string($remote['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $remote['id'])
            || (!empty($profile['remote_id']) && $profile['remote_id'] !== $remote['id'])
            || ($remote['customerId'] ?? '') !== $profile['customer_id'] || ($remote['contractId'] ?? '') !== $profile['contract_id']
            || ($remote['frequency'] ?? '') !== $profile['frequency'] || ($remote['startDate'] ?? '') !== $profile['start_date']
            || (!empty($remote['subscriptionId'])) || (isset($remote['paymentCreationMode']) && $remote['paymentCreationMode'] !== 'MANUAL')) { throw new \RuntimeException('charge_mismatch'); }
        $status = $remote['status'] ?? '';
        $states = ['CREATED' => 'created', 'ACTIVE' => 'active', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired', 'REFUSED' => 'refused'];
        if (!is_string($status) || !isset($states[$status])) { throw new \RuntimeException('uncertain'); }
        if ((in_array($profile['state'], ['cancelled', 'expired', 'refused'], true) && $profile['state'] !== $states[$status])
            || ($profile['state'] === 'active' && $status === 'CREATED')) { throw new \RuntimeException('authorization_changed'); }
        $state = !empty($profile['cancel_requested_at']) && in_array($status, ['CREATED', 'ACTIVE'], true) ? 'cancelling' : $states[$status];
        $changes = ['remote_id' => $remote['id'], 'remote_status' => $status, 'state' => $state, 'reason_code' => null, 'next_check_at' => $this->later($state === 'active' ? 3600 : 300)];
        if (in_array($state, ['cancelled', 'expired', 'refused'], true) && !in_array($profile['state'], ['cancelled', 'expired', 'refused'], true)) {
            $changes['terminal_scan_started_at'] = null; $changes['payment_scan_offset'] = 0;
        }
        $conciliation = $remote['immediateQrCode']['conciliationIdentifier'] ?? $profile['conciliation_identifier'] ?? '';
        if (!is_string($conciliation) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $conciliation)
            || (!empty($profile['conciliation_identifier']) && $profile['conciliation_identifier'] !== $conciliation)) { throw new \RuntimeException('charge_mismatch'); }
        $changes['conciliation_identifier'] = $conciliation;
        $expires = $remote['immediateQrCode']['expirationDate'] ?? $profile['expires_at'] ?? '';
        if (!is_string($expires) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $expires)) { throw new \RuntimeException('charge_mismatch'); }
        $expiration = new \DateTimeImmutable($expires, new \DateTimeZone('America/Sao_Paulo'));
        $changes['expires_at'] = $expiration->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        if ($state === 'created' && $this->validUntil($changes['expires_at'])) {
            $payload = $remote['payload'] ?? ''; $image = $remote['encodedImage'] ?? '';
            if (!is_string($payload) || strlen($payload) < 20 || strlen($payload) > 8192 || preg_match('/[\x00-\x1F]/', $payload)
                || !is_string($image) || strlen($image) > 262144 || ($image !== '' && base64_decode($image, true) === false)) { throw new \RuntimeException('charge_mismatch'); }
            $changes['qrcode_ciphertext'] = $this->store->seal(json_encode(['contract_id' => $profile['contract_id'],
                'invoice_hash' => $profile['initial_invoice_hash'], 'payload' => $payload, 'image' => $image], JSON_THROW_ON_ERROR));
        } else { $changes['qrcode_ciphertext'] = null; }
        $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, $changes);
    }

    public function authorizesNewInvoice(array $data): bool
    {
        $invoice = (object) $data;
        if (!$this->store->ready() || !$this->enabled() || !$this->brl($invoice)
            || array_intersect($this->modes($invoice), ['asaas', 'asaas_automatic_pix']) === []) { return false; }
        $scope = $this->scope($invoice); $profile = $this->store->authorization((int) $invoice->clientid, $scope['key']);
        return $profile !== [] && $profile['state'] === 'active' && empty($profile['cancel_requested_at'])
            && !$this->store->cardBlocks((int) $invoice->clientid, $scope['key']);
    }

    /** No API/commit inside the invoice's own transaction. Old invoices are never silently enrolled. */
    public function enqueue(int $invoiceId): bool
    {
        $invoice = $this->store->invoice($invoiceId);
        if (!$invoice || !$this->available($invoice) || !in_array('asaas_automatic_pix', $this->modes($invoice), true)) { return false; }
        $scope = $this->scope($invoice); $profile = $this->store->authorization((int) $invoice->clientid, $scope['key']);
        if ($profile === [] || $profile['state'] !== 'active' || !empty($profile['cancel_requested_at'])
            || $invoiceId <= (int) $profile['cutoff_invoice_id'] || $this->store->cardBlocks((int) $invoice->clientid, $scope['key'])) { return false; }
        $job = $this->jobData($invoice, $profile, 'cycle');
        if ($this->store->job($invoiceId) !== []) { return false; }
        if ($this->store->jobForCycle((int) $profile['id'], $job['cycle_key']) !== []) {
            $job['cycle_key'] = null; $job['state'] = 'skipped'; $job['reason_code'] = 'cycle_exists';
        }
        return $this->store->insertJob($job);
    }

    private function jobData(object $invoice, array $profile, string $kind): array
    {
        $due = $kind === 'initial' ? $profile['start_date'] : (string) ($invoice->duedate ?? '');
        $this->calendar()->date($due);
        return ['invoice_id' => (int) $invoice->id, 'invoice_hash' => (string) $invoice->hash, 'client_id' => (int) $invoice->clientid,
            'scope_key' => $profile['scope_key'], 'authorization_id' => (int) $profile['id'], 'kind' => $kind, 'state' => 'queued', 'phase' => 'queued',
            'principal_cents' => \asaas_payment_amount_to_cents($this->store->balance($invoice)), 'due_date' => $due,
            'cycle_key' => $this->calendar()->cycle($due, $profile['frequency'], $profile['start_date']), 'reason_code' => null,
            'previous_payment_id' => null, 'payment_id' => null, 'instruction_id' => null, 'instruction_status' => null, 'started_at' => null,
            'next_check_at' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }

    public function processInvoice(int $invoiceId, bool $readOnly = false): array
    {
        $lock = null; $job = []; $mutationInFlight = false;
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            $job = $this->store->job($invoiceId);
            if ($job === []) { return $this->result(false, 'skipped', 'skipped'); }
            if (in_array($job['state'], ['queued', 'preparing', 'creating', 'waiting', 'uncertain'], true)) {
                $this->store->updateJob($invoiceId, [$job['state']], ['next_check_at' => $this->later()]);
            }
            $lock = $this->store->lock((int) $job['client_id'], $job['scope_key']); \asaas_receipt_lock($invoiceId);
            $job = $this->store->job($invoiceId);
            if (in_array($job['state'], ['confirmed', 'failed', 'skipped', 'cancelled'], true)) { return $this->result(true, $job['state'], $job['reason_code'] ?: $job['state']); }
            $invoice = $this->store->invoice($invoiceId); $profile = $this->store->authorizationById((int) $job['authorization_id']);
            $this->assertJob($invoice, $job, $profile);
            if ($job['state'] !== 'queued') { return $this->reconcile($invoice, $job, $profile); }
            if ($readOnly) { return $this->result(true, 'queued', 'queued'); }
            if (!$this->enabled() || !$this->open($invoice) || !in_array('asaas_automatic_pix', $this->modes($invoice), true)
                || $profile['state'] !== 'active' || !empty($profile['cancel_requested_at'])) { return $this->skip($job, 'authorization_changed'); }
            if ($this->store->cardBlocks((int) $invoice->clientid, $job['scope_key']) || \asaas_card_attempt_blocks($this->store->cache($invoiceId))) { return $this->skip($job, 'other_mandate'); }
            $due = (string) ($invoice->duedate ?? ''); $remaining = $this->calendar()->businessDays($this->now()->format('Y-m-d'), $due);
            if ($remaining < 2) { return $this->skip($job, 'outside_window'); }
            if ($remaining > 10) {
                $this->store->updateJob($invoiceId, ['queued'], ['next_check_at' => $this->later(21600), 'reason_code' => 'queued']);
                return $this->result(true, 'queued', 'queued');
            }
            // Recheck authorization from the bank before every new cycle, even if webhooks were missed.
            $this->refreshLocked($invoice, $profile); $profile = $this->store->authorizationById((int) $profile['id']);
            if ($profile['state'] !== 'active' || !empty($profile['cancel_requested_at'])) { return $this->skip($job, 'authorization_changed'); }
            if (($this->store->job((int) $profile['initial_invoice_id'])['state'] ?? '') !== 'confirmed') {
                return $this->result(false, 'queued', 'receipt_pending');
            }
            $snapshot = $this->snapshot($invoice); $amount = \asaas_payment_amount_to_cents($this->store->balance($invoice));
            $charges = $this->charges($invoice);
            if (count($charges) > 1) { throw new \RuntimeException('charge_mismatch'); }
            $charge = $charges[0] ?? [];
            if ($charge !== []) { $this->assertReplaceable($invoice, $charge, $amount); if ($charge['customer'] !== $profile['customer_id']) { throw new \RuntimeException('charge_mismatch'); } }
            $payload = ['customer' => $profile['customer_id'], 'billingType' => 'PIX', 'value' => \asaas_card_money($amount),
                'dueDate' => $due, 'externalReference' => (string) $invoice->hash, 'pixAutomaticAuthorizationId' => $profile['remote_id'],
                'description' => 'Pagamento da fatura ' . (string) format_invoice_number($invoiceId),
                'fine' => ['value' => $this->gateway->getSetting('fine_value') ?: 0], 'interest' => ['value' => $this->gateway->getSetting('interest_value') ?: 0]];
            if (is_callable([$this->gateway, 'card_checkout'])) { $payload['discount'] = $this->gateway->card_checkout()->discount(); }
            \asaas_payment_validate_payload($payload);
            $cycle = $this->calendar()->cycle($due, $profile['frequency'], $profile['start_date']);
            $otherCycle = $this->store->jobForCycle((int) $profile['id'], $cycle);
            if ($otherCycle !== [] && (int) $otherCycle['invoice_id'] !== $invoiceId) { return $this->skip($job, 'cycle_exists'); }
            if (!$this->store->updateJob($invoiceId, ['queued'], ['state' => 'preparing', 'phase' => 'removing', 'principal_cents' => $amount,
                'due_date' => $due, 'cycle_key' => $cycle, 'previous_payment_id' => $charge['id'] ?? null, 'started_at' => date('Y-m-d H:i:s')])) { throw new \RuntimeException('busy'); }
            $job = $this->store->job($invoiceId);
            if ($charge !== []) { $this->removePrevious($invoice, $charge, $job); }
            $this->store->assertLock($lock);
            if (!$this->enabled() || $snapshot !== $this->snapshot($this->fresh($invoice))) { throw new \RuntimeException('invoice_changed'); }
            if (!$this->store->updateJob($invoiceId, ['preparing'], ['state' => 'creating', 'phase' => 'payment'])) { throw new \RuntimeException('storage_unavailable'); }
            $job = $this->store->job($invoiceId);
            $mutationInFlight = true;
            $payment = $this->api()->createPayment($payload);
            $mutationInFlight = false;
            $this->assertCyclePayment($invoice, $profile, $job, $payment);
            $this->bind($invoice, $job, $payment);
            return $this->result(true, 'waiting', 'waiting');
        } catch (\Throwable $error) {
            $reason = $this->reason($error);
            if ($job !== []) {
                $current = $this->store->job($invoiceId);
                $rejected = $mutationInFlight && $error instanceof AsaasAutomaticPixRequestException && $error->rejected();
                if (($current['state'] ?? '') !== 'queued') { $this->store->updateJob($invoiceId, ['preparing', 'creating', 'waiting', 'uncertain'], [
                    'state' => $rejected ? 'failed' : 'uncertain', 'reason_code' => $reason, 'next_check_at' => $this->later(),
                ]); }
            }
            \asaas_automatic_pix_diagnostic($reason, $invoiceId);
            return $this->result(false, ($rejected ?? false) ? 'failed' : 'uncertain', $reason);
        } finally { \asaas_receipt_unlock($invoiceId); if ($lock !== null) { $this->release($lock); } }
    }

    private function reconcile(object $invoice, array $job, array $profile): array
    {
        // The journal proves no authorization/payment POST was started in this phase.
        // Recover the preceding DELETE by reading its charge, without repeating either mutation.
        if (in_array($job['phase'], ['queued', 'removing'], true) && $job['state'] !== 'queued') {
            return $this->recoverPreparation($invoice, $job, $profile);
        }
        if ($job['kind'] === 'initial') {
            if (empty($profile['remote_id']) || empty($profile['conciliation_identifier'])) {
                $this->refreshLocked($invoice, $profile); $profile = $this->store->authorizationById((int) $profile['id']);
                if (empty($profile['remote_id']) || empty($profile['conciliation_identifier'])) { return $this->result(false, 'uncertain', 'uncertain'); }
            }
            $terminalReady = in_array($profile['state'], ['cancelled', 'refused', 'expired'], true)
                && !empty($profile['expires_at']) && !$this->validUntil($profile['expires_at'], 300);
            if ($terminalReady && empty($profile['terminal_scan_started_at'])) {
                // A scan begun while the QR was payable cannot prove absence of a late first payment.
                $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, [
                    'payment_scan_offset' => 0, 'terminal_scan_started_at' => date('Y-m-d H:i:s'),
                ]);
                $profile = $this->store->authorizationById((int) $profile['id']);
            }
            $payment = !empty($job['payment_id']) ? $this->api()->payment($job['payment_id']) : $this->findInitialPayment($profile);
            if ($payment !== null) {
                $this->assertInitialPayment($invoice, $profile, $payment);
                $this->bind($invoice, $job, $payment); $job = $this->store->job((int) $invoice->id);
                return $this->settle($invoice, $job, $payment);
            }
            // Only an authoritative terminal authorization + complete scan + expired QR releases fallback.
            if ($terminalReady && !empty($profile['terminal_scan_started_at'])
                && (int) ($this->store->authorizationById((int) $profile['id'])['payment_scan_offset'] ?? 1) === 0) {
                $this->store->updateJob((int) $invoice->id, self::JOB_STATES, ['state' => 'cancelled', 'reason_code' => $profile['state']]);
                return $this->result(true, 'cancelled', $profile['state']);
            }
            return $this->result(false, 'waiting', $profile['state'] === 'created' ? 'created' : 'waiting');
        }
        if (!empty($job['payment_id'])) { $payment = $this->api()->payment($job['payment_id']); }
        else {
            $page = $this->api()->payments(['externalReference' => $job['invoice_hash'], 'customer' => $profile['customer_id'], 'limit' => 100]);
            $rows = $this->listing($page, true, 0);
            if ($page['hasMore']) { throw new \RuntimeException('charge_mismatch'); }
            $rows = array_values(array_filter($rows, static fn ($p): bool => is_array($p) && empty($p['deleted']) && ($p['id'] ?? '') !== ($job['previous_payment_id'] ?? '')));
            if (count($rows) !== 1) { return $this->result(false, 'uncertain', 'uncertain'); }
            $payment = $rows[0];
        }
        $this->assertCyclePayment($invoice, $profile, $job, $payment);
        $this->bind($invoice, $job, $payment); $job = $this->store->job((int) $invoice->id);
        if ($this->received($payment)) { return $this->settle($invoice, $job, $payment); }
        $instructions = $this->api()->instructions($payment['id']);
        $rows = $this->listing($instructions, true, 0);
        if (($instructions['hasMore'] ?? false) === true) { throw new \RuntimeException('uncertain'); }
        $states = [];
        foreach ($rows as $instruction) {
            if (!is_array($instruction) || ($instruction['paymentId'] ?? '') !== $payment['id']
                || ($instruction['authorization']['id'] ?? '') !== $profile['remote_id']) { throw new \RuntimeException('charge_mismatch'); }
            $states[] = $instruction['status'] ?? '';
        }
        $last = $rows[count($rows) - 1] ?? [];
        if ($last !== []) { $this->store->updateJob((int) $invoice->id, ['waiting', 'uncertain'], [
            'instruction_id' => substr((string) ($last['id'] ?? ''), 0, 128), 'instruction_status' => substr((string) ($last['status'] ?? ''), 0, 32),
        ]); }
        // DONE is not proof of receipt. Only payment RECEIVED/CONFIRMED may reach settle().
        if ($states !== [] && array_diff($states, ['REFUSED', 'CANCELLED']) === [] && in_array($payment['status'] ?? '', ['PENDING', 'OVERDUE'], true)) {
            $reason = in_array('REFUSED', $states, true) ? 'instruction_refused' : 'instruction_cancelled';
            $this->store->updateJob((int) $invoice->id, ['waiting', 'uncertain'], ['state' => 'failed', 'reason_code' => $reason]);
            return $this->result(false, 'failed', $reason);
        }
        $this->store->updateJob((int) $invoice->id, ['waiting', 'uncertain'], ['state' => 'waiting', 'reason_code' => in_array('SCHEDULED', $states, true) ? 'scheduled' : 'waiting']);
        return $this->result(true, 'waiting', in_array('SCHEDULED', $states, true) ? 'scheduled' : 'waiting');
    }

    private function recoverPreparation(object $invoice, array $job, array $profile): array
    {
        if (!empty($job['previous_payment_id'])) {
            $payment = $this->api()->payment($job['previous_payment_id']);
            if (($payment['id'] ?? '') !== $job['previous_payment_id'] || ($payment['externalReference'] ?? '') !== (string) $invoice->hash
                || ($payment['customer'] ?? '') !== $profile['customer_id']) { throw new \RuntimeException('charge_mismatch'); }
            if ($this->received($payment)) {
                if (!in_array($payment['billingType'] ?? '', ['BOLETO', 'PIX', 'UNDEFINED'], true) || !empty($payment['installment']) || !empty($payment['subscription'])
                    || \asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null) !== (int) $job['principal_cents']) { throw new \RuntimeException('receipt_conflict'); }
                if (!$this->store->receiptExists((int) $invoice->id, $payment['id'], (int) $job['principal_cents'])) {
                    if (!$this->open($invoice) || \asaas_payment_amount_to_cents($this->store->balance($invoice)) !== (int) $job['principal_cents']) { throw new \RuntimeException('receipt_conflict'); }
                    if (!$this->gateway->addPayment(['invoiceid' => (int) $invoice->id, 'transactionid' => $payment['id'], 'amount' => \asaas_card_money((int) $job['principal_cents']),
                        'paymentmethod' => (string) ($payment['billingType'] ?? 'Asaas')])) { throw new \RuntimeException('receipt_pending'); }
                }
                if (!$this->store->receiptExists((int) $invoice->id, $payment['id'], (int) $job['principal_cents'])) { throw new \RuntimeException('receipt_pending'); }
                $this->store->updateJob((int) $invoice->id, self::JOB_STATES, ['state' => 'confirmed', 'reason_code' => 'confirmed']);
                if ($job['kind'] === 'initial') { $this->store->updateAuthorization((int) $profile['id'], ['preparing', 'uncertain'], ['state' => 'failed', 'reason_code' => 'invoice_changed']); }
                return $this->result(true, 'confirmed', 'confirmed');
            }
            if (($payment['deleted'] ?? false) !== true) { return $this->result(false, 'uncertain', 'uncertain'); }
            \asaas_lifecycle_store_payment((int) $invoice->id, $payment, 'deleted', ['pix_automatic_replaced' => true]);
        }
        $this->store->updateJob((int) $invoice->id, self::JOB_STATES, ['state' => 'failed', 'reason_code' => 'preparation_failed']);
        if ($job['kind'] === 'initial') { $this->store->updateAuthorization((int) $profile['id'], ['preparing', 'uncertain'], ['state' => 'failed', 'reason_code' => 'preparation_failed']); }
        return $this->result(false, 'failed', 'preparation_failed');
    }

    /** Verified initial Pix correlation: never infer by customer, value or invoice text alone. */
    public function resolvePayment(array $candidate): ?object
    {
        if (!$this->store->ready() || ($candidate['billingType'] ?? '') !== 'PIX' || !is_string($candidate['id'] ?? null)) { return null; }
        $known = $this->store->jobByPayment($candidate['id']);
        if ($known !== []) {
            $invoice = $this->store->invoice((int) $known['invoice_id']);
            return $invoice && $this->matchesPayment($invoice, $candidate) ? $invoice : null;
        }
        if (!is_string($candidate['pixTransaction'] ?? null) || $candidate['pixTransaction'] === '') { return null; }
        $payment = $this->api()->payment($candidate['id']);
        if (($payment['id'] ?? '') !== $candidate['id'] || ($payment['pixTransaction'] ?? '') !== $candidate['pixTransaction']) { throw new \RuntimeException('charge_mismatch'); }
        $transaction = $this->api()->transaction($payment['pixTransaction']);
        if (($transaction['id'] ?? '') !== $payment['pixTransaction'] || ($transaction['payment'] ?? '') !== $payment['id'] || ($transaction['type'] ?? '') !== 'CREDIT') { return null; }
        $conciliation = $transaction['conciliationIdentifier'] ?? '';
        if (!is_string($conciliation) || $conciliation === '') { return null; }
        $profile = $this->store->authorizationByConciliation($conciliation);
        if ($profile === []) { return null; }
        $lock = null;
        try {
            $lock = $this->store->lock((int) $profile['client_id'], $profile['scope_key']);
            $invoice = $this->store->invoice((int) $profile['initial_invoice_id']);
            $job = $this->store->job((int) $profile['initial_invoice_id']);
            $this->assertJob($invoice, $job, $profile); $this->assertInitialPayment($invoice, $profile, $payment, $transaction);
            // Native webhook accounting consumes the event, so verify that payload as well as the GET result.
            $this->assertInitialPayment($invoice, $profile, $candidate, $transaction);
            \asaas_receipt_lock((int) $invoice->id);
            $this->bind($invoice, $job, $payment);
            return $invoice;
        } finally {
            \asaas_receipt_unlock((int) $profile['initial_invoice_id']);
            if ($lock !== null) { $this->release($lock); }
        }
    }

    public function matchesPayment(object $invoice, array $payment): bool
    {
        if (($payment['billingType'] ?? '') !== 'PIX' || !is_string($payment['id'] ?? null) || !empty($payment['deleted'])
            || !empty($payment['installment']) || !empty($payment['subscription'])
            || (!empty($payment['externalReference']) && $payment['externalReference'] !== (string) ($invoice->hash ?? ''))) { return false; }
        $job = $this->store->job((int) ($invoice->id ?? 0));
        if ($job === [] || $job['kind'] !== 'initial' || empty($job['payment_id']) || $job['payment_id'] !== $payment['id']) { return false; }
        $profile = $this->store->authorizationById((int) $job['authorization_id']);
        $this->assertJob($invoice, $job, $profile);
        return !empty($profile['conciliation_identifier']) && ($payment['customer'] ?? '') === $profile['customer_id']
            && \asaas_payment_amount_to_cents($payment['value'] ?? null) === (int) $job['principal_cents'];
    }

    private function findInitialPayment(array $profile): ?array
    {
        $offset = (int) $profile['payment_scan_offset'];
        $page = $this->api()->payments(['customer' => $profile['customer_id'], 'billingType' => 'PIX',
            'dateCreated[ge]' => $this->calendar()->date($profile['start_date'])->modify('-1 day')->format('Y-m-d'), 'offset' => $offset, 'limit' => 10]);
        $rows = $this->listing($page, true, $offset); $processed = 0;
        foreach ($rows as $payment) {
            $this->budget();
            if (!is_array($payment)) { throw new \RuntimeException('charge_mismatch'); }
            if (!empty($payment['pixTransaction']) && is_string($payment['pixTransaction']) && empty($payment['deleted'])) {
                $transaction = $this->api()->transaction($payment['pixTransaction']);
                if (($transaction['conciliationIdentifier'] ?? '') === $profile['conciliation_identifier']) {
                    $invoice = $this->store->invoice((int) $profile['initial_invoice_id']);
                    if (!$invoice) { throw new \RuntimeException('invoice_changed'); }
                    $this->assertInitialPayment($invoice, $profile, $payment, $transaction);
                    return $payment;
                }
            }
            ++$processed;
            // Persist after each inspected item; an outage resumes the scan without skipping it.
            $next = $processed === count($rows) && !$page['hasMore'] ? 0 : $offset + $processed;
            $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['payment_scan_offset' => $next]);
        }
        if ($rows === []) { $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['payment_scan_offset' => 0]); }
        return null;
    }

    private function assertInitialPayment(object $invoice, array $profile, array $payment, ?array $transaction = null): void
    {
        if ((int) $profile['initial_invoice_id'] !== (int) $invoice->id || $profile['initial_invoice_hash'] !== (string) $invoice->hash
            || (int) $profile['client_id'] !== (int) $invoice->clientid || $profile['scope_key'] !== $this->scope($invoice)['key']
            || ($payment['customer'] ?? '') !== $profile['customer_id'] || ($payment['billingType'] ?? '') !== 'PIX'
            || !is_string($payment['id'] ?? null) || !preg_match('/^pay_[A-Za-z0-9_-]{1,100}$/D', $payment['id']) || !empty($payment['deleted'])
            || !empty($payment['installment']) || !empty($payment['subscription'])
            || \asaas_payment_amount_to_cents($payment['value'] ?? null) !== (int) $profile['initial_amount_cents']
            || (!empty($payment['externalReference']) && $payment['externalReference'] !== (string) $invoice->hash)
            || !is_string($payment['pixTransaction'] ?? null) || $payment['pixTransaction'] === '') { throw new \RuntimeException('charge_mismatch'); }
        $transaction ??= $this->api()->transaction($payment['pixTransaction']);
        if (($transaction['id'] ?? '') !== $payment['pixTransaction'] || ($transaction['payment'] ?? '') !== $payment['id']
            || ($transaction['conciliationIdentifier'] ?? '') !== $profile['conciliation_identifier'] || ($transaction['type'] ?? '') !== 'CREDIT') { throw new \RuntimeException('charge_mismatch'); }
    }

    private function assertCyclePayment(object $invoice, array $profile, array $job, array $payment): void
    {
        if (!is_string($payment['id'] ?? null) || !preg_match('/^pay_[A-Za-z0-9_-]{1,100}$/D', $payment['id'])
            || ($payment['externalReference'] ?? '') !== (string) $invoice->hash || ($payment['customer'] ?? '') !== $profile['customer_id']
            || ($payment['billingType'] ?? '') !== 'PIX' || (!empty($job['payment_id']) && $job['payment_id'] !== $payment['id'])
            || !empty($payment['installment']) || !empty($payment['subscription']) || !empty($payment['deleted'])
            || \asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null) !== (int) $job['principal_cents']) { throw new \RuntimeException('charge_mismatch'); }
        // Asaas may omit authorization id in a payment response. Prove it through the instruction.
        $authorizationId = $payment['pixAutomaticAuthorizationId'] ?? null;
        if ($authorizationId !== null) {
            if ($authorizationId !== $profile['remote_id']) { throw new \RuntimeException('charge_mismatch'); }
        } else {
            $instructions = $this->api()->instructions($payment['id']); $found = false;
            foreach ($this->listing($instructions, true, 0) as $instruction) {
                if (($instruction['paymentId'] ?? '') === $payment['id'] && ($instruction['authorization']['id'] ?? '') === $profile['remote_id']) { $found = true; }
                else { throw new \RuntimeException('charge_mismatch'); }
            }
            if (!$found || ($instructions['hasMore'] ?? false)) { throw new \RuntimeException('uncertain'); }
        }
    }

    private function bind(object $invoice, array $job, array $payment): void
    {
        if (!empty($job['payment_id']) && $job['payment_id'] !== $payment['id']) { throw new \RuntimeException('charge_mismatch'); }
        $other = $this->store->jobByPayment($payment['id']);
        if ($other !== [] && (int) $other['invoice_id'] !== (int) $invoice->id) { throw new \RuntimeException('charge_mismatch'); }
        $this->store->updateJob((int) $invoice->id, self::JOB_STATES, ['payment_id' => $payment['id'],
            'state' => $job['state'] === 'confirmed' ? 'confirmed' : 'waiting', 'reason_code' => 'waiting', 'next_check_at' => $this->later()]);
        if (($this->store->job((int) $invoice->id)['payment_id'] ?? '') !== $payment['id']) { throw new \RuntimeException('storage_unavailable'); }
        \asaas_lifecycle_store_payment((int) $invoice->id, $payment, 'active', ['pix_automatic_job' => (int) $invoice->id]);
    }

    private function settle(object $invoice, array $job, array $payment): array
    {
        if (!$this->received($payment)) { return $this->result(false, 'waiting', 'waiting'); }
        $amount = (int) $job['principal_cents'];
        if (!$this->store->receiptExists((int) $invoice->id, $payment['id'], $amount)) {
            if (!$this->open($invoice) || \asaas_payment_amount_to_cents($this->store->balance($invoice)) !== $amount) { throw new \RuntimeException('receipt_conflict'); }
            if (!$this->gateway->addPayment(['invoiceid' => (int) $invoice->id, 'transactionid' => $payment['id'],
                'amount' => \asaas_card_money($amount), 'paymentmethod' => 'PIX',
                'date' => substr((string) ($payment['clientPaymentDate'] ?? $payment['paymentDate'] ?? $this->now()->format('Y-m-d')), 0, 10),
                'note' => 'Recebimento confirmado pelo Pix Automático Asaas.'])) { throw new \RuntimeException('receipt_pending'); }
        }
        if (!$this->store->receiptExists((int) $invoice->id, $payment['id'], $amount)) { throw new \RuntimeException('receipt_pending'); }
        $this->store->updateJob((int) $invoice->id, self::JOB_STATES, ['state' => 'confirmed', 'reason_code' => 'confirmed', 'next_check_at' => null]);
        \asaas_automatic_pix_diagnostic('confirmed', (int) $invoice->id);
        return $this->result(true, 'confirmed', 'confirmed');
    }

    private function removePrevious(object $invoice, array $charge, array $job): void
    {
        $fresh = $this->api()->payment($charge['id']); $this->assertReplaceable($invoice, $fresh, (int) $job['principal_cents']);
        if (($fresh['customer'] ?? '') !== ($charge['customer'] ?? '')) { throw new \RuntimeException('charge_mismatch'); }
        $this->store->updateJob((int) $invoice->id, ['preparing'], ['phase' => 'removing', 'previous_payment_id' => $fresh['id']]);
        $removed = $this->api()->deletePayment($fresh['id']);
        if (($removed['id'] ?? '') !== $fresh['id'] || ($removed['deleted'] ?? null) !== true) { throw new \RuntimeException('uncertain'); }
        $confirmed = $this->api()->payment($fresh['id']);
        if (($confirmed['id'] ?? '') !== $fresh['id'] || ($confirmed['deleted'] ?? null) !== true || $this->received($confirmed)) { throw new \RuntimeException('uncertain'); }
        $fresh['deleted'] = true;
        \asaas_lifecycle_store_payment((int) $invoice->id, $fresh, 'deleted', ['pix_automatic_replaced' => true]);
    }
    private function assertReplaceable(object $invoice, array $payment, int $amount): void
    {
        if (!is_string($payment['id'] ?? null) || !preg_match('/^pay_[A-Za-z0-9_-]{1,100}$/D', $payment['id'])
            || !is_string($payment['customer'] ?? null) || !preg_match('/^cus_[A-Za-z0-9_-]{1,100}$/D', $payment['customer'])
            || ($payment['externalReference'] ?? '') !== (string) $invoice->hash || !empty($payment['deleted']) || !empty($payment['installment'])
            || !empty($payment['subscription']) || !in_array($payment['status'] ?? '', ['PENDING', 'OVERDUE'], true)
            || !in_array($payment['billingType'] ?? '', ['BOLETO', 'PIX', 'UNDEFINED'], true)
            || \asaas_payment_amount_to_cents($payment['value'] ?? null) !== $amount) { throw new \RuntimeException('charge_mismatch'); }
    }
    private function charges(object $invoice): array { return \asaas_lifecycle_charges($invoice); }
    private function received(array $payment): bool { return empty($payment['deleted']) && in_array($payment['status'] ?? '', ['RECEIVED', 'CONFIRMED'], true); }
    private function assertJob(?object $invoice, array $job, array $profile): void
    {
        if (!$invoice || $job === [] || $profile === [] || (int) $invoice->id !== (int) $job['invoice_id']
            || (string) $invoice->hash !== $job['invoice_hash'] || (int) $invoice->clientid !== (int) $job['client_id']
            || (int) $profile['id'] !== (int) $job['authorization_id'] || (int) $profile['client_id'] !== (int) $invoice->clientid
            || $profile['scope_key'] !== $job['scope_key'] || $this->scope($invoice)['key'] !== $job['scope_key']) { throw new \RuntimeException('scope_changed'); }
    }
    private function fresh(object $invoice): object
    {
        $fresh = $this->store->invoice((int) ($invoice->id ?? 0));
        if (!$fresh || (string) $fresh->hash !== (string) ($invoice->hash ?? '') || (int) $fresh->clientid !== (int) ($invoice->clientid ?? 0)) { throw new \RuntimeException('invoice_changed'); }
        return $fresh;
    }
    private function snapshot(object $invoice): string
    {
        return hash('sha256', json_encode([(int) $invoice->id, (string) $invoice->hash, (int) $invoice->clientid, $this->scope($invoice)['key'],
            (int) $invoice->status, $this->store->balance($invoice), (string) ($invoice->duedate ?? ''), $this->modes($invoice), self::CONSENT_VERSION], JSON_THROW_ON_ERROR));
    }
    private function calendar(): AsaasAutomaticPixCalendar
    {
        $value = get_option('paymentmethod_asaas_automatic_pix_additional_holidays');
        $holidays = is_string($value) ? preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) : [];
        $calendar = new AsaasAutomaticPixCalendar($holidays ?: []);
        foreach ($holidays ?: [] as $date) { $calendar->date($date); }
        return $calendar;
    }
    private function listing(array $page, bool $strict = true, ?int $expectedOffset = null): array
    {
        if (!is_array($page['data'] ?? null) || !array_is_list($page['data'])
            || ($strict && !is_bool($page['hasMore'] ?? null)) || (($page['hasMore'] ?? false) === true && $page['data'] === [])) { throw new \RuntimeException('uncertain'); }
        if ($strict && (!is_int($page['offset'] ?? null) || !is_int($page['totalCount'] ?? null))) { throw new \RuntimeException('uncertain'); }
        if (isset($page['offset']) && (!is_int($page['offset']) || $page['offset'] < 0 || ($expectedOffset !== null && $page['offset'] !== $expectedOffset))) { throw new \RuntimeException('uncertain'); }
        if (isset($page['totalCount'])) {
            $end = (int) ($page['offset'] ?? 0) + count($page['data']);
            if (!is_int($page['totalCount']) || $page['totalCount'] < 0
                || (count($page['data']) > 0 && $page['totalCount'] < $end)
                || (($page['hasMore'] ?? false) === true && $page['totalCount'] <= $end)
                || (($page['hasMore'] ?? false) === false && $page['totalCount'] > $end)) { throw new \RuntimeException('uncertain'); }
        }
        return $page['data'];
    }
    private function validUntil(string $date, int $grace = 0): bool
    {
        try { return (new \DateTimeImmutable($date, new \DateTimeZone('America/Sao_Paulo')))->modify('+' . $grace . ' seconds') > $this->now(); }
        catch (\Throwable) { return false; }
    }
    private function skip(array $job, string $reason): array
    {
        $this->store->updateJob((int) $job['invoice_id'], ['queued'], ['state' => 'skipped', 'reason_code' => $reason]);
        \asaas_automatic_pix_diagnostic($reason, (int) $job['invoice_id']);
        return $this->result(false, 'skipped', $reason);
    }
    private function safeStop(array $profile, int $invoice, string $state, string $reason): void
    {
        try {
            $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['state' => $state, 'reason_code' => $reason, 'next_check_at' => $this->later()]);
            $this->store->updateJob($invoice, ['preparing', 'creating', 'waiting', 'uncertain'], ['state' => $state, 'reason_code' => $reason, 'next_check_at' => $this->later()]);
        } catch (\Throwable) {}
    }
    private function reason(\Throwable $error): string
    {
        $code = $error->getMessage();
        $known = ['unavailable', 'storage_unavailable', 'sdk_unavailable', 'other_mandate', 'existing_authorization', 'invalid_consent', 'contact_mismatch',
            'https_required', 'busy', 'scope_changed', 'invoice_changed', 'quote_changed', 'charge_mismatch', 'receipt_conflict', 'receipt_pending',
            'authorization_changed', 'transaction_active', 'invalid_frequency', 'invalid_due_date', 'request_rejected', 'review_pending'];
        return in_array($code, $known, true) ? $code : 'uncertain';
    }
    private function result(bool $ok, string $state, string $reason): array
    {
        return ['ok' => $ok, 'state' => $state, 'error_code' => $ok ? null : $reason, 'message' => \asaas_automatic_pix_message($reason)];
    }
    private function release(string $name): void { try { $this->store->unlock($name); } catch (\Throwable) { \asaas_automatic_pix_diagnostic('storage_unavailable'); } }

    /** Authenticated events only schedule GETs; old/reordered webhooks cannot reactivate a mandate. */
    public function observeWebhook(array $payload): void
    {
        if (!$this->store->ready()) { return; }
        $profile = [];
        $id = $payload['authorization']['id'] ?? $payload['paymentInstruction']['authorization']['id'] ?? null;
        if (is_string($id)) { $profile = $this->store->authorizationByRemote($id); }
        if ($profile === [] && is_string($payload['authorization']['contractId'] ?? null)) { $profile = $this->store->authorizationByContract($payload['authorization']['contractId']); }
        if ($profile !== []) { $this->store->scheduleAuthorization((int) $profile['id']); }
        if (is_string($payload['payment']['id'] ?? null)) {
            $job = $this->store->jobByPayment($payload['payment']['id']);
            if ($job !== []) { $this->store->scheduleAuthorization((int) $job['authorization_id']); }
        }
    }

    public static function runCron(): array { return \asaas_automatic_pix_service()->work(); }
    public function work(): array
    {
        $result = ['authorizations' => 0, 'checked' => 0, 'confirmed' => 0, 'failed' => 0, 'skipped' => 0, 'waiting' => 0, 'uncertain' => 0, 'queued' => 0];
        if (!$this->store->ready() || $this->store->transactionDepth() !== 0) { return $result; }
        $this->deadline = microtime(true) + 40;
        try {
            foreach ($this->store->dueAuthorizations(5) as $profile) {
                $this->budget(); $lock = null;
                try {
                    $invoice = $this->store->invoice((int) $profile['initial_invoice_id']);
                    if (!$invoice) {
                        $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['next_check_at' => $this->later(3600), 'reason_code' => 'invoice_changed']);
                        \asaas_automatic_pix_diagnostic('invoice_changed', (int) $profile['initial_invoice_id']);
                        continue;
                    }
                    $lock = $this->store->lock((int) $profile['client_id'], $profile['scope_key']);
                    $this->refreshLocked($invoice, $this->store->authorizationById((int) $profile['id'])); ++$result['authorizations'];
                } catch (\Throwable $error) {
                    $reason = $this->reason($error);
                    try { $this->store->updateAuthorization((int) $profile['id'], self::AUTH_STATES, ['next_check_at' => $this->later($reason === 'scope_changed' ? 3600 : 300), 'reason_code' => $reason]); } catch (\Throwable) {}
                    \asaas_automatic_pix_diagnostic($reason, (int) $profile['initial_invoice_id']);
                }
                finally { if ($lock !== null) { $this->release($lock); } }
            }
            if ($this->enabled()) {
                foreach ($this->store->missingJobs(40) as $candidate) {
                    $this->budget();
                    try { $this->enqueue((int) $candidate['id']); }
                    catch (\Throwable $error) { \asaas_automatic_pix_diagnostic($this->reason($error), (int) $candidate['id']); }
                    finally { $this->store->advanceMissingCursor((int) $candidate['id']); }
                }
            }
            foreach ($this->store->dueJobs(10) as $candidate) {
                $this->budget(); $outcome = $this->processInvoice((int) $candidate['invoice_id']); ++$result['checked'];
                if (isset($result[$outcome['state']])) { ++$result[$outcome['state']]; }
            }
        } catch (\Throwable $error) { if ($error->getMessage() !== 'review_pending') { \asaas_automatic_pix_diagnostic($this->reason($error)); } }
        finally { $this->deadline = null; }
        return $result;
    }
}
