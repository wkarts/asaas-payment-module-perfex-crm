<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_payment_validation_helper.php';

/** Only fixed, local messages may be surfaced in diagnostics for pricing failures. */
final class AsaasCardReceiptException extends RuntimeException
{
    private string $reason;

    public function __construct(string $reason)
    {
        $messages = [
            'pricing_invalid' => 'A composição do pagamento por cartão é inválida. Confira a tentativa antes de registrar o recebimento.',
            'allocation_missing' => 'O cartão aguarda a conferência completa das parcelas. Compare ou concilie a fatura para validar o recebimento; não repita o pagamento.',
            'allocation_invalid' => 'A distribuição do principal entre as parcelas não confere. Nenhum recebimento deve ser registrado antes da conferência.',
            'payment_mismatch' => 'A parcela recebida não corresponde à composição validada do pagamento por cartão.',
            'balance_mismatch' => 'O principal da parcela supera o saldo atual da fatura. Confira os recebimentos antes de continuar.',
        ];
        $this->reason = isset($messages[$reason]) ? $reason : 'pricing_invalid';
        parent::__construct($messages[$this->reason]);
    }

    public function reason(): string { return $this->reason; }
}

/** Validate the immutable nominal quote; fees do not become invoice principal. */
function asaas_card_validate_pricing(array $pricing): array
{
    foreach (['version', 'principal_cents', 'charged_cents', 'fee_cents', 'installment_count'] as $field) {
        if (!isset($pricing[$field]) || !is_int($pricing[$field])) {
            throw new AsaasCardReceiptException('pricing_invalid');
        }
    }
    if ($pricing['version'] !== 1 || $pricing['principal_cents'] <= 0
        || $pricing['charged_cents'] < $pricing['principal_cents']
        || $pricing['fee_cents'] !== $pricing['charged_cents'] - $pricing['principal_cents']
        || $pricing['installment_count'] < 1 || $pricing['installment_count'] > 21) {
        throw new AsaasCardReceiptException('pricing_invalid');
    }
    return $pricing;
}

/** Null means legacy pricing only. Present but malformed metadata must never fall back. */
function asaas_card_receipt_pricing(array $cache): ?array
{
    $attempt = $cache['_asaas_lifecycle']['card_attempt'] ?? [];
    if (!is_array($attempt)) { throw new AsaasCardReceiptException('pricing_invalid'); }
    if (!array_key_exists('pricing', $attempt)) { return null; }
    if (!is_array($attempt['pricing'])) { throw new AsaasCardReceiptException('pricing_invalid'); }
    $pricing = asaas_card_validate_pricing($attempt['pricing']);
    try {
        if (($attempt['installment_count'] ?? null) !== $pricing['installment_count']
            || asaas_payment_amount_to_cents($attempt['amount'] ?? null) !== $pricing['charged_cents']) {
            throw new AsaasCardReceiptException('pricing_invalid');
        }
    } catch (InvalidArgumentException $error) {
        throw new AsaasCardReceiptException('pricing_invalid');
    }
    return $pricing;
}

/** Exact floor(a*b/divisor) and remainder, without ever multiplying monetary integers. */
function asaas_card_allocation_ratio(int $a, int $b, int $divisor): array
{
    if ($divisor <= 0 || $a < 0 || $a > $divisor || $b < 0 || $b > $divisor) {
        throw new AsaasCardReceiptException('allocation_invalid');
    }
    $quotient = 0;
    $remainder = 0;
    foreach (str_split(decbin($b)) as $bit) {
        // The accumulated quotient is bounded by a (and therefore PHP_INT_MAX).
        $quotient += $quotient;
        if ($remainder >= $divisor - $remainder) {
            $remainder -= $divisor - $remainder;
            ++$quotient;
        } else {
            $remainder += $remainder;
        }
        if ($bit === '1') {
            if ($remainder >= $divisor - $a) {
                $remainder -= $divisor - $a;
                ++$quotient;
            } else {
                $remainder += $a;
            }
        }
    }
    return [$quotient, $remainder];
}

