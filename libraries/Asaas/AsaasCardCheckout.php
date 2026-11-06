<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_card_helper.php';
require_once __DIR__ . '/../../helpers/asaas_card_pricing_helper.php';
require_once __DIR__ . '/../../helpers/asaas_lifecycle_helper.php';
require_once __DIR__ . '/AsaasCardApi.php';
require_once __DIR__ . '/AsaasCardCheckoutException.php';

/** Card-specific orchestration; the existing boleto/Pix gateways keep their contracts. */
final class AsaasCardCheckout
{
    private object $gateway;
    private object $api;

    public function __construct(object $gateway, ?object $api = null)
    {
        $this->gateway = $gateway;
        $this->api = $api ?? new AsaasCardApi($gateway->getProvider());
    }

    public function discount(): array
    {
        return [
            'type' => (int) $this->gateway->getSetting('discount_type') === 1 ? 'FIXED' : 'PERCENTAGE',
            'value' => $this->gateway->getSetting('discount_value') ?: 0,
            'dueDateLimitDays' => (int) $this->gateway->getSetting('discount_days'),
        ];
    }

    public function available(object $invoice): bool
    {
        return (string) $this->gateway->getSetting('card_only') === '1'
            && (string) $this->gateway->getSetting('active') !== '0'
            && in_array('asaas', \asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []), true)
            && (!function_exists('asaas_recurring_invoice_blocks') || !\asaas_recurring_invoice_blocks((int) $invoice->id))
            && !in_array((int) ($invoice->status ?? 0), [2, 5, 6], true);
    }

    /** Render only server-calculated offers; no card credentials are involved in this GET. */
    public function installmentOptions(object $invoice): array
    {
        $balance = get_invoice_total_left_to_pay((int) $invoice->id, $invoice->total);
        if (is_numeric($balance) && (float) $balance <= 0) { return []; }
        $settings = \asaas_card_pricing_settings($this->gateway);
        $cached = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map((int) $invoice->id));
        $existingCount = !empty($cached['installment'])
            ? (int) ($cached['_asaas_lifecycle']['card_attempt']['installment_count'] ?? 0) : 0;
        $options = [];
        for ($count = 1; $count <= $settings['installmentCount']; ++$count) {
            if ($existingCount > 0 && $count !== $existingCount) { continue; }
            try {
                $price = \asaas_card_price($balance, $count, $settings, $this->discount());
            } catch (\InvalidArgumentException | \RuntimeException $error) {
                // The configured minimum and the Asaas minimum may remove individual offers.
                continue;
            }
            $total = (int) $price['charged_cents'];
            $parcel = intdiv($total, $count);
            $last = $parcel + $total % $count;
            $format = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
            $label = $count === 1 ? 'À vista — ' . $format($total)
                : $count . 'x ' . ($parcel === $last ? 'de ' : 'a partir de ') . $format($parcel) . ' — total ' . $format($total);
            if ($count > 12) { $label .= ' (Visa/Mastercard)'; }
            $options[] = [
                'count' => $count, 'label' => $label,
                'principal' => \asaas_card_money($price['principal_cents']),
                'fee' => \asaas_card_money($price['fee_cents']),
                'total' => \asaas_card_money($total),
                'schedule' => $count === 1 ? 'Pagamento à vista.' : ($count - 1) . ' parcela(s) de ' . $format($parcel)
                    . ' e a última de ' . $format($last) . '.',
                'quote' => \asaas_card_pricing_token($price, $this->pricingContext($invoice)),
            ];
        }
        return $options;
    }

    private function pricingContext(object $invoice): string
    {
        return json_encode([(string) $invoice->hash, (string) ($invoice->currency ?? ''),
            (string) ($invoice->clientid ?? ''), (string) ($invoice->duedate ?? ''), $this->discount()], JSON_THROW_ON_ERROR);
    }

    /** GETs may reconcile a known attempt, but must never start another card charge. */
    public function reconcile(object $invoice): ?array
    {
        $invoiceId = (int) $invoice->id;
        \asaas_receipt_lock($invoiceId);
        try {
            $invoice = \asaas_lifecycle_invoice((int) $invoice->id);
            return $invoice ? $this->reconcileLocked($invoice) : null;
        } finally {
            \asaas_receipt_unlock($invoiceId);
        }
    }

    private function reconcileLocked(object $invoice): ?array
    {
        $cache = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map((int) $invoice->id));
        if (in_array($cache['_asaas_lifecycle']['state'] ?? '', ['deleted', 'cancelled', 'manual_received', 'manual_deleted'], true)
            || in_array((int) $invoice->status, [5, 6], true)) {
            return null;
        }
        if (!\asaas_card_attempt_blocks($cache)) {
            return null;
        }
        $attempt = $cache['_asaas_lifecycle']['card_attempt'];
        $retired = array_unique(array_merge($cache['_asaas_lifecycle']['retired_payment_ids'] ?? [], $attempt['replaced_ids'] ?? []));
        $id = (string) ($cache['id'] ?? '');
        if ($id === '' || in_array($id, $retired, true)) {
            $matches = $this->gateway->get_charge2((string) $invoice->hash);
            $candidates = [];
            foreach (is_array($matches) ? $matches : [] as $item) {
                $item = (array) $item;
                if (($item['externalReference'] ?? null) === $invoice->hash && ($item['billingType'] ?? '') === 'CREDIT_CARD'
                    && !empty($item['id']) && empty($item['deleted'])
                    && !in_array($item['id'], $retired, true)) {
                    $candidates[] = $item;
                }
            }
            if ($candidates === []) {
                return null; // An empty list after a timeout does not prove that charging failed.
            }
            $groups = array_unique(array_map(static fn (array $p): string => (string) ($p['installment'] ?? $p['id']), $candidates));
            if (count($groups) !== 1) {
                throw new AsaasCardCheckoutException('Há mais de uma cobrança de cartão para conciliar. Consulte o financeiro.');
            }
            usort($candidates, static fn (array $a, array $b): int => ($a['installmentNumber'] ?? 1) <=> ($b['installmentNumber'] ?? 1));
            $payment = $candidates[0];
        } else {
            $payment = $this->api->getPayment($id);
        }
        $this->checkResponse($invoice, $payment, $attempt['customer_id'] ?? null);
        return $this->complete($invoice, $payment, $attempt);
    }

    public function pay(array $data): array
    {
        return $this->payInvoice($data);
    }

    /** Internal authorized path: a single existing charge, with no PAN or invented payer IP. */
    public function payAuthorizedToken(object $invoice, #[\SensitiveParameter] string $token, string $customerId, callable $assertAuthorized): array
    {
        if (!preg_match('/\A[A-Za-z0-9._~-]{16,512}\z/D', $token) || ctype_digit($token)
            || !preg_match('/\Acus_[A-Za-z0-9_-]{1,100}\z/D', $customerId)) {
            throw new AsaasCardCheckoutException('A autorização recorrente precisa ser cadastrada novamente.');
        }
        return $this->payInvoice(['invoice' => $invoice], [
            'token' => $token, 'customer_id' => $customerId, 'assert' => $assertAuthorized,
        ]);
    }

    /** The public one-time checkout keeps its original validation and payment contracts. */
    private function payInvoice(array $data, #[\SensitiveParameter] ?array $authorized = null): array
    {
        $invoiceId = (int) ($data['invoice']->id ?? 0);
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Fatura não encontrada.');
        }
        $ci = &get_instance();
        // The attempt marker must be durable before sending a non-idempotent request.
        if (\asaas_atomic_payment_depth($ci->db) !== 0) {
            throw new AsaasCardCheckoutException('Conclua a alteração da fatura antes de iniciar o pagamento por cartão.');
        }
        \asaas_receipt_lock($invoiceId);
        try {
            $invoice = \asaas_lifecycle_invoice($invoiceId);
            $available = $invoice && ($authorized === null ? $this->available($invoice)
                : ((string) get_option('paymentmethod_asaas_recurring_active') === '1'
                    && (string) $this->gateway->getSetting('active') === '1'
                    && array_intersect(['asaas', 'asaas_recurring'], \asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? [])) !== []
                    && !in_array((int) ($invoice->status ?? 0), [2, 5, 6], true)));
            if (!$invoice || !hash_equals((string) $invoice->hash, (string) ($data['invoice']->hash ?? '')) || !$available) {
                throw new \InvalidArgumentException('O pagamento por cartão não está disponível para esta fatura.');
            }
            if ($authorized !== null) { ($authorized['assert'])($invoice); }
            $cache = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map($invoiceId));
            if (\asaas_card_attempt_blocks($cache)) {
                $payment = $this->reconcileLocked($invoice);
                if ($payment !== null) {
                    return $payment;
                }
                throw new AsaasCardCheckoutException('Existe uma tentativa de cartão aguardando confirmação. Não repita o pagamento; consulte o financeiro.');
            }
            $settings = \asaas_card_pricing_settings($this->gateway);
            $card = $authorized === null
                ? \asaas_card_validate_input((array) ($data['card'] ?? []), $settings['installmentCount'])
                : ['installmentCount' => 1];
            $balance = \asaas_card_money(\asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($invoiceId, $invoice->total)));
            $count = $card['installmentCount'];
            try {
                $price = \asaas_card_price($balance, $count, $settings, $this->discount());
            } catch (\InvalidArgumentException $error) {
                // Preserve the gateway's existing HTTP-400 monetary-validation exception contract.
                if ((int) $error->getCode() === 400) { throw new \RuntimeException($error->getMessage(), 400); }
                throw $error;
            }
            $quote = $data['card']['quote'] ?? '';
            if ($authorized === null && ($price['fee_cents'] > 0 || $quote !== '') && (!is_string($quote)
                || !hash_equals(\asaas_card_pricing_token($price, $this->pricingContext($invoice)), $quote))) {
                throw new \InvalidArgumentException('As condições do cartão mudaram ou não foram confirmadas. Confira o total e as parcelas novamente antes de pagar.');
            }
            $charged = \asaas_card_money($price['charged_cents']);
            $amount = $count === 1 ? ['value' => $charged] : ['installmentCount' => $count, 'totalValue' => $charged];
            \asaas_payment_validate_payload(['billingType' => 'CREDIT_CARD', 'discount' => $this->discount()] + $amount);
            $remoteIp = $authorized === null && isset($ci->input) ? $ci->input->ip_address() : '';
            if ($authorized === null && (!is_string($remoteIp) || !filter_var($remoteIp, FILTER_VALIDATE_IP))) {
                throw new \InvalidArgumentException('Não foi possível identificar o dispositivo do pagador.');
            }
            if ($authorized === null && function_exists('is_https') && !is_https()) {
                throw new AsaasCardCheckoutException('Acesse esta página por HTTPS para pagar com cartão.');
            }
            $charges = \asaas_lifecycle_charges($invoice);
            $existingInstallment = false;
            if ($charges !== [] && !empty($charges[0]['installment'])) {
                $group = $charges[0]['installment'];
                $sum = 0;
                foreach ($charges as $charge) {
                    if (($charge['installment'] ?? null) !== $group || !in_array($charge['status'] ?? '', ['PENDING', 'OVERDUE'], true)) {
                        throw new AsaasCardCheckoutException('O parcelamento existente precisa de conciliação antes de outra tentativa.');
                    }
                    $sum += \asaas_payment_amount_to_cents($charge['originalValue'] ?? $charge['value']);
                }
                if (count($charges) !== $count || $sum !== $price['charged_cents']) {
                    throw new AsaasCardCheckoutException('O parcelamento existente possui outras condições. Consulte o financeiro para conferir o vínculo antes de pagar.');
                }
                usort($charges, static fn (array $a, array $b): int => ($a['installmentNumber'] ?? 1) <=> ($b['installmentNumber'] ?? 1));
                $existingInstallment = true;
            } elseif (count($charges) > 1) {
                throw new AsaasCardCheckoutException('Esta fatura já possui várias cobranças. Concilie os vínculos antes de iniciar outro pagamento.');
            }
            $previous = $charges[0] ?? null;
            if ($authorized !== null && ($previous === null || !empty($previous['installment'])
                || ($previous['customer'] ?? null) !== $authorized['customer_id'])) {
                throw new AsaasCardCheckoutException('A recorrência exige uma cobrança única já vinculada ao cliente autorizado. Confira a fatura antes de cobrar.');
            }
            if ($previous !== null && !in_array($previous['status'] ?? '', ['PENDING', 'OVERDUE'], true)) {
                throw new AsaasCardCheckoutException('A cobrança já foi processada ou está em análise. Consulte o financeiro antes de tentar novamente.');
            }
            $customerId = (string) ($previous['customer'] ?? '');
            if ($customerId === '') {
                $customer = $this->gateway->ensure_customer_from_client($invoice->client ?? (object) ['userid' => $invoice->clientid]);
                $customerId = (string) ($customer['customerId'] ?? '');
            }
            if ($customerId === '') {
                throw new AsaasCardCheckoutException('Não foi possível identificar o cliente da cobrança.');
            }
            $attempt = ['id' => bin2hex(random_bytes(16)), 'state' => 'preparing', 'started_at' => date('c'),
                'installment_count' => $count, 'amount' => $charged, 'customer_id' => $customerId, 'replaced_ids' => []];
            if ($price['fee_cents'] > 0) {
                // The principal/fee split is frozen before capture and never taken from the browser.
                $attempt['pricing'] = $price;
            }
            $payment = $previous ?? ['id' => '', 'billingType' => 'CREDIT_CARD', 'externalReference' => $invoice->hash];
            $this->save($invoiceId, $payment, $attempt);
            $removed = false;
            $prepared = false;
            $sent = false;
            $responded = false;
            try {
                if ($previous !== null && $count === 1) {
                    // Pay the already-linked charge instead of issuing another boleto/card debt.
                    $payment = $this->gateway->update_charge($previous['id'], json_encode([
                        'billingType' => 'CREDIT_CARD', 'value' => $charged,
                    ], JSON_THROW_ON_ERROR));
                    $this->checkResponse($invoice, $payment, $customerId);
                    $prepared = true;
                    if (\asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null) !== $price['charged_cents']
                        || !in_array($payment['status'], ['PENDING', 'OVERDUE'], true)) {
                        throw new AsaasCardCheckoutException('Confira o valor e o estado da cobrança antes de pagar.');
                    }
                } elseif ($previous !== null && !$existingInstallment) {
                    // Asaas cannot convert one charge to an installment through payWithCreditCard.
                    // Remove the unpaid predecessor first and keep its ID for reconciliation.
                    $attempt['replaced_ids'] = [(string) $previous['id']];
                    $this->save($invoiceId, $payment, $attempt);
                    $this->gateway->delete_charge($previous['id']);
                    $removed = true;
                }
                $attempt['state'] = 'processing';
                $this->save($invoiceId, $payment, $attempt);
                // Re-read consent/account/policy after preparing the remote charge and before capture.
                if ($authorized !== null) { ($authorized['assert'])($invoice); }
                $sent = true;
                $credentials = $authorized === null
                    ? ['creditCard' => $card['creditCard'], 'creditCardHolderInfo' => $card['creditCardHolderInfo']]
                    : ['creditCardToken' => $authorized['token']];
                if ($previous !== null && ($count === 1 || $existingInstallment)) {
                    $payment = $this->api->payPayment($previous['id'], $credentials);
                } else {
                    $payload = [
                        'customer' => $customerId, 'billingType' => 'CREDIT_CARD',
                        'dueDate' => max(date('Y-m-d'), (string) $invoice->duedate),
                        'externalReference' => (string) $invoice->hash, 'remoteIp' => $remoteIp,
                        'description' => str_replace('{invoice_number}', format_invoice_number($invoiceId), (string) $this->gateway->getSetting('description')),
                        'discount' => $this->discount(),
                        'fine' => ['value' => $this->gateway->getSetting('fine_value') ?: 0],
                        'interest' => ['value' => $this->gateway->getSetting('interest_value') ?: 0],
                        'postalService' => false,
                    ] + $amount + $credentials;
                    $payment = $this->api->createPayment($payload);
                }
                $this->checkResponse($invoice, $payment, $customerId);
                $responded = true;
                return $this->complete($invoice, $payment, $attempt);
            } catch (\Throwable $error) {
                $definitive = !$responded && (!$sent || ($error instanceof AsaasCardRequestException && !$error->requestSent)
                    || in_array((int) $error->getCode(), [400, 401, 403, 404, 422], true));
                $attempt['state'] = $definitive ? 'declined' : 'ambiguous';
                if ($definitive && !$removed) {
                    $attempt['replaced_ids'] = [];
                }
                if ($definitive && $removed && $previous !== null) {
                    try {
                        $this->gateway->restore_charge($previous['id']);
                        $payment = $previous;
                        $attempt['replaced_ids'] = [];
                    } catch (\Throwable $restoreError) {
                        $attempt['state'] = 'ambiguous';
                    }
                } elseif ($definitive && $prepared && $previous !== null && !$existingInstallment) {
                    try {
                        // Preparation does not change the due date. Reposting an expired
                        // date can reject the rollback and unnecessarily block boleto/Pix.
                        $payment = $this->gateway->update_charge($previous['id'], json_encode(array_intersect_key(
                            $previous, array_flip(['billingType', 'value', 'discount', 'fine', 'interest'])
                        ), JSON_THROW_ON_ERROR));
                    } catch (\Throwable $restoreError) {
                        $attempt['state'] = 'ambiguous';
                    }
                }
                if ($definitive && $attempt['state'] === 'declined' && !$existingInstallment) {
                    // A restored boleto/Pix must not inherit the failed card's surcharge allocation.
                    unset($attempt['pricing']);
                    if ($previous !== null && ($cache['id'] ?? null) === $previous['id']
                        && isset($cache['_asaas_lifecycle']['card_attempt']['pricing'])) {
                        $priorAttempt = $cache['_asaas_lifecycle']['card_attempt'];
                        $attempt['pricing'] = $priorAttempt['pricing'];
                        $attempt['amount'] = $priorAttempt['amount'];
                        $attempt['installment_count'] = $priorAttempt['installment_count'];
                    }
                }
                $this->save($invoiceId, $payment, $attempt);
                \asaas_diagnostic_log('error', 'Tentativa de cartão não concluída.', [
                    'invoice_id' => $invoiceId, 'payment_id' => $payment['id'] ?? '',
                    'attempt_id' => $attempt['id'], 'state' => $attempt['state'], 'http_status' => (int) $error->getCode(),
                ]);
                if ($attempt['state'] === 'ambiguous') {
                    try {
                        $resolved = $this->reconcileLocked($invoice);
                        if ($resolved !== null && \asaas_card_is_received($resolved)) {
                            return $resolved;
                        }
                    } catch (\Throwable $ignored) {
                        // Keep the durable marker; never repeat charging to discover its outcome.
                    }
                    throw new AsaasCardCheckoutException('O pagamento está aguardando confirmação. Não repita a tentativa nem pague por outro meio; consulte o financeiro.');
                }
                throw new AsaasCardCheckoutException('O pagamento não foi autorizado. Confira os dados do cartão e do titular ou consulte o financeiro.', (int) $error->getCode());
            }
        } finally {
            \asaas_receipt_unlock($invoiceId);
        }
    }

    private function checkResponse(object $invoice, array $payment, ?string $customer): void
    {
        if (!is_string($payment['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $payment['id'])
            || ($payment['externalReference'] ?? null) !== (string) $invoice->hash
            || ($customer !== null && ($payment['customer'] ?? null) !== $customer)
            || ($payment['billingType'] ?? null) !== 'CREDIT_CARD'
            || !is_string($payment['status'] ?? null) || isset($payment['errors']) || isset($payment['error'])) {
            throw new AsaasCardCheckoutException('A resposta da cobrança precisa de conciliação.');
        }
    }

    private function complete(object $invoice, array $payment, array $attempt): array
    {
        $cache = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map((int) $invoice->id));
        if (in_array($payment['id'], array_merge($cache['_asaas_lifecycle']['retired_payment_ids'] ?? [], $attempt['replaced_ids'] ?? []), true)) {
            throw new AsaasCardCheckoutException('A cobrança anterior foi substituída e não pode confirmar esta tentativa.');
        }
        $expectedCount = (int) $attempt['installment_count'];
        $expectedAmount = \asaas_payment_amount_to_cents($attempt['amount']);
        if (($expectedCount > 1) !== !empty($payment['installment'])) {
            throw new AsaasCardCheckoutException('O parcelamento retornado precisa de conciliação.');
        }
        if ($expectedCount === 1 && \asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null) !== $expectedAmount) {
            throw new AsaasCardCheckoutException('O valor confirmado precisa de conciliação com a fatura.');
        }
        $attempt['state'] = \asaas_card_is_received($payment) ? 'confirmed' : 'pending';
        // A reprovation is terminal; a pending status following a timeout is NOT a refusal.
        if (in_array($payment['status'] ?? '', ['REPROVED_BY_RISK_ANALYSIS'], true)) {
            $attempt['state'] = 'declined';
        }
        if (!array_key_exists('pricing', $attempt)) {
            $this->save((int) $invoice->id, $payment, $attempt);
        }
        $receipts = [$payment];
        if (!empty($payment['installment'])) {
            $response = $this->api->listInstallmentPayments((string) $payment['installment']);
            if (!is_array($response['data'] ?? null) || !empty($response['hasMore'])) {
                throw new AsaasCardCheckoutException('Não foi possível conferir todas as parcelas.');
            }
            $receipts = [];
            $ids = [];
            $sum = 0;
            foreach ($response['data'] as $parcel) {
                $this->checkResponse($invoice, $parcel, $attempt['customer_id'] ?? null);
                if (($parcel['installment'] ?? null) !== $payment['installment']) {
                    throw new AsaasCardCheckoutException('O parcelamento retornado precisa de conciliação.');
                }
                if (in_array($parcel['id'], $ids, true)) {
                    throw new AsaasCardCheckoutException('O parcelamento retornou cobranças repetidas.');
                }
                $ids[] = $parcel['id'];
                $sum += \asaas_payment_amount_to_cents($parcel['originalValue'] ?? $parcel['value'] ?? null);
                $receipts[] = $parcel;
            }
            if (count($ids) !== $expectedCount || $sum !== $expectedAmount || !in_array($payment['id'], $ids, true)) {
                throw new AsaasCardCheckoutException('A quantidade ou o total das parcelas precisa de conciliação.');
            }
        }
        if (array_key_exists('pricing', $attempt)) {
            $attempt['pricing'] = \asaas_card_allocate_pricing($attempt['pricing'], $receipts);
            // Persist the validated allocation before any caller can write a receipt.
            $this->save((int) $invoice->id, $payment, $attempt);
        }
        $safe = \asaas_card_safe_payload($payment);
        $safe['_asaas_card_receipts'] = array_values(array_filter(array_map('asaas_card_safe_payload', $receipts), 'asaas_card_is_received'));
        return $safe;
    }

    private function save(int $invoiceId, array $payment, array $attempt): void
    {
        $cached = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map($invoiceId));
        $metadata = $cached['_asaas_lifecycle'] ?? [];
        $metadata['card_attempt'] = $attempt;
        $metadata['state'] = 'active';
        $metadata['retired_payment_ids'] = array_values(array_unique(array_merge(
            $metadata['retired_payment_ids'] ?? [], $attempt['replaced_ids'] ?? []
        )));
        if ($attempt['state'] === 'declined' && empty($attempt['replaced_ids'])) {
            $metadata['retired_payment_ids'] = array_values(array_diff($metadata['retired_payment_ids'], [$payment['id'] ?? '']));
        }
        $payment = \asaas_card_safe_payload($payment);
        $payment['_asaas_lifecycle'] = $metadata;
        if (!$this->gateway->upsert_payment_map($invoiceId, $payment)) {
            throw new AsaasCardCheckoutException('Não foi possível registrar a tentativa de pagamento. Consulte os logs da integração.');
        }
    }
}
