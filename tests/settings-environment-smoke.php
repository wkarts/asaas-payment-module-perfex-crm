<?php

declare(strict_types=1);

/** Actual settings filter, with only the Perfex alert boundary replaced. No database or HTTP. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/helpers/asaas_settings_helper.php';

$GLOBALS['settings_environment_alerts'] = [];
$GLOBALS['settings_environment_scenarios'] = 0;
$GLOBALS['settings_environment_assertions'] = 0;
$GLOBALS['settings_environment_failures'] = 0;

function set_alert(string $level, string $message): void
{
    $GLOBALS['settings_environment_alerts'][] = [$level, $message];
}
function settingsEnvironmentAssert(bool $condition, string $message): void
{
    ++$GLOBALS['settings_environment_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function settingsEnvironmentTest(string $name, callable $test): void
{
    ++$GLOBALS['settings_environment_scenarios'];
    $GLOBALS['settings_environment_alerts'] = [];
    try { $test(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['settings_environment_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function settingsEnvironmentPayload(array $environment = []): array
{
    return [
        'settings' => $environment + [
            'paymentmethod_asaas_api_key' => 'production-secret-fixture',
            'paymentmethod_asaas_api_key_sandbox' => 'homologation-secret-fixture',
            'paymentmethod_asaas_webhook_secret' => 'webhook-secret-fixture',
            'paymentmethod_asaas_debug' => '1',
            'paymentmethod_asaas_file_log_enabled' => '1',
            'paymentmethod_asaas_show_environment_in_production' => '0',
            'paymentmethod_other_sandbox' => 'unchanged',
            'companyname' => 'Fixture company',
        ],
        'csrf_fixture' => 'unchanged-csrf',
        'other' => ['retained' => true],
    ];
}
function settingsEnvironmentAlertIsSafe(): void
{
    settingsEnvironmentAssert(count($GLOBALS['settings_environment_alerts']) === 1, 'Rejected selections must produce one actionable alert.');
    [$level, $message] = $GLOBALS['settings_environment_alerts'][0];
    settingsEnvironmentAssert($level === 'danger' && str_contains($message, 'Homologação') && str_contains($message, 'Produção'), 'Alert must identify the supported choices without changing them.');
    foreach (['production-secret-fixture', 'homologation-secret-fixture', 'webhook-secret-fixture', 'unexpected-secret'] as $secret) {
        settingsEnvironmentAssert(!str_contains($message, $secret), 'Alert cannot expose submitted secret values.');
    }
}

foreach ([null, false, 'unchanged', [], ['settings' => null], ['settings' => 'unchanged'], ['unrelated' => true], settingsEnvironmentPayload()] as $index => $payload) {
    settingsEnvironmentTest('unrelated or malformed envelope is preserved ' . $index, static function () use ($payload): void {
        settingsEnvironmentAssert(asaas_validate_environment_settings($payload) === $payload, 'A payload without environment fields must remain exactly unchanged.');
        settingsEnvironmentAssert($GLOBALS['settings_environment_alerts'] === [], 'Unrelated settings should not produce an environment alert.');
    });
}

foreach (['' => 'global', '_company_7' => 'company'] as $suffix => $scope) {
    $sandboxName = 'paymentmethod_asaas_sandbox' . $suffix;
    $productionName = 'paymentmethod_asaas_production' . $suffix;
    foreach ([['0', '0'], ['1', '1'], [0, '0'], [1, '1'], [false, '0'], [true, '1']] as $index => [$value, $normalized]) {
        settingsEnvironmentTest($scope . ' single selector normalizes native value ' . $index, static function () use ($sandboxName, $value, $normalized): void {
            $data = settingsEnvironmentPayload([$sandboxName => $value]);
            $before = $data;
            $expected = $data;
            $expected['settings'][$sandboxName] = $normalized;
            settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'Only the environment value may be normalized.');
            settingsEnvironmentAssert($data === $before, 'The filter must not mutate the caller payload.');
            settingsEnvironmentAssert($GLOBALS['settings_environment_alerts'] === [], 'A valid single selector must not produce an alert.');
        });
    }

    foreach ([null, '', ' 1', '0 ', '01', 'true', '0,1', ['0', '1'], ['sandbox' => '1'], 2, -1, 0.0, 1.0, 'unexpected-secret'] as $index => $value) {
        settingsEnvironmentTest($scope . ' invalid selector preserves stored environment ' . $index, static function () use ($sandboxName, $value): void {
            $data = settingsEnvironmentPayload([$sandboxName => $value]);
            $expected = $data;
            unset($expected['settings'][$sandboxName]);
            settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'Invalid environment must be omitted while credentials, diagnostics, CSRF and unrelated options remain unchanged.');
            settingsEnvironmentAlertIsSafe();
        });
    }

    foreach ([['1', '0'], ['0', '1'], [true, false], [false, true]] as $index => [$sandbox, $production]) {
        settingsEnvironmentTest($scope . ' complementary second flag is discarded ' . $index, static function () use ($sandboxName, $productionName, $sandbox, $production): void {
            $data = settingsEnvironmentPayload([$sandboxName => $sandbox, $productionName => $production]);
            $expected = $data;
            $expected['settings'][$sandboxName] = ($sandbox === '1' || $sandbox === true) ? '1' : '0';
            unset($expected['settings'][$productionName]);
            settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'A complementary mirror cannot introduce another persisted option.');
            settingsEnvironmentAssert($GLOBALS['settings_environment_alerts'] === [], 'A consistent pair may preserve its canonical selection.');
        });
    }

    foreach ([['1', '1'], ['0', '0'], ['1', ['0', '1']], [['0', '1'], '0'], ['unexpected-secret', '0'], ['1', null], [1.0, '0']] as $index => [$sandbox, $production]) {
        settingsEnvironmentTest($scope . ' conflicting or malformed pair is rejected ' . $index, static function () use ($sandboxName, $productionName, $sandbox, $production): void {
            $data = settingsEnvironmentPayload([$sandboxName => $sandbox, $productionName => $production]);
            $expected = $data;
            unset($expected['settings'][$sandboxName], $expected['settings'][$productionName]);
            settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'An invalid pair cannot switch environment or persist a production option.');
            settingsEnvironmentAlertIsSafe();
        });
    }

    foreach (['0', '1', null, ['0', '1']] as $index => $value) {
        settingsEnvironmentTest($scope . ' unsupported production-only flag cannot switch environment ' . $index, static function () use ($productionName, $value): void {
            $data = settingsEnvironmentPayload([$productionName => $value]);
            $expected = $data;
            unset($expected['settings'][$productionName]);
            settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'A production-only parameter must be removed without inventing sandbox.');
            settingsEnvironmentAlertIsSafe();
        });
    }
}

settingsEnvironmentTest('diagnostic flag is independent of homologation', static function (): void {
    $data = settingsEnvironmentPayload(['paymentmethod_asaas_sandbox' => '1', 'paymentmethod_asaas_debug' => '1']);
    settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $data, 'Legacy homologation plus diagnostics is valid and must not be reinterpreted as dual environments.');
    settingsEnvironmentAssert($GLOBALS['settings_environment_alerts'] === [], 'Diagnostic mode cannot trigger an environment-conflict alert.');
});

settingsEnvironmentTest('global and company scopes preserve independent valid changes', static function (): void {
    $data = settingsEnvironmentPayload([
        'paymentmethod_asaas_sandbox' => '0',
        'paymentmethod_asaas_sandbox_company_7' => ['0', '1'],
        'paymentmethod_asaas_production_company_7' => '1',
        'paymentmethod_asaas_sandbox_company_9' => true,
        'paymentmethod_asaas_api_key_company_7' => 'company-production-fixture',
    ]);
    $expected = $data;
    unset($expected['settings']['paymentmethod_asaas_sandbox_company_7'], $expected['settings']['paymentmethod_asaas_production_company_7']);
    $expected['settings']['paymentmethod_asaas_sandbox_company_9'] = '1';
    settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $expected, 'Rejecting one company cannot discard a valid global/company selection or touch its API key.');
    settingsEnvironmentAlertIsSafe();
});

settingsEnvironmentTest('unrelated similarly named options are not environment aliases', static function (): void {
    $data = settingsEnvironmentPayload([
        'paymentmethod_asaas_sandbox_notes' => 'Keep notes',
        'paymentmethod_asaas_production_notes' => 'Keep production notes',
        'paymentmethod_other_production' => 'Keep another gateway',
    ]);
    settingsEnvironmentAssert(asaas_validate_environment_settings($data) === $data, 'The filter must match only environment setting names.');
    settingsEnvironmentAssert($GLOBALS['settings_environment_alerts'] === [], 'Similar names are unrelated options.');
});

echo sprintf('Settings environment: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['settings_environment_scenarios'], $GLOBALS['settings_environment_assertions'], $GLOBALS['settings_environment_failures']);
exit($GLOBALS['settings_environment_failures'] === 0 ? 0 : 1);
