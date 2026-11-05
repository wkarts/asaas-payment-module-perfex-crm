<?php

declare(strict_types=1);

/**
 * Testes de contrato do webhook sem rede, banco externo ou dependência de PHPUnit.
 *
 * Execute: php tests/webhook-token-smoke.php
 * O adapter, o controller e os validadores são os arquivos reais do módulo.
 * Somente as interfaces fornecidas pelo Perfex/CodeIgniter são substituídas.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASEPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('WEBHOOK_SMOKE_TOKEN', 'whsec_' . hash('sha256', 'asaas-webhook-public-test-fixture'));

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class WebhookSmokeSuite
{
    private int $tests = 0;
    private int $assertions = 0;
    private array $failures = [];

    public function run(string $name, callable $test): void
    {
        ++$this->tests;
        $_GET = [];
        $GLOBALS['webhook_smoke_ci'] = new WebhookSmokeCi();
        $GLOBALS['webhook_smoke_options'] = [];
        $GLOBALS['webhook_smoke_alerts'] = [];
        $GLOBALS['webhook_smoke_logs'] = [];

        try {
            $test();
            echo "OK  {$name}\n";
        } catch (Throwable $exception) {
            $this->failures[] = $name;
            fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        }
    }

    public function same(mixed $expected, mixed $actual, string $message): void
    {
        ++$this->assertions;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Esperado: ' . var_export($expected, true)
                . '; recebido: ' . var_export($actual, true));
        }
    }

    public function truth(bool $condition, string $message): void
    {
        ++$this->assertions;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function finish(): never
    {
        $failed = count($this->failures);
        echo "\nWebhook smoke: {$this->tests} cenários, {$this->assertions} asserções, {$failed} falhas.\n";
        exit($failed === 0 ? 0 : 1);
    }
}

class App_gateway
{
    public array $settings = [];
    public array $payments = [];
    public int $handleCalls = 0;
    public ?Asaas\AsaasAdapter $adapter = null;

    public function getSetting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    public function decryptSetting(string $name): string
    {
        return (string) ($this->settings[$name] ?? '');
    }

    public function addPayment(array $payment): bool
    {
        $this->payments[] = $payment;
        return true;
    }

    public function handle_webhook(array $headers, string $rawBody): array
    {
        ++$this->handleCalls;
        return $this->adapter->handleWebhook($headers, $rawBody);
    }
}

final class WebhookSmokeResult
{
    public function __construct(private array $data = [], private ?object $record = null)
    {
    }

    public function row_array(): array
    {
        return $this->data;
    }

    public function row(): ?object
    {
        return $this->record;
    }
}

final class WebhookSmokeDb
{
    public array $calls = [];
    public array $events = [];
    public array $inserts = [];
    public array $updates = [];
    public bool $forbidCalls = false;
    public bool $eventsTableExists = true;
    public ?string $failureMessage = null;
    public ?object $invoice;
    private array $where = [];

    public function __construct()
    {
        $this->invoice = (object) ['id' => 42, 'hash' => 'invoice-smoke-42', 'status' => '1', 'total' => '125.50'];
    }

    private function hit(string $operation): void
    {
        $this->calls[] = $operation;
        if ($this->forbidCalls) {
            throw new RuntimeException('Acesso ao banco antes de autenticar/validar a notificação.');
        }
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }
    }

    public function table_exists(string $table): bool
    {
        $this->hit('table_exists:' . $table);
        return $table === 'tblasaas_webhook_events' && $this->eventsTableExists;
    }

    public function get_where(string $table, array $where): WebhookSmokeResult
    {
        $this->hit('get_where:' . $table);
        if ($table !== 'tblasaas_webhook_events') {
            throw new RuntimeException('Consulta inesperada: ' . $table);
        }
        return new WebhookSmokeResult($this->events[$where['event_id']] ?? []);
    }

    public function where(string $field, mixed $value): self
    {
        $this->hit('where:' . $field);
        $this->where[$field] = $value;
        return $this;
    }

    public function get(string $table): WebhookSmokeResult
    {
        $this->hit('get:' . $table);
        if ($table !== 'tblinvoices') {
            throw new RuntimeException('Consulta inesperada: ' . $table);
        }
        $invoice = $this->invoice && ($this->where['hash'] ?? null) === $this->invoice->hash
            ? clone $this->invoice
            : null;
        $this->where = [];
        return new WebhookSmokeResult([], $invoice);
    }

    public function insert(string $table, array $data): bool
    {
        $this->hit('insert:' . $table);
        if ($table !== 'tblasaas_webhook_events' || isset($this->events[$data['event_id']])) {
            throw new RuntimeException('Inserção inesperada ou evento duplicado.');
        }
        $this->inserts[] = $data;
        $this->events[$data['event_id']] = $data;
        return true;
    }

    public function update(string $table, array $data): bool
    {
        $this->hit('update:' . $table);
        $eventId = $this->where['event_id'] ?? '';
        if ($table !== 'tblasaas_webhook_events' || !isset($this->events[$eventId])) {
            throw new RuntimeException('Atualização inesperada ou evento inexistente.');
        }
        $this->updates[] = $data;
        $this->events[$eventId] = array_replace($this->events[$eventId], $data);
        $this->where = [];
        return true;
    }
}

final class WebhookSmokeEncryption
{
    public array $values = [];

    public function decrypt(string $value): string|false
    {
        return $this->values[$value] ?? false;
    }
}

final class WebhookSmokeCi
{
    public WebhookSmokeDb $db;
    public WebhookSmokeEncryption $encryption;

    public function __construct()
    {
        $this->db = new WebhookSmokeDb();
        $this->encryption = new WebhookSmokeEncryption();
    }
}

function &get_instance(): object
{
    return $GLOBALS['webhook_smoke_ci'];
}

function db_prefix(): string
{
    return 'tbl';
}

function get_option(string $name): mixed
{
    return $GLOBALS['webhook_smoke_options'][$name] ?? '';
}

function set_alert(string $type, string $message): void
{
    $GLOBALS['webhook_smoke_alerts'][] = [$type, $message];
}

function log_activity(string $message): void
{
    $GLOBALS['webhook_smoke_logs'][] = $message;
}

function log_message(string $level, string $message): void
{
    $GLOBALS['webhook_smoke_logs'][] = $level . ': ' . $message;
}

class CI_Input
{
    public function __construct(
        private string $requestMethod,
        private array $headers,
        public string $raw_input_stream
    ) {
    }

    public function method(bool $upper = false): string
    {
        return $upper ? strtoupper($this->requestMethod) : strtolower($this->requestMethod);
    }

    public function request_headers(): array
    {
        return $this->headers;
    }
}

class CI_Output
{
    public int $status = 200;
    public array $headers = [];
    public string $contentType = '';
    public string $charset = '';
    public string $body = '';

    public function set_header(string $header): self
    {
        $this->headers[] = $header;
        return $this;
    }

    public function set_status_header(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function set_content_type(string $type, string $charset = ''): self
    {
        $this->contentType = $type;
        $this->charset = $charset;
        return $this;
    }

    public function set_output(string $body): self
    {
        $this->body = $body;
        return $this;
    }
}

class APP_Controller
{
    public CI_Input $input;
    public CI_Output $output;
    public App_gateway $asaas_gateway;
}

require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasAdapter.php';
require_once dirname(__DIR__) . '/controllers/gateways/Callback.php';
require_once dirname(__DIR__) . '/helpers/asaas_webhook_helper.php';

function webhook_smoke_fixture(string $secret = WEBHOOK_SMOKE_TOKEN): array
{
    $gateway = new App_gateway();
    $gateway->settings['webhook_secret'] = $secret;
    $adapter = new Asaas\AsaasAdapter(new AsaasSdkProvider($gateway), $gateway);
    $gateway->adapter = $adapter;
    return [$adapter, $gateway, get_instance()->db];
}

function webhook_smoke_payload(string $eventId = 'evt_smoke_1', string $status = 'RECEIVED'): string
{
    return json_encode([
        'id' => $eventId,
        'event' => 'PAYMENT_RECEIVED',
        'payment' => [
            'id' => 'pay_smoke_1',
            'externalReference' => 'invoice-smoke-42',
            'status' => $status,
            'billingType' => 'PIX',
            'value' => 1.23,
        ],
    ], JSON_THROW_ON_ERROR);
}

function webhook_smoke_controller(App_gateway $gateway, string $method, array $headers, string $body): Callback
{
    // O construtor depende do loader completo do Perfex; index() é executado integralmente.
    $controller = (new ReflectionClass(Callback::class))->newInstanceWithoutConstructor();
    $controller->input = new CI_Input($method, $headers, $body);
    $controller->output = new CI_Output();
    $controller->asaas_gateway = $gateway;
    return $controller;
}

function webhook_smoke_candidate(int $length): string
{
    return substr(str_repeat(hash('sha256', 'another-public-validation-fixture'), 5), 0, $length);
}

$suite = new WebhookSmokeSuite();

foreach (['asaas-access-token', 'AsAaS-AcCeSs-ToKeN'] as $header) {
    $suite->run('header oficial válido: ' . $header, function () use ($suite, $header): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $result = $adapter->handleWebhook([$header => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Token oficial deve autorizar.');
        $suite->same(true, $result['processed'], 'Evento deve ser processado.');
        $suite->same(1, count($gateway->payments), 'Cobrança deve baixar uma única vez.');
        $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Evento deve ser registrado.');
    });
}

$invalidHeaders = [
    'ausente' => [],
    'incorreto' => ['asaas-access-token' => str_repeat('b', 64)],
    'vazio' => ['asaas-access-token' => ''],
    'array' => ['asaas-access-token' => [WEBHOOK_SMOKE_TOKEN]],
    'numérico' => ['asaas-access-token' => 123456],
    'booleano' => ['asaas-access-token' => true],
    'nulo' => ['asaas-access-token' => null],
    'espaço inicial' => ['asaas-access-token' => ' ' . WEBHOOK_SMOKE_TOKEN],
    'espaço final' => ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN . ' '],
    'quebra de linha' => ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN . "\n"],
];
foreach ($invalidHeaders as $label => $headers) {
    $suite->run('rejeita token ' . $label . ' antes do banco', function () use ($suite, $headers): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->forbidCalls = true;
        $result = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(401, $result['http_status'], 'Token inválido deve responder 401.');
        $suite->same(false, $result['processed'], 'Evento inválido não pode ser aceito.');
        $suite->same([], $db->calls, 'Autenticação deve preceder qualquer consulta.');
        $suite->same([], $gateway->payments, 'Autenticação deve preceder qualquer pagamento.');
    });
}

foreach (['inválido' => 'wrong', 'vazio' => '', 'array' => [WEBHOOK_SMOKE_TOKEN], 'nulo' => null] as $label => $official) {
    $suite->run('header oficial ' . $label . ' impede fallback', function () use ($suite, $official): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->forbidCalls = true;
        $_GET['token'] = WEBHOOK_SMOKE_TOKEN;
        $result = $adapter->handleWebhook([
            'asaas-access-token' => $official,
            'x-asaas-webhook-token' => WEBHOOK_SMOKE_TOKEN,
            'x-webhook-token' => WEBHOOK_SMOKE_TOKEN,
        ], webhook_smoke_payload());
        $suite->same(401, $result['http_status'], 'Aliases não podem substituir header oficial inválido.');
        $suite->same([], $db->calls, 'Fallback indevido não pode alcançar o banco.');
        $suite->same([], $gateway->payments, 'Fallback indevido não pode baixar cobrança.');
    });
}

foreach (['X-Asaas-Webhook-Token', 'X-Webhook-Token', 'query'] as $source) {
    $suite->run('preserva token legado por ' . $source, function () use ($suite, $source): void {
        [$adapter, $gateway] = webhook_smoke_fixture();
        $headers = $source === 'query' ? [] : [$source => WEBHOOK_SMOKE_TOKEN];
        if ($source === 'query') {
            $_GET['token'] = WEBHOOK_SMOKE_TOKEN;
        }
        $result = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Integração legada deve continuar autorizada.');
        $suite->same(1, count($gateway->payments), 'Integração legada deve processar o pagamento.');
    });
}

$suite->run('header oficial válido prevalece sobre aliases inválidos', function () use ($suite): void {
    [$adapter] = webhook_smoke_fixture();
    $_GET['token'] = 'wrong';
    $result = $adapter->handleWebhook([
        'asaas-access-token' => WEBHOOK_SMOKE_TOKEN,
        'x-asaas-webhook-token' => 'wrong',
    ], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Header oficial válido deve ser suficiente.');
});

$suite->run('query e alias com tipos inválidos não autenticam', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->forbidCalls = true;
    $_GET['token'] = [WEBHOOK_SMOKE_TOKEN];
    $result = $adapter->handleWebhook(['x-asaas-webhook-token' => [WEBHOOK_SMOKE_TOKEN]], webhook_smoke_payload());
    $suite->same(401, $result['http_status'], 'Array não pode ser convertido em credencial.');
    $suite->same([], $db->calls, 'Entrada inválida não pode alcançar o banco.');
    $suite->same([], $gateway->payments, 'Entrada inválida não pode baixar cobrança.');
});

$suite->run('segredo vazio preserva instalação legada', function () use ($suite): void {
    [$adapter, $gateway] = webhook_smoke_fixture('');
    $result = $adapter->handleWebhook([], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Instalação sem segredo mantém o contrato anterior.');
    $suite->same(1, count($gateway->payments), 'Evento legado deve continuar processado.');
});

$suite->run('autenticação precede inclusive a leitura de JSON', function () use ($suite): void {
    [$adapter, , $db] = webhook_smoke_fixture();
    $db->forbidCalls = true;
    $result = $adapter->handleWebhook([], '{invalid');
    $suite->same(401, $result['http_status'], 'Pedido não autenticado deve responder 401 mesmo com JSON inválido.');
    $suite->same([], $db->calls, 'Pedido não autenticado não consulta banco.');
});

foreach (['sintaxe' => '{invalid', 'null' => 'null', 'string' => '"event"', 'número' => '123'] as $label => $body) {
    $suite->run('JSON inválido (' . $label . ') responde 400', function () use ($suite, $body): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->forbidCalls = true;
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], $body);
        $suite->same(400, $result['http_status'], 'Payload inválido deve responder 400.');
        $suite->same(false, $result['processed'], 'Payload inválido não pode ser aceito.');
        $suite->same([], $db->calls, 'Payload inválido não consulta banco.');
        $suite->same([], $gateway->payments, 'Payload inválido não baixa cobrança.');
    });
}

$suite->run('reenvio do mesmo evento mantém HTTP 200 sem dupla baixa', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
    $first = $adapter->handleWebhook($headers, webhook_smoke_payload());
    $second = $adapter->handleWebhook($headers, webhook_smoke_payload());
    $suite->same(200, $first['http_status'], 'Primeira entrega deve ser confirmada.');
    $suite->same(false, $first['duplicated'], 'Primeira entrega não é duplicata.');
    $suite->same(200, $second['http_status'], 'Reenvio já processado deve ser confirmado.');
    $suite->same(true, $second['duplicated'], 'Reenvio deve ser reconhecido como duplicata.');
    $suite->same(1, count($gateway->payments), 'Reenvio não pode duplicar o pagamento.');
    $suite->same(1, count($db->inserts), 'Reenvio não pode duplicar o evento.');
    $suite->same(1, count($db->updates), 'Reenvio não deve reprocessar o evento.');
});

foreach (['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'] as $status) {
    $suite->run('regra de baixa preservada: ' . $status, function () use ($suite, $status): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload('evt_payment', $status));
        $suite->same(200, $result['http_status'], 'Pagamento deve ser confirmado.');
        $suite->same([[
            'amount' => '125.50',
            'invoiceid' => 42,
            'paymentmode' => 'Asaas',
            'paymentmethod' => 'PIX',
            'transactionid' => 'pay_smoke_1',
        ]], $gateway->payments, 'Campos e valor da baixa existente devem ser preservados.');
        $suite->same(42, $db->events['evt_payment']['invoice_id'], 'Evento deve permanecer vinculado à fatura.');
        $suite->same('processed', $db->events['evt_payment']['process_status'], 'Evento deve concluir o processamento.');
    });
}

foreach (['PENDING', 'OVERDUE', 'REFUNDED'] as $status) {
    $suite->run('status sem baixa preservado: ' . $status, function () use ($suite, $status): void {
        [$adapter, $gateway] = webhook_smoke_fixture();
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload('evt_pending', $status));
        $suite->same(200, $result['http_status'], 'Evento conhecido sem baixa deve ser confirmado.');
        $suite->same([], $gateway->payments, 'Status não pago não pode baixar a fatura.');
    });
}

$suite->run('fatura já paga não recebe nova baixa', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->invoice->status = '2';
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Evento de fatura paga deve ser confirmado.');
    $suite->same([], $gateway->payments, 'Fatura paga não pode receber segunda baixa.');
});

$suite->run('fatura não encontrada não recebe pagamento', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->invoice = null;
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Evento sem fatura preserva o comportamento anterior.');
    $suite->same([], $gateway->payments, 'Evento sem vínculo não pode baixar outra fatura.');
    $suite->same(null, $db->events['evt_smoke_1']['invoice_id'], 'Não inventar vínculo de fatura.');
});

foreach (['GET', 'PUT'] as $method) {
    $suite->run('controller rejeita ' . $method . ' com Allow: POST', function () use ($suite, $method): void {
        [, $gateway, $db] = webhook_smoke_fixture();
        $db->forbidCalls = true;
        $controller = webhook_smoke_controller($gateway, $method, [], webhook_smoke_payload());
        $returned = $controller->index();
        $suite->same($controller->output, $returned, 'Controller deve retornar a resposta do CodeIgniter.');
        $suite->same(405, $returned->status, 'Método não suportado deve responder 405.');
        $suite->same(['Allow: POST'], $returned->headers, 'Resposta deve informar o método permitido.');
        $suite->same(0, $gateway->handleCalls, 'Método inválido não deve chamar o gateway.');
        $suite->same([], $db->calls, 'Método inválido não deve consultar o banco.');
    });
}

foreach ([
    'token ausente' => [[], '', 401, 'Token inválido.'],
    'token incorreto' => [['asaas-access-token' => 'wrong'], '', 401, 'Token inválido.'],
    'payload inválido' => [['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], '{invalid', 400, 'Payload inválido.'],
] as $label => [$headers, $body, $status, $message]) {
    $suite->run('controller propaga ' . $label, function () use ($suite, $headers, $body, $status, $message): void {
        [, $gateway, $db] = webhook_smoke_fixture();
        $db->forbidCalls = true;
        $controller = webhook_smoke_controller($gateway, 'POST', $headers, $body);
        $result = $controller->index();
        $suite->same($status, $result->status, 'Controller deve preservar status do adapter.');
        $suite->same($message, $result->body, 'Controller deve explicar a falha sem payload.');
        $suite->same('text/plain', $result->contentType, 'Contrato de resposta texto deve ser mantido.');
        $suite->same('utf-8', $result->charset, 'Resposta deve declarar charset.');
        $suite->same(1, count($GLOBALS['webhook_smoke_logs']), 'Falha deve gerar registro genérico.');
        $suite->truth(!str_contains(json_encode($GLOBALS['webhook_smoke_logs']), WEBHOOK_SMOKE_TOKEN), 'Logs não podem revelar o segredo.');
        $suite->same([], $db->calls, 'Falha de autenticação/payload não pode consultar banco.');
    });
}

$suite->run('controller confirma pagamento e duplicata com HTTP 200', function () use ($suite): void {
    [, $gateway, $db] = webhook_smoke_fixture();
    $headers = ['Asaas-Access-Token' => WEBHOOK_SMOKE_TOKEN];
    $first = webhook_smoke_controller($gateway, 'POST', $headers, "\n" . webhook_smoke_payload() . "\n")->index();
    $second = webhook_smoke_controller($gateway, 'POST', $headers, webhook_smoke_payload())->index();
    $suite->same(200, $first->status, 'Primeira entrega deve responder 200.');
    $suite->same('Evento processado.', $first->body, 'Texto de sucesso deve ser mantido.');
    $suite->same(200, $second->status, 'Duplicata deve responder 200.');
    $suite->same('Evento já processado.', $second->body, 'Duplicata deve ser identificada.');
    $suite->same(1, count($gateway->payments), 'Controller não pode causar dupla baixa.');
    $suite->same(1, count($db->events), 'Controller não pode duplicar evento.');
    $suite->same([], $GLOBALS['webhook_smoke_logs'], 'Processamento normal não deve registrar falha.');
});

$suite->run('controller devolve 500 genérico e não vaza exceção', function () use ($suite): void {
    [, $gateway, $db] = webhook_smoke_fixture();
    $db->failureMessage = 'Database failure with confidential token ' . WEBHOOK_SMOKE_TOKEN;
    $controller = webhook_smoke_controller($gateway, 'POST', ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $result = $controller->index();
    $suite->same(500, $result->status, 'Falha interna não pode confirmar entrega com 200.');
    $suite->same('Falha interna ao processar webhook.', $result->body, 'Resposta deve ser genérica.');
    $suite->same(['Asaas: Falha interna ao processar webhook.'], $GLOBALS['webhook_smoke_logs'], 'Log não pode conter texto de exceção.');
    $suite->truth(!str_contains($result->body, WEBHOOK_SMOKE_TOKEN), 'Resposta não pode revelar o token.');
    $suite->same([], $gateway->payments, 'Falha do banco não pode baixar pagamento.');
});

$suite->run('sanitização remove tokens oficiais e legados de logs', function () use ($suite): void {
    [$adapter] = webhook_smoke_fixture();
    $method = new ReflectionMethod(Asaas\AsaasAdapter::class, 'sanitizeContext');
    $method->setAccessible(true);
    $input = [
        'AuthToken' => WEBHOOK_SMOKE_TOKEN,
        'Webhook_Secret' => WEBHOOK_SMOKE_TOKEN,
        'request' => ['headers' => [
            'Asaas-Access-Token' => WEBHOOK_SMOKE_TOKEN,
            'X-Asaas-Webhook-Token' => WEBHOOK_SMOKE_TOKEN,
            'X-Webhook-Token' => WEBHOOK_SMOKE_TOKEN,
        ]],
        'status' => 'ok',
    ];
    $result = $method->invoke($adapter, $input);
    $suite->truth(!str_contains(json_encode($result), WEBHOOK_SMOKE_TOKEN), 'Nenhum segredo pode permanecer no contexto.');
    $suite->same('[REDACTED]', $result['AuthToken'], 'authToken deve ser redigido.');
    $suite->same('[REDACTED]', $result['request']['headers']['Asaas-Access-Token'], 'Header oficial aninhado deve ser redigido.');
    $suite->same('ok', $result['status'], 'Dados não sensíveis devem ser preservados.');
});

foreach ([32, 64, 255] as $length) {
    $suite->run('validador aceita token novo com ' . $length . ' caracteres', function () use ($suite, $length): void {
        $suite->same(null, asaas_webhook_token_validation_error(webhook_smoke_candidate($length)), 'Tamanho permitido deve ser aceito.');
    });
}

$invalidSettings = [
    '31 caracteres' => webhook_smoke_candidate(31),
    '256 caracteres' => webhook_smoke_candidate(256),
    'espaço inicial' => ' ' . webhook_smoke_candidate(32),
    'espaço final' => webhook_smoke_candidate(32) . ' ',
    'espaço interno' => webhook_smoke_candidate(16) . ' ' . webhook_smoke_candidate(16),
    'tabulação' => webhook_smoke_candidate(32) . "\t",
    'quebra de linha' => webhook_smoke_candidate(32) . "\n",
    'array' => [WEBHOOK_SMOKE_TOKEN],
    'inteiro' => 123456,
    'booleano' => false,
];
foreach ($invalidSettings as $label => $token) {
    $suite->run('validador rejeita configuração: ' . $label, function () use ($suite, $token): void {
        $error = asaas_webhook_token_validation_error($token);
        $suite->truth(is_string($error) && $error !== '', 'Configuração inválida deve gerar erro legível.');
        if (is_string($token)) {
            $suite->truth(!str_contains($error, $token), 'Mensagem de erro não deve expor o token informado.');
        }
    });
}

$suite->run('validador preserva vazio e segredo legado inalterado', function () use ($suite): void {
    $suite->same(null, asaas_webhook_token_validation_error(''), 'Vazio preserva o modo legado.');
    $suite->same(null, asaas_webhook_token_validation_error('legacy-short', 'legacy-short'), 'Segredo salvo anteriormente pode continuar inalterado.');
    $suite->truth(asaas_webhook_token_validation_error('other-short', 'legacy-short') !== null, 'Alteração nova deve obedecer aos requisitos atuais.');
});

$suite->run('validador impede reutilização das chaves de API', function () use ($suite): void {
    $productionKey = webhook_smoke_candidate(64);
    $sandboxKey = strrev($productionKey);
    $suite->truth(asaas_webhook_token_validation_error($productionKey, null, [$productionKey, $sandboxKey]) !== null, 'Chave de produção não pode virar token.');
    $suite->truth(asaas_webhook_token_validation_error($sandboxKey, null, [$productionKey, $sandboxKey]) !== null, 'Chave de sandbox não pode virar token.');
});

$suite->run('filtro preserva dados sem campo de webhook', function () use ($suite): void {
    $data = ['settings' => ['companyname' => 'Empresa teste'], 'other' => ['retained' => true]];
    $suite->same($data, asaas_validate_webhook_settings($data), 'Configurações não relacionadas devem permanecer intactas.');
    $suite->same([], $GLOBALS['webhook_smoke_alerts'], 'Sem alteração do segredo não há alerta.');
});

$suite->run('filtro salva token válido sem alterar demais configurações', function () use ($suite): void {
    $data = ['settings' => [
        'paymentmethod_asaas_webhook_secret' => WEBHOOK_SMOKE_TOKEN,
        'paymentmethod_asaas_sandbox' => '1',
        'companyname' => 'Empresa teste',
    ], 'other' => 'retained'];
    $suite->same($data, asaas_validate_webhook_settings($data), 'Token e demais configurações válidas devem ser salvos.');
    $suite->same([], $GLOBALS['webhook_smoke_alerts'], 'Token válido não deve produzir alerta.');
});

foreach (['curto' => 'new-short', 'array' => [WEBHOOK_SMOKE_TOKEN]] as $label => $token) {
    $suite->run('filtro rejeita somente o token ' . $label, function () use ($suite, $token): void {
        $GLOBALS['webhook_smoke_options']['paymentmethod_asaas_webhook_secret'] = WEBHOOK_SMOKE_TOKEN;
        $data = ['settings' => [
            'paymentmethod_asaas_webhook_secret' => $token,
            'paymentmethod_asaas_sandbox' => '0',
            'companyname' => 'Empresa teste',
        ], 'other' => 'retained'];
        $expected = $data;
        unset($expected['settings']['paymentmethod_asaas_webhook_secret']);
        $suite->same($expected, asaas_validate_webhook_settings($data), 'Rejeição não pode remover outras alterações.');
        $suite->same(1, count($GLOBALS['webhook_smoke_alerts']), 'Rejeição deve ser visível ao administrador.');
        $suite->same('danger', $GLOBALS['webhook_smoke_alerts'][0][0], 'Rejeição deve usar alerta de erro.');
        $suite->same(WEBHOOK_SMOKE_TOKEN, get_option('paymentmethod_asaas_webhook_secret'), 'Validação não pode sobrescrever o token salvo.');
        $suite->truth(!str_contains(json_encode($GLOBALS['webhook_smoke_alerts']), WEBHOOK_SMOKE_TOKEN), 'Alerta não pode revelar o token salvo.');
    });
}

foreach (['', 'legacy-short'] as $token) {
    $suite->run('filtro preserva configuração legada ' . ($token === '' ? 'vazia' : 'inalterada'), function () use ($suite, $token): void {
        $GLOBALS['webhook_smoke_options']['paymentmethod_asaas_webhook_secret'] = 'legacy-short';
        $data = ['settings' => ['paymentmethod_asaas_webhook_secret' => $token, 'companyname' => 'Empresa teste']];
        $suite->same($data, asaas_validate_webhook_settings($data), 'Modo legado deve continuar configurável.');
        $suite->same([], $GLOBALS['webhook_smoke_alerts'], 'Compatibilidade legada não deve gerar erro.');
    });
}

foreach (['api_key', 'api_key_sandbox'] as $key) {
    $suite->run('filtro rejeita chave de API enviada em ' . $key, function () use ($suite, $key): void {
        $token = webhook_smoke_candidate(64);
        $data = ['settings' => [
            'paymentmethod_asaas_webhook_secret' => $token,
            'paymentmethod_asaas_' . $key => $token,
            'companyname' => 'Empresa teste',
        ]];
        $expected = $data;
        unset($expected['settings']['paymentmethod_asaas_webhook_secret']);
        $suite->same($expected, asaas_validate_webhook_settings($data), 'Chave de API enviada não pode ser reutilizada como token.');
        $suite->same(1, count($GLOBALS['webhook_smoke_alerts']), 'Tentativa de reutilização deve gerar alerta.');
        $suite->truth(!str_contains(json_encode($GLOBALS['webhook_smoke_alerts']), $token), 'Alerta não pode revelar chave de API.');
    });

    $suite->run('filtro rejeita chave de API criptografada em ' . $key, function () use ($suite, $key): void {
        $token = webhook_smoke_candidate(64);
        $GLOBALS['webhook_smoke_options']['paymentmethod_asaas_' . $key] = 'encrypted-fixture';
        get_instance()->encryption->values['encrypted-fixture'] = $token;
        $data = ['settings' => ['paymentmethod_asaas_webhook_secret' => $token, 'companyname' => 'Empresa teste']];
        $expected = $data;
        unset($expected['settings']['paymentmethod_asaas_webhook_secret']);
        $suite->same($expected, asaas_validate_webhook_settings($data), 'Chave de API já salva não pode ser reutilizada.');
        $suite->same(1, count($GLOBALS['webhook_smoke_alerts']), 'Tentativa de reutilização deve gerar alerta.');
        $suite->truth(!str_contains(json_encode($GLOBALS['webhook_smoke_alerts']), $token), 'Alerta não pode revelar chave de API descriptografada.');
    });
}

$suite->finish();
