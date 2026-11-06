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
        $state = &asaas_lifecycle_state();
        $state = [];

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
    public bool $paymentSucceeds = true;
    public ?Throwable $paymentFailure = null;
    public array $remoteDepths = [];
    public array $nativeTransactionDepths = [];
    public array $apiResponses = [];
    public array $apiCalls = [];

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
        $state = &asaas_lifecycle_state();
        $this->remoteDepths[] = $state['remote_payment_depth'] ?? 0;
        $db = get_instance()->db;
        $atomic = get_instance()->payments_model instanceof WebhookSmokeAtomicPaymentsModel;
        $this->nativeTransactionDepths[] = $db->transactionDepth();
        if ($atomic && $db->transactionDepth() !== 0) {
            throw new RuntimeException('payment_outer_transaction_not_allowed');
        }
        if ($this->paymentFailure !== null) {
            throw $this->paymentFailure;
        }
        if (!$this->paymentSucceeds) {
            return false;
        }
        if ($atomic) {
            $db->trans_begin();
        }
        $this->payments[] = $payment;
        $db->paymentRecords[] = $payment;
        if ($atomic && !$db->trans_commit()) {
            $db->trans_rollback();
            throw new RuntimeException('payment_commit_unconfirmed');
        }
        return true;
    }

    public function handle_webhook(array $headers, string $rawBody): array
    {
        ++$this->handleCalls;
        return $this->adapter->handleWebhook($headers, $rawBody);
    }

    public function request(string $method, string $path): array
    {
        $this->apiCalls[] = [$method, $path];
        if ($this->apiResponses === []) {
            throw new RuntimeException('Consulta de cobrança sem resposta local programada no teste.');
        }
        return array_shift($this->apiResponses);
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

    public function result_array(): array
    {
        return $this->data;
    }
}

