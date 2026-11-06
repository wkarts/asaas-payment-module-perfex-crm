<?php

declare(strict_types=1);

/** Actual Checkout methods and helpers, with narrow doubles for Perfex and gateway effects. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);

$GLOBALS['card_controller_ci'] = new stdClass();
$GLOBALS['card_controller_redirects'] = [];
$GLOBALS['card_controller_not_found'] = 0;
$GLOBALS['card_controller_logs'] = [];
$GLOBALS['card_controller_assertions'] = 0;
$GLOBALS['card_controller_scenarios'] = 0;
$GLOBALS['card_controller_failures'] = 0;

function &get_instance(): object { return $GLOBALS['card_controller_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return false; }
function log_message(string $level, string $message): void { $GLOBALS['card_controller_logs'][] = $message; }
function show_404(): void { ++$GLOBALS['card_controller_not_found']; }
function redirect(string $url, string $method = 'auto', ?int $code = null): void { $GLOBALS['card_controller_redirects'][] = [$url, $method, $code]; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function set_alert(string $level, string $message): void {}
function get_invoice_total_left_to_pay($id, $total = null): string
{
    $ci = get_instance();
    $balance = asaas_payment_amount_to_cents($ci->db->invoice->base_balance);
    foreach ($ci->asaas_gateway->receipts as $receipt) {
        if ((int) $receipt['invoiceid'] === (int) $id) { $balance -= asaas_payment_amount_to_cents($receipt['amount']); }
    }
    return asaas_card_money(max(0, $balance));
}

require_once dirname(__DIR__) . '/helpers/asaas_card_helper.php';
require_once dirname(__DIR__) . '/helpers/asaas_lifecycle_helper.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasCardCheckout.php';

final class CardControllerResult
{
    public function __construct(private ?array $value) {}
    public function row(): ?object { return $this->value === null ? null : (object) $this->value; }
    public function row_array(): array { return $this->value ?? []; }
}
final class CardControllerDatabase
{
    public ?object $invoice;
    public array $map = [];
    public array $logs = [];
    public bool $db_debug = true;
    private array $where = [];
    public function __construct()
    {
        $this->invoice = (object) [
            'id' => 42, 'hash' => 'hash-card-controller', 'status' => 1, 'total' => '150.00', 'base_balance' => '100.00',
            'client' => (object) ['email' => 'customer@example.test', 'vat' => '12345678909', 'zip' => '01001-000', 'numero' => '100', 'phonenumber' => '11999999999'],
        ];
    }
    public function where(string $key, $value): self { $this->where[$key] = $value; return $this; }
    public function get(string $table): CardControllerResult
    {
        $where = $this->where; $this->where = [];
        if ($table === 'tblinvoices' && $this->invoice !== null && ($where['hash'] ?? null) === $this->invoice->hash) {
            return new CardControllerResult((array) $this->invoice);
        }
        return new CardControllerResult(null);
    }
    public function get_where(string $table, array $where): CardControllerResult
    {
        return new CardControllerResult($table === 'tblasaas_payments_map' && $this->map !== [] ? $this->map : null);
    }
    public function table_exists(string $table): bool { return in_array($table, ['tblasaas_payments_map', 'tblasaas_logs'], true); }
    public function list_fields(string $table): array { return ['level', 'message', 'created_at', 'context_json']; }
    public function insert(string $table, array $data): bool { $this->logs[] = $data; return true; }
}
final class CardControllerInput
{
    public array $posted = [];
    public function post(?string $name = null, bool $filter = false) { return $name === null ? $this->posted : ($this->posted[$name] ?? null); }
}
final class CardControllerSession
{
    public array $flash = [];
    public function flashdata(string $key)
    {
        $value = $this->flash[$key] ?? null;
        unset($this->flash[$key]);
        return $value;
    }
    public function set_flashdata(string $key, $value): void { $this->flash[$key] = $value; }
}
final class CardControllerOutput
{
    public int $cacheMinutes = 60;
    public bool $profilerEnabled = true;
    public array $headers = [];
    public function cache($minutes): self { $this->cacheMinutes = (int) $minutes; return $this; }
    public function enable_profiler($enabled = true): self { $this->profilerEnabled = (bool) $enabled; return $this; }
    public function set_header($header, $replace = true): self { $this->headers[] = $header; return $this; }
}
final class CardControllerInvoiceModel
{
    public function get($id): ?object
    {
        $invoice = get_instance()->db->invoice;
        if ($invoice === null || (int) $invoice->id !== (int) $id) { return null; }
        $result = clone $invoice;
        if (asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($id)) === 0) { $result->status = 2; }
        return $result;
    }
}
final class CardControllerCheckout
{
    public bool $enabled = true;
    public int $reconciles = 0;
    public ?array $reconcileResult = null;
    public ?Throwable $reconcileError = null;
    public function available(object $invoice): bool { return $this->enabled; }
    public function discount(): array { return ['type' => 'FIXED', 'value' => 0, 'dueDateLimitDays' => 0]; }
    public function reconcile(object $invoice): ?array
    {
        ++$this->reconciles;
        if ($this->reconcileError !== null) { throw $this->reconcileError; }
        return $this->reconcileResult;
    }
}
final class CardControllerGateway
{
    public CardControllerCheckout $checkout;
    public array $charges = [];
    public array $receiptCalls = [];
    public array $receipts = [];
    public array $chargeResult = [];
    public ?Throwable $chargeError = null;
    public bool $receiptAccepted = true;
    public int $chargeReads = 0;
    public int $qrcodeCalls = 0;
    public array $globalsAtCapture = [];
    public function __construct() { $this->checkout = new CardControllerCheckout(); }
    public function card_checkout(): CardControllerCheckout { return $this->checkout; }
    public function getSetting(string $name) { return $name === 'installmentCount' ? '3' : '1'; }
    public function getEnvironmentLabel(): string { return 'PRODUCTION'; }
    public function charge_credit_card(array $data): string
    {
        $this->globalsAtCapture = ['post' => $_POST, 'request' => $_REQUEST];
        $this->charges[] = $data;
        if ($this->chargeError !== null) { throw $this->chargeError; }
        return json_encode($this->chargeResult, JSON_THROW_ON_ERROR);
    }
    public function addPayment(array $receipt): bool
    {
        $this->receiptCalls[] = $receipt;
        if (!$this->receiptAccepted) { return false; }
        // This fake represents the existing gateway's idempotent persistence boundary.
        $key = $receipt['invoiceid'] . ':' . $receipt['transactionid'];
        $this->receipts[$key] ??= $receipt;
        return true;
    }
    public function get_charge(string $hash): object
    {
        ++$this->chargeReads;
        return (object) ['id' => 'pay_boleto', 'bankSlipUrl' => 'https://example.test/boleto'];
    }
    public function create_qrcode(string $id): string { ++$this->qrcodeCalls; return '{}'; }
}
class ClientsController
{
    public object $db;
    public object $load;
    public object $input;
    public object $session;
    public object $output;
    public object $asaas_gateway;
    public object $invoices_model;
    public array $viewData = [];
    public ?string $viewName = null;
    public int $layouts = 0;
    public function __construct()
    {
        foreach (['db', 'load', 'input', 'session', 'output', 'asaas_gateway', 'invoices_model'] as $name) { $this->$name = get_instance()->$name; }
    }
    public function data(array $data): void { $this->viewData = $data; }
    public function view(string $name): void { $this->viewName = $name; }
    public function layout(): void { ++$this->layouts; }
    public function title(string $title): void {}
}
require_once dirname(__DIR__) . '/controllers/Checkout.php';

function cardControllerAssert(bool $condition, string $message): void
{
    ++$GLOBALS['card_controller_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function cardControllerTest(string $name, callable $test): void
{
    ++$GLOBALS['card_controller_scenarios'];
    try { $test(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['card_controller_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function cardControllerFixture(): array
{
    $_POST = $_REQUEST = [];
    $GLOBALS['card_controller_redirects'] = [];
    $GLOBALS['card_controller_not_found'] = 0;
    $GLOBALS['card_controller_logs'] = [];
    $gateway = new CardControllerGateway();
    $db = new CardControllerDatabase();
    $GLOBALS['card_controller_ci'] = (object) [
        'db' => $db, 'asaas_gateway' => $gateway, 'input' => new CardControllerInput(), 'session' => new CardControllerSession(),
        'invoices_model' => new CardControllerInvoiceModel(), 'output' => new CardControllerOutput(),
        'load' => new class { public function library(string $name): void {} public function model(string $name): void {} public function helper(string $name): void {} },
    ];
    return [new Checkout(), $gateway, $db];
}
function cardControllerPost(): array
{
    return [
        'holderName' => 'Titular', 'cardNumber' => '4111111111111111', 'expirationMonth' => '12', 'expirationYear' => '2030', 'securityCode' => '937', 'installmentCount' => '2',
        'holderEmail' => 'holder@example.test', 'holderDocument' => '12345678909', 'holderPostalCode' => '01001000', 'holderAddressNumber' => '100', 'holderPhone' => '11999999999',
    ];
}
function cardControllerPayment(string $id, string $status = 'CONFIRMED', bool $installment = true): array
{
    $payment = ['id' => $id, 'billingType' => 'CREDIT_CARD', 'status' => $status, 'customer' => 'cus_fixture', 'externalReference' => 'hash-card-controller', 'value' => '50.00'];
    if ($installment) { $payment['installment'] = 'ins_fixture'; }
    return $payment;
}
function cardControllerPendingMap(CardControllerDatabase $db): void
{
    $db->map = ['invoice_id' => 42, 'asaas_payment_id' => 'pay_pending', 'payload_cache' => json_encode([
        'id' => 'pay_pending', '_asaas_lifecycle' => ['state' => 'active', 'card_attempt' => ['state' => 'pending']],
    ], JSON_THROW_ON_ERROR)];
}

cardControllerTest('unknown card invoice hash returns 404 before any capture', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $controller->input->posted = cardControllerPost();
    $controller->cartao('unknown-hash');
    cardControllerAssert($GLOBALS['card_controller_not_found'] === 1 && $gateway->charges === [], 'Missing hash must be rejected before card processing.');
    cardControllerAssert($controller->viewName === null && $GLOBALS['card_controller_redirects'] === [], 'Missing invoice must not render checkout or redirect to a fabricated invoice.');
});
cardControllerTest('GET, POST and unknown invoice disable cache and profiler', static function (): void {
    foreach (['get', 'post', 'missing'] as $mode) {
        [$controller] = cardControllerFixture();
        if ($mode !== 'get') { $controller->input->posted = cardControllerPost(); }
        $controller->cartao($mode === 'missing' ? 'unknown-hash' : 'hash-card-controller');
        cardControllerAssert($controller->output->cacheMinutes === 0, 'Card responses must not enter the framework page cache.');
        cardControllerAssert(!$controller->output->profilerEnabled, 'Card responses must not render the framework POST profiler.');
        foreach (['Cache-Control: no-store', 'Pragma: no-cache', 'Expires: 0'] as $header) {
            cardControllerAssert(in_array($header, $controller->output->headers, true), 'Missing privacy response header: ' . $header);
        }
    }
});
cardControllerTest('credentials leave request globals before capture or rejection', static function (): void {
    foreach (['success', 'error', 'disabled', 'missing'] as $mode) {
        [$controller, $gateway] = cardControllerFixture();
        $posted = cardControllerPost();
        $controller->input->posted = $posted;
        $_POST = $_REQUEST = $posted + ['csrf_fixture' => 'unchanged', 'unrelated_field' => 'keep'];
        if ($mode === 'error') { $gateway->chargeError = new RuntimeException('Fixture failure'); }
        if ($mode === 'disabled') { $gateway->checkout->enabled = false; }
        $controller->cartao($mode === 'missing' ? 'unknown-hash' : 'hash-card-controller');
        foreach (array_keys($posted) as $field) {
            if ($field === 'installmentCount') { continue; }
            cardControllerAssert(!array_key_exists($field, $_POST) && !array_key_exists($field, $_REQUEST), 'Sensitive field remains in globals after ' . $mode . ': ' . $field);
            if ($gateway->charges !== []) {
                cardControllerAssert(!array_key_exists($field, $gateway->globalsAtCapture['post']) && !array_key_exists($field, $gateway->globalsAtCapture['request']), 'Sensitive globals must already be gone before the HTTP gateway call.');
            }
        }
        cardControllerAssert($_POST === ['installmentCount' => '2', 'csrf_fixture' => 'unchanged', 'unrelated_field' => 'keep'], 'CSRF, installments and unrelated fields must be preserved.');
        cardControllerAssert($_REQUEST === $_POST, 'Both request globals must be purged consistently.');
        if ($mode !== 'missing') {
            cardControllerAssert($GLOBALS['card_controller_redirects'][0][1] === 'location' && $GLOBALS['card_controller_redirects'][0][2] === 303, 'The card POST must redirect explicitly to a GET with status 303.');
        }
    }
});
cardControllerTest('disabled card POST cannot invoke the gateway capture', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $gateway->checkout->enabled = false;
    $controller->input->posted = cardControllerPost();
    $controller->cartao('hash-card-controller');
    cardControllerAssert($gateway->charges === [], 'Disabled card method must not call charge_credit_card.');
    cardControllerAssert(count($GLOBALS['card_controller_redirects']) === 1 && $controller->viewName === null, 'Rejected POST must use PRG.');
    cardControllerAssert(str_contains($controller->session->flash['asaas_card_error'], 'não está disponível'), 'A controlled availability error should be shown.');
});
cardControllerTest('posted holder/card fields preserve gateway contracts and PRG avoids recapture', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $posted = cardControllerPost();
    $controller->input->posted = $posted;
    $gateway->chargeResult = cardControllerPayment('pay_pending', 'AUTHORIZED') + ['_asaas_card_receipts' => []];
    $controller->cartao('hash-card-controller');
    $card = $gateway->charges[0]['card'];
    foreach (['holderName', 'installmentCount', 'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'] as $name) {
        cardControllerAssert($card[$name] === $posted[$name], 'Holder field must keep its value: ' . $name);
    }
    foreach (['number' => 'cardNumber', 'expiryMonth' => 'expirationMonth', 'expiryYear' => 'expirationYear', 'cvv' => 'securityCode'] as $field => $name) {
        cardControllerAssert($card[$field] === $posted[$name], 'Legacy form name must map to the current card contract: ' . $name);
    }
    cardControllerAssert($controller->viewName === null && $GLOBALS['card_controller_redirects'][0][0] === site_url('asaas/checkout/cartao/hash-card-controller'), 'POST must redirect to the GET confirmation page.');
    $controller->input->posted = [];
    $controller->cartao('hash-card-controller');
    cardControllerAssert(count($gateway->charges) === 1 && $gateway->checkout->reconciles === 1, 'Following the redirect must reconcile without another capture.');
    cardControllerAssert($controller->viewName === 'asaas/cartao' && $controller->viewData['invoice']->total_left_to_pay === '100.00', 'GET should render the complete invoice and fresh balance.');
    $saved = json_encode([$controller->session->flash, $controller->viewData], JSON_THROW_ON_ERROR);
    cardControllerAssert(!str_contains($saved, '4111111111111111') && !array_key_exists('securityCode', $controller->viewData['card_holder_defaults']), 'PRG/session/view data must never retain PAN or CVV.');
});
cardControllerTest('unexpected runtime exceptions cannot expose remote or card details', static function (): void {
    [$controller, $gateway, $db] = cardControllerFixture();
    $controller->input->posted = cardControllerPost();
    $gateway->chargeError = new RuntimeException('RAW_REMOTE_ERROR 4111111111111111 token_fixture_sensitive');
    $controller->cartao('hash-card-controller');
    $stored = json_encode([$controller->session->flash, $GLOBALS['card_controller_logs'], $db->logs], JSON_THROW_ON_ERROR);
    foreach (['RAW_REMOTE_ERROR', '4111111111111111', 'token_fixture_sensitive'] as $secret) {
        cardControllerAssert(!str_contains($stored, $secret), 'Unexpected exception detail must not reach flash/logs.');
    }
    cardControllerAssert(str_contains($controller->session->flash['asaas_card_error'], 'Consulte o financeiro'), 'The user must receive the controlled generic failure.');
});
cardControllerTest('GET reconciliation never calls the capture gateway', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $gateway->checkout->reconcileResult = cardControllerPayment('pay_authorized', 'AUTHORIZED') + ['_asaas_card_receipts' => []];
    $controller->cartao('hash-card-controller');
    cardControllerAssert($gateway->checkout->reconciles === 1 && $gateway->charges === [] && $gateway->receiptCalls === [], 'GET may read/reconcile, never capture or settle an authorization.');
    cardControllerAssert($controller->viewName === 'asaas/cartao', 'GET must retain the card view.');
});
cardControllerTest('confirmed parcels settle only their own amount and delegate repeated receipt IDs', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $first = cardControllerPayment('pay_parcel_1');
    $gateway->checkout->reconcileResult = $first + ['_asaas_card_receipts' => [$first]];
    $controller->cartao('hash-card-controller');
    cardControllerAssert($gateway->receiptCalls[0]['amount'] === '50.00' && get_invoice_total_left_to_pay(42) === '50.00', 'The first confirmed parcel must not settle the full balance.');
    $received = cardControllerPayment('pay_parcel_1', 'RECEIVED');
    $second = cardControllerPayment('pay_parcel_2', 'CONFIRMED');
    $gateway->checkout->reconcileResult = $received + ['_asaas_card_receipts' => [$received, $second]];
    $controller->cartao('hash-card-controller');
    cardControllerAssert(array_column($gateway->receiptCalls, 'transactionid') === ['pay_parcel_1', 'pay_parcel_1', 'pay_parcel_2'], 'CONFIRMED/RECEIVED must share the payment ID passed to the gateway idempotency boundary.');
    cardControllerAssert(array_column($gateway->receiptCalls, 'amount') === ['50.00', '50.00', '50.00'], 'Repeated or subsequent parcels must remain their individual amount.');
    cardControllerAssert(count($gateway->receipts) === 2 && get_invoice_total_left_to_pay(42) === '0.00', 'The existing gateway persistence contract should leave one record per parcel ID.');
    cardControllerAssert($gateway->charges === [] && $controller->viewData['invoice']->status === 2, 'Reconciliation must refresh the invoice after the receipt.');
});
cardControllerTest('receipt persistence failure gives a controlled reconciliation message', static function (): void {
    [$controller, $gateway] = cardControllerFixture();
    $controller->input->posted = cardControllerPost();
    $receipt = cardControllerPayment('pay_parcel_1');
    $gateway->chargeResult = $receipt + ['_asaas_card_receipts' => [$receipt]];
    $gateway->receiptAccepted = false;
    $controller->cartao('hash-card-controller');
    cardControllerAssert(str_contains($controller->session->flash['asaas_card_error'], 'baixa no CRM está pendente'), 'Confirmed remote payment with failed local receipt needs explicit reconciliation.');
    cardControllerAssert($gateway->receipts === [] && count($gateway->charges) === 1, 'Local failure cannot call capture again.');
});
foreach (['boleto', 'qrcode'] as $method) {
    cardControllerTest('pending card blocks direct ' . $method . ' before charge lookup', static function () use ($method): void {
        [$controller, $gateway, $db] = cardControllerFixture();
        cardControllerPendingMap($db);
        $controller->$method('hash-card-controller');
        cardControllerAssert($gateway->chargeReads === 0 && $gateway->qrcodeCalls === 0, 'Pending card cannot fetch another payment method.');
        cardControllerAssert($GLOBALS['card_controller_redirects'][0][0] === site_url('asaas/checkout/cartao/hash-card-controller'), 'Alternate payment route must lead to card confirmation.');
    });
}
cardControllerTest('payment selection receives the block flag for an in-flight card attempt', static function (): void {
    [$controller, $gateway, $db] = cardControllerFixture();
    cardControllerPendingMap($db);
    $controller->index('hash-card-controller');
    cardControllerAssert($controller->viewData['card_checkout_blocked'] === true && $controller->viewName === 'asaas/payment', 'The selection view must hide enabled methods while card confirmation is pending.');
    cardControllerAssert($controller->viewData['mensagem_info'] !== '' && $gateway->charges === [], 'The selection needs a payment-state explanation and no capture.');
});

echo sprintf('Card controller: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['card_controller_scenarios'], $GLOBALS['card_controller_assertions'], $GLOBALS['card_controller_failures']);
exit($GLOBALS['card_controller_failures'] === 0 ? 0 : 1);
