<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_payment_validation_helper.php';

/** Defaults are configurable estimates, not a statement of the merchant's Asaas contract. */
function asaas_card_pricing_defaults(): array
{
    return [
        'installmentCount' => 1,
        'card_fee_mode' => 'absorb',
        'card_fee_percent_1' => '2.99',
        'card_fee_percent_2_6' => '3.49',
        'card_fee_percent_7_12' => '3.99',
        'card_fee_percent_13_21' => '4.29',
        'card_fee_fixed' => '0.49',
        'card_fee_fixed_mode' => 'charge',
        'card_min_installment' => '5.00',
        'card_free_installments' => 0,
    ];
}

/** Read only configuration; never inspect cardholder fields, cookies or request input. */
function asaas_card_pricing_settings(object $gateway): array
{
    if (!is_callable([$gateway, 'getSetting'])) {
        throw new InvalidArgumentException('Não foi possível consultar a configuração de cartão.');
    }
    $settings = [];
    foreach (asaas_card_pricing_defaults() as $name => $default) {
        $value = $gateway->getSetting($name);
        $settings[$name] = $value === false ? null : $value;
    }
    return asaas_card_pricing_normalize_settings($settings);
}

/** Keep decimal settings canonical and validate again before every server-side quote. */
function asaas_card_pricing_normalize_settings(array $settings): array
{
    $normalized = [];
    foreach (asaas_card_pricing_defaults() as $name => $default) {
        $value = $settings[$name] ?? null;
        $normalized[$name] = $value === null || $value === '' ? $default : $value;
    }
    foreach (['installmentCount' => [1, 21], 'card_free_installments' => [0, 21]] as $name => [$minimum, $maximum]) {
        $value = $normalized[$name];
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^\d{1,2}$/D', trim((string) $value))
            || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException($name === 'installmentCount'
                ? 'O limite de parcelas do cartão deve estar entre 1 e 21.'
                : 'A quantidade de parcelas sem repasse deve estar entre 0 e 21.');
        }
        $normalized[$name] = (int) $value;
    }
    if ($normalized['card_free_installments'] > $normalized['installmentCount']) {
        throw new InvalidArgumentException('As parcelas sem repasse não podem ultrapassar o limite de parcelas do cartão.');
    }
    foreach (['card_fee_mode' => ['absorb', 'customer'], 'card_fee_fixed_mode' => ['charge', 'installment']] as $name => $choices) {
        if (!is_string($normalized[$name]) || !in_array($normalized[$name], $choices, true)) {
            throw new InvalidArgumentException('Confira o modo de repasse e a incidência da taxa fixa do cartão.');
        }
    }
    foreach (['card_fee_percent_1', 'card_fee_percent_2_6', 'card_fee_percent_7_12', 'card_fee_percent_13_21'] as $name) {
        $bps = asaas_card_pricing_decimal_cents($normalized[$name], 'Percentual da taxa de cartão');
        if ($bps > 9900) {
            throw new InvalidArgumentException('O percentual da taxa de cartão deve estar entre 0% e 99%.');
        }
        $normalized[$name] = asaas_card_pricing_money($bps);
    }
    foreach (['card_fee_fixed' => 'Taxa fixa do cartão', 'card_min_installment' => 'Valor mínimo da parcela'] as $name => $label) {
        $cents = asaas_card_pricing_decimal_cents($normalized[$name], $label);
        if ($name === 'card_min_installment' && $cents < 500) {
            throw new InvalidArgumentException('O valor mínimo da parcela de cartão deve ser de pelo menos R$ 5,00.');
        }
        if ($name === 'card_fee_fixed' && $cents > 10000000) {
            throw new InvalidArgumentException('A taxa fixa do cartão não pode ultrapassar R$ 100.000,00.');
        }
        $normalized[$name] = asaas_card_pricing_money($cents);
    }
    return $normalized;
}