final class WebhookSmokeDb
{
    public array $calls = [];
    public array $events = [];
    public array $inserts = [];
    public array $updates = [];
    public array $paymentRecords = [];
    public array $paymentMaps = [];
    public bool $paymentTableExists = true;
    public bool $paymentFieldsExist = true;
    public bool $mapTableExists = false;
    public bool $forbidCalls = false;
    public bool $eventsTableExists = true;
    public bool $db_debug = true;
    public string $dbdriver = 'mysqli';
    public string $hostname = 'mysql.example.test';
    public string $database = 'crm_webhook_test';
    public bool $commitSucceeds = true;
    public bool $insertSucceeds = true;
    public bool $updateSucceeds = true;
    public bool $receiptLockSucceeds = true;
    public mixed $balance = null;
    public ?Closure $onLock = null;
    public ?Closure $onReceiptLock = null;
    public ?string $failureMessage = null;
    public ?object $invoice;
    protected int $_trans_depth = 0;
    private array $where = [];
    private ?array $snapshot = null;

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
        return match ($table) {
            'tblasaas_webhook_events' => $this->eventsTableExists,
            'tblinvoicepaymentrecords' => $this->paymentTableExists,
            'tblasaas_payments_map' => $this->mapTableExists,
            default => false,
        };
    }

    public function field_exists(string $field, string $table): bool
    {
        $this->hit('field_exists:' . $table . ':' . $field);
        return $table === 'tblinvoicepaymentrecords' && $this->paymentFieldsExist
            && in_array($field, ['invoiceid', 'transactionid'], true);
    }

    public function get_where(string $table, array $where): WebhookSmokeResult
    {
        $this->hit('get_where:' . $table);
        if ($table === 'tblasaas_webhook_events') {
            return new WebhookSmokeResult($this->events[$where['event_id']] ?? []);
        }
        if ($table === 'tblasaas_payments_map') {
            return new WebhookSmokeResult($this->paymentMaps[$where['invoice_id']] ?? []);
        }
        if ($table === 'tblinvoicepaymentrecords') {
            foreach ($this->paymentRecords as $payment) {
                if ((int) $payment['invoiceid'] === (int) $where['invoiceid'] && $payment['transactionid'] === $where['transactionid']) {
                    return new WebhookSmokeResult($payment);
                }
            }
            return new WebhookSmokeResult();
        }
        throw new RuntimeException('Consulta inesperada: ' . $table);
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
        $matches = $this->invoice
            && ((!isset($this->where['hash']) || $this->where['hash'] === $this->invoice->hash)
                && (!isset($this->where['id']) || (int) $this->where['id'] === (int) $this->invoice->id));
        $invoice = $matches
            ? clone $this->invoice
            : null;
        $this->where = [];
        return new WebhookSmokeResult([], $invoice);
    }

    public function insert(string $table, array $data): bool
    {
        $this->hit('insert:' . $table);
        if (!$this->insertSucceeds) {
            return false;
        }
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
        if (!$this->updateSucceeds) {
            $this->where = [];
            return false;
        }
        $eventId = $this->where['event_id'] ?? '';
        if ($table !== 'tblasaas_webhook_events' || !isset($this->events[$eventId])) {
            throw new RuntimeException('Atualização inesperada ou evento inexistente.');
        }
        $this->updates[] = $data;
        $this->events[$eventId] = array_replace($this->events[$eventId], $data);
        $this->where = [];
        return true;
    }

    public function trans_begin(): bool
    {
        $this->hit('trans_begin');
        $this->snapshot = [$this->events, $this->paymentRecords];
        ++$this->_trans_depth;
        return true;
    }

    public function trans_commit(): bool
    {
        $this->hit('trans_commit');
        if (!$this->commitSucceeds) {
            return false;
        }
        --$this->_trans_depth;
        $this->snapshot = null;
        return true;
    }

    public function trans_rollback(): bool
    {
        $this->hit('trans_rollback');
        --$this->_trans_depth;
        if ($this->snapshot !== null) {
            [$this->events, $this->paymentRecords] = $this->snapshot;
            $this->snapshot = null;
        }
        $this->where = [];
        return true;
    }

    public function trans_status(): bool
    {
        return true;
    }

    public function transactionDepth(): int
    {
        return $this->_trans_depth;
    }

    public function query(string $sql, array $bindings = []): WebhookSmokeResult
    {
        $this->hit('query:' . $sql);
        if (str_contains($sql, 'GET_LOCK(')) {
            if ($this->onReceiptLock !== null) {
                ($this->onReceiptLock)($this);
            }
            return new WebhookSmokeResult(['acquired' => $this->receiptLockSucceeds ? 1 : 0]);
        }
        if (str_contains($sql, 'RELEASE_LOCK(')) {
            return new WebhookSmokeResult(['released' => 1]);
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return new WebhookSmokeResult(array_map(static fn (string $table): array => ['table_name' => $table, 'engine' => 'InnoDB'], $bindings));
        }
        if (str_contains($sql, 'FOR UPDATE')) {
            if ($this->onLock !== null) {
                ($this->onLock)($this);
            }
            return new WebhookSmokeResult([], $this->invoice);
        }
        throw new RuntimeException('SQL inesperado no teste de webhook.');
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
    public object $payments_model;
    public object $load;
    public App_gateway $asaas_gateway;

    public function __construct()
    {
        $this->db = new WebhookSmokeDb();
        $this->encryption = new WebhookSmokeEncryption();
        $this->payments_model = new stdClass();
        $this->load = new class {
            public function model(string $model): void {}
            public function library(string $library): void {}
        };
    }
}

/** Capability markers for the custom core; payment behavior remains in the stub above. */
final class WebhookSmokeAtomicPaymentsModel
{
    private function insert_payment_atomically(): void {}
}

final class WebhookSmokeAtomicWriter {}
class_alias(WebhookSmokeAtomicWriter::class, 'app\\services\\InvoicePaymentWriter');

function &get_instance(): object
{
    return $GLOBALS['webhook_smoke_ci'];
}

function db_prefix(): string
{
    return 'tbl';
}

