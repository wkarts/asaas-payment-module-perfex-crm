<?php

declare(strict_types=1);

/** Actual SDK/provider middleware; only the network handler is replaced. No Asaas request is made. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
class App_gateway
{
    public function getSetting(string $name): mixed { return $name === 'sandbox' ? '1' : ''; }
    public function decryptSetting(string $name): string { return 'pix-transport-fixture-only'; }
}
function get_option(string $name): mixed { return ''; }
function db_prefix(): string { return 'fixture_'; }
function &get_instance(): object { return $GLOBALS['pix_api_ci']; }
function log_message(string $level, string $message): void { $GLOBALS['pix_api_logs'][] = compact('level', 'message'); }
$GLOBALS['pix_api_logs'] = [];
$GLOBALS['pix_api_ci'] = (object) ['db' => new class {
    public bool $db_debug = true;
    public function table_exists(string $table): bool { return $table === 'fixture_asaas_logs'; }
    public function list_fields(string $table): array { return ['level', 'message', 'created_at', 'context_json']; }
    public function insert(string $table, array $row): bool { $GLOBALS['pix_api_logs'][] = $row; return true; }
}];
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasAutomaticPixApi.php';

use Asaas\AsaasAutomaticPixApi;
use Asaas\AsaasAutomaticPixRequestException;
use Asaas\Sdk\AsaasSdk;
use Asaas\Sdk\Http\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class PixTransportProvider extends AsaasSdkProvider
{
    public Client $transport;
    public int $pixCalls = 0;
    public bool $missingCapability = false;
    public function automaticPixClient(?int $companyId = null): Client { ++$this->pixCalls; return $this->transport; }
    public function cardClient(?int $companyId = null): Client { throw new RuntimeException('Pix cannot inherit the card timeout.'); }
    public function client(?int $companyId = null): Client { throw new RuntimeException('Pix cannot inherit standard retries.'); }
    public function sdk(?int $companyId = null): AsaasSdk
    {
        $sdk = parent::sdk($companyId);
        if ($this->missingCapability) { unset($sdk->automaticPix); }
        return $sdk;
    }
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
};
$gateway = new App_gateway();
$realProvider = new AsaasSdkProvider($gateway);
$prepare = static function (array $responses, array &$history) use ($gateway, $realProvider): array {
    $provider = new PixTransportProvider($gateway);
    // Preserve the actual single-attempt stack and timeout configured by the module.
    $provider->transport = $realProvider->automaticPixClient();
    $property = new ReflectionProperty(Client::class, 'client');
    $property->setAccessible(true);
    $guzzle = $property->getValue($provider->transport);
    $stack = $guzzle->getConfig('handler');
    $stack->setHandler(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return [new AsaasAutomaticPixApi($provider), $provider, $guzzle];
};

$calls = [
    ['createAuthorization', [['customerId' => 'cus_fixture', 'contractId' => 'pf-fixture', 'mode' => 'MANUAL', 'payloadSentinel' => 'private-qr-fixture']], 'POST', '/v3/pix/automatic/authorizations', []],
    ['authorization', ['auth_fixture'], 'GET', '/v3/pix/automatic/authorizations/auth_fixture', []],
    ['authorizations', [['customerId' => 'cus_fixture', 'limit' => 50, 'offset' => 100]], 'GET', '/v3/pix/automatic/authorizations', ['customerId' => 'cus_fixture', 'limit' => '50', 'offset' => '100']],
    ['cancelAuthorization', ['auth_fixture'], 'DELETE', '/v3/pix/automatic/authorizations/auth_fixture', []],
    ['createPayment', [['customer' => 'cus_fixture', 'value' => 100, 'pixAutomaticAuthorizationId' => 'auth_fixture']], 'POST', '/v3/payments', []],
    ['payment', ['pay_fixture'], 'GET', '/v3/payments/pay_fixture', []],
    ['payments', [['customer' => 'cus_fixture', 'externalReference' => 'invoice-fixture', 'dateCreated[ge]' => '2026-11-01']], 'GET', '/v3/payments', ['customer' => 'cus_fixture', 'externalReference' => 'invoice-fixture', 'dateCreated' => ['ge' => '2026-11-01']]],
    ['deletePayment', ['pay_fixture'], 'DELETE', '/v3/payments/pay_fixture', []],
    ['transaction', ['tx_fixture'], 'GET', '/v3/pix/transactions/tx_fixture', []],
    ['instructions', ['pay_fixture'], 'GET', '/v3/pix/automatic/paymentInstructions', ['paymentId' => 'pay_fixture']],
];
foreach ($calls as [$name, $arguments, $method, $path, $query]) {
    $history = [];
    [$api, $provider, $transport] = $prepare([new Response(200, [], '{"id":"fixture","payload":"private-response-fixture"}')], $history);
    $response = $api->$name(...$arguments);
    $assert($response['id'] === 'fixture' && count($history) === 1 && $provider->pixCalls === 1, $name . ': one verified transport request.');
    $request = $history[0]['request'];
    $assert($request->getMethod() === $method && $request->getUri()->getPath() === $path, $name . ': documented fixed endpoint.');
    parse_str($request->getUri()->getQuery(), $actualQuery);
    $assert($actualQuery === $query, $name . ': query filters preserved.');
    $assert($method === 'POST' ? json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR) === $arguments[0] : (string) $request->getBody() === '', $name . ': body only on explicit mutation.');
    $assert($request->hasHeader('X-Correlation-Id') && $request->getHeaderLine('access_token') === 'pix-transport-fixture-only', $name . ': authenticated correlation headers.');
    $assert((float) $transport->getConfig('timeout') === 30.0 && (float) $transport->getConfig('connect_timeout') === 10.0, $name . ': provider timeout, without card minimum.');
    $assert($transport->getConfig('allow_redirects') === false, $name . ': credentials never follow redirects.');
}

foreach (['createAuthorization', 'cancelAuthorization', 'payment'] as $method) {
    foreach ([400, 401, 403, 408, 409, 429, 500, 503] as $status) {
        $history = [];
        [$api] = $prepare([new Response($status, ['Retry-After' => '3600'], '{"errors":[{"description":"private-upstream-fixture"}]}')], $history);
        $error = null;
        try { $api->$method($method === 'createAuthorization' ? ['customerId' => 'cus_fixture'] : ($method === 'payment' ? 'pay_fixture' : 'auth_fixture')); }
        catch (AsaasAutomaticPixRequestException $caught) { $error = $caught; }
        $assert($error !== null && $error->getCode() === $status && $error->requestSent && count($history) === 1, $method . ': never retry HTTP ' . $status);
        $assert($error->rejected() === ($status < 500 && !in_array($status, [408, 409, 429], true)), $method . ': definitive rejection must differ from uncertain result.');
        $assert($error->getPrevious() === null && !str_contains($error->getMessage(), 'private-upstream-fixture'), 'Upstream body and exception chain must not escape.');
    }
}

$history = [];
[$api, $provider] = $prepare([], $history);
foreach ([['payment', 'pay_fixture?access_token=x'], ['authorization', '../authorizations'], ['instructions', 'other_payment']] as [$method, $id]) {
    try { $api->$method($id); throw new LogicException('Invalid endpoint identifier was accepted.'); }
    catch (AsaasAutomaticPixRequestException $error) { $assert(!$error->requestSent && $error->rejected() && $provider->pixCalls === 0, 'Malformed id fails before client preparation.'); }
}
$provider->missingCapability = true;
try { $api->createAuthorization(['customerId' => 'cus_fixture']); throw new LogicException('Missing SDK capability was accepted.'); }
catch (AsaasAutomaticPixRequestException $error) {
    $assert(!$error->requestSent && $error->rejected() && $provider->pixCalls === 0 && $error->getMessage() === 'sdk_unavailable', 'Incompatible SDK fails closed before any request.');
}

foreach ([
    new ConnectException('private-upstream-fixture', new Request('POST', 'https://api.example.test/')),
    new Response(200, [], '{}'),
    new Response(200, [], 'not-json'),
    new Response(200, [], '{"errors":[{"description":"private-upstream-fixture"}]}'),
    new Response(302, ['Location' => 'https://outside.example.test/'], ''),
] as $response) {
    $history = [];
    [$api] = $prepare([$response], $history);
    try { $api->createPayment(['customer' => 'cus_fixture', 'value' => 100]); throw new LogicException('Ambiguous response was accepted.'); }
    catch (AsaasAutomaticPixRequestException $error) {
        $assert($error->requestSent && !$error->rejected() && count($history) === 1 && $error->getPrevious() === null, 'Unknown result remains uncertain without a retry or redirect.');
    }
}
$logs = json_encode($GLOBALS['pix_api_logs'], JSON_THROW_ON_ERROR);
foreach (['pix-transport-fixture-only', 'private-qr-fixture', 'private-response-fixture', 'private-upstream-fixture'] as $private) {
    $assert(!str_contains($logs, $private), 'Diagnostics cannot retain request secrets, QR or upstream bodies.');
}
$assert(str_contains($logs, 'automatic_pix') && str_contains($logs, 'http_status'), 'Diagnostics retain useful source and HTTP status.');
echo 'PASS Pix Automático API: ' . $assertions . ' assertions, no external requests.' . PHP_EOL;