function asaas_card_allocation_identifier($value): bool
{
    return is_string($value) && strlen($value) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function asaas_card_allocation_number($value, int $count): int
{
    if (is_string($value) && preg_match('/^[1-9][0-9]?$/D', $value)) { $value = (int) $value; }
    if (!is_int($value) || $value < 1 || $value > $count) { throw new AsaasCardReceiptException('allocation_invalid'); }
    return $value;
}

/** Called only after the complete provider group has passed invoice/customer validation. */
function asaas_card_allocate_pricing(array $pricing, array $allPayments): array
{
    $pricing = asaas_card_validate_pricing($pricing);
    $count = $pricing['installment_count'];
    if (count($allPayments) !== $count) { throw new AsaasCardReceiptException('allocation_invalid'); }
    $parcels = [];
    $numbers = [];
    $total = 0;
    $groupId = null;
    $customer = null;
    $reference = null;
    foreach ($allPayments as $payment) {
        if (!is_array($payment) || !asaas_card_allocation_identifier($payment['id'] ?? null)
            || isset($parcels[$payment['id']]) || ($payment['billingType'] ?? null) !== 'CREDIT_CARD'
            || !empty($payment['deleted']) || isset($payment['errors']) || isset($payment['error'])
            || !is_string($payment['customer'] ?? null) || $payment['customer'] === ''
            || !is_string($payment['externalReference'] ?? null) || $payment['externalReference'] === '') {
            throw new AsaasCardReceiptException('allocation_invalid');
        }
        $number = asaas_card_allocation_number($payment['installmentNumber'] ?? ($count === 1 ? 1 : null), $count);
        $installment = $payment['installment'] ?? '';
        if (isset($numbers[$number]) || ($count > 1 && !asaas_card_allocation_identifier($installment))
            || ($count === 1 && $installment !== '' && $installment !== null)) {
            throw new AsaasCardReceiptException('allocation_invalid');
        }
        $installment = $count === 1 ? '' : $installment;
        if ($groupId !== null && ($installment !== $groupId || $payment['customer'] !== $customer || $payment['externalReference'] !== $reference)) {
            throw new AsaasCardReceiptException('allocation_invalid');
        }
        $groupId = $installment;
        $customer = $payment['customer'];
        $reference = $payment['externalReference'];
        try { $gross = asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null); }
        catch (InvalidArgumentException $error) { throw new AsaasCardReceiptException('allocation_invalid'); }
        if ($gross <= 0 || $gross > $pricing['charged_cents'] - $total) { throw new AsaasCardReceiptException('allocation_invalid'); }
        $total += $gross;
        $numbers[$number] = true;
        [$principal, $remainder] = asaas_card_allocation_ratio($pricing['principal_cents'], $gross, $pricing['charged_cents']);
        $parcels[$payment['id']] = ['gross_cents' => $gross, 'principal_cents' => $principal,
            'fee_cents' => 0, 'installment_number' => $number, 'remainder' => $remainder];
    }
    if ($total !== $pricing['charged_cents']) { throw new AsaasCardReceiptException('allocation_invalid'); }
    uasort($parcels, static fn (array $a, array $b): int => $a['installment_number'] <=> $b['installment_number']);
    $remaining = $pricing['principal_cents'] - array_sum(array_column($parcels, 'principal_cents'));
    if ($remaining < 0 || $remaining >= $count) { throw new AsaasCardReceiptException('allocation_invalid'); }
    $ranked = array_map('strval', array_keys($parcels));
    usort($ranked, static function (string $a, string $b) use ($parcels): int {
        return ($parcels[$b]['remainder'] <=> $parcels[$a]['remainder'])
            ?: (($parcels[$a]['installment_number'] <=> $parcels[$b]['installment_number']) ?: strcmp($a, $b));
    });
    for ($index = 0; $index < $remaining; ++$index) { ++$parcels[$ranked[$index]]['principal_cents']; }
    foreach ($parcels as &$parcel) {
        if ($parcel['principal_cents'] <= 0 || $parcel['principal_cents'] > $parcel['gross_cents']) {
            throw new AsaasCardReceiptException('allocation_invalid');
        }
        $parcel['fee_cents'] = $parcel['gross_cents'] - $parcel['principal_cents'];
        unset($parcel['remainder']);
    }
    unset($parcel);
    if (array_key_exists('allocations', $pricing)) {
        if (!is_array($pricing['allocations']) || count($pricing['allocations']) !== $count
            || ($pricing['installment_id'] ?? null) !== $groupId) { throw new AsaasCardReceiptException('allocation_invalid'); }
        foreach ($parcels as $id => $parcel) {
            $stored = $pricing['allocations'][$id] ?? null;
            if (!is_array($stored) || count($stored) !== count($parcel)) {
                throw new AsaasCardReceiptException('allocation_invalid');
            }
            foreach ($parcel as $field => $value) {
                if (!array_key_exists($field, $stored) || $stored[$field] !== $value) { throw new AsaasCardReceiptException('allocation_invalid'); }
            }
        }
    }
    $pricing['installment_id'] = $groupId;
    $pricing['allocations'] = $parcels;
    return $pricing;
}

