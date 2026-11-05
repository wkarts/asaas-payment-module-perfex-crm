<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Validate amounts without changing the payload sent to Asaas. */
function asaas_payment_validate_payload(array $payload): void
{
    $error = asaas_payment_validation_error($payload);
    if ($error !== null) {
        throw new RuntimeException($error, 400);
    }
}

function asaas_payment_validation_error(array $payload): ?string
{
    // Partial updates without an amount must still reach the API unchanged.
    if (!array_key_exists('value', $payload) && !array_key_exists('installmentValue', $payload)
        && !array_key_exists('totalValue', $payload)) {
        return null;
    }

    try {
        $count = ['digits' => '1', 'scale' => 0];
        if (isset($payload['installmentCount']) && $payload['installmentCount'] !== '') {
            $count = asaas_payment_decimal_value($payload['installmentCount'], 'Quantidade de parcelas');
            if ($count['scale'] !== 0 || $count['digits'] === '0') {
                return 'A quantidade de parcelas deve ser um número inteiro positivo.';
            }
        }
        $installments = asaas_payment_integer_compare($count['digits'], '1') > 0;
        $billingType = is_string($payload['billingType'] ?? null) ? strtoupper($payload['billingType']) : '';
        $minimum = $installments && in_array($billingType, ['BOLETO', 'PIX'], true) ? '1000' : '500';
        $divisor = '1';

        if ($installments && array_key_exists('installmentValue', $payload)) {
            $amount = asaas_payment_decimal_value($payload['installmentValue'], 'Valor da parcela');
        } elseif ($installments && array_key_exists('totalValue', $payload)) {
            $amount = asaas_payment_decimal_value($payload['totalValue'], 'Valor total do parcelamento');
            $divisor = $count['digits'];
        } elseif (array_key_exists('value', $payload)) {
            $amount = asaas_payment_decimal_value($payload['value'], 'Valor da cobrança');
            $divisor = $installments ? $count['digits'] : '1';
        } else {
            // The API decides whether a partial installment update has enough fields.
            return null;
        }

        $left = asaas_payment_integer_multiply($amount['digits'], '100');
        $right = asaas_payment_integer_multiply($minimum . str_repeat('0', $amount['scale']), $divisor);
        $discount = is_array($payload['discount'] ?? null) ? $payload['discount'] : [];
        if (array_key_exists('value', $discount) && $discount['value'] !== null && $discount['value'] !== '') {
            $discountAmount = asaas_payment_decimal_value($discount['value'], 'Desconto');
            if ($discountAmount['digits'] !== '0') {
                $discountType = is_string($discount['type'] ?? null) ? strtoupper($discount['type']) : '';
                $scale = '1' . str_repeat('0', $discountAmount['scale']);
                if ($discountType === 'FIXED') {
                    $left = asaas_payment_integer_multiply($left, $scale);
                    $threshold = asaas_payment_integer_add(
                        asaas_payment_integer_multiply($minimum, $scale),
                        asaas_payment_integer_multiply($discountAmount['digits'], '100')
                    );
                    $right = asaas_payment_integer_multiply($threshold . str_repeat('0', $amount['scale']), $divisor);
                } elseif ($discountType === 'PERCENTAGE') {
                    $hundred = '100' . str_repeat('0', $discountAmount['scale']);
                    if (asaas_payment_integer_compare($discountAmount['digits'], $hundred) > 0) {
                        return 'O percentual de desconto não pode ultrapassar 100%.';
                    }
                    $factor = asaas_payment_integer_subtract($hundred, $discountAmount['digits']);
                    $left = asaas_payment_integer_multiply($left, $factor);
                    $right = asaas_payment_integer_multiply($right, $hundred);
                } else {
                    return 'Informe o tipo de desconto FIXED ou PERCENTAGE para validar o valor líquido da cobrança.';
                }
            }
        }

        if (asaas_payment_integer_compare($left, $right) < 0) {
            $formattedMinimum = $minimum === '1000' ? '10,00' : '5,00';
            return $installments
                ? 'O valor líquido de cada parcela no Asaas deve ser de pelo menos R$ ' . $formattedMinimum . ', considerando o desconto.'
                : 'O valor líquido da cobrança no Asaas deve ser de pelo menos R$ ' . $formattedMinimum . ', considerando o desconto.';
        }
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return null;
}

/** Convert a non-negative decimal amount to cents with half-up rounding. */
function asaas_payment_amount_to_cents($value, string $field = 'Valor'): int
{
    $decimal = asaas_payment_decimal_value($value, $field);
    if ($decimal['scale'] <= 2) {
        $cents = $decimal['digits'] . str_repeat('0', 2 - $decimal['scale']);
    } else {
        $cut = strlen($decimal['digits']) - ($decimal['scale'] - 2);
        $cents = $cut > 0 ? substr($decimal['digits'], 0, $cut) : '0';
        if ($cut >= 0 && (int) ($decimal['digits'][$cut] ?? '0') >= 5) {
            $cents = asaas_payment_integer_add($cents, '1');
        }
    }
    $cents = ltrim($cents, '0') ?: '0';
    if (asaas_payment_integer_compare($cents, (string) PHP_INT_MAX) > 0) {
        throw new InvalidArgumentException($field . ' excede a precisão monetária suportada.');
    }

    return (int) $cents;
}

/** @return array{digits: string, scale: int} Unsigned coefficient and decimal scale. */
function asaas_payment_decimal_value($value, string $field): array
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || (is_float($value) && !is_finite($value))) {
        throw new InvalidArgumentException($field . ' deve ser um número decimal válido.');
    }
    $text = is_float($value) ? (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION) : trim((string) $value);
    if (strlen($text) > 128 || !preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $text, $matches)) {
        throw new InvalidArgumentException($field . ' deve ser um número decimal válido, com ponto como separador.');
    }
    $exponent = isset($matches[5]) ? (int) $matches[5] : 0;
    if ($exponent < -100 || $exponent > 100) {
        throw new InvalidArgumentException($field . ' excede a precisão decimal suportada.');
    }
    $fraction = ($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? '');
    $digits = ltrim(($matches[2] ?? '0') . $fraction, '0') ?: '0';
    if ($matches[1] === '-' && $digits !== '0') {
        throw new InvalidArgumentException($field . ' não pode ser negativo.');
    }
    $scale = strlen($fraction) - $exponent;
    if ($scale < 0) {
        $digits .= str_repeat('0', -$scale);
        $scale = 0;
    }
    while ($scale > 0 && substr($digits, -1) === '0') {
        $digits = substr($digits, 0, -1);
        --$scale;
    }
    if ($digits === '' || trim($digits, '0') === '') {
        return ['digits' => '0', 'scale' => 0];
    }

    return ['digits' => $digits, 'scale' => $scale];
}