function get_invoice_total_left_to_pay(int $invoiceId, mixed $total): mixed
{
    $db = get_instance()->db;
    if ($db->balance !== null) {
        return $db->balance;
    }
    $paid = 0;
    foreach ($db->paymentRecords as $payment) {
        if ((int) $payment['invoiceid'] === $invoiceId) {
            $paid += (float) $payment['amount'];
        }
    }
    return number_format((float) $total - $paid, 2, '.', '');
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
    get_instance()->asaas_gateway = $gateway;
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

$suite->run('status inteiro de fatura paga não duplica a baixa', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->invoice->status = 2;
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Status inteiro deve ser reconhecido.');
    $suite->same([], $gateway->payments, 'Fatura paga não deve receber novo lançamento.');
});

$suite->run('baixa somente o saldo após pagamentos e créditos locais', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->balance = '12.34';
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Saldo positivo deve ser processado.');
    $suite->same('12.34', $gateway->payments[0]['amount'], 'Deve usar o helper nativo de saldo, não o total ou valor do evento.');
    $suite->same([1], $gateway->remoteDepths, 'Hook de pagamento manual precisa estar suspenso na baixa remota.');
    $suite->same(0, asaas_lifecycle_state()['remote_payment_depth'], 'A flag precisa ser liberada após a baixa.');
});

foreach (['0.00', '-1.00'] as $balance) {
    $suite->run('saldo sem dívida ' . $balance . ' não lança pagamento', function () use ($suite, $balance): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->balance = $balance;
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Fatura sem saldo pode confirmar o evento.');
        $suite->same([], $gateway->payments, 'Não gerar baixa zero ou negativa.');
    });
}

foreach (['received', 'failed', null] as $processStatus) {
    $suite->run('evento incompleto ' . ($processStatus ?? 'null') . ' permite nova tentativa', function () use ($suite, $processStatus): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->events['evt_smoke_1'] = ['event_id' => 'evt_smoke_1', 'process_status' => $processStatus];
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Evento incompleto pode ser retomado.');
        $suite->same(false, $result['duplicated'], 'Somente processed representa evento concluído.');
        $suite->same(1, count($gateway->payments), 'Tentativa anterior incompleta não elimina a baixa.');
        $suite->same([], $db->inserts, 'Registro incompleto deve ser reaproveitado.');
        $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Marcar concluído só depois da baixa.');
    });
}

foreach (['false', 'throw'] as $failure) {
    $suite->run('addPayment ' . $failure . ' reverte evento e permite retry', function () use ($suite, $failure): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $gateway->paymentSucceeds = $failure !== 'false';
        $gateway->paymentFailure = $failure === 'throw' ? new RuntimeException('Teste de falha na baixa local.') : null;
        $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
        $failed = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(500, $failed['http_status'], 'Uma baixa não confirmada precisa pedir nova entrega.');
        $suite->same(false, $failed['processed'], 'Evento com baixa falha não está processado.');
        $suite->same([], $db->events, 'Rollback não pode deixar evento processed.');
        $suite->same([], $db->paymentRecords, 'Rollback não deixa pagamento persistido.');
        $suite->same(0, asaas_lifecycle_state()['remote_payment_depth'], 'Exceção também precisa liberar a flag.');
        $suite->truth(!asaas_lifecycle_transaction()->isActive(), 'Transação precisa encerrar após a falha.');
        $gateway->paymentSucceeds = true;
        $gateway->paymentFailure = null;
        $retried = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(200, $retried['http_status'], 'Reenvio deve conseguir concluir a baixa.');
        $suite->same(1, count($db->paymentRecords), 'Reenvio deve persistir somente um pagamento.');
        $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Só a nova tentativa bem sucedida conclui o evento.');
    });
}

foreach (['insertSucceeds', 'updateSucceeds', 'commitSucceeds'] as $property) {
    $suite->run('falha em ' . $property . ' não confirma entrega nem deixa baixa parcial', function () use ($suite, $property): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->{$property} = false;
        $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
        $result = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(500, $result['http_status'], 'Falha de gravação/commit deve responder 500.');
        $suite->same([], $db->events, 'Registro do evento deve ser revertido.');
        $suite->same([], $db->paymentRecords, 'Pagamento local deve ser revertido junto com o evento.');
        $suite->same(true, $db->db_debug, 'Configuração de erro do CI precisa ser restaurada.');
        $suite->truth(in_array('trans_rollback', $db->calls, true), 'Falha deve executar rollback real.');
        $db->{$property} = true;
        $retried = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(200, $retried['http_status'], 'Reentrega deve continuar processável.');
        $suite->same(1, count($db->paymentRecords), 'Após retry deve haver só uma baixa persistida.');
    });
}

