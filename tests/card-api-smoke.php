<?php

declare(strict_types=1);

/** Real provider + SDK + Guzzle; MockHandler is the only HTTP handler used. */
use Asaas\AsaasCardApi;
use Asaas\AsaasCardRequestException;
use Asaas\Sdk\Http\Client as SdkClient;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasCardApi.php';

const CARD_API_TEST_KEY = '$aact_card_test_secret';
const CARD_API_TEST_TOKEN = 'opaque-card-token-fixture';
const CARD_API_TEST_NUMBER = '4111111111111111';
const CARD_API_TEST_CVV = '947';
const CARD_API_TEST_RAW_ERROR = 'RAW-CARD-ERROR-MUST-NOT-ESCAPE';

function db_prefix(): string { return 'tbl'; }
function log_message(string $level, string $message): void
{
    $GLOBALS['card_api_logs'][] = $message;
    if ($GLOBALS['card_api_fail_logger'] ?? false) {
        throw new RuntimeException(CARD_API_TEST_RAW_ERROR);
    }
}

function cardApiAssert(bool $condition, string $message): void
{
    ++$GLOBALS['card_api_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}

function cardApiSafe(string $text): void
{
    $text .= json_encode($GLOBALS['card_api_db_logs'] ?? [], JSON_THROW_ON_ERROR);
    foreach ([CARD_API_TEST_KEY, CARD_API_TEST_TOKEN, CARD_API_TEST_NUMBER, CARD_API_TEST_RAW_ERROR] as $secret) {
        cardApiAssert(!str_contains($text, $secret), 'Logs/erros não podem expor dados de cartão, credenciais ou o corpo HTTP.');
    }
    cardApiAssert(!preg_match('/(?<![A-Za-z0-9])' . CARD_API_TEST_CVV . '(?![A-Za-z0-9])/', $text), 'Logs/erros não podem expor o CVV.');
}

$ci = &get_instance();
$ci->db = new class {
    public bool $db_debug = true;
    public function table_exists(string $table): bool { return $table === 'tblasaas_logs'; }
    public function list_fields(string $table): array { return ['level', 'message', 'created_at', 'context_json']; }
    public function insert(string $table, array $payload): bool
    {
        $GLOBALS['card_api_db_logs'][] = $payload;
        return true;
    }
};

final class CardApiGatewayFixture extends App_gateway
{
    public function __construct(private bool $sandbox = true) {}
    public function getSetting(string $name): mixed { return $name === 'sandbox' ? ($this->sandbox ? '1' : '0') : null; }
    public function decryptSetting(string $name): string { return CARD_API_TEST_KEY; }
}

final class CardApiProviderFixture extends AsaasSdkProvider
{
    public int $cardCalls = 0;
    public int $normalCalls = 0;
    private ?SdkClient $card = null;
    private ?SdkClient $normal = null;
    private array $history;

    public function __construct(App_gateway $gateway, private MockHandler $mock, array &$history)
    {
        $this->history = &$history;
        parent::__construct($gateway);
    }

    public function cardClient(?int $companyId = null): SdkClient
    {
        ++$this->cardCalls;
        if ($this->card === null) {
            $this->card = parent::cardClient($companyId);
            $this->attachMock($this->card);
        }
        return $this->card;
    }

    public function client(?int $companyId = null): SdkClient
    {
        ++$this->normalCalls;
        if ($this->normal === null) {
            $this->normal = parent::client($companyId);
            $this->attachMock($this->normal);
        }
        return $this->normal;
    }

    private function attachMock(SdkClient $client): void
    {
        $property = new ReflectionProperty(SdkClient::class, 'client');
        $property->setAccessible(true);
        $transport = $property->getValue($client);
        $stack = $transport->getConfig('handler');
        cardApiAssert($stack instanceof HandlerStack, 'O provider deve usar o transporte Guzzle real.');
        $stack->setHandler($this->mock);
        $stack->push(Middleware::history($this->history));
    }
}

/** @return array{AsaasCardApi, CardApiProviderFixture, MockHandler} */
function cardApiFixture(array $responses, array &$history, bool $sandbox = true): array
{
    $history = [];
    $GLOBALS['card_api_logs'] = [];
    $GLOBALS['card_api_db_logs'] = [];
    $GLOBALS['card_api_fail_logger'] = false;
    $mock = new MockHandler($responses);
    $provider = new CardApiProviderFixture(new CardApiGatewayFixture($sandbox), $mock, $history);
    return [new AsaasCardApi($provider), $provider, $mock];
}

function cardApiPayload(bool $token = false): array
{
    $payload = [
        'customer' => 'cus_card_fixture',
        'billingType' => 'CREDIT_CARD',
        'value' => 55.5,
        'dueDate' => '2027-01-15',
        'externalReference' => 'invoice-card-fixture',
        'remoteIp' => '203.0.113.10',
    ];
    if ($token) {
        $payload['creditCardToken'] = CARD_API_TEST_TOKEN;
    } else {
        $payload['creditCard'] = ['holderName' => 'Titular Fixture', 'number' => CARD_API_TEST_NUMBER, 'expiryMonth' => '05', 'expiryYear' => '2030', 'ccv' => CARD_API_TEST_CVV];
        $payload['creditCardHolderInfo'] = ['name' => 'Titular Fixture', 'email' => 'fixture@example.test', 'cpfCnpj' => '12345678909', 'postalCode' => '01310100', 'addressNumber' => '100', 'phone' => '11999999999'];
    }
    return $payload;
}

$GLOBALS['card_api_assertions'] = 0;
$GLOBALS['card_api_logs'] = [];
$scenarios = 0;

// Separate processes prove that a preloaded, incompatible root SDK fails closed.
if (in_array($argv[1] ?? '', ['--unsupported-sdk', '--ignored-injection'], true)) {
    cardApiAssert(!class_exists(SdkClient::class, false), 'A fixture deve definir o contrato antigo antes do autoload da SDK.');
    if ($argv[1] === '--unsupported-sdk') {
        class CardApiUnsupportedClientFixture
        {
            public function __construct(string $apiKey, Environment $environment, string $appName, float $timeout = 30, float $connectTimeout = 10) {}
        }
        class_alias(CardApiUnsupportedClientFixture::class, SdkClient::class);
    } else {
        class CardApiIgnoredClientFixture
        {
            private GuzzleClient $client;
            public function __construct(string $apiKey, Environment $environment, string $appName, float $timeout = 30, float $connectTimeout = 10, ?\Psr\Log\LoggerInterface $logger = null, ?GuzzleClient $client = null)
            {
                $this->client = new GuzzleClient();
            }
        }
        class_alias(CardApiIgnoredClientFixture::class, SdkClient::class);
    }
    try {
        (new AsaasSdkProvider(new CardApiGatewayFixture()))->cardClient();
        throw new LogicException('SDK incompatível deveria impedir a operação antes de qualquer HTTP.');
    } catch (RuntimeException $exception) {
        cardApiAssert(str_contains($exception->getMessage(), 'transporte seguro'), 'O erro precisa orientar a atualização do transporte da SDK.');
        cardApiAssert($exception->getPrevious() === null, 'O erro público não pode carregar uma exceção com credenciais.');
        cardApiSafe($exception->getMessage() . implode("\n", $GLOBALS['card_api_logs']));
    }
    echo json_encode(['assertions' => $GLOBALS['card_api_assertions']], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

foreach ([true, false] as $sandbox) {
    $history = [];
    [$api, $provider] = cardApiFixture([
        new Response(200, [], '{"id":"pay_card_fixture","status":"CONFIRMED"}'),
        new Response(200, [], '{"id":"pay_card_existing","status":"CONFIRMED"}'),
        new Response(200, [], '{"id":"pay_card_existing","status":"CONFIRMED"}'),
        new Response(200, [], '{"data":[{"id":"pay_card_existing"}],"hasMore":false}'),
    ], $history, $sandbox);
    $payload = cardApiPayload();
    $created = $api->createPayment($payload);
    $api->payPayment('pay_card_existing', ['creditCardToken' => CARD_API_TEST_TOKEN]);
    $api->getPayment('pay_card_existing');
    $api->listInstallmentPayments('ins_card_fixture');
    cardApiAssert($created['id'] === 'pay_card_fixture' && $provider->cardCalls === 2 && $provider->normalCalls === 2, 'Somente mutações de cartão devem usar o novo transporte.');
    $paths = ['/v3/payments', '/v3/payments/pay_card_existing/payWithCreditCard', '/v3/payments/pay_card_existing', '/v3/installments/ins_card_fixture/payments'];
    foreach ($history as $index => $entry) {
        $request = $entry['request'];
        cardApiAssert($request->getUri()->getPath() === $paths[$index], 'A rota real deve preservar /v3 e o contrato de crédito.');
        cardApiAssert($request->getUri()->getHost() === ($sandbox ? 'api-sandbox.asaas.com' : 'api.asaas.com'), 'O ambiente configurado deve ser preservado.');
        cardApiAssert($request->getHeaderLine('access_token') === CARD_API_TEST_KEY, 'A autenticação deve usar a API Key.');
        cardApiAssert($request->getHeaderLine('X-Correlation-Id') !== '' && $request->getHeaderLine('X-Request-Source') === 'perfex-asaas', 'Os headers existentes devem ser reutilizados.');
        cardApiAssert($request->getMethod() === ($index < 2 ? 'POST' : 'GET'), 'Método HTTP incorreto.');
        if ($index < 2) {
            cardApiAssert((float) $entry['options']['timeout'] === 90.0, 'O timeout de cartão deve ser 90 segundos.');
            cardApiAssert((float) $entry['options']['connect_timeout'] === 10.0, 'O timeout de conexão deve ser preservado.');
            cardApiAssert($entry['options']['allow_redirects'] === false, 'Dados de cartão não podem seguir redirecionamentos HTTP.');
        } else {
            cardApiAssert((float) $entry['options']['timeout'] === 30.0, 'O timeout anterior das consultas deve permanecer inalterado.');
        }
    }
    cardApiAssert(count($history) === 4, 'Não deve haver requisição adicional.');
    cardApiAssert(json_decode((string) $history[0]['request']->getBody(), true) === $payload, 'A API não deve alterar dados, valor ou referência do pagamento.');
    cardApiAssert(json_decode((string) $history[1]['request']->getBody(), true) === ['creditCardToken' => CARD_API_TEST_TOKEN], 'O token deve permanecer no payload, sem mudar o contrato para cardToken.');
    cardApiAssert((string) $history[3]['request']->getUri()->getQuery() === 'limit=100', 'A consulta deve obter as parcelas dentro do limite da API.');
    cardApiSafe(implode("\n", $GLOBALS['card_api_logs']));
    ++$scenarios;
}

$history = [];
[$api] = cardApiFixture([new Response(200, [], '{"id":"pay_installment_first","installment":"ins_card_fixture"}')], $history);
$payload = cardApiPayload(true);
unset($payload['value']);
$payload['installmentCount'] = 3;
$payload['totalValue'] = 15.01;
$api->createPayment($payload);
cardApiAssert(json_decode((string) $history[0]['request']->getBody(), true) === $payload, 'Parcelamento deve preservar totalValue sem recalcular parcelas em float.');
++ $scenarios;

foreach ([['value' => 4.99], ['value' => 5.01, 'discount' => ['type' => 'FIXED', 'value' => 0.02]], ['installmentCount' => 3, 'totalValue' => 14.99]] as $amount) {
    $history = [];
    [$api] = cardApiFixture([], $history);
    $payload = cardApiPayload();
    unset($payload['value']);
    $payload = array_merge($payload, $amount);
    try {
        $api->createPayment($payload);
        throw new LogicException('Valor abaixo do mínimo deveria impedir o POST.');
    } catch (RuntimeException $exception) {
        cardApiAssert($exception->getCode() === 400 && str_contains($exception->getMessage(), '5,00'), 'A validação monetária existente deve continuar explicando o mínimo.');
        cardApiAssert($exception instanceof AsaasCardRequestException && !$exception->requestSent, 'A validação local deve informar que nenhuma tentativa foi enviada.');
        cardApiSafe($exception->getMessage());
    }
    cardApiAssert($history === [], 'Valor inválido não pode chegar ao Asaas.');
    ++$scenarios;
}

foreach (['create', 'pay'] as $operation) {
    foreach ([400, 401, 403, 404, 422, 429, 500, 503, 0] as $status) {
        $history = [];
        $body = json_encode(['errors' => [['code' => CARD_API_TEST_TOKEN, 'description' => CARD_API_TEST_RAW_ERROR . ' ' . CARD_API_TEST_NUMBER . ' ' . CARD_API_TEST_CVV . ' ' . CARD_API_TEST_KEY]]]);
        $response = $status === 0
            ? new ConnectException(CARD_API_TEST_RAW_ERROR . ' ' . CARD_API_TEST_KEY, new Request('POST', 'https://api-sandbox.asaas.com/v3/payments', [], json_encode(cardApiPayload())))
            : new Response($status, [], $body);
        [$api, , $mock] = cardApiFixture([$response, new Response(200, [], '{"id":"pay_must_not_retry"}')], $history);
        try {
            $operation === 'create' ? $api->createPayment(cardApiPayload()) : $api->payPayment('pay_card_existing', ['creditCardToken' => CARD_API_TEST_TOKEN]);
            throw new LogicException('Era esperada uma falha segura do cartão.');
        } catch (RuntimeException $exception) {
            cardApiAssert($exception->getCode() === $status, 'O HTTP real deve ser preservado; transporte fica com código zero.');
            cardApiAssert($exception instanceof AsaasCardRequestException && $exception->requestSent, 'Após entrar no SDK o erro deve ser tratado como requisição potencialmente enviada.');
            cardApiAssert($exception->getPrevious() === null, 'A exceção não pode carregar o request ou corpo de erro original.');
            cardApiAssert(str_contains($exception->getMessage(), $status >= 400 && $status < 500 ? 'Revise os dados' : 'antes de tentar novamente'), 'O erro deve distinguir recusa de resultado inconclusivo.');
            cardApiSafe((string) $exception . implode("\n", $GLOBALS['card_api_logs']));
        }
        cardApiAssert(count($history) === 1 && count($mock) === 1, 'Falha de cartão não pode repetir a mutação, inclusive em 429, 5xx ou timeout.');
        ++$scenarios;
    }
}

// This request has no card key, so SDK 0.2.64's ordinary retry filter would retry it.
$history = [];
[$api, , $mock] = cardApiFixture([new Response(503, [], '{}'), new Response(200, [], '{"id":"pay_must_not_retry"}')], $history);
try { $api->createPayment(['customer' => 'cus_card_fixture', 'billingType' => 'CREDIT_CARD', 'value' => 55.5]); }
catch (RuntimeException $exception) { cardApiAssert($exception->getCode() === 503, 'O erro HTTP deve continuar disponível sem middleware da SDK.'); }
cardApiAssert(count($history) === 1 && count($mock) === 1, 'O transporte não pode depender do reconhecimento de campos de cartão pela SDK raiz.');
++ $scenarios;

// Simulate the HTTP exception chain produced by older SDK versions.
$history = [];
$request = new Request('POST', 'https://api-sandbox.asaas.com/v3/payments', [], json_encode(cardApiPayload()));
$httpError = new RequestException(CARD_API_TEST_RAW_ERROR, $request, new Response(401, [], CARD_API_TEST_NUMBER));
[$api] = cardApiFixture([$httpError], $history);
try { $api->payPayment('pay_card_existing', ['creditCardToken' => CARD_API_TEST_TOKEN]); }
catch (RuntimeException $exception) {
    cardApiAssert($exception->getCode() === 401 && $exception->getPrevious() === null, 'Recuperar o status antigo sem preservar a exceção HTTP sensível.');
    cardApiSafe($exception->getMessage() . implode("\n", $GLOBALS['card_api_logs']));
}
++ $scenarios;

foreach (['', '<html>' . CARD_API_TEST_RAW_ERROR . '</html>'] as $body) {
    $history = [];
    [$api] = cardApiFixture([new Response(200, [], $body)], $history);
    try { $api->payPayment('pay_card_existing', ['creditCardToken' => CARD_API_TEST_TOKEN]); throw new LogicException('Resposta vazia não confirma pagamento.'); }
    catch (RuntimeException $exception) {
        cardApiAssert($exception->getCode() === 0 && $exception->getPrevious() === null, 'Resposta ilegível deve ser inconclusiva e sem corpo bruto.');
        cardApiAssert($exception instanceof AsaasCardRequestException && $exception->requestSent, 'Resposta ilegível após POST não pode ser confundida com falha de configuração.');
        cardApiSafe($exception->getMessage() . implode("\n", $GLOBALS['card_api_logs']));
    }
    cardApiAssert(count($history) === 1, 'Resposta ilegível não pode iniciar outra cobrança.');
    ++$scenarios;
}

$history = [];
[$api] = cardApiFixture([new Response(200, [], '{"id":"pay_accepted"}')], $history);
$GLOBALS['card_api_fail_logger'] = true;
cardApiAssert($api->createPayment(cardApiPayload())['id'] === 'pay_accepted', 'Falha de diagnóstico não pode descartar um pagamento aceito.');
$GLOBALS['card_api_fail_logger'] = false;
cardApiAssert(count($history) === 1, 'Falha do logger não pode repetir pagamento.');
++ $scenarios;

// A broken vendor/configuration can be corrected without inventing an in-flight charge.
$GLOBALS['card_api_logs'] = [];
$GLOBALS['card_api_db_logs'] = [];
$provider = new class(new CardApiGatewayFixture()) extends AsaasSdkProvider {
    public function cardClient(?int $companyId = null): SdkClient
    {
        throw new RuntimeException(CARD_API_TEST_RAW_ERROR . ' ' . CARD_API_TEST_KEY);
    }
};
try {
    (new AsaasCardApi($provider))->createPayment(cardApiPayload());
    throw new LogicException('A falha de configuração deveria interromper antes do SDK HTTP.');
} catch (AsaasCardRequestException $exception) {
    cardApiAssert(!$exception->requestSent && $exception->getCode() === 0, 'Bootstrap sem HTTP deve ser distinguível de timeout depois do envio.');
    cardApiAssert($exception->getPrevious() === null, 'A falha de bootstrap não pode carregar mensagem ou credenciais originais.');
    cardApiSafe($exception->getMessage() . implode("\n", $GLOBALS['card_api_logs']));
}
++ $scenarios;

foreach (['../pay_other', 'pay_test?cardToken=' . CARD_API_TEST_TOKEN, "pay_test\r\nheader"] as $id) {
    $history = [];
    [$api] = cardApiFixture([], $history);
    try { $api->payPayment($id, ['creditCardToken' => CARD_API_TEST_TOKEN]); throw new LogicException('Identificador inválido deveria ser recusado.'); }
    catch (RuntimeException $exception) { cardApiAssert($exception->getCode() === 400, 'Identificador inválido deve retornar validação local.'); cardApiSafe($exception->getMessage()); }
    cardApiAssert($history === [], 'Identificador inválido não pode compor uma URL externa.');
    ++$scenarios;
}

foreach (['--unsupported-sdk', '--ignored-injection'] as $scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, $scenario], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar a fixture isolada da SDK.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($output, true);
    cardApiAssert($exit === 0 && is_array($result) && isset($result['assertions']), 'Fixture SDK: ' . $scenario . ' falhou: ' . trim($error ?: $output));
    $GLOBALS['card_api_assertions'] += $result['assertions'];
    ++$scenarios;
}

echo 'Card API smoke: ' . $scenarios . ' cenários, ' . $GLOBALS['card_api_assertions'] . " verificações aprovadas; nenhuma chamada externa.\n";