/** Decimal-string arithmetic keeps validation exact without BCMath or float money. */
function asaas_payment_integer_compare(string $left, string $right): int
{
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
}

function asaas_payment_integer_add(string $left, string $right): string
{
    $result = '';
    $carry = 0;
    for ($a = strlen($left) - 1, $b = strlen($right) - 1; $a >= 0 || $b >= 0 || $carry; --$a, --$b) {
        $sum = ($a >= 0 ? (int) $left[$a] : 0) + ($b >= 0 ? (int) $right[$b] : 0) + $carry;
        $result = ($sum % 10) . $result;
        $carry = intdiv($sum, 10);
    }
    return ltrim($result, '0') ?: '0';
}

/** $left must be greater than or equal to $right. */
function asaas_payment_integer_subtract(string $left, string $right): string
{
    $result = '';
    $borrow = 0;
    for ($a = strlen($left) - 1, $b = strlen($right) - 1; $a >= 0; --$a, --$b) {
        $digit = (int) $left[$a] - ($b >= 0 ? (int) $right[$b] : 0) - $borrow;
        $borrow = $digit < 0 ? 1 : 0;
        $result = ($digit + ($borrow ? 10 : 0)) . $result;
    }
    return ltrim($result, '0') ?: '0';
}

function asaas_payment_integer_multiply(string $left, string $right): string
{
    $digits = array_fill(0, strlen($left) + strlen($right), 0);
    for ($a = strlen($left) - 1; $a >= 0; --$a) {
        $carry = 0;
        for ($b = strlen($right) - 1; $b >= 0; --$b) {
            $index = $a + $b + 1;
            $product = (int) $left[$a] * (int) $right[$b] + $digits[$index] + $carry;
            $digits[$index] = $product % 10;
            $carry = intdiv($product, 10);
        }
        $digits[$a] += $carry;
    }
    return ltrim(implode('', $digits), '0') ?: '0';
}
