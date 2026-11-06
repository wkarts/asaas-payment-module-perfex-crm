<?php

declare(strict_types=1);

/** Render actual recurring views with synthetic values; CLI only, without CRM/API/session. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixtureOrigin = getenv('ASAAS_RECURRING_FIXTURE_ORIGIN') ?: 'https://crm.example.test';
if ($fixtureOrigin !== 'https://crm.example.test'
    && (!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $fixtureOrigin)
        || (int) parse_url($fixtureOrigin, PHP_URL_PORT) > 65535)) {
    fwrite(STDERR, "The recurring fixture accepts only a loopback HTTP origin.\n"); exit(1);
}
define('RECURRING_FIXTURE_ORIGIN', $fixtureOrigin);
define('BASEPATH', __DIR__ . '/');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$fixtureMode = $argv[1] ?? 'inactive';
if (!in_array($fixtureMode, ['inactive', 'active', 'suspended', 'uncertain', 'paid', 'guest', 'invoice', 'hostile'], true)) {
    fwrite(STDERR, "Unknown recurring fixture.\n"); exit(1);
}
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function site_url(string $path = ''): string { return RECURRING_FIXTURE_ORIGIN . '/' . ltrim($path, '/'); }
function get_option(string $name) { return $name === 'paymentmethod_asaas_show_environment_in_production' ? '0' : ''; }
function format_invoice_number(int $id): string { return 'FAT-000042/11/2026'; }
function format_invoice_status($status, $classes = '', $label = true): string {
    return (int) $status === 2 ? '<span class="label label-success">Pago</span>' : '<span class="label label-warning">Não pago</span>';
}
class RecurringFixtureView
{
    public object $security;
    public function __construct() {
        $this->security = new class {
            public function get_csrf_token_name(): string { return 'csrf_fixture'; }
            public function get_csrf_hash(): string { return 'fixture-csrf-token'; }
        };
    }
    public function render(string $mode): void
    {
        $invoice = (object) ['id' => 42, 'hash' => 'recurring-browser-fixture', 'clientid' => 7, 'status' => $mode === 'paid' ? 2 : 1];
        $invoice_url = site_url('invoice/42/' . $invoice->hash);
        $recurring_url = site_url('asaas/recurring/index/' . $invoice->hash);
        $recurring_csrf_nonce = str_repeat('b', 64);
        $login_url = site_url('authentication/login');
        $environment = 'PRODUCTION';
        $requires_login = $mode === 'guest';
        $mensagem_info = $mode === 'paid' ? 'Pagamento confirmado. A autorização permanece ativa para as próximas faturas.' : '';
        $mensagem_erro = '';
        $card_holder_defaults = [
            'holderEmail' => 'customer@example.test', 'holderDocument' => '52998224725',
            'holderPostalCode' => '01001-000', 'holderAddressNumber' => '100', 'holderPhone' => '11900000000',
        ];
        $recurring_page = [
            'enabled' => true, 'state' => $mode === 'paid' ? 'confirmed' : $mode,
            'authorization_state' => in_array($mode, ['active', 'uncertain', 'paid'], true) ? 'active' : ($mode === 'suspended' ? 'suspended' : 'inactive'),
            'attempt_state' => $mode === 'uncertain' ? 'uncertain' : ($mode === 'paid' ? 'confirmed' : ''),
            'can_enroll' => in_array($mode, ['inactive', 'active', 'suspended', 'hostile'], true),
            'can_revoke' => in_array($mode, ['active', 'suspended', 'uncertain', 'paid'], true),
            'can_pay_now' => !in_array($mode, ['guest', 'uncertain', 'paid'], true),
            'quote' => str_repeat('a', 64), 'consent_version' => '2026-11-v1',
            'principal' => '100.00', 'fee' => '3.59', 'total' => '103.59',
            'message' => $mode === 'suspended' ? 'O cartão foi recusado. Cadastre o cartão novamente ou escolha um pagamento pontual.' : '',
        ];
        if ($mode === 'hostile') {
            $recurring_page['message'] = '<script>window.fixtureXss = true;</script>';
            $recurring_page['consent_text'] = '<img src=x onerror="window.fixtureXss = true;">';
            $card_holder_defaults['holderEmail'] = '"><svg onload="window.fixtureXss = true;">';
        }
        if ($mode === 'invoice') {
            echo '<main class="container mtop25"><div class="panel_s"><div class="panel-body"><h1>FAT-000042/11/2026</h1>';
            echo '<h3>Pagamento online</h3><label><input type="radio" name="paymentmode" value="asaas" checked> Asaas — Boleto, Pix ou cartão</label>';
            include dirname(__DIR__, 2) . '/views/partials/recurring_payment_option.php';
            echo '</div></div></main>';
            return;
        }
        include dirname(__DIR__, 2) . '/views/recorrente.php';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cartão recorrente — fixture isolada</title>
    <link rel="stylesheet" href="/_fixture/bootstrap.css">
    <style>
        body{background:#f7f9fb;color:#334155;font-family:Arial,sans-serif;font-size:14px}
        .panel_s{background:#fff;border:1px solid #dce4ee;border-radius:5px;margin-bottom:20px}
        .panel_s>.panel-body{padding:25px}.bold{font-weight:600}.no-mbot{margin-bottom:0}.no-margin{margin:0}
        .mtop5{margin-top:5px}.mtop10{margin-top:10px}.mtop15{margin-top:15px}.mtop20{margin-top:20px}.mtop25{margin-top:25px}
        .mbot0{margin-bottom:0}.mbot30{margin-bottom:30px}.display-block{display:block}.mleft10{margin-left:10px}
        .form-control{box-shadow:none;border-color:#cbd5e1}.btn{border-radius:4px}.btn-primary{background:#0088c5;border-color:#0088c5}
        .text-muted{color:#64748b}.label{display:inline-block;padding:5px 8px}
    </style>
</head>
<body>
<?php (new RecurringFixtureView())->render($fixtureMode); ?>
</body>
</html>