$suite->run('eventos distintos do mesmo pagamento usam transactionid e invoiceid', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
    $adapter->handleWebhook($headers, webhook_smoke_payload('evt_confirmed', 'CONFIRMED'));
    $db->balance = '125.50';
    $result = $adapter->handleWebhook($headers, webhook_smoke_payload('evt_received'));
    $suite->same(200, $result['http_status'], 'Segundo evento deve ser confirmado.');
    $suite->same(1, count($gateway->payments), 'Não duplicar pagamento mesmo que status/saldo ainda estejam desatualizados.');
    $suite->same(2, count($db->events), 'Cada evento distinto deve receber sua confirmação.');
});

$suite->run('transactionid de outra fatura não oculta pagamento desta fatura', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->paymentRecords[] = ['invoiceid' => 43, 'transactionid' => 'pay_smoke_1', 'amount' => '1.00'];
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Fatura correta deve receber o evento.');
    $suite->same(1, count($gateway->payments), 'Dedup precisa combinar as duas colunas.');
});

foreach (['deleted', 'cancelled', 'manual_received', 'manual_deleted'] as $lifecycleStatus) {
    $suite->run('mapa ' . $lifecycleStatus . ' ignora callback antigo', function () use ($suite, $lifecycleStatus): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->mapTableExists = true;
        $db->paymentMaps[42] = ['asaas_payment_id' => 'pay_smoke_1', 'payload_cache' => json_encode(['_asaas_lifecycle' => ['state' => $lifecycleStatus]])];
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Callback obsoleto deve ser confirmado sem nova baixa.');
        $suite->same([], $gateway->payments, 'Estado local já conciliado não pode gerar nova baixa.');
        $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Callback ignorado deve encerrar sua entrega.');
    });
}

foreach ([true, false] as $retired) {
    $suite->run('id anterior ' . ($retired ? 'aposentado' : 'legado sem marca') . ' respeita metadata explícita', function () use ($suite, $retired): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->mapTableExists = true;
        $db->paymentMaps[42] = ['asaas_payment_id' => 'pay_current', 'payload_cache' => json_encode([
            '_asaas_lifecycle' => ['state' => 'active', 'retired_payment_ids' => $retired ? ['pay_smoke_1'] : []],
        ])];
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Evento deve ser confirmado.');
        $suite->same($retired ? 0 : 1, count($gateway->payments), 'Somente ID explicitamente aposentado deve ser ignorado; preservar múltiplas cobranças legadas.');
    });
}

$suite->run('recarrega fatura após lock para observar quitação manual concorrente', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->onLock = static function (WebhookSmokeDb $db): void { $db->invoice->status = 2; };
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Commit manual anterior ao lock deve ser observado.');
    $suite->same([], $gateway->payments, 'A leitura antiga da fatura não pode causar dupla baixa.');
    $suite->truth(in_array('where:id', $db->calls, true), 'A fatura deve ser relida após o lock.');
});

$suite->run('reconsulta dedup do evento dentro do lock', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->onLock = static function (WebhookSmokeDb $db): void {
        $db->events['evt_smoke_1'] = ['event_id' => 'evt_smoke_1', 'process_status' => 'processed'];
    };
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(true, $result['duplicated'], 'Outro callback pode concluir enquanto este aguarda o lock.');
    $suite->same([], $gateway->payments, 'Evento concluído durante a espera não deve baixar novamente.');
    $suite->same([], $db->inserts, 'Evento concluído durante a espera não deve ser inserido novamente.');
});

