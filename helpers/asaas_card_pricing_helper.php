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
        'card_anticipation_mode' => 'none',
        'card_anticipation_manual_rate_1' => '1.25',
        'card_anticipation_manual_rate_installments' => '1.70',
        'card_anticipation_automatic_rate_1' => '1.15',
        'card_anticipation_automatic_rate_installments' => '1.60',
        'card_anticipation_first_days' => 30,
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
    $days = $normalized['card_anticipation_first_days'];
    if ((!is_int($days) && !is_string($days)) || !preg_match('/^\d{1,3}$/D', trim((string) $days))
        || (int) $days < 0 || (int) $days > 365) {
        throw new InvalidArgumentException('O prazo estimado da primeira parcela deve estar entre 0 e 365 dias inteiros.');
    }
    $normalized['card_anticipation_first_days'] = (int) $days;
    foreach (['card_fee_mode' => ['absorb', 'customer'], 'card_fee_fixed_mode' => ['charge', 'installment']] as $name => $choices) {
        if (!is_string($normalized[$name]) || !in_array($normalized[$name], $choices, true)) {
            throw new InvalidArgumentException('Confira o modo de repasse e a incidência da taxa fixa do cartão.');
        }
    }
    if (!is_string($normalized['card_anticipation_mode'])
        || !in_array($normalized['card_anticipation_mode'], ['none', 'manual', 'automatic'], true)) {
        throw new InvalidArgumentException('Escolha não incluir antecipação, avulsa ou automática no cálculo do cartão.');
    }
    foreach (['card_fee_percent_1', 'card_fee_percent_2_6', 'card_fee_percent_7_12', 'card_fee_percent_13_21'] as $name) {
        $bps = asaas_card_pricing_decimal_cents($normalized[$name], 'Percentual da taxa de cartão');
        if ($bps > 9900) {
            throw new InvalidArgumentException('O percentual da taxa de cartão deve estar entre 0% e 99%.');
        }
        $normalized[$name] = asaas_card_pricing_money($bps);
    }
    foreach (['card_anticipation_manual_rate_1', 'card_anticipation_manual_rate_installments',
        'card_anticipation_automatic_rate_1', 'card_anticipation_automatic_rate_installments'] as $name) {
        $bps = asaas_card_pricing_decimal_cents($normalized[$name], 'Taxa mensal de antecipação');
        if ($bps > 9900) {
            throw new InvalidArgumentException('A taxa mensal de antecipação deve estar entre 0% e 99%.');
        }
        $normalized[$name] = asaas_card_pricing_money($bps);
    }
    if ($normalized['card_anticipation_mode'] !== 'none') {
        // The single-payment rate is independent from the rate used for installments.
        // With a fixed 30-day interval, the last allowed count has the longest term.
        foreach (array_unique([1, $normalized['installmentCount']]) as $count) {
            $rateName = 'card_anticipation_' . $normalized['card_anticipation_mode']
                . '_rate_' . ($count === 1 ? '1' : 'installments');
            asaas_card_pricing_anticipation_factor(asaas_card_pricing_decimal_cents($normalized[$rateName],
                'Taxa mensal de antecipação'), $normalized['card_anticipation_first_days'], $count);
        }
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
    if ($divisor < 1 || $divisor > 600000 || !preg_match('/^\d+$/D', $digits)) {
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

/**
 * Estimated simple monthly discount on the net card receivable, using a 30-day month.
 * Equal installments have average term firstDays + 30 * (count - 1) / 2.
 * The result is a numerator over 600000, retaining half-days and fractional basis points.
 * This local price model neither requests anticipation nor promises the provider's final fee.
 */
function asaas_card_pricing_anticipation_factor(int $monthlyRate, int $firstDays, int $count): int
{
    if ($monthlyRate < 0 || $monthlyRate > 9900 || $firstDays < 0 || $firstDays > 365 || $count < 1 || $count > 21) {
        throw new InvalidArgumentException('Confira a taxa mensal e o prazo estimado de antecipação do cartão.');
    }
    $factor = $monthlyRate * (2 * $firstDays + 30 * ($count - 1));
    // Checking only the average could hide a zero/negative net value in the final installment.
    $lastFactor = $monthlyRate * ($firstDays + 30 * ($count - 1));
    if ($factor >= 600000 || $lastFactor >= 300000) {
        throw new InvalidArgumentException('A taxa mensal e o prazo de antecipação devem resultar em um custo inferior a 100% de cada parcela.');
    }
    return $factor;
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
    $anticipation = null;
    $anticipationFactor = 0;
    if ($settings['card_anticipation_mode'] !== 'none') {
        $rateName = 'card_anticipation_' . $settings['card_anticipation_mode'] . '_rate_' . ($count === 1 ? '1' : 'installments');
        $monthlyRate = asaas_card_pricing_decimal_cents($settings[$rateName], 'Taxa mensal de antecipação');
        $anticipationFactor = asaas_card_pricing_anticipation_factor($monthlyRate, $settings['card_anticipation_first_days'], $count);
        $anticipation = ['mode' => $settings['card_anticipation_mode'], 'monthly_rate_bps' => $monthlyRate,
            'first_days' => $settings['card_anticipation_first_days'], 'estimated_fee_cents' => 0];
    }
    $charged = $principal;
    if ($mode === 'customer') {
        if ($anticipationFactor > 0) {
            // G = (P / (1 - a) + F) / (1 - c). The anticipation fee applies after card costs.
            // Divide twice without rounding either quotient; any remainder means a final cent is needed.
            $retained = 600000 - $anticipationFactor;
            $numerator = asaas_payment_integer_multiply(asaas_payment_integer_add(
                asaas_payment_integer_multiply((string) $principal, '600000'),
                asaas_payment_integer_multiply((string) $fixedTotal, (string) $retained)
            ), '10000');
            [$numerator, $anticipationRemainder] = asaas_card_pricing_divide($numerator, $retained);
        } else {
            $numerator = asaas_payment_integer_multiply(asaas_payment_integer_add((string) $principal, (string) $fixedTotal), '10000');
            $anticipationRemainder = 0;
        }
        [$gross, $remainder] = asaas_card_pricing_divide($numerator, 10000 - $rate);
        $charged = asaas_card_pricing_integer($remainder > 0 || $anticipationRemainder > 0 ? asaas_payment_integer_add($gross, '1') : $gross);
    }
    [$percentageFee, $remainder] = asaas_card_pricing_divide(asaas_payment_integer_multiply((string) $charged, (string) $rate), 10000);
    if ($remainder >= 5000) {
        $percentageFee = asaas_payment_integer_add($percentageFee, '1');
    }
    $estimatedFee = asaas_card_pricing_integer(asaas_payment_integer_add($percentageFee, (string) $fixedTotal));
    if ($anticipation !== null) {
        // A fee larger than the balance in absorb mode does not create a negative anticipated value.
        $netReceivable = max(0, $charged - $estimatedFee);
        [$anticipationFee, $remainder] = asaas_card_pricing_divide(
            asaas_payment_integer_multiply((string) $netReceivable, (string) $anticipationFactor), 600000);
        if ($remainder >= 300000) { $anticipationFee = asaas_payment_integer_add($anticipationFee, '1'); }
        $anticipation['estimated_fee_cents'] = asaas_card_pricing_integer($anticipationFee);
        $estimatedFee = asaas_card_pricing_integer(asaas_payment_integer_add((string) $estimatedFee, $anticipationFee));
    }
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
    $pricing = [
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
    // Preserve the legacy snapshot and fingerprint exactly when anticipation is not included.
    if ($anticipation !== null) { $pricing['anticipation'] = $anticipation; }
    return $pricing;
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
    if (array_key_exists('anticipation', $pricing)) {
        $anticipation = $pricing['anticipation'];
        if (!is_array($anticipation) || !is_string($anticipation['mode'] ?? null)
            || !in_array($anticipation['mode'], ['manual', 'automatic'], true)) {
            throw new InvalidArgumentException('A simulação da antecipação está inválida. Atualize a página de pagamento.');
        }
        $quote['anticipation'] = ['mode' => $anticipation['mode']];
        foreach (['monthly_rate_bps', 'first_days', 'estimated_fee_cents'] as $name) {
            if (!isset($anticipation[$name]) || !is_int($anticipation[$name]) || $anticipation[$name] < 0) {
                throw new InvalidArgumentException('A simulação da antecipação está inválida. Atualize a página de pagamento.');
            }
            $quote['anticipation'][$name] = $anticipation[$name];
        }
        asaas_card_pricing_anticipation_factor($anticipation['monthly_rate_bps'], $anticipation['first_days'], $quote['installment_count']);
        if ($anticipation['estimated_fee_cents'] > $quote['estimated_fee_cents']
            || $anticipation['estimated_fee_cents'] > $quote['charged_cents']) {
            throw new InvalidArgumentException('A simulação da antecipação está inválida. Atualize a página de pagamento.');
        }
    }
    return hash('sha256', "asaas-card-pricing-v1\0" . json_encode(['pricing' => $quote, 'context' => $context], JSON_THROW_ON_ERROR));
}
