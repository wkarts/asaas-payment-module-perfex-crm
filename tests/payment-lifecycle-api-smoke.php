<?php

declare(strict_types=1);

/**
 * Real gateway + adapter + SDK/Guzzle, with an exclusively local MockHandler.
 * Optional: ASAAS_TEST_SDK_SOURCE=/absolute/sdk/src php tests/payment-lifecycle-api-smoke.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Asaas\AsaasAdapter;
use Asaas\Sdk\Http\Client as SdkClient;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$temporaryRoot = sys_get_temp_dir() . '/asaas-lifecycle-api-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/modules', 0700, true);
symlink(dirname(__DIR__), $temporaryRoot . '/modules/asaas');
register_shutdown_function(static function () use ($temporaryRoot): void {
    unlink($temporaryRoot . '/modules/asaas');
    rmdir($temporaryRoot . '/modules');
    rmdir($temporaryRoot);
});
define('BASEPATH', __DIR__);
define('FCPATH', $temporaryRoot . '/');
const LIFECYCLE_API_KEY = '$aact_lifecycle_api_public_fixture';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class App_gateway
{
    public array $definedSettings = [];
    public array $nativePayments = [];
    public bool $nativePaymentResult = true;
    public ?Throwable $nativePaymentError = null;
    public array $paymentDepths = [];
    public function __construct() {}
    public function setId(string $id): void {}
    public function setName(string $name): void {}
    public function setSettings(array $settings): void { $this->definedSettings = array_column($settings, null, 'name'); }
    public function getSetting(string $name): mixed { return $name === 'sandbox' ? '1' : ''; }
    public function decryptSetting(string $name): string { return LIFECYCLE_API_KEY; }
    public function addPayment($data)
    {
        $this->paymentDepths[] = asaas_lifecycle_state()['remote_payment_depth'] ?? 0;
        if ($this->nativePaymentError !== null) { throw $this->nativePaymentError; }
        if ($this->nativePaymentResult) { $this->nativePayments[] = $data; }
        return $this->nativePaymentResult;
    }
}

final class LifecycleApiResult
{
    public function __construct(private array $row = []) {}
    public function row_array(): array { return $this->row; }
}

final class LifecycleApiDb
{
    public bool $db_debug = true;
    public array $maps = [];
    public function table_exists(string $table): bool { return $table === 'tblasaas_payments_map'; }
    public function get_where(string $table, array $where): LifecycleApiResult { return new LifecycleApiResult($this->maps[$where['invoice_id']] ?? []); }
    public function where(string $field, mixed $value): self { return $this; }
    public function insert(string $table, array $payload): bool { $this->maps[$payload['invoice_id']] = $payload; return true; }
    public function update(string $table, array $payload): bool { return $this->insert($table, $payload); }
}

function &get_instance(): object { return $GLOBALS['lifecycle_api_ci']; }
function get_option(string $name): mixed { return ''; }
function db_prefix(): string { return 'tbl'; }
function html_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function site_url(string $path): string { return 'https://crm.example.test/' . $path; }
function admin_url(string $path): string { return site_url('admin/' . $path); }
function log_message(string $level, string $message): void { $GLOBALS['lifecycle_api_logs'][] = $message; }

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$sdkSource = getenv('ASAAS_TEST_SDK_SOURCE');
if (is_string($sdkSource) && $sdkSource !== '') {
    if (!is_file($sdkSource . '/Http/Client.php')) { throw new RuntimeException('Invalid ASAAS_TEST_SDK_SOURCE.'); }
    $loader->setPsr4('Asaas\\Sdk\\', rtrim($sdkSource, '/\\') . DIRECTORY_SEPARATOR);
}
require_once dirname(__DIR__) . '/libraries/Asaas_gateway.php';

final class LifecycleApiProvider extends AsaasSdkProvider
{
    public function __construct(App_gateway $gateway, private SdkClient $transport) { parent::__construct($gateway); }
    public function client(?int $companyId = null): SdkClient { return $this->transport; }
}

function lifecycleApiFixture(array $responses, array &$history): array
{
    $history = [];
    $GLOBALS['lifecycle_api_ci'] = (object) ['db' => new LifecycleApiDb()];
    $GLOBALS['lifecycle_api_logs'] = [];
    $state = &asaas_lifecycle_state();
    $state = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $http = new GuzzleClient(['handler' => $stack, 'base_uri' => Environment::Sandbox->value]);
    $client = new SdkClient(LIFECYCLE_API_KEY, Environment::Sandbox, 'LifecycleApiSmoke/1.0', 30, 10, null, $http);
    $gateway = new Asaas_gateway();
    $provider = new LifecycleApiProvider($gateway, $client);
    $adapter = new AsaasAdapter($provider, $gateway);
    foreach (['provider' => $provider, 'adapter' => $adapter] as $property => $value) {
        $reflection = new ReflectionProperty(Asaas_gateway::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($gateway, $value);
    }
    return [$gateway, $adapter, get_instance()->db];
}

function lifecycleApiResponse(array $body): Response { return new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)); }
function lifecycleApiAssert(bool $condition, string $message): void
{
    ++$GLOBALS['lifecycle_api_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function lifecycleApiFails(callable $operation, ?int $code = null): void
{
    try { $operation(); }
    catch (RuntimeException $error) {
        lifecycleApiAssert($code === null || $error->getCode() === $code, 'Failure must preserve its expected status.');
        return;
    }
    throw new LogicException('Expected a rejected operation.');
}

$GLOBALS['lifecycle_api_assertions'] = 0;
$scenarios = 0;
$history = [];
[$gateway, $adapter] = lifecycleApiFixture([
    lifecycleApiResponse(['id' => 'pay_test', 'status' => 'PENDING']),
    lifecycleApiResponse(['id' => 'pay_test', 'status' => 'PENDING']),
    lifecycleApiResponse(['id' => 'pay_test', 'deleted' => true]),
    lifecycleApiResponse(['id' => 'pay_test', 'deleted' => false, 'status' => 'PENDING']),
    lifecycleApiResponse(['id' => 'pay_test', 'status' => 'RECEIVED_IN_CASH']),
    lifecycleApiResponse(['id' => 'pay_test', 'status' => 'PENDING']),
    lifecycleApiResponse(['id' => 'pay_test', 'status' => 'RECEIVED', 'billingType' => 'RECEIVED_IN_CASH']),
    lifecycleApiResponse(['id' => 'pay_test', 'deleted' => true]),
], $history);
$payload = ['customer' => 'cus_test', 'billingType' => 'PIX', 'value' => '5.75', 'externalReference' => 'invoice-hash', 'discount' => ['type' => 'FIXED', 'value' => '.75']];
$created = $gateway->create_charge('legacy-url', 'legacy-key', json_encode($payload));
lifecycleApiAssert(is_string($created) && json_decode($created, true)['id'] === 'pay_test', 'create_charge must preserve JSON return contract.');
$updated = $gateway->update_charge('pay_test', json_encode($payload));
lifecycleApiAssert(is_array($updated) && $updated['id'] === 'pay_test', 'update_charge must preserve array return contract.');
$gateway->delete_charge('pay_test');
$gateway->restore_charge('pay_test');
$gateway->receive_charge_in_cash('pay_test', '2026-11-05', '5.75');
$gateway->undo_receive_in_cash('pay_test');
$gateway->receive_charge_in_cash('pay_test', '2026-11-05', '0.50', true);
$adapter->cancelPayment('pay_test');
$expected = [
    ['POST', '/v3/payments'], ['POST', '/v3/payments/pay_test'],
    ['DELETE', '/v3/payments/pay_test'], ['POST', '/v3/payments/pay_test/restore'],
    ['POST', '/v3/payments/pay_test/receiveInCash'], ['POST', '/v3/payments/pay_test/undoReceivedInCash'],
    ['POST', '/v3/payments/pay_test/receiveInCash'], ['DELETE', '/v3/payments/pay_test'],
];
foreach ($expected as $index => [$method, $path]) {
    $request = $history[$index]['request'];
    lifecycleApiAssert($request->getMethod() === $method && $request->getUri()->getPath() === $path, 'Incorrect HTTP operation: ' . $method . ' ' . $path);
    lifecycleApiAssert($request->getHeaderLine('access_token') === LIFECYCLE_API_KEY, 'SDK must keep API authentication separate from webhook token.');
    lifecycleApiAssert($request->getUri()->getHost() === 'api-sandbox.asaas.com', 'Environment must not change.');
}
lifecycleApiAssert(count($history) === count($expected), 'Exactly one request per operation; no hidden retries.');
lifecycleApiAssert(json_decode((string) $history[0]['request']->getBody(), true) === $payload, 'Validation cannot change the create payload.');
lifecycleApiAssert(json_decode((string) $history[1]['request']->getBody(), true) === $payload, 'Validation cannot change the POST update payload.');
lifecycleApiAssert(json_decode((string) $history[4]['request']->getBody(), true) === ['paymentDate' => '2026-11-05', 'value' => '5.75', 'notifyCustomer' => false], 'Cash receipt must preserve date, amount and default notification.');
lifecycleApiAssert(json_decode((string) $history[6]['request']->getBody(), true)['notifyCustomer'] === true, 'Explicit cash notification must be preserved.');
foreach (['sync_invoice_lifecycle', 'manual_payment_sync', 'manual_payment_receive_in_cash'] as $name) {
    lifecycleApiAssert(($gateway->definedSettings[$name]['default_value'] ?? null) === 1 && $gateway->definedSettings[$name]['type'] === 'yes_no', 'New lifecycle settings must be enabled by default.');
}
lifecycleApiAssert(($gateway->definedSettings['delete_charge']['default_value'] ?? null) === 0, 'Existing legacy option default must remain unchanged.');
++ $scenarios;

foreach (['create', 'update'] as $operation) {
    foreach ([['value' => '4.99'], ['value' => 5, 'discount' => ['type' => 'FIXED', 'value' => '.01']], ['billingType' => 'PIX', 'installmentCount' => 2, 'installmentValue' => '9.99']] as $invalid) {
        [$gateway] = lifecycleApiFixture([], $history);
        lifecycleApiFails(static fn () => $operation === 'create'
            ? $gateway->create_charge('', '', json_encode($invalid))
            : $gateway->update_charge('pay_test', json_encode($invalid)), 400);
        lifecycleApiAssert($history === [], 'An invalid amount must be rejected before HTTP.');
        ++$scenarios;
    }
    [$gateway] = lifecycleApiFixture([lifecycleApiResponse(['id' => 'pay_test'])], $history);
    $partial = ['dueDate' => '2026-12-01'];
    if ($operation === 'create') { $gateway->create_charge('', '', json_encode($partial)); }
    else { $gateway->update_charge('pay_test', json_encode($partial)); }
    lifecycleApiAssert(count($history) === 1 && json_decode((string) $history[0]['request']->getBody(), true) === $partial, 'Payload without amount must reach the API unchanged.');
    ++$scenarios;
}

$badReplies = [
    'delete missing id' => ['delete_charge', ['deleted' => true]],
    'delete other id' => ['delete_charge', ['id' => 'pay_other', 'deleted' => true]],
    'delete false' => ['delete_charge', ['id' => 'pay_test', 'deleted' => false]],
    'delete string true' => ['delete_charge', ['id' => 'pay_test', 'deleted' => 'true']],
    'delete no flag' => ['delete_charge', ['id' => 'pay_test']],
    'restore deleted true' => ['restore_charge', ['id' => 'pay_test', 'status' => 'PENDING', 'deleted' => true]],
    'restore paid' => ['restore_charge', ['id' => 'pay_test', 'status' => 'RECEIVED', 'deleted' => false]],
    'restore no flag' => ['restore_charge', ['id' => 'pay_test', 'status' => 'PENDING']],
    'undo paid' => ['undo_receive_in_cash', ['id' => 'pay_test', 'status' => 'RECEIVED_IN_CASH']],
    'undo other id' => ['undo_receive_in_cash', ['id' => 'pay_other', 'status' => 'PENDING']],
    'cash pending' => ['receive_charge_in_cash', ['id' => 'pay_test', 'status' => 'PENDING']],
    'cash received without cash signal' => ['receive_charge_in_cash', ['id' => 'pay_test', 'status' => 'RECEIVED', 'billingType' => 'PIX']],
    'cash wrong id' => ['receive_charge_in_cash', ['id' => 'pay_other', 'status' => 'RECEIVED_IN_CASH']],
];
foreach ($badReplies as $label => [$method, $body]) {
    [$gateway] = lifecycleApiFixture([lifecycleApiResponse($body)], $history);
    lifecycleApiFails(static fn () => $method === 'receive_charge_in_cash'
        ? $gateway->{$method}('pay_test', '2026-11-05', 10)
        : $gateway->{$method}('pay_test'));
    lifecycleApiAssert(count($history) === 1, 'Ambiguous response must not retry remote operation: ' . $label);
    ++$scenarios;
}

foreach (['create', 'update'] as $operation) {
    foreach ([[], ['id' => ''], ['id' => 123], ['id' => 'pay_test', 'errors' => []], ['id' => 'pay_test', 'error' => 'refused']] as $badBody) {
        [$gateway] = lifecycleApiFixture([lifecycleApiResponse($badBody)], $history);
        lifecycleApiFails(static fn () => $operation === 'create'
            ? $gateway->create_charge('', '', '{"value":5}')
            : $gateway->update_charge('pay_test', '{"value":5}'));
        lifecycleApiAssert(count($history) === 1, 'An invalid 200 response must fail without creating a second payment.');
        ++$scenarios;
    }
}
[$gateway] = lifecycleApiFixture([lifecycleApiResponse(['id' => 'pay_other'])], $history);
lifecycleApiFails(static fn () => $gateway->update_charge('pay_test', '{"value":5}'));
lifecycleApiAssert(count($history) === 1, 'Update must reject a different returned payment id.');
++ $scenarios;

foreach (['', 'pay/other', ' pay_test', 'pay_test?x=1'] as $id) {
    [$gateway] = lifecycleApiFixture([], $history);
    lifecycleApiFails(static fn () => $gateway->delete_charge($id), 400);
    lifecycleApiFails(static fn () => $gateway->restore_charge($id), 400);
    lifecycleApiFails(static fn () => $gateway->receive_charge_in_cash($id, '2026-11-05', 10), 400);
    lifecycleApiFails(static fn () => $gateway->undo_receive_in_cash($id), 400);
    lifecycleApiFails(static fn () => $gateway->update_charge($id, '{"value":5}'), 400);
    lifecycleApiAssert($history === [], 'Malformed identifier must be rejected before HTTP.');
    ++$scenarios;
}
foreach ([['2026-02-30', 10], ['2026-2-01', 10], ['2026-11-05', 0], ['2026-11-05', -10], ['2026-11-05', 'NaN']] as [$date, $value]) {
    [$gateway] = lifecycleApiFixture([], $history);
    lifecycleApiFails(static fn () => $gateway->receive_charge_in_cash('pay_test', $date, $value), 400);
    lifecycleApiAssert($history === [], 'Cash receipt needs valid date and positive amount before HTTP.');
    ++$scenarios;
}

foreach (['true', 'false', 'throw'] as $outcome) {
    [$gateway] = lifecycleApiFixture([], $history);
    $gateway->nativePaymentResult = $outcome !== 'false';
    $gateway->nativePaymentError = $outcome === 'throw' ? new RuntimeException('Native addPayment failed.') : null;
    $data = ['invoiceid' => 42, 'amount' => 10, 'transactionid' => 'pay_test'];
    $record = static fn () => asaas_lifecycle_remote_payment(static fn () => $gateway->addPayment($data));
    if ($outcome === 'throw') { lifecycleApiFails($record); }
    else { lifecycleApiAssert($record() === ($outcome === 'true'), 'Gateway addPayment must preserve native return.'); }
    lifecycleApiAssert($gateway->paymentDepths === [2], 'An already guarded Asaas payment must preserve the nested origin guard before parent callback.');
    lifecycleApiAssert((asaas_lifecycle_state()['remote_payment_depth'] ?? null) === 0, 'Remote origin must clear on success, false and exception.');
    lifecycleApiAssert($gateway->nativePayments === ($outcome === 'true' ? [$data] : []), 'Gateway must not alter native payment fields.');
    lifecycleApiAssert($history === [], 'Native payment origin guard must not call Asaas.');
    ++$scenarios;
}

foreach (['same', 'new', 'explicit'] as $case) {
    [$gateway, , $db] = lifecycleApiFixture([], $history);
    $metadata = ['state' => 'manual_received', 'retired_payment_ids' => ['pay_old']];
    $db->maps[42] = ['invoice_id' => 42, 'asaas_payment_id' => 'pay_test', 'payload_cache' => json_encode(['id' => 'pay_test', '_asaas_lifecycle' => $metadata])];
    $charge = ['id' => $case === 'new' ? 'pay_new' : 'pay_test', 'status' => 'PENDING'];
    if ($case === 'explicit') { $charge['_asaas_lifecycle'] = ['state' => 'active']; }
    lifecycleApiAssert($gateway->upsert_payment_map(42, $charge), 'Payment map update must succeed.');
    $cached = json_decode($db->maps[42]['payload_cache'], true);
    lifecycleApiAssert(($cached['_asaas_lifecycle'] ?? null) === match ($case) { 'same' => $metadata, 'explicit' => ['state' => 'active'], default => null }, 'Metadata must survive only a refresh of the same id, unless explicitly provided.');
    lifecycleApiAssert($history === [], 'Updating local mapping must not call the API.');
    ++$scenarios;
}

printf("Payment lifecycle API smoke: %d scenarios, %d assertions passed; no external calls.\n", $scenarios, $GLOBALS['lifecycle_api_assertions']);
