<?php

declare(strict_types=1);

/** Renders the actual public views; no SDK, database or external HTTP is used. */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('BASEPATH', __DIR__);
require_once __DIR__ . '/../helpers/asaas_checkout_display_helper.php';

$GLOBALS['environment_smoke_options'] = [];
$GLOBALS['environment_smoke_tests'] = 0;
$GLOBALS['environment_smoke_assertions'] = 0;
$GLOBALS['environment_smoke_failures'] = 0;

function get_option(string $name)
{
    return $GLOBALS['environment_smoke_options'][$name] ?? false;
}
function validation_errors(string $before = '', string $after = ''): string { return ''; }
function is_invoice_overdue($invoice): bool { return false; }
function is_client_logged_in(): bool { return false; }
function has_contact_permission(string $permission): bool { return false; }
function format_invoice_number($id): string { return 'FAT-' . (string) $id; }
function format_invoice_status($status, string $classes = '', bool $html = true): string { return '<span>Não pago</span>'; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function _l(string $key, $value = null): string { return $key; }
function format_organization_info(): string { return 'Empresa de teste'; }
function app_format_money($value, string $currency): string { return number_format((float) $value, 2, ',', '.') . ' ' . $currency; }
function app_format_number($value, bool $decimals = false): string { return (string) $value; }
function is_sale_discount_applied($invoice): bool { return false; }
function is_sale_discount($invoice, $type): bool { return false; }
function db_prefix(): string { return 'tbl'; }
function total_credits_applied_to_invoice($invoiceId): int { return 0; }
function sum_from_table(string $table, array $options): int { return 0; }

function environmentSmokeAssert(bool $condition, string $message): void
{
    $GLOBALS['environment_smoke_assertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function environmentSmokeTest(string $name, callable $callback): void
{
    $GLOBALS['environment_smoke_tests']++;
    try {
        $callback();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $GLOBALS['environment_smoke_failures']++;
        echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
    }
}

function environmentSmokeRender(string $view, string $environmentValue, $productionOption, array $overrides = []): string
{
    $GLOBALS['environment_smoke_options'] = $productionOption === null ? [] : [
        'paymentmethod_asaas_show_environment_in_production' => $productionOption,
    ];
    $environment = $environmentValue;
    $invoice = (object) [
        'id' => 42, 'hash' => 'hash-environment-fixture', 'status' => 1,
        'duedate' => '2030-01-20', 'subtotal' => 120, 'total' => 120,
        'total_left_to_pay' => 120, 'adjustment' => 0, 'discount_total' => 0,
        'discount_percent' => 0, 'currency_name' => 'BRL', 'payments' => [],
    ];
    $hash = $invoice->hash;
    $billet_only = 1;
    $pix_only = 1;
    $card_only = 1;
    $response = (object) ['payload' => 'pix-copy-fixture', 'encodedImage' => 'R0lGODlhAQABAIAAAAAAAP///w=='];
    extract($overrides, EXTR_OVERWRITE);
    ob_start();
    try {
        require __DIR__ . '/../views/' . $view . '.php';
        return (string) ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
}

$cases = [
    ['production disabled', 'PRODUCTION', '0', false],
    ['production enabled', 'PRODUCTION', '1', true],
    ['production default', 'PRODUCTION', null, false],
    ['sandbox with production disabled', 'SANDBOX', '0', true],
    ['sandbox with production enabled', 'SANDBOX', '1', true],
];

foreach (['payment', 'qrcode'] as $view) {
    foreach ($cases as [$name, $environment, $option, $visible]) {
        environmentSmokeTest($view . ': ' . $name, static function () use ($view, $environment, $option, $visible): void {
            $html = environmentSmokeRender($view, $environment, $option);
            environmentSmokeAssert(str_contains($html, 'Ambiente ativo:') === $visible, 'Environment notice visibility differs from the setting.');
            environmentSmokeAssert(str_contains($html, $environment === 'SANDBOX' ? 'Homologação' : 'Produção') === $visible, 'Portuguese environment label must follow the notice.');
            environmentSmokeAssert(!str_contains($html, $environment), 'Technical environment identifiers must not be displayed.');
            environmentSmokeAssert(str_contains($html, 'invoice/42/hash-environment-fixture'), 'Invoice return URL must remain available.');
            if ($view === 'payment') {
                foreach (['boleto', 'cartao', 'qrcode'] as $method) {
                    environmentSmokeAssert(str_contains($html, '/asaas/checkout/' . $method . '/hash-environment-fixture'), 'Payment method URL must remain unchanged: ' . $method);
                }
            } else {
                environmentSmokeAssert(str_contains($html, 'pix-copy-fixture'), 'Pix payload must remain visible.');
                environmentSmokeAssert(str_contains($html, 'Copiar a chave'), 'Pix copy control must remain visible.');
                environmentSmokeAssert(str_contains($html, 'asaas/get_invoice_data/'), 'Pix payment confirmation polling must remain available.');
            }
        });
    }
}

environmentSmokeTest('production notice does not enable a disabled card method', static function (): void {
    $html = environmentSmokeRender('payment', 'PRODUCTION', '1', ['card_only' => 0]);
    environmentSmokeAssert(str_contains($html, 'Ambiente ativo:'), 'Production notice should be shown when enabled.');
    environmentSmokeAssert(!str_contains($html, '/asaas/checkout/cartao/'), 'Disabled card payment must remain disabled.');
    environmentSmokeAssert(str_contains($html, '/asaas/checkout/boleto/'), 'Boleto must remain available.');
    environmentSmokeAssert(str_contains($html, '/asaas/checkout/qrcode/'), 'Pix must remain available.');
});

environmentSmokeTest('partial escapes environment and hint text', static function (): void {
    $html = environmentSmokeRender('partials/payment_environment', '<test-environment>', '1', [
        'asaas_environment_hint' => '<script>unsafe()</script>',
    ]);
    environmentSmokeAssert(str_contains($html, '&lt;test-environment&gt;'), 'Environment text must be escaped.');
    environmentSmokeAssert(str_contains($html, '&lt;script&gt;unsafe()&lt;/script&gt;'), 'Hint text must be escaped.');
    environmentSmokeAssert(!str_contains($html, '<script>unsafe()'), 'Partial must not render supplied HTML.');
});

environmentSmokeTest('missing environment does not invent an active environment', static function (): void {
    $html = environmentSmokeRender('partials/payment_environment', '', '1');
    environmentSmokeAssert(!str_contains($html, 'Ambiente ativo:'), 'Empty environment must not display an invented label.');
});

echo sprintf(
    'Checkout environment: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['environment_smoke_tests'],
    $GLOBALS['environment_smoke_assertions'],
    $GLOBALS['environment_smoke_failures']
);
exit($GLOBALS['environment_smoke_failures'] === 0 ? 0 : 1);