/** Validate stored allocations even when the caller only has a local transaction ID. */
function asaas_card_receipt_allocations(array $cache): ?array
{
    $pricing = asaas_card_receipt_pricing($cache);
    if ($pricing === null) { return null; }
    if (!array_key_exists('allocations', $pricing)) { throw new AsaasCardReceiptException('allocation_missing'); }
    if (!is_array($pricing['allocations']) || count($pricing['allocations']) !== $pricing['installment_count']) {
        throw new AsaasCardReceiptException('allocation_invalid');
    }
    $members = [];
    foreach ($pricing['allocations'] as $id => $parcel) {
        if (!is_array($parcel) || !is_int($parcel['gross_cents'] ?? null) || $parcel['gross_cents'] <= 0
            || !is_int($parcel['installment_number'] ?? null)) { throw new AsaasCardReceiptException('allocation_invalid'); }
        $members[] = ['id' => (string) $id, 'billingType' => 'CREDIT_CARD', 'customer' => 'validated-allocation',
            'externalReference' => 'validated-allocation', 'installment' => $pricing['installment_id'] ?? null,
            'installmentNumber' => $parcel['installment_number'], 'value' => asaas_card_money($parcel['gross_cents'])];
    }
    return asaas_card_allocate_pricing($pricing, $members);
}

/** Revalidate the persisted distribution, then bind the event to one exact nominal parcel. */
function asaas_card_receipt_principal(array $payment, array $cache): ?int
{
    $pricing = asaas_card_receipt_allocations($cache);
    if ($pricing === null) { return null; }
    $id = $payment['id'] ?? null;
    $parcel = is_string($id) ? ($pricing['allocations'][$id] ?? null) : null;
    try {
        $gross = asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null);
        $number = asaas_card_allocation_number($payment['installmentNumber'] ?? ($pricing['installment_count'] === 1 ? 1 : null), $pricing['installment_count']);
    } catch (InvalidArgumentException | AsaasCardReceiptException $error) {
        throw new AsaasCardReceiptException('payment_mismatch');
    }
    if ($parcel === null || ($payment['billingType'] ?? null) !== 'CREDIT_CARD' || !empty($payment['deleted'])
        || isset($payment['error']) || isset($payment['errors']) || $parcel['gross_cents'] !== $gross
        || $parcel['installment_number'] !== $number || ($payment['installment'] ?? '') !== $pricing['installment_id']) {
        throw new AsaasCardReceiptException('payment_mismatch');
    }
    foreach ([$cache['customer'] ?? null, $cache['_asaas_lifecycle']['card_attempt']['customer_id'] ?? null] as $customer) {
        if ($customer !== null && (!is_string($customer) || ($payment['customer'] ?? null) !== $customer)) {
            throw new AsaasCardReceiptException('payment_mismatch');
        }
    }
    if (isset($cache['externalReference']) && ($payment['externalReference'] ?? null) !== $cache['externalReference']) {
        throw new AsaasCardReceiptException('payment_mismatch');
    }
    return $parcel['principal_cents'];
}

function asaas_card_receipt_note(array $payment, array $cache): string
{
    $principal = asaas_card_receipt_principal($payment, $cache);
    if ($principal === null) { return ''; }
    $gross = asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value']);
    $money = static fn (int $cents): string => 'R$ ' . str_replace('.', ',', asaas_card_money($cents));
    return 'Cartão: principal da fatura ' . $money($principal) . '; encargo repassado ' . $money($gross - $principal)
        . '; bruto nominal ' . $money($gross) . ' (antes dos descontos do Asaas).';
}
