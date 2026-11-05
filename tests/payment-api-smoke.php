<?php

declare(strict_types=1);

/** Actual SDK/Guzzle transport contract; all responses are local and no payment is sent. */
use Asaas\AsaasAdapter;
use Asaas\Sdk\Http\Client as SdkClient;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

define('BASEPATH', __DIR__);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasAdapter.php';

function db_prefix(): string { return 'tbl'; }
function log_message(string $level, string $message): void { $GLOBALS['payment_api_logs'][] = $message; }

final class PaymentApiGatewayFixture extends App_gateway
{
    public function getSetting(string $name): mixed { return $name === 'sandbox' ? '1' : null; }
    public function decryptSetting(string $name): string { return '$aact_payment_test_secret'; }
}

final class PaymentApiProviderFixture extends AsaasSdkProvider
{
    public function __construct(App_gateway $gateway, private SdkClient $transport) { parent::__construct($gateway); }
    public function client(?int $companyId = null): SdkClient { return $this->transport; }
}

function paymentApiFixture(array $responses, array &$history, bool $httpErrors = true, bool $sandbox = true): AsaasAdapter
{
    $history = [];
    $GLOBALS['payment_api_logs'] = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $environment = $sandbox ? Environment::Sandbox : Environment::Production;
    $transport = new GuzzleClient(['handler' => $stack, 'base_uri' => $environment->value, 'http_errors' => $httpErrors]);
    $gateway = new PaymentApiGatewayFixture();
    $client = new SdkClient('$aact_payment_test_secret', $environment, 'PaymentApiSmoke/1.0', 30, 10, null, $transport);

    return new AsaasAdapter(new PaymentApiProviderFixture($gateway, $client), $gateway);
}

