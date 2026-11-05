<?php

declare(strict_types=1);

/** Pure decimal boundary checks; no network, database or BCMath required. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASEPATH', __DIR__ . DIRECTORY_SEPARATOR);
require_once dirname(__DIR__) . '/helpers/asaas_payment_validation_helper.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$assertions = 0;
$failures = [];
$cases = [
    'single exact minimum' => [['value' => '5.00'], true],
    'single one cent below' => [['value' => '4.99'], false],
    'single sub-cent below must not round up' => [['value' => '4.999999999999999999999999'], false],
    'single sub-cent above' => [['value' => '5.000000000000000000000001'], true],
    'single integer' => [['value' => 5], true],
    'single float' => [['value' => 5.01], true],
    'single negative' => [['value' => '-5'], false],
    'single zero' => [['value' => 0], false],
    'single null amount' => [['value' => null], false],
    'single boolean' => [['value' => true], false],
    'single array' => [['value' => []], false],
    'single NaN' => [['value' => NAN], false],
    'single infinity' => [['value' => INF], false],
    'single decimal comma not guessed' => [['value' => '5,00'], false],
    'single scientific' => [['value' => '5e0'], true],
    'single scientific fractional' => [['value' => '500e-2'], true],
    'single scientific below' => [['value' => '499e-2'], false],
    'single exponent limit' => [['value' => '5e101'], false],
    'single fixed discount minimum' => [['value' => '5.01', 'discount' => ['type' => 'FIXED', 'value' => '0.01']], true],
    'single fixed discount below' => [['value' => '5.00', 'discount' => ['type' => 'FIXED', 'value' => '0.01']], false],
    'single fixed discount exact sub-cent' => [['value' => '5.0001', 'discount' => ['type' => 'FIXED', 'value' => '.0001']], true],
    'single percentage minimum' => [['value' => 10, 'discount' => ['type' => 'PERCENTAGE', 'value' => 50]], true],
    'single percentage below' => [['value' => '9.99', 'discount' => ['type' => 'PERCENTAGE', 'value' => 50]], false],
    'single percentage fractional below' => [['value' => '5.10', 'discount' => ['type' => 'PERCENTAGE', 'value' => '2']], false],
    'single percentage fractional above' => [['value' => '5.11', 'discount' => ['type' => 'PERCENTAGE', 'value' => '2']], true],
    'single percentage precision below' => [['value' => 10, 'discount' => ['type' => 'PERCENTAGE', 'value' => '50.0000000000000001']], false],
    'single percentage over 100' => [['value' => 100, 'discount' => ['type' => 'PERCENTAGE', 'value' => 101]], false],
    'single discount invalid type' => [['value' => 100, 'discount' => ['type' => 'OTHER', 'value' => 1]], false],
    'single negative discount' => [['value' => 100, 'discount' => ['type' => 'FIXED', 'value' => -1]], false],
    'single zero discount no type' => [['value' => 5, 'discount' => ['value' => 0]], true],
    'partial due date only' => [['dueDate' => '2026-12-01'], true],
    'partial discount without amount' => [['discount' => ['type' => 'FIXED', 'value' => 100]], true],
    'partial count without amount' => [['installmentCount' => 2], true],
    'one installment single minimum' => [['installmentCount' => 1, 'billingType' => 'PIX', 'value' => 5], true],
    'card installment exact' => [['installmentCount' => 2, 'billingType' => 'CREDIT_CARD', 'installmentValue' => 5], true],
    'card installment below' => [['installmentCount' => 2, 'billingType' => 'CREDIT_CARD', 'installmentValue' => '4.99'], false],
    'card installment total exact' => [['installmentCount' => 3, 'billingType' => 'CREDIT_CARD', 'totalValue' => 15], true],
    'card installment total below' => [['installmentCount' => 3, 'billingType' => 'CREDIT_CARD', 'totalValue' => '14.99'], false],
    'card installment value total exact' => [['installmentCount' => 3, 'billingType' => 'CREDIT_CARD', 'value' => 15], true],
    'boleto installment exact' => [['installmentCount' => 2, 'billingType' => 'BOLETO', 'installmentValue' => 10], true],
    'boleto installment below' => [['installmentCount' => 2, 'billingType' => 'BOLETO', 'installmentValue' => '9.99'], false],
    'pix installment exact' => [['installmentCount' => 2, 'billingType' => 'PIX', 'totalValue' => 20], true],
    'pix installment below' => [['installmentCount' => 2, 'billingType' => 'PIX', 'totalValue' => '19.99'], false],
    'pix installment fixed discount exact' => [['installmentCount' => 2, 'billingType' => 'PIX', 'totalValue' => 22, 'discount' => ['type' => 'FIXED', 'value' => 1]], true],
    'pix installment fixed discount below' => [['installmentCount' => 2, 'billingType' => 'PIX', 'totalValue' => '21.99', 'discount' => ['type' => 'FIXED', 'value' => 1]], false],
    'pix installment percentage exact' => [['installmentCount' => 2, 'billingType' => 'PIX', 'installmentValue' => 20, 'discount' => ['type' => 'PERCENTAGE', 'value' => 50]], true],
    'pix installment percentage below' => [['installmentCount' => 2, 'billingType' => 'PIX', 'installmentValue' => '19.99', 'discount' => ['type' => 'PERCENTAGE', 'value' => 50]], false],
    'installment zero count' => [['installmentCount' => 0, 'value' => 100], false],
    'installment fractional count' => [['installmentCount' => '2.5', 'value' => 100], false],
    'installment integer decimal count' => [['installmentCount' => '2.0', 'billingType' => 'PIX', 'totalValue' => 20], true],
    'large exact decimals do not overflow integer' => [['value' => '1000000000000000000000000000.01', 'discount' => ['type' => 'FIXED', 'value' => '999999999999999999999999995.01']], true],
    'large exact decimals below' => [['value' => '1000000000000000000000000000.00', 'discount' => ['type' => 'FIXED', 'value' => '999999999999999999999999995.01']], false],
];
foreach ($cases as $name => [$payload, $valid]) {
    try {
        $before = serialize($payload);
        $error = asaas_payment_validation_error($payload);
        ++$assertions;
        if (($error === null) !== $valid) {
            throw new RuntimeException($error ?? 'An invalid amount was accepted.');
        }
        ++$assertions;
        if (serialize($payload) !== $before) {
            throw new RuntimeException('Validation changed the API payload.');
        }
        try {
            asaas_payment_validate_payload($payload);
            ++$assertions;
            if (!$valid) {
                throw new LogicException('Rejected payload did not throw before dispatch.');
            }
        } catch (RuntimeException $exception) {
            ++$assertions;
            if ($valid || $exception->getCode() !== 400 || $exception->getMessage() !== $error) {
                throw $exception;
            }
        }
        echo "OK  {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}
foreach (['0' => 0, '0.0049' => 0, '0.005' => 1, '.009' => 1, '1.234' => 123, '1.235' => 124, '5e-2' => 5, '005.500' => 550] as $value => $expected) {
    ++$assertions;
    if (asaas_payment_amount_to_cents($value) !== $expected) {
        $failures[] = 'cent rounding ' . $value;
    }
}
try {
    asaas_payment_amount_to_cents((string) PHP_INT_MAX);
    $failures[] = 'cents overflow must fail';
} catch (InvalidArgumentException $expected) {
    ++$assertions;
}

printf("\nPayment validation smoke: %d scenarios, %d assertions, %d failures.\n", count($cases) + 9, $assertions, count($failures));
exit($failures === [] ? 0 : 1);