/** Strict settings accept a decimal point or comma, without ambiguous thousands separators. */
function asaas_card_pricing_decimal_cents($value, string $field): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new InvalidArgumentException($field . ' deve ser um decimal válido com até duas casas.');
    }
    $text = trim((string) $value);
    if (strlen($text) > 128 || !preg_match('/^(?:\d+(?:[.,]\d{1,2})?|[.,]\d{1,2})$/D', $text)) {
        throw new InvalidArgumentException($field . ' deve ser um decimal não negativo com até duas casas.');
    }
    return asaas_payment_amount_to_cents(str_replace(',', '.', $text), $field);
}

function asaas_card_pricing_money(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Division by a small positive integer; the dividend can exceed native integer width. */
function asaas_card_pricing_divide(string $digits, int $divisor): array
{
    if ($divisor < 1 || $divisor > 10000 || !preg_match('/^\d+$/D', $digits)) {
        throw new InvalidArgumentException('Não foi possível calcular a taxa do cartão.');
    }
    $quotient = '';
    $remainder = 0;
    for ($index = 0, $length = strlen($digits); $index < $length; ++$index) {
        $partial = $remainder * 10 + (int) $digits[$index];
        $quotient .= (string) intdiv($partial, $divisor);
        $remainder = $partial % $divisor;
    }
    return [ltrim($quotient, '0') ?: '0', $remainder];
}

function asaas_card_pricing_integer(string $digits): int
{
    if (!preg_match('/^\d+$/D', $digits) || asaas_payment_integer_compare($digits, (string) PHP_INT_MAX) > 0) {
        throw new InvalidArgumentException('O cálculo do cartão excede a precisão monetária suportada.');
    }
    return (int) $digits;
}

/** Apply the administrative minimum after the same discount semantics as the API validator. */
function asaas_card_pricing_meets_minimum(int $charged, int $count, int $minimum, array $discount): bool
{
    $left = (string) $charged;
    $right = asaas_payment_integer_multiply((string) $minimum, (string) $count);
    if (isset($discount['value']) && $discount['value'] !== '') {
        $decimal = asaas_payment_decimal_value($discount['value'], 'Desconto');
        if ($decimal['digits'] !== '0') {
            if (strtoupper((string) ($discount['type'] ?? '')) === 'FIXED') {
                $scale = '1' . str_repeat('0', $decimal['scale']);
                $left = asaas_payment_integer_multiply($left, $scale);
                $right = asaas_payment_integer_multiply(asaas_payment_integer_add(
                    asaas_payment_integer_multiply((string) $minimum, $scale),
                    asaas_payment_integer_multiply($decimal['digits'], '100')
                ), (string) $count);
            } else {
                // Type/range have already passed asaas_payment_validation_error().
                $hundred = '100' . str_repeat('0', $decimal['scale']);
                $left = asaas_payment_integer_multiply($left, asaas_payment_integer_subtract($hundred, $decimal['digits']));
                $right = asaas_payment_integer_multiply($right, $hundred);
            }
        }
    }
    return asaas_payment_integer_compare($left, $right) >= 0;
}

/**
 * Quote in integer cents. The base debt remains separate from any customer surcharge.
 * The discount is validated unchanged; it is not silently folded into the invoice principal.
 */
function asaas_card_price($balance, int $count, array $settings, array $discount = []): array
{
    $settings = asaas_card_pricing_normalize_settings($settings);
    if ($count < 1 || $count > $settings['installmentCount']) {
        throw new InvalidArgumentException('A quantidade de parcelas não está disponível para esta fatura.');
    }
    // Keep the existing balance conversion/half-up contract; locale input may use a comma.
    if (is_string($balance) && strpos($balance, ',') !== false) {
        $balance = str_replace(',', '.', $balance);
    }
    $principal = asaas_payment_amount_to_cents($balance, 'Saldo da fatura');
    if ($principal <= 0) {
        throw new InvalidArgumentException('A fatura não possui saldo em aberto para pagamento por cartão.', 400);
    }
    $band = $count === 1 ? '1' : ($count <= 6 ? '2_6' : ($count <= 12 ? '7_12' : '13_21'));
    $rate = asaas_card_pricing_decimal_cents($settings['card_fee_percent_' . $band], 'Percentual da taxa de cartão');
    $fixed = asaas_card_pricing_decimal_cents($settings['card_fee_fixed'], 'Taxa fixa do cartão');
    $fixedTotal = asaas_card_pricing_integer(asaas_payment_integer_multiply((string) $fixed,
        (string) ($settings['card_fee_fixed_mode'] === 'installment' ? $count : 1)));
    $mode = $settings['card_fee_mode'] === 'customer' && $count > $settings['card_free_installments'] ? 'customer' : 'absorb';
    $charged = $principal;
    if ($mode === 'customer') {
        $numerator = asaas_payment_integer_multiply(asaas_payment_integer_add((string) $principal, (string) $fixedTotal), '10000');
        [$gross, $remainder] = asaas_card_pricing_divide($numerator, 10000 - $rate);
        $charged = asaas_card_pricing_integer($remainder > 0 ? asaas_payment_integer_add($gross, '1') : $gross);
    }
    [$percentageFee, $remainder] = asaas_card_pricing_divide(asaas_payment_integer_multiply((string) $charged, (string) $rate), 10000);
    if ($remainder >= 5000) {
        $percentageFee = asaas_payment_integer_add($percentageFee, '1');
    }
    $estimatedFee = asaas_card_pricing_integer(asaas_payment_integer_add($percentageFee, (string) $fixedTotal));
    $minimum = $count === 1 ? 500 : asaas_card_pricing_decimal_cents($settings['card_min_installment'], 'Valor mínimo da parcela');
    $amount = asaas_card_pricing_money($charged);
    $payload = ['billingType' => 'CREDIT_CARD', 'discount' => $discount]
        + ($count === 1 ? ['value' => $amount] : ['installmentCount' => $count, 'totalValue' => $amount]);
    $error = asaas_payment_validation_error($payload);
    if ($error !== null) {
        throw new InvalidArgumentException($error, 400);
    }
    if ($count > 1 && $minimum > 500 && !asaas_card_pricing_meets_minimum($charged, $count, $minimum, $discount)) {
        $formatted = str_replace('.', ',', asaas_card_pricing_money($minimum));
        throw new InvalidArgumentException('O valor líquido de cada parcela deve ser de pelo menos R$ ' . $formatted . ', considerando o desconto.', 400);
    }
    return [
        'version' => 1,
        'principal_cents' => $principal,
        'charged_cents' => $charged,
        'fee_cents' => $charged - $principal,
        'installment_count' => $count,
        'rate_bps' => $rate,
        'fixed_total_cents' => $fixedTotal,
        'estimated_fee_cents' => $estimatedFee,
        'fee_mode' => $mode,
        'minimum_cents' => $minimum,
    ];
}

/** A stable quote fingerprint, compared against a newly computed server-side quote. */
function asaas_card_pricing_token(array $pricing, string $context): string
{
    $quote = [];
    foreach (['version', 'principal_cents', 'charged_cents', 'fee_cents', 'installment_count',
        'rate_bps', 'fixed_total_cents', 'estimated_fee_cents', 'minimum_cents'] as $name) {
        if (!isset($pricing[$name]) || !is_int($pricing[$name]) || $pricing[$name] < 0) {
            throw new InvalidArgumentException('A simulação do cartão está inválida. Atualize a página de pagamento.');
        }
        $quote[$name] = $pricing[$name];
    }
    if ($quote['version'] !== 1 || $quote['installment_count'] < 1 || $quote['installment_count'] > 21
        || $quote['rate_bps'] > 9900 || $quote['minimum_cents'] < 500
        || !in_array($pricing['fee_mode'] ?? null, ['absorb', 'customer'], true)) {
        throw new InvalidArgumentException('A simulação do cartão está inválida. Atualize a página de pagamento.');
    }
    $quote['fee_mode'] = $pricing['fee_mode'];
    return hash('sha256', "asaas-card-pricing-v1\0" . json_encode(['pricing' => $quote, 'context' => $context], JSON_THROW_ON_ERROR));
}
