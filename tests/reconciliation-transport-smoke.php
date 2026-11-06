<?php

declare(strict_types=1);

/** Real SDK/Guzzle stacks with offline handlers; no credential or external request is used. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
class App_gateway
{
    public function getSetting(string $name): mixed { return ['sandbox' => '1', 'timeout' => '12', 'connect_timeout' => '4'][$name] ?? ''; }
    public function decryptSetting(string $name): string { return 'transport-fixture-only'; }
}
function get_option(string $name): mixed { return ''; }
function db_prefix(): string { return 'fixture_'; }
function &get_instance(): object { return $GLOBALS['transport_fixture_ci']; }
function log_message(string $level, string $message): void
{
    $GLOBALS['transport_fixture_logs'][] = compact('level', 'message');
}
$GLOBALS['transport_fixture_ci'] = (object) ['db' => new class {
    public bool $db_debug = true;
    public function table_exists(string $table): bool { return $table === 'fixture_asaas_logs'; }
    public function list_fields(string $table): array { return ['level', 'message', 'created_at', 'context_json']; }
    public function insert(string $table, array $row): bool { return true; }
}];
$GLOBALS['transport_fixture_logs'] = [];
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasAdapter.php';

use Asaas\Sdk\Http\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
};
$attach = static function (Client $client, array $responses, array &$history): GuzzleHttp\Client {
    $property = new ReflectionProperty(Client::class, 'client');
    $property->setAccessible(true);
    $transport = $property->getValue($client);
    $stack = $transport->getConfig('handler');
    // Preserve the actual middleware assembled by the provider/SDK; replace only network I/O.
    $stack->setHandler(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return $transport;
};
$gateway = new App_gateway();
$provider = new AsaasSdkProvider($gateway);
foreach ([401, 403, 429, 500, 503] as $status) {
    $history = [];
    $client = $provider->reconciliationClient();
    $transport = $attach($client, [new Response($status, ['RateLimit-Reset' => '1800'], '{"errors":[{"description":"fixture unavailable"}]}')], $history);
    $error = null;
    try { $client->request('GET', '/v3/payments', ['limit' => 25, 'paymentDate[ge]' => '2026-11-04']); }
    catch (Throwable $caught) { $error = $caught; }
    $assert($error !== null && $error->getCode() === $status, 'A falha HTTP deve permanecer identificável: ' . $status);
    $assert(count($history) === 1, 'A consulta não pode repetir imediatamente HTTP ' . $status);
    $assert($history[0]['request']->getMethod() === 'GET' && (string) $history[0]['request']->getBody() === '', 'Recuperação deve enviar GET sem body.');
    $assert($transport->getConfig('allow_redirects') === false, 'A consulta não deve encaminhar credenciais em redirecionamento.');
}

$history = [];
$standard = $provider->client();
$attach($standard, [new Response(503, [], '{}'), new Response(200, [], '{"data":[],"hasMore":false,"totalCount":0}')], $history);
$response = $standard->request('GET', '/v3/payments');
$assert(count($history) === 2 && $response['hasMore'] === false, 'O cliente legado deve preservar seu comportamento anterior.');

final class RoutingRecoveryProvider extends AsaasSdkProvider
{
    public int $standardCalls = 0;
    public int $recoveryCalls = 0;
    public Client $standardTransport;
    public Client $recoveryTransport;
    public function client(?int $companyId = null): Client { ++$this->standardCalls; return $this->standardTransport; }
    public function reconciliationClient(?int $companyId = null): Client { ++$this->recoveryCalls; return $this->recoveryTransport; }
}
$routing = new RoutingRecoveryProvider($gateway);
$routing->standardTransport = $provider->reconciliationClient();
$routing->recoveryTransport = $provider->reconciliationClient();
$standardHistory = [];
$recoveryHistory = [];
$attach($routing->standardTransport, [new Response(200, [], '{"id":"pay_standard"}')], $standardHistory);
$attach($routing->recoveryTransport, [new Response(200, [], '{"data":[],"hasMore":false,"totalCount":0}')], $recoveryHistory);
$adapter = new Asaas\AsaasAdapter($routing);
$adapter->request('GET', '/payments', ['externalReference' => 'invoice-fixture', 'limit' => 25], [], null, false, null, true);
$assert($routing->recoveryCalls === 1 && $routing->standardCalls === 0, 'O caminho de recuperação deve selecionar somente seu transporte.');
$assert($recoveryHistory[0]['request']->getUri()->getPath() === '/v3/payments', 'A normalização da rota SDK deve ser preservada.');
parse_str($recoveryHistory[0]['request']->getUri()->getQuery(), $query);
$assert($query['externalReference'] === 'invoice-fixture' && $query['limit'] === '25', 'Os filtros devem chegar ao SDK sem mudança de contrato.');
$assert($recoveryHistory[0]['request']->hasHeader('X-Correlation-Id'), 'A consulta deve preservar correlação de diagnóstico.');
$refused = false;
try { $adapter->request('POST', '/payments', [], [], ['value' => 100], false, null, true); }
catch (InvalidArgumentException) { $refused = true; }
$assert($refused && $routing->recoveryCalls === 1, 'O modo de recuperação deve rejeitar mutação antes de preparar um cliente.');
$normal = $adapter->request('GET', '/payments/pay_standard');
$assert($normal['id'] === 'pay_standard' && $routing->standardCalls === 1 && count($standardHistory) === 1, 'Os chamadores existentes devem continuar usando o transporte padrão.');
$assert(!str_contains(json_encode($GLOBALS['transport_fixture_logs'], JSON_THROW_ON_ERROR), 'transport-fixture-only'), 'Logs não podem expor a chave do transporte.');
echo "Transporte de conciliação: {$checks} verificações, 0 falhas; nenhuma chamada externa.\n";
