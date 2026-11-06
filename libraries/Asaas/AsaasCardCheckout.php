<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_card_helper.php';
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
            && !in_array((int) ($invoice->status ?? 0), [2, 5, 6], true);
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
            if (!$invoice || !hash_equals((string) $invoice->hash, (string) ($data['invoice']->hash ?? '')) || !$this->available($invoice)) {
                throw new \InvalidArgumentException('O pagamento por cartão não está disponível para esta fatura.');
            }
            $cache = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map($invoiceId));
            if (\asaas_card_attempt_blocks($cache)) {
                $payment = $this->reconcileLocked($invoice);
                if ($payment !== null) {
                    return $payment;
                }
                throw new AsaasCardCheckoutException('Existe uma tentativa de cartão aguardando confirmação. Não repita o pagamento; consulte o financeiro.');
            }
            $card = \asaas_card_validate_input((array) ($data['card'] ?? []),
                \asaas_card_max_installments($this->gateway->getSetting('installmentCount')));
            $balance = \asaas_card_money(\asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($invoiceId, $invoice->total)));
            $count = $card['installmentCount'];
            $amount = $count === 1 ? ['value' => $balance] : ['installmentCount' => $count, 'totalValue' => $balance];
            \asaas_payment_validate_payload(['billingType' => 'CREDIT_CARD', 'discount' => $this->discount()] + $amount);
            $remoteIp = isset($ci->input) ? $ci->input->ip_address() : '';
            if (!is_string($remoteIp) || !filter_var($remoteIp, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException('Não foi possível identificar o dispositivo do pagador.');
            }
            if (function_exists('is_https') && !is_https()) {
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
                if (count($charges) !== $count || $sum !== \asaas_payment_amount_to_cents($balance)) {
                    throw new AsaasCardCheckoutException('Mantenha a quantidade de parcelas do parcelamento existente ou consulte o financeiro.');
                }
                usort($charges, static fn (array $a, array $b): int => ($a['installmentNumber'] ?? 1) <=> ($b['installmentNumber'] ?? 1));
                $existingInstallment = true;
            } elseif (count($charges) > 1) {
                throw new AsaasCardCheckoutException('Esta fatura já possui várias cobranças. Concilie os vínculos antes de iniciar outro pagamento.');
            }
            $previous = $charges[0] ?? null;
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
                'installment_count' => $count, 'amount' => $balance, 'customer_id' => $customerId, 'replaced_ids' => []];
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
                        'billingType' => 'CREDIT_CARD', 'value' => $balance,
                    ], JSON_THROW_ON_ERROR));
                    $this->checkResponse($invoice, $payment, $customerId);
                    $prepared = true;
                    if (\asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null) !== \asaas_payment_amount_to_cents($balance)
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
                $sent = true;
                $credentials = ['creditCard' => $card['creditCard'], 'creditCardHolderInfo' => $card['creditCardHolderInfo']];
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
                        $payment = $this->gateway->update_charge($previous['id'], json_encode(array_intersect_key(
                            $previous, array_flip(['billingType', 'value', 'dueDate', 'discount', 'fine', 'interest'])
                        ), JSON_THROW_ON_ERROR));
                    } catch (\Throwable $restoreError) {
                        $attempt['state'] = 'ambiguous';
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
        $this->save((int) $invoice->id, $payment, $attempt);
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
