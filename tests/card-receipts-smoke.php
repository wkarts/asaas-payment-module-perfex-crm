<?php

declare(strict_types=1);

/** Exact nominal allocation only: no network, card credentials or CRM persistence. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/helpers/asaas_card_helper.php';
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
$scenarios = 0;
$failures = 0;
function receiptsCheck(bool $condition, string $message): void
{
    ++$GLOBALS['assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function receiptsReject(callable $callback, ?string $reason = null): void
{
    try { $callback(); }
    catch (AsaasCardReceiptException $error) {
        receiptsCheck($reason === null || $error->reason() === $reason, 'Expected safe pricing failure.');
        return;
    }
    throw new RuntimeException('Expected operation to fail closed.');
}
function receiptsTest(string $name, callable $callback): void
{
    ++$GLOBALS['scenarios'];
    try { $callback(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['failures']; echo 'FAIL ' . $name . ': ' . $error->getMessage() . ' @' . $error->getLine() . PHP_EOL; }
}
function receiptsPricing(int $principal = 10000, int $gross = 10460, int $count = 1): array
{
    return ['version' => 1, 'principal_cents' => $principal, 'charged_cents' => $gross,
        'fee_cents' => $gross - $principal, 'installment_count' => $count];
}
function receiptsPayments(array $gross): array
{
    $payments = [];
    foreach ($gross as $index => $value) {
        $payments[] = ['id' => 'pay_fixture_' . ($index + 1), 'customer' => 'cus_fixture', 'externalReference' => 'invoice-fixture',
            'billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED', 'value' => asaas_card_money($value),
            'installment' => count($gross) > 1 ? 'ins_fixture' : '', 'installmentNumber' => $index + 1];
    }
    return $payments;
}
function receiptsCache(array $pricing): array
{
    return ['customer' => 'cus_fixture', 'externalReference' => 'invoice-fixture', '_asaas_lifecycle' => ['card_attempt' => [
        'state' => 'confirmed', 'amount' => asaas_card_money($pricing['charged_cents']),
        'customer_id' => 'cus_fixture', 'installment_count' => $pricing['installment_count'], 'pricing' => $pricing,
    ]]];
}

receiptsTest('single payment separates principal and fee and writes a nominal note', static function (): void {
    $payment = receiptsPayments([10460])[0];
    $pricing = asaas_card_allocate_pricing(receiptsPricing(), [$payment]);
    $cache = receiptsCache($pricing);
    receiptsCheck(asaas_card_receipt_principal($payment, $cache) === 10000, 'Fee must not settle principal.');
    receiptsCheck(asaas_card_receipt_amount($payment, '100.00', $cache) === '100.00', 'Exact principal expected.');
    $note = asaas_card_receipt_note($payment, $cache);
    foreach (['100,00', '4,60', '104,60', 'nominal', 'antes dos descontos'] as $value) {
        receiptsCheck(str_contains($note, $value), 'Note must distinguish principal, fee and nominal gross.');
    }
    receiptsCheck(!str_contains($note, 'cus_fixture') && !str_contains($note, 'invoice-fixture'), 'Note must not include customer data.');
});

receiptsTest('uneven installments allocate exact cents in deterministic order', static function (): void {
    $payments = receiptsPayments([3434, 3434, 3433]);
    $pricing = asaas_card_allocate_pricing(receiptsPricing(10000, 10301, 3), $payments);
    receiptsCheck(array_column($pricing['allocations'], 'principal_cents') === [3334, 3333, 3333], 'Largest remainders must be deterministic.');
    receiptsCheck(array_sum(array_column($pricing['allocations'], 'fee_cents')) === 301, 'No fee cents may enter the principal.');
    receiptsCheck(asaas_card_allocate_pricing($pricing, array_reverse($payments)) === $pricing, 'Provider response order must not change a frozen allocation.');
    $reordered = $pricing;
    foreach ($reordered['allocations'] as &$parcel) { ksort($parcel); }
    unset($parcel);
    receiptsCheck(asaas_card_allocate_pricing($reordered, $payments) === $pricing, 'Serialized object key order must not invalidate an unchanged allocation.');
    $cache = receiptsCache($pricing);
    $received = 0;
    foreach (array_reverse($payments) as $payment) {
        $received += asaas_card_receipt_principal($payment, $cache);
        receiptsCheck($received <= 10000, 'Out-of-order receipts must never exceed invoice principal.');
    }
    receiptsCheck($received === 10000, 'All parcels must settle the exact principal.');
});

receiptsTest('twenty one installments preserve all monetary sums', static function (): void {
    $gross = array_fill(0, 21, 5001);
    $payments = receiptsPayments($gross);
    $pricing = asaas_card_allocate_pricing(receiptsPricing(100000, array_sum($gross), 21), $payments);
    receiptsCheck(count($pricing['allocations']) === 21, 'Maximum documented count must be supported.');
    receiptsCheck(array_sum(array_column($pricing['allocations'], 'principal_cents')) === 100000, 'Principal sum must be exact.');
    receiptsCheck(array_sum(array_column($pricing['allocations'], 'gross_cents')) === 105021, 'Gross sum must be exact.');
    foreach ($payments as $payment) { receiptsCheck(asaas_card_receipt_principal($payment, receiptsCache($pricing)) > 0, 'Every parcel needs positive principal.'); }
});

receiptsTest('integer ratio stays exact including PHP maximum without overflow', static function (): void {
    foreach ([[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX - 1, PHP_INT_MAX, PHP_INT_MAX],
        [PHP_INT_MAX - 1, PHP_INT_MAX - 1, PHP_INT_MAX]] as $index => $values) {
        $expected = [[$values[0], 0], [$values[0], 0], [PHP_INT_MAX - 2, 1]][$index];
        receiptsCheck(asaas_card_allocation_ratio(...$values) === $expected, 'Maximum integer ratio must not round via float.');
    }
    foreach (range(1, 37) as $total) {
        foreach ([1, max(1, intdiv($total, 2)), $total] as $principal) {
            foreach ([1, max(1, $total - 1), $total] as $gross) {
                receiptsCheck(asaas_card_allocation_ratio($principal, $gross, $total) === [intdiv($principal * $gross, $total), ($principal * $gross) % $total], 'Small exact ratio oracle mismatch.');
            }
        }
    }
    $part = intdiv(PHP_INT_MAX, 3);
    $payments = receiptsPayments([$part, $part, PHP_INT_MAX - $part - $part]);
    $pricing = asaas_card_allocate_pricing(receiptsPricing(PHP_INT_MAX - 2, PHP_INT_MAX, 3), $payments);
    receiptsCheck(array_sum(array_column($pricing['allocations'], 'principal_cents')) === PHP_INT_MAX - 2, 'Large allocation must remain an integer sum.');
    receiptsCheck(array_sum(array_column($pricing['allocations'], 'fee_cents')) === 2, 'Large allocation must preserve its fee cents.');
});

receiptsTest('incomplete or foreign groups never create allocations', static function (): void {
    $base = receiptsPayments([5230, 5230]);
    foreach (['missing', 'duplicate_id', 'duplicate_number', 'missing_number', 'wrong_number', 'wrong_group', 'wrong_customer', 'wrong_reference', 'sum', 'deleted', 'wrong_type'] as $case) {
        $payments = $base;
        switch ($case) {
            case 'missing': array_pop($payments); break;
            case 'duplicate_id': $payments[1]['id'] = $payments[0]['id']; break;
            case 'duplicate_number': $payments[1]['installmentNumber'] = 1; break;
            case 'missing_number': unset($payments[1]['installmentNumber']); break;
            case 'wrong_number': $payments[1]['installmentNumber'] = 3; break;
            case 'wrong_group': $payments[1]['installment'] = 'ins_other'; break;
            case 'wrong_customer': $payments[1]['customer'] = 'cus_other'; break;
            case 'wrong_reference': $payments[1]['externalReference'] = 'invoice-other'; break;
            case 'sum': $payments[1]['value'] = '52.31'; break;
            case 'deleted': $payments[1]['deleted'] = true; break;
            case 'wrong_type': $payments[1]['billingType'] = 'PIX'; break;
        }
        receiptsReject(static fn () => asaas_card_allocate_pricing(receiptsPricing(10000, 10460, 2), $payments), 'allocation_invalid');
    }
    receiptsReject(static fn () => asaas_card_allocate_pricing(receiptsPricing(1, 1000, 2), receiptsPayments([500, 500])), 'allocation_invalid');
});

receiptsTest('discounted receipts preserve the nominal allocation and current discount contract', static function (): void {
    $payment = receiptsPayments([10460])[0];
    $payment['originalValue'] = '104.60';
    $payment['value'] = '94.60';
    $payment['netValue'] = '89.00';
    $pricing = asaas_card_allocate_pricing(receiptsPricing(), [$payment]);
    receiptsCheck(asaas_card_receipt_principal($payment, receiptsCache($pricing)) === 10000, 'Existing discount must not redefine the frozen principal.');
    receiptsCheck($pricing['allocations'][$payment['id']]['gross_cents'] === 10460, 'Nominal originalValue remains authoritative.');
});

receiptsTest('corrupt snapshots and missing allocations fail closed instead of using gross', static function (): void {
    $payment = receiptsPayments([10460])[0];
    $pricing = asaas_card_allocate_pricing(receiptsPricing(), [$payment]);
    foreach (['version' => 2, 'principal_cents' => '10000', 'charged_cents' => 9999, 'fee_cents' => 459, 'installment_count' => 22] as $key => $value) {
        receiptsReject(static fn () => asaas_card_receipt_principal($payment, receiptsCache(array_replace($pricing, [$key => $value]))));
    }
    $cache = receiptsCache($pricing);
    $cache['_asaas_lifecycle']['card_attempt']['amount'] = '104.61';
    receiptsReject(static fn () => asaas_card_receipt_principal($payment, $cache), 'pricing_invalid');
    $cache = receiptsCache($pricing);
    $cache['_asaas_lifecycle']['card_attempt']['pricing'] = null;
    receiptsReject(static fn () => asaas_card_receipt_principal($payment, $cache), 'pricing_invalid');
    receiptsReject(static fn () => asaas_card_receipt_principal($payment, receiptsCache(receiptsPricing())), 'allocation_missing');
    $pricing['allocations'][$payment['id']]['principal_cents'] = 9999;
    receiptsReject(static fn () => asaas_card_receipt_principal($payment, receiptsCache($pricing)), 'allocation_invalid');
});

receiptsTest('balanced but altered allocations are detected as immutable', static function (): void {
    $payments = receiptsPayments([5230, 5230]);
    $pricing = asaas_card_allocate_pricing(receiptsPricing(10000, 10460, 2), $payments);
    --$pricing['allocations'][$payments[0]['id']]['principal_cents'];
    ++$pricing['allocations'][$payments[0]['id']]['fee_cents'];
    ++$pricing['allocations'][$payments[1]['id']]['principal_cents'];
    --$pricing['allocations'][$payments[1]['id']]['fee_cents'];
    receiptsReject(static fn () => asaas_card_receipt_principal($payments[0], receiptsCache($pricing)), 'allocation_invalid');
    receiptsReject(static fn () => asaas_card_allocate_pricing($pricing, $payments), 'allocation_invalid');
});

receiptsTest('event identifiers and bindings must match the validated allocation', static function (): void {
    $payments = receiptsPayments([5230, 5230]);
    $cache = receiptsCache(asaas_card_allocate_pricing(receiptsPricing(10000, 10460, 2), $payments));
    foreach (['id' => 'pay_foreign', 'customer' => 'cus_foreign', 'externalReference' => 'other-invoice',
        'installment' => 'ins_foreign', 'installmentNumber' => 2, 'value' => '52.29', 'billingType' => 'BOLETO', 'deleted' => true] as $field => $value) {
        receiptsReject(static fn () => asaas_card_receipt_principal(array_replace($payments[0], [$field => $value]), $cache), 'payment_mismatch');
    }
});

receiptsTest('current balance cannot clip the frozen principal', static function (): void {
    $payments = receiptsPayments([5230, 5230]);
    $cache = receiptsCache(asaas_card_allocate_pricing(receiptsPricing(10000, 10460, 2), $payments));
    receiptsCheck(asaas_card_receipt_amount($payments[0], '100.00', $cache) === '50.00', 'Only this parcel principal may be credited.');
    receiptsCheck(asaas_card_receipt_amount($payments[0], '50.00', $cache) === '50.00', 'Exact remaining balance is valid.');
    foreach (['49.99', '0.00'] as $balance) {
        receiptsReject(static fn () => asaas_card_receipt_amount($payments[0], $balance, $cache), 'balance_mismatch');
    }
});

receiptsTest('legacy, absorbed fees and restored boleto retain the previous path', static function (): void {
    $payment = receiptsPayments([10460])[0];
    receiptsCheck(asaas_card_receipt_amount($payment, '100.00') === '100.00', 'Legacy receipt clipping remains unchanged.');
    receiptsCheck(asaas_card_receipt_principal($payment, []) === null && asaas_card_receipt_note($payment, []) === '', 'No pricing snapshot must remain legacy.');
    $cache = receiptsCache(asaas_card_allocate_pricing(receiptsPricing(), [$payment]));
    $cache['_asaas_lifecycle']['card_attempt']['state'] = 'declined';
    receiptsCheck(asaas_card_receipt_principal($payment, $cache) === 10000, 'Declined status alone may never erase a real allocation.');
    unset($cache['_asaas_lifecycle']['card_attempt']['pricing']);
    $payment['billingType'] = 'BOLETO';
    receiptsCheck(asaas_card_receipt_principal($payment, $cache) === null, 'A safely restored predecessor without pricing must remain legacy.');
    receiptsCheck(asaas_card_receipt_amount($payment, '100.00', $cache) === '100.00', 'Restored boleto must use native invoice balance.');
});

echo "Card receipts smoke: {$scenarios} scenarios, {$assertions} assertions, {$failures} failures." . PHP_EOL;
exit($failures > 0 ? 1 : 0);