foreach (['PAYMENT_DELETED', 'PAYMENT_RECEIVED_IN_CASH_UNDONE', 'deleted'] as $event) {
    $suite->run('evento ' . $event . ' com status anterior recebido não baixa', function () use ($suite, $event): void {
        [$adapter, $gateway] = webhook_smoke_fixture();
        $payload = json_decode(webhook_smoke_payload(), true);
        if ($event === 'deleted') { $payload['payment']['deleted'] = true; }
        else { $payload['event'] = $event; }
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($payload));
        $suite->same(200, $result['http_status'], 'Evento sem recebimento deve ser confirmado.');
        $suite->same([], $gateway->payments, 'Status histórico não deve transformar exclusão/reversão em recebimento.');
    });
}

foreach (['missing_id', 'invalid_balance'] as $failure) {
    $suite->run('recebimento incompleto ' . $failure . ' não marca processed', function () use ($suite, $failure): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $payload = json_decode(webhook_smoke_payload(), true);
        if ($failure === 'missing_id') { unset($payload['payment']['id']); }
        else { $db->balance = 'inválido'; }
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($payload));
        $suite->same(500, $result['http_status'], 'Recebimento que não pode ser registrado precisa permanecer pendente.');
        $suite->same([], $db->events, 'Evento incompleto não pode ser confirmado como processado.');
        $suite->same([], $gateway->payments, 'Dados incompletos não podem gerar baixa.');
    });
}

$suite->run('instalação sem tabela de eventos preserva dedup pelo pagamento', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->eventsTableExists = false;
    $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
    $first = $adapter->handleWebhook($headers, webhook_smoke_payload('evt_first'));
    $second = $adapter->handleWebhook($headers, webhook_smoke_payload('evt_second'));
    $suite->same(200, $first['http_status'], 'Instalação legada deve aceitar a primeira entrega.');
    $suite->same(200, $second['http_status'], 'Instalação legada deve aceitar reentrega.');
    $suite->same(1, count($gateway->payments), 'Tabela nativa de pagamentos mantém deduplicação.');
    $suite->same([], $db->events, 'Não criar dados em tabela inexistente.');
});

$suite->run('core com writer atômico não recebe transação externa', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    get_instance()->payments_model = new WebhookSmokeAtomicPaymentsModel();
    $suite->same(true, asaas_lifecycle_has_atomic_payment_writer(), 'Teste deve ativar a capacidade real do core customizado.');
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(200, $result['http_status'], 'Writer que rejeita outer transaction deve continuar funcionando.');
    $suite->same([0], $gateway->nativeTransactionDepths, 'Transação deve pertencer exclusivamente ao writer do core.');
    $suite->same(1, count($db->paymentRecords), 'Writer deve gravar exatamente um pagamento.');
    $suite->same(0, $db->transactionDepth(), 'Writer deve concluir sua própria transação.');
    $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Evento só conclui após o retorno do writer.');
});

foreach (['PENDING' => 0, 'OVERDUE' => 0, 'RECEIVED' => 1, 'CONFIRMED' => 1, 'RECEIVED_IN_CASH' => 1, 'DELETED' => 0] as $remoteStatus => $expectedPayments) {
    $suite->run('callback de dinheiro revertido confere estado atual ' . $remoteStatus, function () use ($suite, $remoteStatus, $expectedPayments): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->mapTableExists = true;
        $db->paymentMaps[42] = ['asaas_payment_id' => 'pay_smoke_1', 'payload_cache' => json_encode([
            '_asaas_lifecycle' => ['state' => 'active', 'cash_receipt_reverted' => true],
        ])];
        $gateway->apiResponses[] = ['id' => 'pay_smoke_1', 'status' => $remoteStatus === 'DELETED' ? 'RECEIVED_IN_CASH' : $remoteStatus, 'deleted' => $remoteStatus === 'DELETED'];
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload('evt_old_cash', 'RECEIVED_IN_CASH'));
        $suite->same(200, $result['http_status'], 'Estado atual confirmado deve resolver o evento.');
        $suite->same($expectedPayments, count($gateway->payments), 'Evento de recebimento desfeito não pode baixar cobrança atualmente pendente/excluída.');
        $suite->same([['GET', '/payments/pay_smoke_1']], $gateway->apiCalls, 'Só consultar a cobrança correspondente, usando o helper real e transporte local.');
        $suite->same('processed', $db->events['evt_old_cash']['process_status'], 'Evento resolvido deve concluir o registro.');
    });
}

