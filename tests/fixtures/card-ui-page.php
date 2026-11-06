<?php

declare(strict_types=1);

/** Render the real card view with synthetic invoice/offers; CLI only, no CRM or API. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$mode = $argv[1] ?? 'extended';
if (!in_array($mode, ['extended', 'limited', 'extended-first', 'blocked'], true)) {
    fwrite(STDERR, "Unknown card fixture.\n"); exit(1);
}

function get_option(string $name) { return $name === 'show_amount_due_on_invoice' ? '1' : '0'; }
function validation_errors(string $before = '', string $after = ''): string { return ''; }
function is_invoice_overdue($invoice): bool { return false; }
function is_client_logged_in(): bool { return false; }
function has_contact_permission(string $permission): bool { return false; }
function format_invoice_number($id): string { return 'FAT-000042/11/2026'; }
function format_invoice_status($status, string $classes = '', bool $html = true): string { return '<span class="label label-warning">Não pago</span>'; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function _l(string $key, $value = null): string {
    return [
        'invoice_bill_to' => 'Faturar para', 'invoice_data_date' => 'Data da fatura:',
        'invoice_data_duedate' => 'Vencimento:', 'invoice_subtotal' => 'Subtotal',
        'invoice_total' => 'Total', 'invoice_amount_due' => 'Saldo da fatura',
    ][$key] ?? $key;
}
function _d(string $date): string { return implode('/', array_reverse(explode('-', $date))); }
function format_organization_info(): string { return 'Empresa de demonstração<br>Serviços mensais'; }
function format_customer_info($invoice, string $type, string $address): string { return 'Cliente de demonstração<br>Endereço de teste'; }
function get_custom_fields(string $type, array $filters): array { return []; }
function app_format_money($value, string $currency): string { return 'R$ ' . number_format((float) $value, 2, ',', '.'); }
function app_format_number($value, bool $decimals = false): string { return (string) $value; }
function is_sale_discount_applied($invoice): bool { return false; }
function is_sale_discount($invoice, $type): bool { return false; }
function db_prefix(): string { return 'tbl'; }
function total_credits_applied_to_invoice($invoiceId): int { return 0; }
function sum_from_table(string $table, array $options): int { return 0; }
class Invoices_model { public const STATUS_CANCELLED = 5; }

final class CardBrowserFixture
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
        $invoice = (object) [
            'id' => 42, 'hash' => 'card-browser-fixture', 'status' => 1,
            'date' => '2026-11-01', 'duedate' => '2026-11-20', 'subtotal' => 1000, 'total' => 1000,
            'total_left_to_pay' => 1000, 'adjustment' => 0, 'discount_total' => 0,
            'discount_percent' => 0, 'currency_name' => 'BRL', 'payments' => [],
            'include_shipping' => 0, 'show_shipping_on_invoice' => 0, 'sale_agent' => 0, 'project_id' => 0,
        ];
        $environment = 'PRODUCTION';
        $card_holder_defaults = [
            'holderName' => '', 'holderEmail' => 'customer@example.test',
            'holderDocument' => '52998224725', 'holderPostalCode' => '01001-000',
            'holderAddressNumber' => '100', 'holderPhone' => '11900000000',
        ];
        $counts = $mode === 'limited' ? [1, 3, 12] : ($mode === 'extended-first' ? [13, 1, 3, 12, 21] : [1, 3, 12, 13, 21]);
        $totals = [1 => 103590, 3 => 104130, 12 => 104660, 13 => 105030, 21 => 105030];
        $money = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
        $installment_options = [];
        foreach ($counts as $count) {
            $total = $totals[$count];
            $installment = intdiv($total, $count);
            $last = $total - ($installment * ($count - 1));
            $installment_options[] = [
                'count' => $count, 'principal' => '1000.00',
                'fee' => number_format(($total - 100000) / 100, 2, '.', ''),
                'total' => number_format($total / 100, 2, '.', ''),
                'label' => $count === 1 ? 'À vista — ' . $money($total)
                    : $count . 'x a partir de ' . $money($installment) . ' — total ' . $money($total),
                'schedule' => $count === 1 ? 'Pagamento à vista.'
                    : ($count - 1) . ' parcelas de ' . $money($installment) . ' e a última de ' . $money($last) . '.',
                'quote' => str_repeat('a', 64),
            ];
        }
        $card_checkout_blocked = $mode === 'blocked';
        $mensagem_info = $card_checkout_blocked ? 'Aguardando confirmação do pagamento. Não envie uma nova tentativa.' : '';
        require dirname(__DIR__, 2) . '/views/cartao.php';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento com cartão — fixture isolada</title>
    <link rel="stylesheet" href="/_fixture/bootstrap.css">
    <style>
        body{background:#f7f9fb;color:#334155;font-family:Arial,sans-serif;font-size:14px}
        .kt-container{max-width:1170px;margin:25px auto}.kt-portlet{background:#fff;border:1px solid #dce4ee;border-radius:5px}
        .bold{font-weight:600}.no-mtop{margin-top:0}.no-mbot{margin-bottom:0}
        .mtop5{margin-top:5px}.mtop10{margin-top:10px}.mtop15{margin-top:15px}.mtop20{margin-top:20px}.mtop25{margin-top:25px}
        .mright5{margin-right:5px}.text-muted{color:#64748b}.label{display:inline-block;padding:5px 8px}
        .form-control{box-shadow:none;border-color:#cbd5e1}.btn{border-radius:4px}
    </style>
</head>
<body>
<?php (new CardBrowserFixture())->render($mode); ?>
</body>
</html>
