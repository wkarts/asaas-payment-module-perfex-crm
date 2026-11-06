<?php

declare(strict_types=1);

/** Real settings filter and pricing validator; only option reads and alerts are replaced. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/helpers/asaas_settings_helper.php';

$GLOBALS['card_settings_options'] = [];
$GLOBALS['card_settings_alerts'] = [];
$GLOBALS['card_settings_reads'] = [];
$GLOBALS['card_settings_scenarios'] = 0;
$GLOBALS['card_settings_assertions'] = 0;
$GLOBALS['card_settings_failures'] = 0;

function get_option(string $name)
{
    $GLOBALS['card_settings_reads'][] = $name;
    return $GLOBALS['card_settings_options'][$name] ?? false;
}
function set_alert(string $level, string $message): void
{
    $GLOBALS['card_settings_alerts'][] = [$level, $message];
}
function cardSettingsAssert(bool $condition, string $message): void
{
    ++$GLOBALS['card_settings_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function cardSettingsTest(string $name, callable $test): void
{
    ++$GLOBALS['card_settings_scenarios'];
    $GLOBALS['card_settings_options'] = [];
    $GLOBALS['card_settings_alerts'] = [];
    $GLOBALS['card_settings_reads'] = [];
    try { $test(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['card_settings_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function cardSettingsFields(array $values, string $suffix = ''): array
{
    $fields = [];
    foreach ($values as $name => $value) { $fields['paymentmethod_asaas_' . $name . $suffix] = $value; }
    return $fields;
}
function cardSettingsPayload(array $fields = []): array
{
    return [
        'settings' => $fields + [
            'paymentmethod_asaas_api_key' => 'production-secret-fixture',
            'paymentmethod_asaas_webhook_secret' => 'webhook-secret-fixture',
            'paymentmethod_asaas_sandbox' => '0',
            'paymentmethod_asaas_debug' => '1',
            'paymentmethod_asaas_interest_value' => '2.00',
            'paymentmethod_asaas_sync_enabled' => '1',
            'paymentmethod_other_installmentCount' => '8',
            'companyname' => 'Fixture company',
        ],
        'csrf_fixture' => 'unchanged-csrf',
        'other' => ['retained' => true],
    ];
}
function cardSettingsSafeAlert(): void
{
    cardSettingsAssert(count($GLOBALS['card_settings_alerts']) === 1, 'Rejected groups must produce one alert.');
    [$level, $message] = $GLOBALS['card_settings_alerts'][0];
    cardSettingsAssert($level === 'danger' && str_contains($message, 'preservados'), 'Alert must explain that prior card settings remain.');
    foreach (['production-secret-fixture', 'webhook-secret-fixture', 'unexpected-secret'] as $secret) {
        cardSettingsAssert(!str_contains($message, $secret), 'Alerts cannot repeat a submitted value or credential.');
    }
}

foreach ([null, false, 'unchanged', [], ['settings' => null], ['settings' => 'unchanged'], cardSettingsPayload()] as $index => $payload) {
    cardSettingsTest('unrelated or malformed payload ' . $index, static function () use ($payload): void {
        cardSettingsAssert(asaas_validate_card_settings($payload) === $payload, 'Unrelated payload must remain identical.');
        cardSettingsAssert($GLOBALS['card_settings_reads'] === [], 'Do not read stored settings for another group.');
        cardSettingsAssert($GLOBALS['card_settings_alerts'] === [], 'Do not alert for another group.');
    });
}

cardSettingsTest('valid complete group uses the runtime decimal and integer contract', static function (): void {
    $values = [
        'installmentCount' => '21', 'card_free_installments' => '06',
        'card_fee_mode' => 'customer', 'card_fee_fixed_mode' => 'installment',
        'card_min_installment' => ' 12,50 ', 'card_fee_fixed' => ',49',
        'card_fee_percent_1' => '0', 'card_fee_percent_2_6' => '3,4',
        'card_fee_percent_7_12' => '3.99', 'card_fee_percent_13_21' => '4.29',
    ];
    $payload = cardSettingsPayload(cardSettingsFields($values));
    $expected = cardSettingsPayload(cardSettingsFields([
        'installmentCount' => 21, 'card_free_installments' => 6,
        'card_fee_mode' => 'customer', 'card_fee_fixed_mode' => 'installment',
        'card_min_installment' => '12.50', 'card_fee_fixed' => '0.49',
        'card_fee_percent_1' => '0.00', 'card_fee_percent_2_6' => '3.40',
        'card_fee_percent_7_12' => '3.99', 'card_fee_percent_13_21' => '4.29',
    ]));
    cardSettingsAssert(asaas_validate_card_settings($payload) === $expected, 'Normalize only this group, preserving secrets and native envelope.');
    cardSettingsAssert($GLOBALS['card_settings_reads'] === [], 'Full settings form does not require extra option reads.');
    cardSettingsAssert($GLOBALS['card_settings_alerts'] === [], 'Valid values must save without an alert.');
});

cardSettingsTest('partial update does not insert unsubmitted defaults', static function (): void {
    $payload = cardSettingsPayload(cardSettingsFields(['installmentCount' => '21']));
    $expected = cardSettingsPayload(cardSettingsFields(['installmentCount' => 21]));
    cardSettingsAssert(asaas_validate_card_settings($payload) === $expected, 'Normalize submitted count without adding card defaults.');
    cardSettingsAssert($GLOBALS['card_settings_alerts'] === [], 'Absent stored new fields use compatible defaults.');
});

cardSettingsTest('lowering maximum cannot invalidate the stored free range', static function (): void {
    $GLOBALS['card_settings_options'] = cardSettingsFields(['installmentCount' => '12', 'card_free_installments' => '6']);
    $payload = cardSettingsPayload(cardSettingsFields(['installmentCount' => '3', 'card_fee_mode' => 'customer']));
    cardSettingsAssert(asaas_validate_card_settings($payload) === cardSettingsPayload(), 'Both submitted fields are omitted on cross-field rejection.');
    cardSettingsSafeAlert();
});

cardSettingsTest('free range is checked against stored maximum on partial update', static function (): void {
    $GLOBALS['card_settings_options'] = cardSettingsFields(['installmentCount' => '6']);
    cardSettingsAssert(asaas_validate_card_settings(cardSettingsPayload(cardSettingsFields(['card_free_installments' => '7']))) === cardSettingsPayload(), 'A partial free-range update must still honor maximum.');
    cardSettingsSafeAlert();
});

cardSettingsTest('partial mode switch cannot bypass an invalid stored fee', static function (): void {
    $GLOBALS['card_settings_options'] = cardSettingsFields(['card_fee_percent_1' => '100']);
    cardSettingsAssert(asaas_validate_card_settings(cardSettingsPayload(cardSettingsFields(['card_fee_mode' => 'customer']))) === cardSettingsPayload(), 'Switching mode cannot activate an invalid configuration.');
    cardSettingsSafeAlert();
});

cardSettingsTest('a complete valid save can repair previously invalid stored settings', static function (): void {
    $GLOBALS['card_settings_options'] = cardSettingsFields(['card_fee_percent_1' => '100', 'card_free_installments' => '22']);
    $payload = cardSettingsPayload(cardSettingsFields(asaas_card_pricing_defaults()));
    cardSettingsAssert(asaas_validate_card_settings($payload) === $payload, 'Submitted valid fields take precedence over invalid stored values.');
    cardSettingsAssert($GLOBALS['card_settings_alerts'] === [], 'Correcting invalid stored values must be possible.');
});

$invalid = [
    ['installmentCount', '0'], ['installmentCount', '22'], ['installmentCount', '1.5'], ['installmentCount', '1e1'],
    ['card_free_installments', '-1'], ['card_free_installments', '22'], ['card_free_installments', '2'],
    ['card_fee_mode', 'unexpected-secret'], ['card_fee_fixed_mode', 'unknown'],
    ['card_fee_percent_1', '99.01'], ['card_fee_percent_2_6', '-1'], ['card_fee_percent_7_12', '3.999'],
    ['card_fee_percent_13_21', '1e1'], ['card_fee_fixed', '100000.01'], ['card_min_installment', '4.99'],
    ['card_min_installment', '999999999999999999999999'], ['card_fee_fixed', '1.000,00'],
    ['card_fee_mode', ''], ['card_min_installment', null], ['card_fee_percent_1', ' '],
    ['card_fee_fixed', ['unexpected-secret']], ['installmentCount', ['value' => '2']],
    ['card_fee_percent_2_6', (object) ['value' => '3.49']], ['card_fee_percent_7_12', 3.99],
    ['card_fee_percent_13_21', true], ['card_fee_mode', false], ['installmentCount', 1.0],
];
foreach ($invalid as $index => [$field, $value]) {
    cardSettingsTest('invalid card group remains unchanged ' . $index . ' (' . $field . ')', static function () use ($field, $value): void {
        $values = asaas_card_pricing_defaults();
        $values[$field] = $value;
        $payload = cardSettingsPayload(cardSettingsFields($values));
        cardSettingsAssert(asaas_validate_card_settings($payload) === cardSettingsPayload(), 'An invalid field must omit every submitted option of this card group only.');
        cardSettingsSafeAlert();
    });
}

cardSettingsTest('inclusive boundaries and integer amounts are accepted', static function (): void {
    $values = asaas_card_pricing_defaults();
    $values['installmentCount'] = 21;
    $values['card_free_installments'] = 21;
    $values['card_fee_fixed'] = 100000;
    $values['card_min_installment'] = 5;
    foreach (['1', '2_6', '7_12', '13_21'] as $band) { $values['card_fee_percent_' . $band] = 99; }
    $result = asaas_validate_card_settings(cardSettingsPayload(cardSettingsFields($values)));
    cardSettingsAssert($result['settings']['paymentmethod_asaas_card_fee_fixed'] === '100000.00', 'Fixed boundary must normalize exactly.');
    cardSettingsAssert($result['settings']['paymentmethod_asaas_card_fee_percent_13_21'] === '99.00', 'Percentage boundary must normalize exactly.');
    cardSettingsAssert($result['settings']['paymentmethod_asaas_card_free_installments'] === 21, 'Maximum free count is accepted when max matches.');
    cardSettingsAssert($GLOBALS['card_settings_alerts'] === [], 'Valid boundaries must not alert.');
});

cardSettingsTest('invalid company does not discard other card groups or credentials', static function (): void {
    $GLOBALS['card_settings_options'] = cardSettingsFields(['installmentCount' => '9', 'card_free_installments' => '6'], '_company_7');
    $payload = cardSettingsPayload(cardSettingsFields(['installmentCount' => '21'])
        + cardSettingsFields(['installmentCount' => '3', 'card_fee_mode' => 'customer'], '_company_7')
        + cardSettingsFields(['installmentCount' => '12', 'card_free_installments' => '6'], '_company_9')
        + ['paymentmethod_asaas_api_key_company_7' => 'production-secret-fixture']);
    $expected = cardSettingsPayload(cardSettingsFields(['installmentCount' => 21])
        + cardSettingsFields(['installmentCount' => 12, 'card_free_installments' => 6], '_company_9')
        + ['paymentmethod_asaas_api_key_company_7' => 'production-secret-fixture']);
    cardSettingsAssert(asaas_validate_card_settings($payload) === $expected, 'Preserve valid global/company updates and tenant credentials.');
    cardSettingsSafeAlert();
});

cardSettingsTest('unsupported lookalike names cannot alter the canonical configuration', static function (): void {
    $payload = cardSettingsPayload([
        'paymentmethod_asaas_card_fee_percent_1_extra' => 'unexpected-secret',
        'paymentmethod_asaas_installmentCount_company_0' => '0',
        'paymentmethod_asaas_card_fee_mode_company_7_extra' => 'unexpected-secret',
    ]);
    cardSettingsAssert(asaas_validate_card_settings($payload) === $payload, 'Other fields are owned by their original settings path.');
    cardSettingsAssert($GLOBALS['card_settings_reads'] === [] && $GLOBALS['card_settings_alerts'] === [], 'No card group was submitted.');
});

cardSettingsTest('native before_settings_updated filter is registered exactly once', static function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/asaas.php');
    cardSettingsAssert(substr_count($source, "add_filter('before_settings_updated', 'asaas_validate_card_settings')") === 1, 'The real settings save must call the validator.');
});

printf("\nCard settings: %d scenarios, %d assertions, %d failures.\n", $GLOBALS['card_settings_scenarios'], $GLOBALS['card_settings_assertions'], $GLOBALS['card_settings_failures']);
exit($GLOBALS['card_settings_failures'] > 0 ? 1 : 0);
