<?php

declare(strict_types=1);

/**
 * Webhook API regression checks using the shipped SDK and Guzzle MockHandler.
 * Run: php tests/webhook-api-smoke.php
 * No request leaves this process; no Perfex installation or PHPUnit is required.
 */

use Asaas\AsaasAdapter;
use Asaas\Sdk\Http\Client as SdkClient;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

define('BASEPATH', __DIR__);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasAdapter.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function db_prefix(): string
{
    return 'tbl';
}

function log_message(string $level, string $message): void
{
    $GLOBALS['webhook_api_logs'][] = $message;
}

final class WebhookApiGatewayFixture extends App_gateway
{
    public function __construct(private string $token, private string $apiKey, private bool $sandbox)
    {
    }

    public function getSetting(string $name): mixed
    {
        return match ($name) {
            'webhook_secret' => $this->token,
            'sandbox' => $this->sandbox ? '1' : '0',
            default => null,
        };
    }

    public function decryptSetting(string $name): string
    {
        return in_array($name, ['api_key', 'api_key_sandbox'], true) ? $this->apiKey : '';
    }
}

final class WebhookApiProviderFixture extends AsaasSdkProvider
{
    public function __construct(App_gateway $gateway, private SdkClient $mockClient)
    {
        parent::__construct($gateway);
    }

    public function client(?int $companyId = null): SdkClient
    {
        return $this->mockClient;
    }
}

const WEBHOOK_API_TEST_TOKEN = '3c961ca49d8b08d3e2ef748eb96056b7d134e4a26f0e12c7085db6a9107f2e4c';
const WEBHOOK_API_TEST_KEY = '$aact_test_fixture_api_78654b990e37b8ed39c65acb2f73148b';
const WEBHOOK_API_TEST_URL = 'https://crm.example.test/asaas/gateways/callback/index';

/** Build the actual SDK HTTP client with a mock-only transport and request history. */
function webhookApiFixture(array $responses, array &$history, string $token = WEBHOOK_API_TEST_TOKEN, bool $sandbox = false, string $apiKey = WEBHOOK_API_TEST_KEY): AsaasAdapter
{
    $history = [];
    $GLOBALS['webhook_api_logs'] = [];
    $mockResponses = array_map(static fn (array $body): Response => new Response(
        200,
        ['Content-Type' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR)
    ), $responses);

    $handler = HandlerStack::create(new MockHandler($mockResponses));
    $handler->push(Middleware::history($history));
    $environment = $sandbox ? Environment::Sandbox : Environment::Production;
    $transport = new GuzzleClient([
        'base_uri' => $environment->value,
        'handler' => $handler,
        'http_errors' => false,
    ]);
    $client = new SdkClient($apiKey, $environment, 'WebhookApiSmoke/1.0', 30, 10, null, $transport);
    $gateway = new WebhookApiGatewayFixture($token, $apiKey, $sandbox);

    return new AsaasAdapter(new WebhookApiProviderFixture($gateway, $client), $gateway);
}