$suite->run('conferência de dinheiro revertido sem ID confirmado pede retry', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->mapTableExists = true;
    $db->paymentMaps[42] = ['asaas_payment_id' => 'pay_smoke_1', 'payload_cache' => json_encode([
        '_asaas_lifecycle' => ['state' => 'active', 'cash_receipt_reverted' => true],
    ])];
    $gateway->apiResponses[] = ['id' => 'pay_other', 'status' => 'PENDING'];
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload('evt_old_cash', 'RECEIVED_IN_CASH'));
    $suite->same(500, $result['http_status'], 'Consulta sem confirmação não deve consumir o evento.');
    $suite->same([], $gateway->payments, 'Resposta de outra cobrança não autoriza baixa.');
    $suite->same([], $db->events, 'No core nativo, evento não confirmado deve ser revertido.');
});

$suite->run('mapa quitado manualmente prevalece sobre flag antigo de dinheiro revertido', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->mapTableExists = true;
    $db->paymentMaps[42] = ['asaas_payment_id' => 'pay_smoke_1', 'payload_cache' => json_encode([
        '_asaas_lifecycle' => ['state' => 'manual_received', 'cash_receipt_reverted' => true],
    ])];
    $gateway->apiResponses[] = ['id' => 'pay_smoke_1', 'status' => 'RECEIVED_IN_CASH'];
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload('evt_old_cash', 'RECEIVED_IN_CASH'));
    $suite->same(200, $result['http_status'], 'Callback antigo de quitação já conciliada deve ser confirmado sem baixa.');
    $suite->same([], $gateway->payments, 'Metadata manual_received precisa impedir nova baixa, mesmo que status local seja reaberto.');
    $suite->same([], $gateway->apiCalls, 'Estado manual concluído não precisa ser substituído por uma consulta remota.');
});

foreach ([false, true] as $atomic) {
    $suite->run('lock do recebimento precede transações e releitura no core ' . ($atomic ? 'atômico' : 'nativo'), function () use ($suite, $atomic): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        if ($atomic) { get_instance()->payments_model = new WebhookSmokeAtomicPaymentsModel(); }
        $db->onReceiptLock = static function (WebhookSmokeDb $db): void { $db->invoice->status = 2; };
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
        $suite->same(200, $result['http_status'], 'Commit anterior à obtenção do lock deve ser observado.');
        $suite->same([], $gateway->payments, 'Fatura deve ser relida depois de esperar pelo lock, inclusive no core atômico.');
        $acquire = array_search('query:SELECT GET_LOCK(?, 5) AS acquired', $db->calls, true);
        $release = array_search('query:SELECT RELEASE_LOCK(?) AS released', $db->calls, true);
        $suite->truth(is_int($acquire) && is_int($release) && $acquire < $release, 'Callback deve obter e liberar o lock nomeado.');
        if (!$atomic) {
            $begin = array_search('trans_begin', $db->calls, true);
            $commit = array_search('trans_commit', $db->calls, true);
            $suite->truth(is_int($begin) && $acquire < $begin && is_int($commit) && $commit < $release, 'Proteção deve cobrir integralmente a transação.');
        }
    });
}

$suite->run('timeout no lock de recebimento pede retry antes de baixar ou gravar evento', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $db->receiptLockSucceeds = false;
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], webhook_smoke_payload());
    $suite->same(500, $result['http_status'], 'Timeout não pode confirmar um recebimento ainda não protegido.');
    $suite->same([], $gateway->payments, 'Não baixar sem obter exclusão mútua.');
    $suite->same([], $db->events, 'Não consumir evento antes de obter o lock.');
    $suite->truth(!in_array('trans_begin', $db->calls, true), 'Lock nomeado deve vir antes da transação do banco.');
});