function paymentApiAssert(bool $condition, string $message): void
{
    ++$GLOBALS['payment_api_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}

$GLOBALS['payment_api_assertions'] = 0;
$scenarios = 0;

foreach ([true, false] as $sandbox) {
    $history = [];
    $adapter = paymentApiFixture(array_fill(0, 8, new Response(200, [], '{"id":"pay_test","data":[]}')), $history, true, $sandbox);
    $adapter->getCustomer('cus_test');
    $adapter->createPayment(['customer' => 'cus_test', 'billingType' => 'BOLETO', 'value' => 55.5, 'externalReference' => 'invoice-hash-1']);
    $adapter->listPayments(['externalReference' => 'invoice-hash-1']);
    $adapter->updatePayment('pay_test', ['value' => 65.5]);
    $adapter->getPixInfo('pay_test');
    $adapter->request('GET', '/v3/webhooks', ['limit' => 100]);
    $adapter->request('GET', 'customers', ['cpfCnpj' => '12345678900']);
    $structured = $adapter->requestStructured('GET', '/payments');
    $paths = ['/v3/customers/cus_test', '/v3/payments', '/v3/payments', '/v3/payments/pay_test', '/v3/payments/pay_test/pixQrCode', '/v3/webhooks', '/v3/customers', '/v3/payments'];
    foreach ($history as $index => $entry) {
        paymentApiAssert($entry['request']->getUri()->getPath() === $paths[$index], 'A URL efetiva deve preservar /v3: ' . $paths[$index]);
        paymentApiAssert($entry['request']->getUri()->getHost() === ($sandbox ? 'api-sandbox.asaas.com' : 'api.asaas.com'), 'O ambiente deve permanecer inalterado.');
        paymentApiAssert($entry['request']->getHeaderLine('access_token') === '$aact_payment_test_secret', 'A chamada deve usar a API Key, nunca o token de webhook.');
    }
    paymentApiAssert(count($history) === count($paths), 'Cada operação deve realizar apenas a chamada esperada.');
    $payload = json_decode((string) $history[1]['request']->getBody(), true);
    paymentApiAssert($payload['value'] === 55.5 && $payload['externalReference'] === 'invoice-hash-1', 'Valor e referência da fatura devem permanecer inalterados.');
    paymentApiAssert($structured['success'] && $structured['meta']['path'] === '/payments', 'O envelope público deve manter o caminho fornecido pelo consumidor.');
    paymentApiAssert(!str_contains(implode('\n', $GLOBALS['payment_api_logs']), '12345678900'), 'O CPF consultado não deve aparecer nos logs.');
    paymentApiAssert(!str_contains(implode('\n', $GLOBALS['payment_api_logs']), '$aact_payment_test_secret'), 'A API Key não deve aparecer nos logs.');
    ++$scenarios;
}

foreach ([true, false] as $httpErrors) {
    foreach ([400, 401, 404, 429, 500] as $status) {
        $description = 'Descrição de erro controlada ' . $status;
        $body = json_encode(['errors' => [['code' => 'test_error', 'description' => $description]]]);
        $history = [];
        $adapter = paymentApiFixture([new Response($status, [], $body)], $history, $httpErrors);
        try {
            $adapter->createPayment(['customer' => 'cus_test']);
            throw new LogicException('Era esperada uma falha HTTP.');
        } catch (RuntimeException $exception) {
            paymentApiAssert($exception->getCode() === $status, 'O HTTP real deve sobreviver ao envelope da SDK.');
            paymentApiAssert(str_contains($exception->getMessage(), $description), 'A descrição do Asaas deve permanecer disponível.');
        }
        paymentApiAssert(count($history) === 1, 'Uma resposta de erro não pode causar reenvio pelo adapter.');
        $logs = implode('\n', $GLOBALS['payment_api_logs']);
        paymentApiAssert(str_contains($logs, (string) $status) && str_contains($logs, $description), 'O diagnóstico deve conter status e descrição do erro.');
        ++$scenarios;
    }
}

$history = [];
$adapter = paymentApiFixture([new Response(401, [], '')], $history);
$response = $adapter->requestStructured('GET', '/payments');
paymentApiAssert(!$response['success'] && $response['error']['type'] === 'api_error' && $response['error']['status'] === 401, 'O envelope deve diferenciar rejeição HTTP de falha de rede.');
paymentApiAssert(str_contains($response['error']['message'], 'API Key'), 'HTTP 401 sem corpo deve orientar a autenticação.');
++ $scenarios;

$history = [];
$adapter = paymentApiFixture([new ConnectException('Connection refused', new Request('GET', 'https://api-sandbox.asaas.com/v3/payments'))], $history);
$response = $adapter->requestStructured('GET', '/payments');
paymentApiAssert(!$response['success'] && $response['error']['type'] === 'transport_error' && $response['error']['status'] === null, 'Falha de conexão deve permanecer distinta de HTTP.');
paymentApiAssert(str_contains($response['error']['message'], 'DNS'), 'Falha de conexão deve orientar o diagnóstico do servidor.');
++ $scenarios;

$ci = &get_instance();
$previousDb = $ci->db;
$ci->db = new class { public bool $db_debug = true; public function table_exists(string $table): bool { throw new RuntimeException('DB indisponível'); } };
$history = [];
$adapter = paymentApiFixture([new Response(200, [], '{"id":"pay_accepted"}')], $history);
paymentApiAssert($adapter->createPayment(['customer' => 'cus_test'])['id'] === 'pay_accepted', 'Falha no log não pode descartar uma cobrança aceita.');
paymentApiAssert(count($history) === 1, 'Falha de diagnóstico não pode repetir um pagamento.');
paymentApiAssert($ci->db->db_debug === true, 'O logger deve restaurar a configuração do banco.');
$ci->db = $previousDb;
++ $scenarios;

$gateway = new PaymentApiGatewayFixture();
$provider = new class($gateway) extends AsaasSdkProvider {
    public function client(?int $companyId = null): SdkClient { throw new RuntimeException('SDK indisponível para teste'); }
};
$GLOBALS['payment_api_logs'] = [];
$adapter = new AsaasAdapter($provider, $gateway);
$response = $adapter->requestStructured('GET', '/payments');
paymentApiAssert(!$response['success'] && $response['error']['type'] === 'integration_error', 'Falha anterior ao HTTP deve retornar diagnóstico estruturado.');
paymentApiAssert(str_contains(implode('\n', $GLOBALS['payment_api_logs']), 'SDK indisponível'), 'Falha de bootstrap deve produzir log.');
++ $scenarios;

echo 'Payment API smoke: ' . $scenarios . ' cenários, ' . $GLOBALS['payment_api_assertions'] . " verificações aprovadas; nenhuma chamada externa.\n";
