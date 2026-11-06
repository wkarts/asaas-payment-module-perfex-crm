<?php

declare(strict_types=1);

/** Pure offline pricing tests: no CRM, database, card credentials or external requests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once __DIR__ . '/../helpers/asaas_card_pricing_helper.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class PricingGatewayFixture
{
    public function __construct(private array $settings = [], private $missing = '') {}
    public function getSetting(string $name) { return $this->settings[$name] ?? $this->missing; }
}

$assertions = 0;
function pricingCheck(bool $condition, string $message): void
{
    ++$GLOBALS['assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function pricingEqual($actual, $expected, string $message): void
{
    pricingCheck($actual === $expected, $message . ' (recebido ' . var_export($actual, true) . ')');
}
function pricingReject(callable $callback, string $message = ''): void
{
    try { $callback(); }
    catch (InvalidArgumentException $error) {
        pricingCheck($message === '' || str_contains($error->getMessage(), $message), 'A rejeição deve explicar o parâmetro inválido.');
        return;
    }
    throw new RuntimeException('Era esperada a rejeição da configuração/cotação.');
}
function pricingSettings(array $settings = []): array
{
    return asaas_card_pricing_normalize_settings(array_replace(asaas_card_pricing_defaults(), ['installmentCount' => 21], $settings));
}

$tests = [];
$tests['defaults preservam saldo e limite legado sem repasse'] = static function (): void {
    foreach (['', null, false] as $missing) {
        $settings = asaas_card_pricing_settings(new PricingGatewayFixture([], $missing));
        pricingEqual($settings, asaas_card_pricing_defaults(), 'Opções ausentes devem receber defaults compatíveis.');
        $price = asaas_card_price('100.00', 1, $settings);
        pricingEqual($price['principal_cents'], 10000, 'Principal deve corresponder ao saldo.');
        pricingEqual($price['charged_cents'], 10000, 'Instalação existente absorve taxa por padrão.');
        pricingEqual($price['fee_cents'], 0, 'Não adicionar taxa por padrão.');
        pricingEqual($price['estimated_fee_cents'], 348, 'A estimativa interna não altera o valor cobrado.');
        pricingEqual($price['fee_mode'], 'absorb', 'O modo padrão deve absorver a taxa.');
        pricingReject(static fn () => asaas_card_price('100.00', 2, $settings), 'quantidade de parcelas');
    }
};

$tests['normalização aceita ponto/vírgula e preserva zeros explícitos'] = static function (): void {
    $settings = asaas_card_pricing_settings(new PricingGatewayFixture([
        'installmentCount' => '21', 'card_fee_percent_1' => ' 2,99 ', 'card_fee_percent_2_6' => '3.49',
        'card_fee_percent_7_12' => 0, 'card_fee_percent_13_21' => '4,29',
        'card_fee_fixed' => ',49', 'card_min_installment' => '10,5', 'card_free_installments' => '0',
    ]));
    pricingEqual($settings['installmentCount'], 21, 'Quantidade deve ser inteira.');
    pricingEqual($settings['card_fee_percent_1'], '2.99', 'Vírgula deve ser normalizada.');
    pricingEqual($settings['card_fee_percent_7_12'], '0.00', 'Taxa zero não deve virar o default.');
    pricingEqual($settings['card_fee_fixed'], '0.49', 'Aceitar fração sem zero inicial.');
    pricingEqual($settings['card_min_installment'], '10.50', 'Normalizar mínimo para centavos.');
    pricingEqual(asaas_card_price('100,00', 1, $settings)['principal_cents'], 10000, 'Saldo textual pode usar vírgula.');
};

foreach ([[1, 299, 103133, 3133], [2, 349, 103667, 3667], [6, 349, 103667, 3667],
    [7, 399, 104207, 4207], [12, 399, 104207, 4207], [13, 429, 104534, 4534], [21, 429, 104534, 4534]] as [$count, $bps, $gross, $fee]) {
    $tests['repasse por faixa ' . $count . ' parcelas'] = static function () use ($count, $bps, $gross, $fee): void {
        $settings = pricingSettings(['card_fee_mode' => 'customer']);
        $price = asaas_card_price('1000.00', $count, $settings);
        pricingEqual($price['version'], 1, 'Snapshot deve ser versionado.');
        pricingEqual($price['rate_bps'], $bps, 'Faixa deve respeitar suas fronteiras.');
        pricingEqual($price['charged_cents'], $gross, 'Aplicar gross-up com teto no centavo.');
        pricingEqual($price['fee_cents'], $fee, 'Acréscimo é a diferença para o principal.');
        pricingEqual($price['fixed_total_cents'], 49, 'Taxa fixa por cobrança incide uma vez.');
        pricingEqual($price['estimated_fee_cents'], $fee, 'Estimativa usa percentual do bruto e fixo.');
        pricingEqual($price['fee_mode'], 'customer', 'O modo efetivo deve informar repasse.');
        $numerator = (100000 + 49) * 10000;
        pricingCheck($gross * (10000 - $bps) >= $numerator && ($gross - 1) * (10000 - $bps) < $numerator,
            'O total deve ser o menor centavo que cobre principal e taxa configurada.');
    };
}

$tests['taxa fixa por parcela é explícita e arredondamento não subestima repasse'] = static function (): void {
    $settings = pricingSettings(['card_fee_mode' => 'customer', 'card_fee_fixed_mode' => 'installment']);
    $price = asaas_card_price('1000.00', 3, $settings);
    pricingEqual($price['fixed_total_cents'], 147, 'Multiplicar taxa fixa pela quantidade escolhida.');
    pricingEqual($price['charged_cents'], 103769, 'Gross-up deve incluir todas as taxas fixas.');
    $price = asaas_card_price('1000.00', 21, $settings);
    pricingEqual($price['fixed_total_cents'], 1029, 'Incluir 21 taxas apenas quando configurado.');
    pricingEqual($price['charged_cents'], 105558, 'Ceil deve proteger frações de centavo.');
    pricingEqual($price['fee_cents'], 5558, 'O repasse inclui o centavo necessário ao teto.');
    pricingEqual($price['estimated_fee_cents'], 5557, 'A estimativa half-up é distinta do gross-up com teto.');
};

$tests['parcelas gratuitas absorvem taxa somente até o limite configurado'] = static function (): void {
    $settings = pricingSettings(['card_fee_mode' => 'customer', 'card_free_installments' => 6]);
    foreach ([1, 6] as $count) {
        $price = asaas_card_price('1000.00', $count, $settings);
        pricingEqual($price['charged_cents'], 100000, 'Faixa gratuita mantém o saldo.');
        pricingEqual($price['fee_cents'], 0, 'Faixa gratuita não repassa taxa.');
        pricingEqual($price['fee_mode'], 'absorb', 'Snapshot deve registrar quem absorve a taxa.');
        pricingCheck($price['estimated_fee_cents'] > 0, 'Ainda deve informar o custo estimado absorvido.');
    }
    pricingEqual(asaas_card_price('1000.00', 7, $settings)['charged_cents'], 104207, 'O repasse começa após o limite gratuito.');
    $settings['card_fee_mode'] = 'absorb';
    pricingEqual(asaas_card_price('1000.00', 21, $settings)['charged_cents'], 100000, 'Absorver sempre deve prevalecer sobre todas as faixas.');
};

$tests['estimativa percentual usa half-up em centavos'] = static function (): void {
    $settings = pricingSettings(['card_fee_percent_1' => '0.01', 'card_fee_fixed' => '0']);
    pricingEqual(asaas_card_price('149.99', 1, $settings)['estimated_fee_cents'], 1, 'Abaixo de meio centavo arredonda para baixo.');
    pricingEqual(asaas_card_price('150.00', 1, $settings)['estimated_fee_cents'], 2, 'Meio centavo arredonda para cima.');
    $settings['card_fee_mode'] = 'customer';
    $settings['card_fee_percent_1'] = '0.00';
    $price = asaas_card_price('5.00', 1, $settings);
    pricingEqual($price['charged_cents'], 500, 'Taxas zeradas não devem adicionar centavo.');
    pricingEqual($price['estimated_fee_cents'], 0, 'Estimativa deve ser zero.');
};

$tests['mínimo da API é respeitado por parcela e no pagamento à vista'] = static function (): void {
    $settings = pricingSettings();
    pricingEqual(asaas_card_price('5.00', 1, $settings)['charged_cents'], 500, 'Aceitar mínimo exato da API.');
    pricingReject(static fn () => asaas_card_price('4.99', 1, $settings), 'R$ 5,00');
    pricingEqual(asaas_card_price('10.00', 2, $settings)['charged_cents'], 1000, 'Duas parcelas exatas de R$ 5 são válidas.');
    pricingReject(static fn () => asaas_card_price('9.99', 2, $settings), 'cada parcela');
    pricingEqual(asaas_card_price('105.00', 21, $settings)['installment_count'], 21, 'O mínimo se aplica também a 21 parcelas.');
    pricingReject(static fn () => asaas_card_price('104.99', 21, $settings), 'cada parcela');
};

$tests['mínimo administrativo vale somente para parcelamento e inclui desconto líquido'] = static function (): void {
    $settings = pricingSettings(['card_min_installment' => '10.00']);
    pricingEqual(asaas_card_price('5.00', 1, $settings)['minimum_cents'], 500, 'À vista deve ignorar o mínimo administrativo do parcelamento.');
    pricingEqual(asaas_card_price('20.00', 2, $settings)['minimum_cents'], 1000, 'Parcelamento deve respeitar o mínimo configurado.');
    pricingReject(static fn () => asaas_card_price('19.99', 2, $settings), 'R$ 10,00');
    $fixed = ['type' => 'FIXED', 'value' => '5.00', 'dueDateLimitDays' => 0];
    $percent = ['type' => 'PERCENTAGE', 'value' => '20', 'dueDateLimitDays' => 0];
    $before = serialize([$settings, $fixed, $percent]);
    pricingEqual(asaas_card_price('30.00', 2, $settings, $fixed)['principal_cents'], 3000, 'Desconto fixo não deve ser subtraído silenciosamente do principal.');
    pricingReject(static fn () => asaas_card_price('29.99', 2, $settings, $fixed), 'R$ 10,00');
    pricingEqual(asaas_card_price('25.00', 2, $settings, $percent)['principal_cents'], 2500, 'Desconto percentual deve respeitar o valor bruto original.');
    pricingReject(static fn () => asaas_card_price('24.99', 2, $settings, $percent), 'R$ 10,00');
    pricingEqual(serialize([$settings, $fixed, $percent]), $before, 'A cotação não deve modificar configuração ou desconto recebido.');
};

$tests['validação de desconto continua sendo a validação financeira existente'] = static function (): void {
    $settings = pricingSettings(['card_min_installment' => '100.00']);
    $discount = ['type' => 'FIXED', 'value' => 15];
    pricingEqual(asaas_card_price('20.00', 1, $settings, $discount)['charged_cents'], 2000, 'Desconto válido deve preservar cobrança bruta.');
    pricingReject(static fn () => asaas_card_price('19.99', 1, $settings, $discount), 'R$ 5,00');
    pricingReject(static fn () => asaas_card_price('1000.00', 1, $settings, ['type' => 'PERCENTAGE', 'value' => 101]), '100%');
    pricingReject(static fn () => asaas_card_price('1000.00', 1, $settings, ['type' => 'UNKNOWN', 'value' => 1]), 'tipo de desconto');
};

foreach ([0, '0.00', -1, '-1.00', NAN, INF, true, [], new stdClass(), '1.000,00'] as $index => $balance) {
    $tests['saldo inválido não gera cobrança de taxa isolada ' . $index] = static function () use ($balance): void {
        pricingReject(static fn () => asaas_card_price($balance, 1, pricingSettings(['card_fee_mode' => 'customer', 'card_fee_fixed' => '5'])));
    };
}

foreach ([['installmentCount', 0], ['installmentCount', 22], ['installmentCount', '2.0'], ['installmentCount', true],
    ['card_fee_mode', '0'], ['card_fee_mode', 'CUSTOMER'], ['card_fee_fixed_mode', 'unknown'],
    ['card_fee_percent_1', '-1'], ['card_fee_percent_1', '99.01'], ['card_fee_percent_2_6', '100'],
    ['card_fee_percent_7_12', '1.001'], ['card_fee_percent_13_21', '1e1'],
    ['card_fee_percent_1', 2.99], ['card_fee_percent_1', false], ['card_fee_percent_1', []], ['card_fee_fixed', new stdClass()],
    ['card_fee_fixed', '-0.01'], ['card_fee_fixed', '100000.01'], ['card_fee_fixed', '1,000.00'],
    ['card_min_installment', '4.99'], ['card_min_installment', '5.001'], ['card_free_installments', '-1'], ['card_free_installments', 22]] as $index => [$name, $value]) {
    $tests['configuração inválida falha fechada ' . $index . ' ' . $name] = static function () use ($name, $value): void {
        pricingReject(static fn () => pricingSettings([$name => $value]));
    };
}

$tests['fronteiras de configuração e tentativa acima do limite'] = static function (): void {
    $settings = pricingSettings(['card_fee_percent_1' => '99.00', 'card_fee_fixed' => '100000.00', 'card_free_installments' => 21]);
    pricingEqual($settings['card_fee_percent_1'], '99.00', 'Aceitar teto percentual exato.');
    pricingEqual($settings['card_fee_fixed'], '100000.00', 'Aceitar teto fixo exato.');
    pricingReject(static fn () => pricingSettings(['installmentCount' => 6, 'card_free_installments' => 7]), 'ultrapassar');
    pricingReject(static fn () => asaas_card_price('1000.00', 0, $settings), 'quantidade');
    pricingReject(static fn () => asaas_card_price('1000.00', 22, $settings), 'quantidade');
    pricingReject(static fn () => asaas_card_price('1000.00', 7, pricingSettings(['installmentCount' => 6])), 'quantidade');
    pricingReject(static fn () => asaas_card_pricing_settings(new stdClass()), 'configuração');
};

$tests['aritmética não transborda intermediários nativos e rejeita resultado fora do limite'] = static function (): void {
    $maximum = asaas_card_pricing_money(PHP_INT_MAX);
    $zeroFees = pricingSettings(['card_fee_percent_1' => '0', 'card_fee_fixed' => '0']);
    pricingEqual(asaas_card_price($maximum, 1, $zeroFees)['charged_cents'], PHP_INT_MAX, 'Centavos no limite devem continuar inteiros exatos.');
    pricingReject(static fn () => asaas_card_price($maximum, 1, pricingSettings(['card_fee_mode' => 'customer'])), 'precisão');
    pricingReject(static fn () => asaas_card_price('999999999999999999999999999999.99', 1, $zeroFees), 'precisão');
    $settings = pricingSettings(['card_fee_mode' => 'customer']);
    $price = asaas_card_price(asaas_card_pricing_money(intdiv(PHP_INT_MAX, 2)), 1, $settings);
    $numerator = asaas_payment_integer_multiply(asaas_payment_integer_add((string) $price['principal_cents'], '49'), '10000');
    $denominator = (string) (10000 - $price['rate_bps']);
    pricingCheck(asaas_payment_integer_compare(asaas_payment_integer_multiply((string) $price['charged_cents'], $denominator), $numerator) >= 0,
        'Gross-up grande deve cobrir o numerador sem converter para float.');
    pricingCheck(asaas_payment_integer_compare(asaas_payment_integer_multiply((string) ($price['charged_cents'] - 1), $denominator), $numerator) < 0,
        'Gross-up grande ainda deve ser mínimo no centavo.');
    pricingReject(static fn () => asaas_card_price('100.00', 2, pricingSettings(['card_min_installment' => $maximum])), 'valor líquido');
};

$tests['fingerprint estável identifica mudança de cotação ou contexto'] = static function (): void {
    $pricing = asaas_card_price('100.00', 1, pricingSettings(['card_fee_mode' => 'customer']));
    $context = 'invoice-test|BRL|2026-11-30|client-test|{"type":"FIXED","value":0}';
    $token = asaas_card_pricing_token($pricing, $context);
    pricingCheck((bool) preg_match('/^[a-f0-9]{64}$/D', $token), 'Fingerprint deve ser SHA-256 hexadecimal.');
    pricingEqual(asaas_card_pricing_token(array_reverse($pricing, true), $context), $token, 'Ordem de chaves não pode invalidar a mesma cotação.');
    pricingCheck(asaas_card_pricing_token($pricing, $context . '|new-date') !== $token, 'Mudança no contexto deve invalidar a cotação anterior.');
    foreach (array_keys($pricing) as $name) {
        if ($name === 'version') { continue; }
        $changed = $pricing;
        $changed[$name] = $name === 'fee_mode' ? 'absorb' : $changed[$name] + 1;
        pricingCheck(asaas_card_pricing_token($changed, $context) !== $token, 'Campo financeiro alterado deve mudar o fingerprint: ' . $name);
    }
    pricingEqual(asaas_card_pricing_token($pricing + ['unrelated' => 'never-part-of-the-quote'], $context), $token,
        'Somente campos financeiros conhecidos integram o fingerprint.');
    pricingReject(static fn () => asaas_card_pricing_token(array_replace($pricing, ['version' => 2]), $context), 'simulação');
    pricingReject(static fn () => asaas_card_pricing_token(array_replace($pricing, ['charged_cents' => '10000']), $context), 'simulação');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, 'PASS ' . $name . PHP_EOL); }
    catch (Throwable $error) { ++$failures; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
}
fwrite(STDOUT, sprintf('Card pricing: %d scenarios, %d assertions, %d failures.%s', count($tests), $assertions, $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
