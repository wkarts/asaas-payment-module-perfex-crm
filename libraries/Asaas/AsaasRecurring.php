<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_card_helper.php';
require_once __DIR__ . '/../../helpers/asaas_card_pricing_helper.php';
require_once __DIR__ . '/../../helpers/asaas_recurring_helper.php';
require_once __DIR__ . '/AsaasRecurringStore.php';
require_once __DIR__ . '/AsaasCardApi.php';

/** Consent + durable work queue. The existing card checkout still owns capture and its marker. */
final class AsaasRecurring
{
    public const CONSENT_VERSION = '2026-11-v1';
    private object $gateway;
    private object $store;
    private ?object $api;

    public function __construct(object $gateway, ?object $store = null, ?object $api = null)
    {
        $this->gateway = $gateway;
        $this->store = $store ?? new AsaasRecurringStore();
        $this->api = $api;
    }

    private function api(): object { return $this->api ??= new AsaasCardApi($this->gateway->getProvider()); }
    private function enabled(): bool
    {
        return $this->store->freshGatewayActive()
            && (string) $this->gateway->getSetting('active') === '1';
    }
    private function open(object $invoice): bool
    {
        return (int) ($invoice->id ?? 0) > 0 && !in_array((int) ($invoice->status ?? 0), [2, 5, 6], true)
            && \asaas_payment_amount_to_cents($this->store->balance($invoice)) > 0;
    }
    private function modes(object $invoice): array { return \asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []); }

    public function available(object $invoice): bool
    {
        try {
            if (!$this->enabled() || !$this->store->ready() || !$this->brl($invoice) || !$this->open($invoice)
                || array_intersect(['asaas', 'asaas_recurring'], $this->modes($invoice)) === []) { return false; }
            if (function_exists('asaas_automatic_pix_client_blocks') && \asaas_automatic_pix_client_blocks($invoice)) { return false; }
            $this->scope($invoice);
            return true;
        } catch (\Throwable $error) { return false; }
    }

    /** No API calls, capture, secret, or decryption in the page's read model. */
    public function page(object $invoice): array
    {
        $page = ['enabled' => $this->enabled(), 'state' => 'unavailable', 'authorization_state' => 'inactive',
            'attempt_state' => '', 'can_enroll' => false, 'can_revoke' => false, 'can_pay_now' => false,
            'quote' => '', 'principal' => '0.00', 'fee' => '0.00', 'total' => '0.00',
            'consent_version' => self::CONSENT_VERSION,
            'consent_text' => 'Autorizo o pagamento desta fatura à vista e a cobrança automática, à vista, das novas faturas Asaas deste cliente, '
                . 'conforme o valor de cada fatura e a política de encargos informada. Faturas anteriores não serão incluídas automaticamente. '
                . 'Posso revogar a autorização para as próximas cobranças. Uma recusa pausa a recorrência; uma operação sem resposta será conferida sem repetição.',
            'message' => \asaas_recurring_message('unavailable'), 'error_code' => null];
        try {
            if (!$this->store->ready()) { throw new \RuntimeException('storage_unavailable'); }
            $scope = $this->scope($invoice);
            $authorization = $this->store->authorization((int) $invoice->clientid, $scope['key']);
            $job = $this->store->job((int) $invoice->id);
            $attempt = $this->store->cache((int) $invoice->id)['_asaas_lifecycle']['card_attempt']['state'] ?? '';
            $blocked = in_array($job['state'] ?? '', ['processing', 'uncertain'], true)
                || in_array($attempt, ['preparing', 'processing', 'ambiguous', 'pending'], true);
            $profileBlocked = $authorization !== [] && $this->store->hasBlockingJob((int) $authorization['id'], 0, 0);
            $page['authorization_state'] = $authorization['state'] ?? 'inactive';
            $page['attempt_state'] = $blocked ? 'uncertain' : ($job['state'] ?? $attempt);
            $page['can_revoke'] = in_array($authorization['state'] ?? '', ['active', 'suspended'], true);
            $page['state'] = $blocked ? 'uncertain' : ($authorization['state'] ?? 'inactive');
            $page['can_pay_now'] = $this->open($invoice) && !$blocked;
            if (!$blocked && $profileBlocked) { $page['error_code'] = 'awaiting_previous'; }
            if ($this->available($invoice) && !$blocked && !$profileBlocked && ($job['state'] ?? '') !== 'confirmed'
                && !\asaas_card_attempt_blocks($this->store->cache((int) $invoice->id))) {
                $offer = $this->offer($invoice);
                $page = array_replace($page, $offer);
                $page['can_enroll'] = true;
            }
            if (($authorization['state'] ?? '') === 'active'
                && !hash_equals((string) $authorization['pricing_policy_hash'], $this->policy())) {
                $page['state'] = 'suspended';
                $page['error_code'] = 'policy_changed';
            }
            $reason = $page['error_code'] ?? $page['state'];
            if (!$blocked && ($job['state'] ?? '') === 'queued') { $reason = 'queued'; }
            if (!$blocked && ($job['state'] ?? '') === 'confirmed'
                && !in_array($page['state'], ['revoked', 'suspended'], true)) { $reason = 'confirmed'; }
            $page['message'] = \asaas_recurring_message($reason);
        } catch (\Throwable $error) {
            $reason = $this->reason($error, 'unavailable');
            $page['error_code'] = $reason;
            $page['message'] = \asaas_recurring_message($reason);
        }
        return $page;
    }

    /** Only an authenticated contact's explicit POST may create/revise consent. */
    public function enroll(object $invoice, #[\SensitiveParameter] array $card, array $consent): array
    {
        $lock = null;
        $invoiceId = (int) ($invoice->id ?? 0);
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            if (!$this->available($invoice)) { throw new \RuntimeException('unavailable'); }
            if (function_exists('is_https') && !is_https()) { throw new \RuntimeException('https_required'); }
            if (!in_array($consent['accepted'] ?? false, [true, 1, '1'], true)
                || !in_array($consent['pay_now'] ?? false, [true, 1, '1'], true)
                || ($consent['terms_version'] ?? null) !== self::CONSENT_VERSION) { throw new \RuntimeException('invalid_consent'); }
            if (!$this->store->contactBelongs((int) ($consent['contact_id'] ?? 0), (int) $invoice->clientid)) {
                throw new \RuntimeException('contact_mismatch');
            }
            $scope = $this->scope($invoice);
            $lock = $this->store->lock((int) $invoice->clientid, $scope['key']);
            \asaas_receipt_lock($invoiceId);
            $fresh = $this->fresh($invoice);
            if (function_exists('asaas_automatic_pix_client_blocks') && \asaas_automatic_pix_client_blocks($fresh)) {
                throw new \RuntimeException('other_mandate');
            }
            if (!$this->available($fresh) || $scope !== $this->scope($fresh)) { throw new \RuntimeException('invoice_changed'); }
            $job = $this->store->job($invoiceId);
            if (in_array($job['state'] ?? '', ['processing', 'uncertain', 'confirmed'], true)
                || \asaas_card_attempt_blocks($this->store->cache($invoiceId))) { throw new \RuntimeException('uncertain'); }
            $offer = $this->offer($fresh);
            if (!is_string($consent['quote'] ?? null) || !hash_equals($offer['quote'], $consent['quote'])) {
                throw new \RuntimeException('quote_changed');
            }
            $snapshot = $this->snapshot($fresh);
            $policy = $this->policy();
            $previous = $this->store->authorization((int) $fresh->clientid, $scope['key']);
            if ($previous !== [] && $this->store->hasBlockingJob((int) $previous['id'], 0, 0)) { throw new \RuntimeException('uncertain'); }
            $charges = $this->charges($fresh);
            if (count($charges) > 1) { throw new \RuntimeException('charge_mismatch'); }
            if ($charges !== []) {
                $charge = $charges[0];
                $this->assertCharge($fresh, $charge, (string) ($charge['customer'] ?? ''));
                if (!in_array($charge['status'] ?? '', ['PENDING', 'OVERDUE'], true) || !empty($charge['installment'])) {
                    throw new \RuntimeException('charge_mismatch');
                }
                $customerId = $charge['customer'];
            } else {
                $customer = $this->gateway->ensure_customer_from_client($fresh->client ?? (object) ['userid' => $fresh->clientid]);
                $customerId = (string) ($customer['customerId'] ?? '');
            }
            if (!preg_match('/^cus_[A-Za-z0-9_-]{1,100}$/D', $customerId)) { throw new \RuntimeException('customer_mismatch'); }
            if (!is_string($consent['ip'] ?? null) || !filter_var($consent['ip'], FILTER_VALIDATE_IP)) {
                throw new \RuntimeException('invalid_consent');
            }
            $card['installmentCount'] = '1';
            $validated = \asaas_card_validate_input($card, 1);
            $token = $this->api()->tokenize(['customer' => $customerId, 'creditCard' => $validated['creditCard'],
                'creditCardHolderInfo' => $validated['creditCardHolderInfo'], 'remoteIp' => $consent['ip']]);
            unset($card, $validated);
            if (!is_string($token) || ctype_digit($token) || !preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $token)) {
                throw new \RuntimeException('tokenization_failed');
            }
            $this->store->assertLock($lock);
            $fresh = $this->fresh($fresh);
            if ($snapshot !== $this->snapshot($fresh) || $scope !== $this->scope($fresh) || $policy !== $this->policy()) {
                throw new \RuntimeException('quote_changed');
            }
            $authorization = ['client_id' => (int) $fresh->clientid, 'scope_key' => $scope['key'],
                'environment' => $scope['environment'], 'company_id' => $scope['company_id'], 'customer_id' => $customerId,
                'revision' => (int) ($previous['revision'] ?? 0) + 1, 'state' => 'active',
                'contact_id' => (int) $consent['contact_id'], 'consent_version' => self::CONSENT_VERSION,
                'authorized_at' => date('Y-m-d H:i:s'), 'cutoff_invoice_id' => $this->store->maxInvoiceId(),
                'pricing_policy_hash' => $policy, 'reason_code' => null, 'revoked_at' => null, 'updated_at' => date('Y-m-d H:i:s')];
            $authorization['token_ciphertext'] = $this->store->seal(json_encode($this->envelope($authorization) + ['token' => $token], JSON_THROW_ON_ERROR));
            unset($token);
            $authorization = $this->store->saveAuthorization($authorization, isset($previous['revision']) ? (int) $previous['revision'] : null);
            if (!$this->queue($fresh, $authorization, true)) { throw new \RuntimeException('storage_unavailable'); }
            \asaas_recurring_diagnostic('active', $invoiceId);
            \asaas_receipt_unlock($invoiceId);
            return $this->processInvoice($invoiceId);
        } catch (\Throwable $error) {
            $reason = $this->reason($error, isset($authorization['id']) ? 'uncertain' : 'tokenization_failed');
            if (isset($authorization['id'])) { $this->stop($authorization, $reason); }
            \asaas_recurring_diagnostic($reason, $invoiceId);
            return $this->result(false, isset($authorization['id']) ? 'uncertain' : 'unavailable', $reason);
        } finally {
            unset($token, $card, $validated);
            \asaas_receipt_unlock($invoiceId);
            if ($lock !== null) { $this->release($lock); }
        }
    }

    /** Revocation is permitted on a paid invoice and while an earlier charge is unresolved. */
    public function revoke(object $invoice): array
    {
        $lock = null;
        try {
            $fresh = $this->fresh($invoice);
            $scope = $this->scope($fresh);
            $lock = $this->store->lock((int) $fresh->clientid, $scope['key']);
            $authorization = $this->store->authorization((int) $fresh->clientid, $scope['key']);
            if ($authorization !== []) { $this->store->stopAuthorization($authorization, 'revoked', 'revoked'); }
            \asaas_recurring_diagnostic('revoked', (int) $fresh->id);
            return $this->result(true, 'revoked', 'revoked');
        } catch (\Throwable $error) { return $this->result(false, 'unavailable', $this->reason($error, 'unavailable')); }
        finally { if ($lock !== null) { $this->release($lock); } }
    }

    public function authorizesNewInvoice(array $data): bool
    {
        if (!$this->enabled() || !$this->store->ready() || (int) ($data['clientid'] ?? 0) <= 0
            || array_intersect(['asaas', 'asaas_recurring'], \asaas_invoice_payment_modes($data['allowed_payment_modes'] ?? [])) === []) { return false; }
        $invoice = (object) $data;
        if (!$this->brl($invoice)) { return false; }
        if (function_exists('asaas_automatic_pix_client_blocks') && \asaas_automatic_pix_client_blocks($invoice)) { return false; }
        $scope = $this->scope($invoice);
        $authorization = $this->store->authorization((int) $invoice->clientid, $scope['key']);
        return ($authorization['state'] ?? '') === 'active' && !empty($authorization['token_ciphertext'])
            && hash_equals((string) $authorization['pricing_policy_hash'], $this->policy());
    }

    /** Called by after_invoice_added inside its existing transaction: no API, capture or commit. */
    public function enqueue(int $invoiceId, bool $explicit = false): bool
    {
        // Explicit consent is accepted only by enroll(), never inferred from an after-hook argument.
        if ($explicit) { return false; }
        $invoice = $this->store->invoice($invoiceId);
        if (!$invoice || !$this->available($invoice) || !in_array('asaas_recurring', $this->modes($invoice), true)) { return false; }
        $scope = $this->scope($invoice);
        $authorization = $this->store->authorization((int) $invoice->clientid, $scope['key']);
        if (($authorization['state'] ?? '') !== 'active' || empty($authorization['token_ciphertext'])
            || $invoiceId <= (int) $authorization['cutoff_invoice_id']
            || !hash_equals((string) $authorization['pricing_policy_hash'], $this->policy())) { return false; }
        return $this->queue($invoice, $authorization, false);
    }

    private function queue(object $invoice, array $authorization, bool $explicit): bool
    {
        $job = $this->jobData($invoice, $authorization, $explicit);
        $existing = $this->store->job((int) $invoice->id);
        if ($existing !== []) {
            if (!$explicit || !in_array($existing['state'], ['queued', 'declined', 'skipped'], true)
                || $existing['invoice_hash'] !== (string) $invoice->hash || (int) $existing['client_id'] !== (int) $invoice->clientid) { return false; }
            return $this->store->updateJob((int) $invoice->id, [$existing['state']], $job);
        }
        return $this->store->insertJob($job);
    }

    private function jobData(object $invoice, array $authorization, bool $explicit): array
    {
        return ['invoice_id' => (int) $invoice->id, 'invoice_hash' => (string) $invoice->hash,
            'client_id' => (int) $invoice->clientid, 'authorization_id' => (int) $authorization['id'],
            'authorization_revision' => (int) $authorization['revision'], 'scope_key' => $authorization['scope_key'],
            'customer_id' => $authorization['customer_id'],
            'explicit_current' => $explicit ? 1 : 0, 'state' => 'queued', 'phase' => 'queued',
            'payment_id' => null, 'reason_code' => null, 'next_check_at' => null, 'started_at' => null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }

    /** Processes each durable authorization revision at most once; resumed work is GET-only. */
    public function processInvoice(int $invoiceId): array
    {
        $lock = null;
        $job = [];
        $authorization = [];
        try {
            if ($this->store->transactionDepth() !== 0) { throw new \RuntimeException('transaction_active'); }
            $job = $this->store->job($invoiceId);
            if ($job === []) { return $this->result(false, 'skipped', 'invoice_ineligible'); }
            $lock = $this->store->lock((int) $job['client_id'], $job['scope_key']);
            $job = $this->store->job($invoiceId);
            if (in_array($job['state'], ['confirmed', 'declined', 'skipped'], true)) {
                return $this->result($job['state'] === 'confirmed', $job['state'], $job['reason_code'] ?: $job['state']);
            }
            try { \asaas_receipt_lock($invoiceId); } catch (\Throwable $error) { throw new \RuntimeException('busy'); }
            $authorization = $this->store->authorizationById((int) $job['authorization_id']);
            if ($authorization === []) { throw new \RuntimeException('authorization_changed'); }
            // Historical results can stop only their own consent revision, never a newer card.
            $authorization['revision'] = (int) $job['authorization_revision'];
            $authorization['customer_id'] = $job['customer_id'];
            $invoice = $this->store->invoice($invoiceId);
            if (!$invoice || (string) $invoice->hash !== $job['invoice_hash'] || (int) $invoice->clientid !== (int) $job['client_id']) {
                throw new \RuntimeException('invoice_changed');
            }
            if ($this->scope($invoice)['key'] !== $job['scope_key']) { throw new \RuntimeException('scope_changed'); }
            if ($job['state'] !== 'queued') { return $this->reconcile($invoice, $job, $authorization); }
            $this->assertAuthorization($invoice, $job, $authorization, $lock);
            if ($this->store->hasBlockingJob((int) $authorization['id'], (int) $authorization['revision'], $invoiceId)) {
                $this->store->updateJob($invoiceId, ['queued'], ['reason_code' => 'awaiting_previous', 'next_check_at' => date('Y-m-d H:i:s', time() + 300)]);
                return $this->result(false, 'queued', 'awaiting_previous');
            }
            if (!$this->store->updateJob($invoiceId, ['queued'], ['state' => 'processing', 'phase' => 'preparing',
                'started_at' => date('Y-m-d H:i:s'), 'next_check_at' => date('Y-m-d H:i:s', time() + 300)])) {
                throw new \RuntimeException('busy');
            }
            $job = $this->store->job($invoiceId);
            $snapshot = $this->snapshot($invoice);
            $payment = $this->ensureCharge($invoice, $job, $authorization);
            $job = $this->store->job($invoiceId);
            if (\asaas_card_is_received($payment)) { return $this->finishPayment($invoice, $job, $authorization, $payment); }
            $this->assertAuthorization($this->fresh($invoice), $job, $authorization, $lock);
            $token = $this->token($authorization);
            if (!$this->store->updateJob($invoiceId, ['processing'], ['phase' => 'capturing'])) { throw new \RuntimeException('storage_unavailable'); }
            $payment = $this->gateway->card_checkout()->payAuthorizedToken($invoice, $token, $authorization['customer_id'],
                function (object $passedInvoice) use ($invoice, $job, $authorization, $lock, $snapshot): void {
                    $fresh = $this->fresh($invoice);
                    $this->assertAuthorization($fresh, $job, $authorization, $lock);
                    if ($snapshot !== $this->snapshot($fresh) || (string) $passedInvoice->hash !== (string) $fresh->hash) {
                        throw new \RuntimeException('invoice_changed');
                    }
                });
            unset($token);
            return $this->finishPayment($invoice, $this->store->job($invoiceId), $authorization, $payment);
        } catch (\Throwable $error) {
            $reason = $this->reason($error, 'uncertain');
            if ($job === []) { return $this->result(false, 'unavailable', $reason); }
            if ($lock === null) { return $this->result(false, $job['state'], 'busy'); }
            try { $this->store->assertLock($lock); }
            catch (\Throwable $ignored) { return $this->result(false, $job['state'], 'busy'); }
            $current = $this->store->job($invoiceId);
            $attempt = $this->store->cache($invoiceId)['_asaas_lifecycle']['card_attempt']['state'] ?? '';
            $beforeSend = ($current['state'] ?? '') === 'queued';
            if ($beforeSend && in_array($reason, ['busy', 'storage_unavailable', 'uncertain'], true)) {
                return $this->result(false, 'queued', $reason);
            }
            $definitivePreparation = ($current['phase'] ?? '') === 'creating' && $error instanceof AsaasCardRequestException
                && (!$error->requestSent || in_array((int) $error->getCode(), [400, 401, 403, 404, 422], true));
            $preCapture = in_array($current['phase'] ?? '', ['preparing', 'prepared'], true)
                && !in_array($attempt, ['preparing', 'processing', 'ambiguous', 'pending', 'confirmed'], true);
            if ($preCapture) {
                $state = 'skipped';
                if ($reason === 'uncertain') { $reason = 'preparation_failed'; }
                if (in_array($reason, ['policy_changed', 'scope_changed', 'vault_unavailable', 'customer_mismatch', 'charge_mismatch'], true)) {
                    $this->stop($authorization, $reason);
                }
            } elseif ($attempt === 'declined' || $definitivePreparation) {
                $reason = $attempt === 'declined' ? 'declined' : 'preparation_failed';
                $state = 'declined';
                $this->stop($authorization, $reason);
            } elseif ($beforeSend) {
                $state = 'skipped';
                if (in_array($reason, ['policy_changed', 'scope_changed', 'vault_unavailable'], true)) { $this->stop($authorization, $reason); }
            } else {
                $state = 'uncertain';
                if (in_array($reason, ['policy_changed', 'scope_changed', 'vault_unavailable'], true)) { $this->stop($authorization, $reason); }
            }
            $this->store->updateJob($invoiceId, ['queued', 'processing', 'uncertain'], ['state' => $state,
                'reason_code' => $reason, 'next_check_at' => date('Y-m-d H:i:s', time() + 300)]);
            \asaas_recurring_diagnostic($reason, $invoiceId);
            return $this->result(false, $state, $reason);
        } finally {
            unset($token);
            \asaas_receipt_unlock($invoiceId);
            if ($lock !== null) { $this->release($lock); }
        }
    }

    private function assertAuthorization(object $invoice, array $job, array $authorization, string $lock): void
    {
        $this->store->assertLock($lock);
        if (function_exists('asaas_automatic_pix_client_blocks') && \asaas_automatic_pix_client_blocks($invoice)) {
            throw new \RuntimeException('other_mandate');
        }
        $current = $this->store->authorizationById((int) $authorization['id']);
        if (($current['state'] ?? '') !== 'active' || (int) ($current['revision'] ?? 0) !== (int) $job['authorization_revision']
            || empty($current['token_ciphertext']) || ($current['customer_id'] ?? '') !== ($authorization['customer_id'] ?? '')) {
            throw new \RuntimeException('authorization_changed');
        }
        if ($this->scope($invoice)['key'] !== $job['scope_key']) { throw new \RuntimeException('scope_changed'); }
        if (!hash_equals((string) $current['pricing_policy_hash'], $this->policy())) { throw new \RuntimeException('policy_changed'); }
        if (!$this->available($invoice) || (string) $invoice->hash !== $job['invoice_hash']
            || (int) $invoice->clientid !== (int) $current['client_id']) { throw new \RuntimeException('invoice_changed'); }
        if (!(bool) $job['explicit_current'] && ((int) $invoice->id <= (int) $current['cutoff_invoice_id']
            || !in_array('asaas_recurring', $this->modes($invoice), true))) { throw new \RuntimeException('invoice_ineligible'); }
    }

    /** Creation has no card credentials: it prepares one debt and never performs a capture. */
    private function ensureCharge(object $invoice, array $job, array $authorization): array
    {
        $charges = $this->charges($invoice);
        if (count($charges) > 1) { throw new \RuntimeException('charge_mismatch'); }
        $payment = $charges[0] ?? null;
        if ($payment === null) {
            if (!$this->enabled()) { throw new \RuntimeException('unavailable'); }
            if (!$this->store->updateJob((int) $invoice->id, ['processing'], ['phase' => 'creating'])) { throw new \RuntimeException('storage_unavailable'); }
            $payload = ['customer' => $authorization['customer_id'], 'billingType' => 'CREDIT_CARD',
                'value' => $this->store->balance($invoice), 'dueDate' => max(date('Y-m-d'), (string) ($invoice->duedate ?? '')),
                'externalReference' => (string) $invoice->hash, 'discount' => $this->gateway->card_checkout()->discount(),
                'fine' => ['value' => $this->gateway->getSetting('fine_value') ?: 0],
                'interest' => ['value' => $this->gateway->getSetting('interest_value') ?: 0]];
            $payment = $this->api()->createPayment($payload);
        }
        $this->assertCharge($invoice, $payment, $authorization['customer_id']);
        if (!empty($payment['installment']) || (!\asaas_card_is_received($payment)
            && !in_array($payment['status'] ?? '', ['PENDING', 'OVERDUE'], true))) { throw new \RuntimeException('charge_mismatch'); }
        if (!$this->store->updateJob((int) $invoice->id, ['processing'], ['phase' => 'prepared', 'payment_id' => $payment['id']])) {
            throw new \RuntimeException('storage_unavailable');
        }
        if (!$this->gateway->upsert_payment_map((int) $invoice->id, $payment)) { throw new \RuntimeException('storage_unavailable'); }
        return $payment;
    }

    /** Only GET requests may be made when resuming a process whose result is unknown. */
    private function reconcile(object $invoice, array $job, array $authorization): array
    {
        $cache = $this->store->cache((int) $invoice->id);
        $attempt = $cache['_asaas_lifecycle']['card_attempt']['state'] ?? '';
        if ($attempt === 'declined') { return $this->decline($invoice, $job, $authorization); }
        if (\asaas_card_attempt_blocks($cache)) {
            $payment = $this->gateway->card_checkout()->reconcile($invoice);
            if (is_array($payment)) { return $this->finishPayment($invoice, $job, $authorization, $payment); }
            if (($this->store->cache((int) $invoice->id)['_asaas_lifecycle']['card_attempt']['state'] ?? '') === 'declined') {
                return $this->decline($invoice, $job, $authorization);
            }
        } else {
            $charges = $this->charges($invoice);
            if (count($charges) === 1) {
                $payment = $charges[0];
                $this->assertCharge($invoice, $payment, $authorization['customer_id']);
                if (\asaas_card_is_received($payment)) { return $this->finishPayment($invoice, $job, $authorization, $payment); }
                if (in_array($job['phase'], ['preparing', 'creating', 'prepared'], true)
                    && in_array($payment['status'] ?? '', ['PENDING', 'OVERDUE'], true) && empty($payment['installment'])) {
                    $this->gateway->upsert_payment_map((int) $invoice->id, $payment);
                    $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'skipped',
                        'payment_id' => $payment['id'], 'reason_code' => 'preparation_recovered']);
                    $this->stop($authorization, 'preparation_recovered');
                    return $this->result(false, 'skipped', 'preparation_recovered');
                }
            }
        }
        $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'uncertain',
            'reason_code' => 'uncertain', 'next_check_at' => date('Y-m-d H:i:s', time() + 300)]);
        return $this->result(false, 'uncertain', 'uncertain');
    }

    private function finishPayment(object $invoice, array $job, array $authorization, array $payment): array
    {
        $this->assertCharge($invoice, $payment, $authorization['customer_id']);
        if (!empty($job['payment_id']) && $job['payment_id'] !== $payment['id']) { throw new \RuntimeException('charge_mismatch'); }
        if (($payment['status'] ?? '') === 'CREDIT_CARD_RISK_ANALYSIS_REQUESTED') {
            $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'uncertain', 'reason_code' => 'uncertain']);
            return $this->result(false, 'uncertain', 'uncertain');
        }
        if (($payment['status'] ?? '') === 'CREDIT_CARD_RISK_ANALYSIS_REPROVED'
            || ($this->store->cache((int) $invoice->id)['_asaas_lifecycle']['card_attempt']['state'] ?? '') === 'declined') {
            return $this->decline($invoice, $job, $authorization);
        }
        $receipts = $payment['_asaas_card_receipts'] ?? (\asaas_card_is_received($payment) ? [$payment] : []);
        if ($receipts === []) {
            $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'uncertain', 'reason_code' => 'uncertain',
                'next_check_at' => date('Y-m-d H:i:s', time() + 300)]);
            return $this->result(false, 'uncertain', 'uncertain');
        }
        foreach ($receipts as $receipt) {
            if (!is_array($receipt) || !\asaas_card_is_received($receipt)) { throw new \RuntimeException('charge_mismatch'); }
            $this->assertCharge($invoice, $receipt, $authorization['customer_id']);
            if ($receipt['id'] !== $payment['id']) { throw new \RuntimeException('charge_mismatch'); }
            $cache = $this->store->cache((int) $invoice->id);
            $principal = \asaas_card_receipt_principal($receipt, $cache);
            $amount = $principal === null
                ? \asaas_card_receipt_amount($receipt, $this->store->balance($invoice), $cache)
                : \asaas_card_money($principal);
            if (!$this->gateway->addPayment(['invoiceid' => (int) $invoice->id,
                'amount' => $amount, 'transactionid' => $receipt['id'],
                'date' => substr((string) ($receipt['clientPaymentDate'] ?? $receipt['paymentDate'] ?? date('Y-m-d')), 0, 10),
                'paymentmethod' => 'CREDIT_CARD', 'note' => \asaas_card_receipt_note($receipt, $cache)])) {
                throw new \RuntimeException('receipt_pending');
            }
        }
        $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'confirmed',
            'payment_id' => $payment['id'], 'reason_code' => 'confirmed', 'next_check_at' => null]);
        \asaas_recurring_diagnostic('confirmed', (int) $invoice->id);
        return $this->result(true, 'confirmed', 'confirmed');
    }

    private function decline(object $invoice, array $job, array $authorization): array
    {
        $this->store->updateJob((int) $invoice->id, ['processing', 'uncertain'], ['state' => 'declined', 'reason_code' => 'declined']);
        $this->stop($authorization, 'declined');
        \asaas_recurring_diagnostic('declined', (int) $invoice->id);
        return $this->result(false, 'declined', 'declined');
    }

    /** Keep the native webhook ACK fast: only schedule a GET review, never HTTP or a profile lock. */
    public function observePayment(array $payment, string $event): void
    {
        if (!$this->store->ready() || !is_string($payment['id'] ?? null)
            || !preg_match('/^pay_[A-Za-z0-9_-]{1,100}$/D', $payment['id'])) { return; }
        $job = $this->store->jobByPayment($payment['id']);
        if ($job !== [] && in_array($job['state'], ['processing', 'uncertain'], true)) {
            $this->store->updateJob((int) $job['invoice_id'], ['processing', 'uncertain'], ['next_check_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Independent of reconciliation.sync_enabled; the native CRM cron invokes this bounded worker. */
    public static function runCron(): array { return \asaas_recurring_service()->work(); }

    public function work(): array
    {
        $result = ['checked' => 0, 'confirmed' => 0, 'declined' => 0, 'uncertain' => 0, 'skipped' => 0];
        if (!$this->store->ready() || $this->store->transactionDepth() !== 0) { return $result; }
        $deadline = microtime(true) + 45;
        if ($this->enabled()) {
            foreach ($this->store->missingJobs(40) as $candidate) {
                if (microtime(true) >= $deadline) { break; }
                try {
                    $id = (int) $candidate['id'];
                    if (!$this->enqueue($id) && $this->store->job($id) === []) {
                        $invoice = $this->store->invoice($id);
                        $authorization = $this->store->authorizationById((int) $candidate['authorization_id']);
                        if ($invoice && $authorization !== []) {
                            $skipped = $this->jobData($invoice, $authorization, false);
                            $skipped['state'] = 'skipped'; $skipped['reason_code'] = 'invoice_ineligible';
                            $this->store->insertJob($skipped);
                        }
                    }
                } catch (\Throwable $error) { \asaas_recurring_diagnostic('storage_unavailable', (int) ($candidate['id'] ?? 0)); }
            }
        }
        foreach ($this->store->dueJobs(10) as $job) {
            if (microtime(true) >= $deadline) { break; }
            try {
                $outcome = $this->processInvoice((int) $job['invoice_id']);
                ++$result['checked'];
                if (isset($result[$outcome['state']])) { ++$result[$outcome['state']]; }
            } catch (\Throwable $error) { \asaas_recurring_diagnostic('storage_unavailable', (int) $job['invoice_id']); }
        }
        return $result;
    }

    private function offer(object $invoice): array
    {
        $discount = $this->gateway->card_checkout()->discount();
        $price = \asaas_card_price($this->store->balance($invoice), 1, \asaas_card_pricing_settings($this->gateway), $discount);
        $context = json_encode([(string) $invoice->hash, (string) ($invoice->currency ?? ''), (string) $invoice->clientid,
            (string) ($invoice->duedate ?? ''), $discount], JSON_THROW_ON_ERROR);
        return ['principal' => \asaas_card_money($price['principal_cents']), 'fee' => \asaas_card_money($price['fee_cents']),
            'total' => \asaas_card_money($price['charged_cents']), 'quote' => \asaas_card_pricing_token($price, $context)];
    }

    /** Only the effective one-payment customer policy participates; unrelated installment changes do not. */
    private function policy(): string
    {
        $s = \asaas_card_pricing_settings($this->gateway);
        $passed = $s['card_fee_mode'] === 'customer' && $s['card_free_installments'] === 0;
        $policy = ['version' => 1, 'mode' => $passed ? 'customer' : 'absorb', 'discount' => $this->gateway->card_checkout()->discount()];
        if ($passed) {
            $policy['rate'] = $s['card_fee_percent_1']; $policy['fixed'] = $s['card_fee_fixed'];
            $policy['anticipation'] = $s['card_anticipation_mode'];
            if ($s['card_anticipation_mode'] !== 'none') {
                $policy['anticipation_rate'] = $s['card_anticipation_' . $s['card_anticipation_mode'] . '_rate_1'];
                $policy['anticipation_days'] = $s['card_anticipation_first_days'];
            }
        }
        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    private function scope(object $invoice): array
    {
        if ((int) ($invoice->clientid ?? 0) <= 0) { throw new \RuntimeException('scope_changed'); }
        $provider = $this->gateway->getProvider();
        $environment = $provider->env();
        $key = $provider->apiKey();
        if (!in_array($environment, ['sandbox', 'production'], true) || !is_string($key) || $key === '') {
            throw new \RuntimeException('scope_changed');
        }
        foreach (['issuer_id', 'emitter_id', 'issuer_company_id'] as $name) {
            if (isset($invoice->$name) && !in_array($invoice->$name, ['', 0, '0', null], true)) { throw new \RuntimeException('scope_changed'); }
        }
        $company = 0;
        foreach (['company_id', 'companyid'] as $name) {
            if (!isset($invoice->$name) || in_array($invoice->$name, ['', 0, '0'], true)) { continue; }
            $id = (string) $invoice->$name;
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 2147483647
                || ($company !== 0 && $company !== (int) $id)) { throw new \RuntimeException('scope_changed'); }
            $company = (int) $id;
        }
        if ($company !== 0 && ($provider->env($company) !== $environment || !hash_equals($key, (string) $provider->apiKey($company)))) {
            // The current transport uses the base gateway. Never guess a different issuer's credentials.
            throw new \RuntimeException('scope_changed');
        }
        return ['key' => hash('sha256', json_encode([$environment, hash('sha256', $key), $company], JSON_THROW_ON_ERROR)),
            'environment' => $environment, 'company_id' => $company];
    }

    /** Direct links and cron must enforce the same currency restriction as the native gateway. */
    private function brl(object $invoice): bool
    {
        $currency = strtoupper((string) ($invoice->currency_name ?? ''));
        if ($currency === '' && function_exists('get_currency') && isset($invoice->currency)) {
            $record = get_currency($invoice->currency);
            $currency = strtoupper((string) (is_object($record) ? ($record->name ?? '') : (is_array($record) ? ($record['name'] ?? '') : '')));
        }
        return $currency === 'BRL';
    }

    private function envelope(array $authorization): array
    {
        return ['version' => 1, 'client_id' => (int) $authorization['client_id'], 'scope_key' => $authorization['scope_key'],
            'revision' => (int) $authorization['revision'], 'customer_id' => $authorization['customer_id']];
    }
    private function token(#[\SensitiveParameter] array $authorization): string
    {
        try {
            $data = json_decode($this->store->unseal($authorization['token_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_intersect_key($data, $this->envelope($authorization)) !== $this->envelope($authorization)
                || !is_string($data['token'] ?? null) || ctype_digit($data['token']) || !preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $data['token'])) {
                throw new \RuntimeException('vault_unavailable');
            }
            return $data['token'];
        } catch (\Throwable $error) { throw new \RuntimeException('vault_unavailable'); }
    }
    private function fresh(object $invoice): object
    {
        $fresh = $this->store->invoice((int) ($invoice->id ?? 0));
        if (!$fresh || (string) $fresh->hash !== (string) ($invoice->hash ?? '') || (int) $fresh->clientid !== (int) ($invoice->clientid ?? 0)) {
            throw new \RuntimeException('invoice_changed');
        }
        return $fresh;
    }
    private function snapshot(object $invoice): string
    {
        return hash('sha256', json_encode([(int) $invoice->id, (string) $invoice->hash, (int) $invoice->clientid,
            \asaas_payment_amount_to_cents($invoice->total), $this->store->balance($invoice), (string) ($invoice->currency ?? ''),
            (string) ($invoice->duedate ?? ''), (int) $invoice->status, $this->modes($invoice)], JSON_THROW_ON_ERROR));
    }
    private function charges(object $invoice): array { return \asaas_lifecycle_charges($invoice); }
    private function assertCharge(object $invoice, array $payment, string $customerId): void
    {
        if (!preg_match('/^cus_[A-Za-z0-9_-]{1,100}$/D', $customerId) || ($payment['customer'] ?? null) !== $customerId) {
            throw new \RuntimeException('customer_mismatch');
        }
        if (!is_string($payment['id'] ?? null) || !preg_match('/^pay_[A-Za-z0-9_-]{1,100}$/D', $payment['id'])
            || !empty($payment['deleted']) || isset($payment['error']) || isset($payment['errors'])
            || ($payment['externalReference'] ?? null) !== (string) $invoice->hash) { throw new \RuntimeException('charge_mismatch'); }
    }
    private function stop(array $authorization, string $reason): void
    {
        if ($authorization !== []) { $this->store->stopAuthorization($authorization, 'suspended', $reason); }
    }
    private function release(string $lock): void
    {
        try { $this->store->unlock($lock); } catch (\Throwable $error) { \asaas_recurring_diagnostic('storage_unavailable'); }
    }
    private function reason(\Throwable $error, string $fallback): string
    {
        // Messages from the provider/DB may contain secrets; match fixed reason codes only.
        $reason = $error->getMessage();
        return in_array($reason, ['transaction_active', 'unavailable', 'storage_unavailable', 'vault_unavailable', 'busy',
            'invalid_consent', 'contact_mismatch', 'quote_changed', 'policy_changed', 'scope_changed', 'authorization_changed',
            'invoice_changed', 'invoice_ineligible', 'customer_mismatch', 'charge_mismatch', 'tokenization_failed',
            'https_required', 'uncertain', 'receipt_pending', 'other_mandate'], true) ? $reason : $fallback;
    }
    private function result(bool $ok, string $state, string $reason): array
    {
        return ['ok' => $ok, 'state' => $state, 'attempt_state' => $state, 'message' => \asaas_recurring_message($reason),
            'error_code' => $ok ? null : $reason];
    }
}