function webhookApiCheck(bool $condition, string $message): void
{
    ++$GLOBALS['webhook_api_assertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webhookApiCheckNoSecretInLogs(): void
{
    $logs = implode("\n", $GLOBALS['webhook_api_logs']);
    webhookApiCheck(!str_contains($logs, WEBHOOK_API_TEST_TOKEN), 'O log não deve conter o token do webhook.');
    webhookApiCheck(!str_contains($logs, WEBHOOK_API_TEST_KEY), 'O log não deve conter a API Key.');
}

$GLOBALS['webhook_api_assertions'] = 0;
$tests = [];

foreach ([false, true] as $sandbox) {
    $environmentName = $sandbox ? 'sandbox' : 'produção';
    $tests['criação autenticada em ' . $environmentName] = static function () use ($sandbox): void {
        $history = [];
        $adapter = webhookApiFixture([[
            'id' => 'wh_created',
            'url' => WEBHOOK_API_TEST_URL,
            'authToken' => WEBHOOK_API_TEST_TOKEN,
        ]], $history, WEBHOOK_API_TEST_TOKEN, $sandbox);
        $response = $adapter->createWebhook([
            'url' => WEBHOOK_API_TEST_URL,
            'email' => 'admin@example.test',
            'authToken' => 'caller-value-must-not-replace-the-saved-token',
        ]);

        webhookApiCheck(count($history) === 1, 'A criação deve executar exatamente uma requisição.');
        $request = $history[0]['request'];
        $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        webhookApiCheck($request->getMethod() === 'POST', 'A criação deve usar POST.');
        webhookApiCheck($request->getUri()->getPath() === '/v3/webhooks', 'A URL deve manter /v3 e o recurso plural webhooks.');
        webhookApiCheck($request->getUri()->getHost() === ($sandbox ? 'api-sandbox.asaas.com' : 'api.asaas.com'), 'O ambiente selecionado deve ser preservado.');
        webhookApiCheck($request->getHeaderLine('access_token') === WEBHOOK_API_TEST_KEY, 'A autenticação da API deve usar access_token e a API Key.');
        webhookApiCheck(!$request->hasHeader('asaas-access-token'), 'O token de recebimento não deve substituir a autenticação da API.');
        webhookApiCheck($payload['authToken'] === WEBHOOK_API_TEST_TOKEN, 'O cadastro remoto deve usar exatamente o token salvo no recebedor.');
        webhookApiCheck($payload['url'] === WEBHOOK_API_TEST_URL && $payload['email'] === 'admin@example.test', 'A URL e o e-mail fornecidos devem ser mantidos.');
        webhookApiCheck($payload['events'] === ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], 'O cadastro padrão deve assinar eventos de pagamento suportados.');
        webhookApiCheck($payload['sendType'] === 'SEQUENTIALLY', 'O modo padrão deve preservar a ordem dos eventos.');
        webhookApiCheck($payload['enabled'] === true && $payload['interrupted'] === false && $payload['apiVersion'] === 3, 'O cadastro padrão deve habilitar a entrega v3.');
        webhookApiCheck($response === ['id' => 'wh_created', 'url' => WEBHOOK_API_TEST_URL], 'O resultado deve preservar os dados públicos sem expor authToken.');
        webhookApiCheckNoSecretInLogs();
    };
}

$tests['opções explícitas do cadastro são preservadas'] = static function (): void {
    $history = [];
    $adapter = webhookApiFixture([['id' => 'wh_custom']], $history);
    $explicit = [
        'url' => WEBHOOK_API_TEST_URL,
        'email' => 'alerts@example.test',
        'name' => 'Integração existente',
        'events' => ['PAYMENT_RECEIVED'],
        'sendType' => 'NON_SEQUENTIALLY',
        'enabled' => false,
        'interrupted' => true,
        'apiVersion' => 3,
    ];
    $adapter->createWebhook($explicit);
    webhookApiCheck(count($history) === 1, 'As opções explícitas não devem causar requisições adicionais.');
    $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    unset($payload['authToken']);
    webhookApiCheck($payload === $explicit, 'O adaptador deve preservar todos os campos explicitamente fornecidos.');
};

$invalidTokens = [
    'ausente' => '',
    'menor que 32 caracteres' => substr(WEBHOOK_API_TEST_TOKEN, 0, 31),
    'maior que 255 caracteres' => str_repeat(WEBHOOK_API_TEST_TOKEN, 5),
    'com espaço' => substr(WEBHOOK_API_TEST_TOKEN, 0, 32) . ' restante',
    'com quebra de linha' => WEBHOOK_API_TEST_TOKEN . "\n",
    'com caractere de controle' => WEBHOOK_API_TEST_TOKEN . "\0",
    'igual à API Key' => WEBHOOK_API_TEST_KEY,
    'com prefixo de API Key' => '$aact_' . WEBHOOK_API_TEST_TOKEN,
];
foreach ($invalidTokens as $label => $invalidToken) {
    $tests['cadastro bloqueado para token ' . $label] = static function () use ($invalidToken): void {
        $history = [];
        $adapter = webhookApiFixture([['id' => 'must_not_be_created']], $history, $invalidToken);
        $exception = null;
        try {
            $adapter->createWebhook([
                'url' => WEBHOOK_API_TEST_URL,
                'email' => 'admin@example.test',
                'authToken' => WEBHOOK_API_TEST_TOKEN,
            ]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        webhookApiCheck($exception !== null, 'O provisionamento deve rejeitar o token local inválido mesmo que o payload contenha outro token.');
        webhookApiCheck(count($history) === 0, 'A rejeição do token deve acontecer antes de qualquer acesso HTTP.');
    };
}

$tests['token igual à API Key legada sem prefixo é rejeitado'] = static function (): void {
    $history = [];
    $legacyKey = 'legacy-api-key-78654b990e37b8ed39c65acb2f73148b';
    $adapter = webhookApiFixture([['id' => 'must_not_be_created']], $history, $legacyKey, false, $legacyKey);
    $exception = null;
    try {
        $adapter->createWebhook(['url' => WEBHOOK_API_TEST_URL, 'email' => 'admin@example.test']);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }
    webhookApiCheck($exception !== null, 'O segredo não deve reutilizar a API Key mesmo quando ela não tem o prefixo atual.');
    webhookApiCheck(count($history) === 0, 'A API Key legada deve ser rejeitada antes de qualquer acesso HTTP.');
};

$tests['busca seleciona somente a URL desta instalação'] = static function (): void {
    $history = [];
    $own = ['id' => 'wh_own', 'url' => WEBHOOK_API_TEST_URL, 'enabled' => false, 'authToken' => WEBHOOK_API_TEST_TOKEN];
    $adapter = webhookApiFixture([['hasMore' => false, 'data' => [
        ['id' => 'wh_other_host', 'url' => 'https://other.example.test/asaas/gateways/callback/index'],
        ['id' => 'wh_other_path', 'url' => WEBHOOK_API_TEST_URL . '/external'],
        ['id' => 'wh_query', 'url' => WEBHOOK_API_TEST_URL . '?unrelated=true'],
        $own,
    ]]], $history);
    $result = $adapter->findWebhook(WEBHOOK_API_TEST_URL);
    unset($own['authToken']);
    webhookApiCheck($result === $own, 'A seleção deve corresponder à URL completa e remover somente authToken do retorno.');
    webhookApiCheck(count($history) === 1, 'Uma correspondência na primeira página deve encerrar a busca.');
    $request = $history[0]['request'];
    webhookApiCheck($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/v3/webhooks', 'A busca deve consultar GET /v3/webhooks.');
    webhookApiCheck($request->getHeaderLine('access_token') === WEBHOOK_API_TEST_KEY, 'A busca deve usar a autenticação normal da API.');
    parse_str($request->getUri()->getQuery(), $query);
    webhookApiCheck($query === ['limit' => '100', 'offset' => '0'], 'A primeira página deve ser consultada com offset zero.');
    webhookApiCheckNoSecretInLogs();
};

$tests['URL legada sem index e com barra final permanece válida'] = static function (): void {
    $history = [];
    $alias = substr(WEBHOOK_API_TEST_URL, 0, -6) . '/';
    $adapter = webhookApiFixture([['hasMore' => false, 'data' => [
        ['id' => 'wh_alias', 'url' => $alias, 'authToken' => WEBHOOK_API_TEST_TOKEN],
    ]]], $history);
    webhookApiCheck($adapter->findWebhook(WEBHOOK_API_TEST_URL . '/') === ['id' => 'wh_alias', 'url' => $alias], 'O alias legado da mesma rota deve ser reconhecido sem propagar authToken.');
    webhookApiCheck(count($history) === 1, 'O alias deve ser encontrado sem cadastrar outro webhook.');
};

$tests['paginação encontra a instalação sem modificar outros webhooks'] = static function (): void {
    $history = [];
    $adapter = webhookApiFixture([
        ['hasMore' => true, 'data' => [
            ['id' => 'wh_other1', 'url' => 'https://other.example.test/one'],
            ['id' => 'wh_other2', 'url' => 'https://other.example.test/two'],
        ]],
        ['hasMore' => false, 'data' => [
            ['id' => 'wh_own_later', 'url' => WEBHOOK_API_TEST_URL, 'authToken' => WEBHOOK_API_TEST_TOKEN],
        ]],
    ], $history);
    webhookApiCheck($adapter->findWebhook(WEBHOOK_API_TEST_URL) === ['id' => 'wh_own_later', 'url' => WEBHOOK_API_TEST_URL], 'A busca deve localizar o webhook na próxima página.');
    webhookApiCheck(count($history) === 2, 'A busca deve consultar apenas as páginas necessárias.');
    foreach ($history as $transaction) {
        webhookApiCheck($transaction['request']->getMethod() === 'GET', 'A busca não deve modificar nem substituir webhooks da conta.');
    }
    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    webhookApiCheck($query['offset'] === '2', 'O offset deve avançar pela quantidade recebida.');
};

$tests['nenhuma correspondência preserva retorno vazio'] = static function (): void {
    $history = [];
    $adapter = webhookApiFixture([['hasMore' => false, 'data' => [
        ['id' => 'wh_unrelated', 'url' => 'https://other.example.test/webhook'],
    ]]], $history);
    webhookApiCheck($adapter->findWebhook(WEBHOOK_API_TEST_URL) === [], 'Um webhook de outra integração não deve ser retornado como próprio.');
    webhookApiCheck(count($history) === 1, 'A última página sem correspondência deve encerrar a busca.');
};

$tests['página vazia encerra a busca mesmo com hasMore'] = static function (): void {
    $history = [];
    $adapter = webhookApiFixture([['hasMore' => true, 'data' => []]], $history);
    webhookApiCheck($adapter->findWebhook(WEBHOOK_API_TEST_URL) === [], 'A página vazia deve resultar em ausência de webhook próprio.');
    webhookApiCheck(count($history) === 1, 'O adaptador não deve repetir indefinidamente a mesma página vazia.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'OK: ' . $name . PHP_EOL);
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDERR, 'FALHOU: ' . $name . ' — ' . $exception->getMessage() . PHP_EOL);
    }
}

fwrite(STDOUT, sprintf('%d cenários; %d verificações; %d falhas.%s', count($tests), $GLOBALS['webhook_api_assertions'], $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