$suite->run('retry após writer commitado e evento não confirmado não duplica pagamento', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    get_instance()->payments_model = new WebhookSmokeAtomicPaymentsModel();
    $db->updateSucceeds = false;
    $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
    $first = $adapter->handleWebhook($headers, webhook_smoke_payload());
    $suite->same(500, $first['http_status'], 'Falha ao confirmar o evento deve pedir reentrega.');
    $suite->same(1, count($db->paymentRecords), 'Pagamento já commitado pelo core deve permanecer gravado.');
    $suite->same('received', $db->events['evt_smoke_1']['process_status'], 'Evento não confirmado precisa continuar pendente.');
    $suite->truth(!in_array('trans_rollback', $db->calls, true), 'Adapter não pode reverter transação que pertence ao core.');
    $db->updateSucceeds = true;
    $db->balance = '125.50';
    $second = $adapter->handleWebhook($headers, webhook_smoke_payload());
    $suite->same(200, $second['http_status'], 'Retry deve concluir o evento usando o pagamento já registrado.');
    $suite->same(1, count($gateway->payments), 'Dedup por fatura e transactionid evita novo lançamento, mesmo com saldo desatualizado.');
    $suite->same(1, count($db->paymentRecords), 'Pagamento confirmado não pode ser duplicado.');
    $suite->same('processed', $db->events['evt_smoke_1']['process_status'], 'Retry deve concluir só o registro do evento.');
});

foreach (['false', 'throw'] as $failure) {
    $suite->run('writer atômico ' . $failure . ' deixa evento pendente para retry', function () use ($suite, $failure): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        get_instance()->payments_model = new WebhookSmokeAtomicPaymentsModel();
        $gateway->paymentSucceeds = $failure !== 'false';
        $gateway->paymentFailure = $failure === 'throw' ? new RuntimeException('payment_insert_failed') : null;
        $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
        $first = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(500, $first['http_status'], 'Falha do writer não pode ser confirmada.');
        $suite->same('received', $db->events['evt_smoke_1']['process_status'], 'Evento recebido sem pagamento precisa permitir retry.');
        $suite->same([], $db->paymentRecords, 'Writer recusado não deve persistir pagamento.');
        $gateway->paymentSucceeds = true;
        $gateway->paymentFailure = null;
        $second = $adapter->handleWebhook($headers, webhook_smoke_payload());
        $suite->same(200, $second['http_status'], 'Retry deve conseguir concluir.');
        $suite->same(1, count($db->paymentRecords), 'Retry deve deixar somente um pagamento.');
        $suite->same([0, 0], $gateway->nativeTransactionDepths, 'Nenhuma tentativa pode receber transação externa.');
    });
}

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
    $suite->truth(!str_contains(json_encode($GLOBALS['webhook_smoke_logs']), WEBHOOK_SMOKE_TOKEN), 'Log não pode conter token presente no texto de exceção.');
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


$suite->run('cartão avulso baixa somente o valor confirmado', function () use ($suite): void {
    [$adapter, $gateway] = webhook_smoke_fixture();
    $event = json_decode(webhook_smoke_payload('evt_card_partial'), true);
    $event['payment']['billingType'] = 'CREDIT_CARD';
    $event['payment']['value'] = '40.00';
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($event));
    $suite->same(200, $result['http_status'], 'Recebimento parcial é aceito.');
    $suite->same('40.00', $gateway->payments[0]['amount'], 'Não quitar125.50 com cobrança40.00.');
});

$suite->run('parcelas confirmadas conciliam individualmente e RECEIVED não duplica CONFIRMED', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $headers = ['asaas-access-token' => WEBHOOK_SMOKE_TOKEN];
    foreach (['40.00', '40.00', '45.50'] as $number => $value) {
        foreach (['CONFIRMED', 'RECEIVED'] as $status) {
            $event = json_decode(webhook_smoke_payload('evt_card_' . $number . '_' . $status, $status), true);
            $event['event'] = 'PAYMENT_' . $status;
            $event['payment'] = array_replace($event['payment'], ['id' => 'pay_card_' . $number,
                'billingType' => 'CREDIT_CARD', 'value' => $value, 'installment' => 'ins_fixture', 'installmentNumber' => $number + 1]);
            $result = $adapter->handleWebhook($headers, json_encode($event));
            $suite->same(200, $result['http_status'], 'Cada evento válido é aceito.');
            $suite->same($number + 1, count($gateway->payments), 'Uma baixa por ID da parcela.');
        }
    }
    $suite->same(['40.00', '40.00', '45.50'], array_column($gateway->payments, 'amount'), 'Somente a última parcela quita o saldo.');
    $suite->same('0.00', get_invoice_total_left_to_pay(42, '125.50'), 'Saldo termina zerado após todas as parcelas.');
});

