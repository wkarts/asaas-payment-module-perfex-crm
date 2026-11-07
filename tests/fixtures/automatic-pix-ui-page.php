<?php

declare(strict_types=1);

/** Actual customer views with synthetic data; CLI only. No session, database or bank access. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$origin = getenv('ASAAS_PIX_FIXTURE_ORIGIN') ?: 'https://crm.example.test';
if ($origin !== 'https://crm.example.test' && (!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $origin) || (int) parse_url($origin, PHP_URL_PORT) > 65535)) {
    fwrite(STDERR, "Only the synthetic or loopback origin is allowed.\n"); exit(1);
}
define('PIX_FIXTURE_ORIGIN', $origin);
$mode = $argv[1] ?? 'inactive';
if (!in_array($mode, ['inactive', 'created', 'active', 'uncertain', 'cancelled', 'guest', 'invoice', 'hostile'], true)) { exit(1); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function site_url(string $path = ''): string { return PIX_FIXTURE_ORIGIN . '/' . ltrim($path, '/'); }
function module_dir_url(string $module, string $path): string { return site_url('modules/' . $module . '/' . $path); }
function format_invoice_number(int $id): string { return 'FAT-000042/11/2026'; }
function form_open(string $url, array $attributes = []): string
{
    $html = '<form action="' . html_escape($url) . '" method="post"';
    foreach ($attributes as $name => $value) { $html .= ' ' . html_escape($name) . '="' . html_escape($value) . '"'; }
    return $html . '><input type="hidden" name="csrf_fixture" value="fixture-csrf-token">';
}
function form_close(): string { return '</form>'; }
require_once dirname(__DIR__, 2) . '/libraries/Asaas/AsaasAutomaticPix.php';

final class AutomaticPixFixtureView
{
    public object $session;
    public function __construct() { $this->session = new class { public function flashdata(string $name): mixed { return null; } }; }
    public function render(string $mode): void
    {
        $invoice = (object) ['id' => 42, 'hash' => 'pix-browser-fixture', 'status' => 1, 'clientid' => 7];
        $invoice_url = site_url('invoice/42/' . $invoice->hash);
        $pix_url = site_url('asaas/automatic_pix/index/' . $invoice->hash);
        $login_url = site_url('authentication/login');
        $requires_login = $mode === 'guest';
        $pix_nonce = str_repeat('b', 64);
        $pix_page = [
            'message' => asaas_automatic_pix_message($mode),
            'can_enroll' => in_array($mode, ['inactive', 'cancelled', 'hostile'], true),
            'can_refresh' => in_array($mode, ['created', 'active', 'uncertain', 'cancelled'], true),
            'can_cancel' => in_array($mode, ['created', 'active'], true),
            'can_pay_now' => in_array($mode, ['inactive', 'cancelled', 'hostile'], true),
            'amount' => '207.69', 'frequency' => 'MONTHLY', 'quote' => str_repeat('a', 64),
            'consent_version' => \Asaas\AsaasAutomaticPix::CONSENT_VERSION,
            'consent_text' => 'Autorizo o pagamento inicial pelo valor exibido e o agendamento das novas faturas Asaas deste cliente na frequência escolhida. A cobrança em aberto desta fatura será substituída pelo QR Code de adesão. A autorização deve ser confirmada no aplicativo do meu banco. Faturas anteriores não serão incluídas automaticamente. Posso solicitar o cancelamento; operações já enviadas dependem da confirmação do banco.',
            'payload' => $mode === 'created' ? str_repeat('PIX-FIXTURE-NOT-A-PAYMENT-', 12) : '',
            // A single transparent PNG tests native base64 rendering; it is not a real payment QR.
            'encoded_image' => $mode === 'created' ? 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVmEAAAAASUVORK5CYII=' : '',
            'expires_at' => '2026-11-09 10:10:00',
        ];
        if ($mode === 'hostile') {
            $pix_page['message'] = '<script>window.fixtureXss = true;</script>';
            $pix_page['consent_text'] = '<img src=x onerror="window.fixtureXss = true;">';
        }
        if ($mode === 'invoice') {
            echo '<main class="container"><div class="panel_s"><div class="panel-body"><h1 class="h4">FAT-000042/11/2026</h1><div class="row">';
            echo '<div class="col-md-12"><table class="table table-bordered"><tr><th>Descrição</th><th>Valor</th></tr><tr><td>Serviço mensal</td><td>R$ 207,69</td></tr></table></div>';
            echo '<div class="col-md-6 col-md-offset-6" data-fixture-totals><table class="table text-right"><tr><td>Total</td><td>R$ 207,69</td></tr><tr><td>Quantia devida</td><td>R$ 207,69</td></tr></table></div>';
            include dirname(__DIR__, 2) . '/views/partials/automatic_pix_option.php';
            echo '<div class="col-md-12" data-fixture-payment><hr><h3 class="h4">Pagamento online</h3><label><input type="radio" name="paymentmode" value="asaas" checked> Asaas — boleto, Pix ou cartão</label></div></div></div></div></main>';
            return;
        }
        include dirname(__DIR__, 2) . '/views/automatic_pix.php';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pix Automático — fixture isolada</title>
    <link rel="stylesheet" href="/_fixture/bootstrap.css">
    <style>
        body{background:#f7f9fb;color:#334155;font:14px Arial,sans-serif}
        .panel_s{background:#fff;border:1px solid #dce4ee;border-radius:5px;margin-bottom:20px}
        .panel_s>.panel-body{padding:24px}.container{margin-top:24px}.mtop20{margin-top:20px}
        .form-control{box-shadow:none;border-color:#cbd5e1}.btn{border-radius:4px}
        .btn-primary{background:#0088c5;border-color:#0088c5}.text-muted{color:#64748b}
        .alert-info{background:#eff6ff;color:#1d4ed8;border:0;border-left:3px solid #38bdf8}
        .alert p{margin-top:6px}.btn-danger{background:#fff;color:#b91c1c;border-color:#fecaca}
    </style>
</head>
<body><?php (new AutomaticPixFixtureView())->render($mode); ?></body>
</html>