foreach (['AUTHORIZED', 'AWAITING_RISK_ANALYSIS', 'REPROVED_BY_RISK_ANALYSIS', 'CHARGEBACK_REQUESTED'] as $status) {
    $suite->run('cartão ' . $status . ' não registra recebimento', function () use ($suite, $status): void {
        [$adapter, $gateway] = webhook_smoke_fixture();
        $event = json_decode(webhook_smoke_payload('evt_card_pending', $status), true);
        $event['payment']['billingType'] = 'CREDIT_CARD';
        $event['payment']['value'] = '125.50';
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($event));
        $suite->same(200, $result['http_status'], 'Status conhecido pode ser acompanhado.');
        $suite->same([], $gateway->payments, 'Não confundir autorização/análise com recebimento.');
    });
}

$suite->run('cartão sem valor confirmado fica pendente de conciliação', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $event = json_decode(webhook_smoke_payload('evt_card_no_amount'), true);
    $event['payment']['billingType'] = 'CREDIT_CARD';
    unset($event['payment']['value']);
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($event));
    $suite->same(500, $result['http_status'], 'Sem valor não marcar o evento como processado.');
    $suite->same([], $gateway->payments, 'Nenhuma baixa integral presumida.');
    $suite->truth(!isset($db->events['evt_card_no_amount']), 'Rollback permite uma entrega completa posterior.');
});

$suite->run('credenciais de cartão do webhook não são persistidas', function () use ($suite): void {
    [$adapter, $gateway, $db] = webhook_smoke_fixture();
    $event = json_decode(webhook_smoke_payload('evt_card_safe'), true);
    $event['payment'] = array_replace($event['payment'], ['billingType' => 'CREDIT_CARD', 'value' => '125.50',
        'creditCardToken' => 'token-fixture-never-store',
        'creditCard' => ['number' => '4111111111111111', 'ccv' => 'unlogged-cvv'],
        'creditCardHolderInfo' => ['name' => 'unlogged-holder']]);
    $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($event));
    $suite->same(200, $result['http_status'], 'Dados sensíveis não impedem a conciliação dos dados públicos.');
    $stored = json_encode([$db->events, $GLOBALS['webhook_smoke_logs']]);
    foreach (['token-fixture-never-store', '4111111111111111', 'unlogged-cvv', 'unlogged-holder'] as $secret) {
        $suite->truth(!str_contains($stored, $secret), 'Não armazenar credenciais de cartão no evento nem nos logs.');
    }
    $suite->same('125.50', $gateway->payments[0]['amount'], 'Valor e identificador continuam disponíveis.');
});

foreach ([5, 6] as $invoiceStatus) {
    $suite->run('webhook de cartão não baixa fatura indisponível ' . $invoiceStatus, function () use ($suite, $invoiceStatus): void {
        [$adapter, $gateway, $db] = webhook_smoke_fixture();
        $db->invoice->status = $invoiceStatus;
        $event = json_decode(webhook_smoke_payload('evt_card_closed'), true);
        $event['payment']['billingType'] = 'CREDIT_CARD';
        $event['payment']['value'] = '125.50';
        $result = $adapter->handleWebhook(['asaas-access-token' => WEBHOOK_SMOKE_TOKEN], json_encode($event));
        $suite->same(200, $result['http_status'], 'Evento conhecido é aceito sem forçar a fatura.');
        $suite->same([], $gateway->payments, 'Não alterar fatura cancelada ou rascunho.');
    });
}

$suite->finish();
