<?php

declare(strict_types=1);

/**
 * Runs the real invoice hooks, gateway, adapter and SDK with an offline transport.
 * Only Perfex/CodeIgniter interfaces and the HTTP provider are replaced.
 * This suite isolates HTTP behavior with an already available invoice fixture.
 * The before/after native persistence contract is in invoice-lifecycle-smoke.php.
 * Requires the real mbstring extension used by the production description path.
 * Run: php tests/invoice-dispatch-smoke.php
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

const INVOICE_SMOKE_KEY = '$aact_invoice_smoke_fake_key_85309623';
const INVOICE_SMOKE_HASH = 'invoice-smoke-external-reference';

$temporaryRoot = sys_get_temp_dir() . '/asaas-invoice-smoke-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/modules', 0700, true);
mkdir($temporaryRoot . '/application/logs', 0700, true);
symlink(dirname(__DIR__), $temporaryRoot . '/modules/asaas');
register_shutdown_function(static function () use ($temporaryRoot): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isLink() || $file->isFile()) {
            unlink($file->getPathname());
        } else {
            rmdir($file->getPathname());
        }
    }
    rmdir($temporaryRoot);
});
define('BASEPATH', __DIR__);
define('FCPATH', $temporaryRoot . '/');
define('APPPATH', $temporaryRoot . '/application/');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // process_payment still uses the pre-existing utf8_encode on PHP 8.2+.
    if ($severity === E_DEPRECATED) {
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class App_gateway
{
    public function __construct() {}
    public function setId(string $id): void {}
    public function setName(string $name): void {}
    public function setSettings(array $settings): void {}
    public function getSetting(string $name): mixed
    {
        return get_option('paymentmethod_asaas_' . $name);
    }
    public function decryptSetting(string $name): string
    {
        return (string) $this->getSetting($name);
    }
}

// The boleto GET uses only the gateway and database; no card/renderer path is run.
class ClientsController
{
    public $db;
    public $asaas_gateway;
}

require_once __DIR__ . '/../controllers/Checkout.php';

final class InvoiceSmokeResult
{
    public function __construct(private array $rows = []) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function row(): ?object { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
    public function result_array(): array { return $this->rows; }
}

final class InvoiceSmokeDatabase
{
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public array $logs = [];
    public array $customers = [];
    public array $payments = [];
    public array $writes = [];
    private string $table = '';
    private ?array $transactionSnapshot = null;

    public function __construct(private InvoiceSmokeCi $ci) {}
    public function trans_begin(): bool
    {
        if ($this->_trans_depth === 0) {
            $this->transactionSnapshot = [$this->logs, $this->customers, $this->payments,
                $this->ci->invoice ? clone $this->ci->invoice : null, $this->ci->client ? clone $this->ci->client : null];
        }
        ++$this->_trans_depth;
        return true;
    }
    public function trans_commit(): bool
    {
        if ($this->_trans_depth < 1) { return false; }
        --$this->_trans_depth;
        if ($this->_trans_depth === 0) { $this->transactionSnapshot = null; }
        return true;
    }
    public function trans_rollback(): bool
    {
        if ($this->_trans_depth < 1) { return false; }
        --$this->_trans_depth;
        if ($this->_trans_depth === 0 && $this->transactionSnapshot !== null) {
            [$this->logs, $this->customers, $this->payments, $this->ci->invoice, $this->ci->client] = $this->transactionSnapshot;
            $this->transactionSnapshot = null;
        }
        return true;
    }
    public function trans_status(): bool { return true; }
    public function table_exists(string $table): bool
    {
        return in_array($table, ['tblasaas_logs', 'tblasaas_customers_map', 'tblasaas_payments_map'], true);
    }
    public function field_exists(string $field, string $table): bool
    {
        return $table === 'tblasaas_logs' && in_array($field, $this->list_fields($table), true);
    }
    public function list_fields(string $table): array
    {
        return ['id', 'level', 'message', 'context_json', 'created_at', 'correlation_id'];
    }
    public function select(string $fields): self { return $this; }
    public function from(string $table): self { $this->table = $table; return $this; }
    public function join(string $table, string $on, string $type): self { return $this; }
    public function where(string $field, mixed $value): self { return $this; }
    public function get(?string $table = null): InvoiceSmokeResult
    {
        $table = $table ?? $this->table;
        $record = str_starts_with($table, 'tblclients') ? $this->ci->client : $this->ci->invoice;
        return new InvoiceSmokeResult($record ? [(array) $record] : []);
    }
    public function get_where(string $table, array $where): InvoiceSmokeResult
    {
        if ($table === 'tblinvoices') {
            $record = $this->ci->invoice ? (array) $this->ci->invoice : [];
            foreach ($where as $field => $value) {
                if (($record[$field] ?? null) != $value) { return new InvoiceSmokeResult(); }
            }
            return new InvoiceSmokeResult($record === [] ? [] : [$record]);
        }
        if ($table === 'tblinvoicepaymentrecords') { return new InvoiceSmokeResult(); }
        $record = $table === 'tblasaas_customers_map'
            ? ($this->customers[$where['perfex_client_id']] ?? null)
            : ($this->payments[$where['invoice_id']] ?? null);
        return new InvoiceSmokeResult($record ? [$record] : []);
    }
    public function replace(string $table, array $payload): bool
    {
        $this->customers[$payload['perfex_client_id']] = $payload;
        return true;
    }
    public function insert(string $table, array $payload): bool
    {
        $this->writes[] = ['operation' => 'insert', 'table' => $table, 'payload' => $payload, 'depth' => $this->_trans_depth];
        if ($table === 'tblasaas_logs') {
            $this->logs[] = $payload;
        } elseif ($table === 'tblasaas_payments_map') {
            $this->payments[$payload['invoice_id']] = $payload;
        }
        return true;
    }
    public function update(string $table, array $payload): bool
    {
        if ($table === 'tblinvoices') {
            $this->writes[] = ['operation' => 'update', 'table' => $table, 'payload' => $payload, 'depth' => $this->_trans_depth];
            foreach ($payload as $field => $value) { $this->ci->invoice->$field = $value; }
            return true;
        }
        return $this->insert($table, $payload);
    }
    public function query(string $sql): InvoiceSmokeResult
    {
        return new InvoiceSmokeResult(array_map(static fn (string $field): array => ['Field' => $field], $this->list_fields('tblasaas_logs')));
    }
    public function error(): array { return []; }
}

final class InvoiceSmokeLoader
{
    public ?string $failure = null;
    public function library(string $name): void
    {
        if ($this->failure === 'library') {
            throw new RuntimeException('Falha simulada ao carregar gateway.');
        }
    }
    public function model(string $name): void
    {
        if ($this->failure === 'model') {
            throw new RuntimeException('Falha simulada ao carregar invoices_model.');
        }
    }
}

final class InvoiceSmokeInvoices
{
    public function __construct(private InvoiceSmokeCi $ci) {}
    public function get(mixed $id): ?object
    {
        return $this->ci->invoice && (int) $id === (int) $this->ci->invoice->id ? $this->ci->invoice : null;
    }
}

final class InvoiceSmokeCi
{
    public InvoiceSmokeDatabase $db;
    public InvoiceSmokeLoader $load;
    public InvoiceSmokeInvoices $invoices_model;
    public object $input;
    public ?Asaas_gateway $asaas_gateway = null;
    public ?object $client;
    public ?object $invoice;
    public float $received = 0.0;
    public float $credits = 0.0;

    public function __construct()
    {
        $this->client = (object) [
            'userid' => 7, 'company' => 'Cliente teste', 'email' => 'customer@example.test',
            'vat' => '12345678901', 'zip' => '12345678', 'address' => 'Rua teste',
            'numero' => '10', 'complemento' => '', 'phonenumber' => '11999999999',
        ];
        $this->invoice = (object) [
            'id' => 42, 'clientid' => 7, 'client' => $this->client,
            'prefix' => 'INV-', 'number' => 42, 'hash' => INVOICE_SMOKE_HASH,
            'duedate' => '2026-12-15', 'total' => '149.90', 'subtotal' => '149.90', 'adjustment' => '0.00', 'recurring' => 0, 'status' => 1,
            'adminnote' => '', 'allowed_payment_modes' => serialize(['asaas']),
        ];
        $this->db = new InvoiceSmokeDatabase($this);
        $this->load = new InvoiceSmokeLoader();
        $this->invoices_model = new InvoiceSmokeInvoices($this);
        $this->input = new class {
            public function ip_address(): string { return '203.0.113.10'; }
        };
    }
}

final class InvoiceSmokeHooks
{
    public array $actions = [];
    public array $filters = [];
    public function add_action(string $name, callable $callback): void { $this->actions[$name][] = $callback; }
    public function add_filter(string $name, callable $callback): void { $this->filters[$name][] = $callback; }
}

$GLOBALS['invoice_smoke_ci'] = new InvoiceSmokeCi();
$GLOBALS['invoice_smoke_hooks'] = new InvoiceSmokeHooks();
$GLOBALS['invoice_smoke_options'] = [];

function &get_instance(): InvoiceSmokeCi { return $GLOBALS['invoice_smoke_ci']; }
function hooks(): InvoiceSmokeHooks { return $GLOBALS['invoice_smoke_hooks']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name): mixed { return $GLOBALS['invoice_smoke_options'][$name] ?? ''; }
function is_staff_logged_in(): bool { return $GLOBALS['invoice_smoke_staff']; }
function set_alert(string $type, string $message): void { $GLOBALS['invoice_smoke_alerts'][] = [$type, $message]; }
function log_message(string $level, string $message): void { $GLOBALS['invoice_smoke_framework_logs'][] = [$level, $message]; }
function log_activity(string $message): void
{
    $GLOBALS['invoice_smoke_activity'][] = $message;
    if ($GLOBALS['invoice_smoke_activity_failure']) {
        throw new RuntimeException('Falha simulada ao gravar atividade.');
    }
}
function html_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . $path; }
function is_https(): bool { return true; }
function admin_url(string $path = ''): string { return site_url('admin/' . $path); }
function format_invoice_number(mixed $id): string { return 'INV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function get_invoice_total_left_to_pay($id, $total = null): float
{
    $ci = get_instance();
    return max(0.0, (float) ($total ?? $ci->invoice->total) - $ci->received - $ci->credits);
}
function redirect(string $url): void { $GLOBALS['invoice_smoke_redirects'][] = $url; }
function register_activation_hook(string $module, callable $callback): void {}
function register_payment_gateway(string $gateway, string $module): void {}
function do_action_deprecated(): void {}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/Asaas_gateway.php';
require_once dirname(__DIR__) . '/asaas.php';

final class InvoiceSmokeProvider extends AsaasSdkProvider
{
    public function __construct(App_gateway $gateway, private SdkClient $mockClient)
    {
        parent::__construct($gateway);
    }
    public function client(?int $companyId = null): SdkClient { return $this->mockClient; }
    public function cardClient(?int $companyId = null): SdkClient { return $this->mockClient; }
}

function invoiceSmokeFixture(array $responses, array &$history): InvoiceSmokeCi
{
    asaas_receipt_lock_shutdown();
    $state = &asaas_lifecycle_state(); $state = [];
    $atomic = &asaas_atomic_payment_state(); $atomic = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
    $history = [];
    $GLOBALS['invoice_smoke_staff'] = true;
    $GLOBALS['invoice_smoke_alerts'] = [];
    $GLOBALS['invoice_smoke_framework_logs'] = [];
    $GLOBALS['invoice_smoke_activity'] = [];
    $GLOBALS['invoice_smoke_activity_failure'] = false;
    $GLOBALS['invoice_smoke_redirects'] = [];
    $GLOBALS['invoice_smoke_options'] = [];
    foreach ([
        'api_key' => INVOICE_SMOKE_KEY, 'api_key_sandbox' => INVOICE_SMOKE_KEY,
        'sandbox' => '1', 'debug' => '0', 'file_log_enabled' => '0',
        'webhook_secret' => '', 'billet_only' => '1', 'card_only' => '1', 'pix_only' => '1',
        'installmentCount' => '2',
        'update_charge' => '0', 'description' => 'Pagamento {invoice_number}',
        'interest_value' => '0', 'fine_value' => '0', 'discount_value' => '0',
        'discount_days' => '0', 'discount_type' => '1', 'disable_charge_notification' => '1', 'diasdevencimento' => '10',
    ] as $name => $value) {
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_' . $name] = $value;
    }
    $GLOBALS['invoice_smoke_ci'] = $ci = new InvoiceSmokeCi();
    $responses = array_map(static fn (mixed $body): mixed => is_array($body)
        ? new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))
        : $body, $responses);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $transport = new GuzzleClient(['base_uri' => Environment::Sandbox->value, 'handler' => $stack]);
    $sdk = new SdkClient(INVOICE_SMOKE_KEY, Environment::Sandbox, 'InvoiceDispatchSmoke/1.0', 30, 10, null, $transport);
    $ci->asaas_gateway = new Asaas_gateway();
    $provider = new InvoiceSmokeProvider($ci->asaas_gateway, $sdk);
    foreach (['provider' => $provider, 'adapter' => new AsaasAdapter($provider, $ci->asaas_gateway)] as $name => $value) {
        $property = new ReflectionProperty(Asaas_gateway::class, $name);
        $property->setAccessible(true);
        $property->setValue($ci->asaas_gateway, $value);
    }
    return $ci;
}

function invoiceSmokeCheck(bool $condition, string $message): void
{
    ++$GLOBALS['invoice_smoke_assertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invoiceSmokeLogs(InvoiceSmokeCi $ci): string
{
    return json_encode([$ci->db->logs, $GLOBALS['invoice_smoke_framework_logs']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function invoiceSmokePayload(array $history, int $index): array
{
    return json_decode((string) $history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}

function invoiceSmokeRequests(array $history): array
{
    return array_map(static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(), $history);
}

function invoiceSmokeReject(callable $operation, ?int $expectedStatus = null): AsaasInvoiceSyncException
{
    try { $operation(); }
    catch (AsaasInvoiceSyncException $error) {
        invoiceSmokeCheck(str_contains($error->getMessage(), 'bloqueado'), 'A rejeição deve explicar que a operação foi bloqueada.');
        if ($expectedStatus !== null) {
            invoiceSmokeCheck($error->getCode() === $expectedStatus, 'A rejeição deve preservar o status HTTP remoto.');
        }
        return $error;
    }
    throw new RuntimeException('Era esperada uma AsaasInvoiceSyncException no contrato CLI.');
}

function invoiceSmokeCardReject(callable $operation, int $expectedStatus): RuntimeException
{
    try { $operation(); }
    catch (RuntimeException $error) {
        invoiceSmokeCheck($error->getCode() === $expectedStatus, 'A rejeição do cartão deve preservar o status HTTP ou a validação local.');
        invoiceSmokeCheck($error->getPrevious() === null, 'O erro do cartão não deve expor a cadeia HTTP com credenciais.');
        return $error;
    }
    throw new RuntimeException('Era esperada uma rejeição segura do pagamento com cartão.');
}

function invoiceSmokeCheckout(InvoiceSmokeCi $ci, string $method, int $installments = 2)
{
    return $ci->asaas_gateway->$method(['invoice' => $ci->invoice, 'card' => [
        'installmentCount' => $installments, 'holderName' => 'Card fixture', 'number' => '4111111111111111',
        'expiryMonth' => '12', 'expiryYear' => '2030', 'cvv' => '123',
        'holderEmail' => 'cardholder@example.test', 'holderDocument' => '12345678909',
        'holderPostalCode' => '12345678', 'holderAddressNumber' => '10', 'holderPhone' => '11999999999',
    ]]);
}

$GLOBALS['invoice_smoke_assertions'] = 0;
$tests = [];
$customer = ['totalCount' => 1, 'data' => [['id' => 'cus_existing']]];
$payment = ['id' => 'pay_created', 'billingType' => 'BOLETO', 'status' => 'PENDING', 'value' => 149.90, 'dueDate' => '2026-12-15'];

$tests['registro nativo de hooks sem duplicar atualização'] = static function (): void {
    invoiceSmokeCheck(hooks()->actions['after_invoice_added'] === ['asaas_after_invoice_added'], 'O hook de criação deve permanecer registrado.');
    invoiceSmokeCheck(hooks()->actions['invoice_updated'] === ['asaas_after_invoice_updated'], 'Perfex moderno deve usar invoice_updated.');
    invoiceSmokeCheck(!isset(hooks()->actions['after_invoice_updated']), 'Não registrar o callback legado e moderno simultaneamente.');
};

$tests['criação automática usa gateway real e preserva a fatura'] = static function () use ($customer, $payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([$customer, $payment], $history);
    $before = serialize($ci->invoice);
    invoiceSmokeCheck(asaas_after_invoice_added(42) === 42, 'O ID da fatura deve ser preservado.');
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'POST /v3/payments'], 'Cliente e pagamento devem usar a API v3.');
    $payload = invoiceSmokePayload($history, 1);
    invoiceSmokeCheck($payload['billingType'] === 'BOLETO' && $payload['externalReference'] === INVOICE_SMOKE_HASH, 'O envio automático deve preservar BOLETO e o hash da fatura.');
    invoiceSmokeCheck(!isset($payload['authToken']), 'O token do webhook não pertence ao payload de cobrança.');
    invoiceSmokeCheck($before === serialize($ci->invoice), 'O envio não deve modificar a fatura local.');
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Sucesso não deve gerar alerta de erro.');
    invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), 'cobrança criada'), 'Sucesso deve produzir diagnóstico.');
};

$tests['modos em array e novo cliente usam referência escalar'] = static function () use ($payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([['totalCount' => 0, 'data' => []], ['id' => 'cus_new'], $payment], $history);
    $ci->invoice->allowed_payment_modes = ['asaas'];
    asaas_after_invoice_added(42);
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'POST /v3/customers', 'POST /v3/payments'], 'Deve cadastrar o cliente e criar uma única cobrança.');
    invoiceSmokeCheck(invoiceSmokePayload($history, 1)['externalReference'] === '7', 'A referência externa do cliente deve ser o clientid como string.');
    invoiceSmokeCheck(invoiceSmokePayload($history, 2)['externalReference'] === INVOICE_SMOKE_HASH, 'A referência externa da cobrança deve continuar sendo o hash.');
};

$tests['falhas no histórico após criar cliente e cobrança preservam o sucesso'] = static function () use ($payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([['totalCount' => 0, 'data' => []], ['id' => 'cus_new'], $payment], $history);
    $GLOBALS['invoice_smoke_activity_failure'] = true;
    $before = serialize($ci->invoice);

    invoiceSmokeCheck(asaas_after_invoice_added(42) === 42, 'A falha de histórico deve preservar o retorno do hook.');
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'POST /v3/customers', 'POST /v3/payments'], 'Falhar no histórico do cliente não deve interromper o pagamento nem repetir qualquer POST.');
    invoiceSmokeCheck(count($GLOBALS['invoice_smoke_activity']) === 2, 'O cenário deve provocar falha no histórico do cliente e da cobrança.');
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Uma cobrança aceita pelo Asaas não deve gerar danger por falha no histórico.');
    invoiceSmokeCheck(serialize($ci->invoice) === $before, 'A fatura local deve continuar preservada.');

    $confirmed = array_values(array_filter($ci->db->logs, static fn (array $row): bool => $row['message'] === 'invoice_added: cobrança criada.'));
    invoiceSmokeCheck(count($confirmed) === 1, 'O hook deve reconhecer exatamente uma criação confirmada.');
    $context = json_decode($confirmed[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
    invoiceSmokeCheck(($context['payment_id'] ?? null) === 'pay_created', 'O gateway deve devolver o id aceito mesmo quando log_activity lança.');
    invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), 'Falha ao gravar histórico de atividade'), 'A falha secundária de histórico deve permanecer diagnosticável.');
};

foreach (['boleto desabilitado', 'sem vencimento', 'forma não permitida', 'modos inválidos', 'fatura ausente'] as $reason) {
    $tests['criação não executada com diagnóstico: ' . $reason] = static function () use ($reason): void {
        $history = [];
        $ci = invoiceSmokeFixture([], $history);
        if ($reason === 'boleto desabilitado') {
            $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_billet_only'] = '0';
        } elseif ($reason === 'sem vencimento') {
            $ci->invoice->duedate = null;
        } elseif ($reason === 'forma não permitida') {
            $ci->invoice->allowed_payment_modes = serialize(['bank_transfer']);
        } elseif ($reason === 'modos inválidos') {
            $ci->invoice->allowed_payment_modes = 'not-serialized';
        } else {
            $ci->invoice = null;
        }
        if ($reason === 'fatura ausente') {
            invoiceSmokeReject(static fn () => asaas_after_invoice_added(42));
        } else {
            invoiceSmokeCheck(asaas_after_invoice_added(42) === 42, 'A saída deve preservar o ID.');
        }
        invoiceSmokeCheck($history === [], 'A regra existente deve impedir qualquer requisição.');
        invoiceSmokeCheck(count($ci->db->logs) > 0, 'O motivo de não envio deve ser registrado.');
        invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Ausência de envio por configuração não é falha de transporte.');
    };
}

$tests['edição cria cobrança ausente com update_charge desabilitado'] = static function () use ($customer, $payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([$customer, ['data' => []], $payment], $history);
    $ci->invoice->allowed_payment_modes = ['asaas'];
    invoiceSmokeCheck(asaas_after_invoice_updated(['id' => 42]) === 42, 'O hook moderno deve reconhecer id.');
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'GET /v3/payments', 'POST /v3/payments'], 'Atualização desabilitada não pode impedir a criação ausente.');
    $payload = invoiceSmokePayload($history, 2);
    invoiceSmokeCheck($payload['billingType'] === 'UNDEFINED' && $payload['externalReference'] === INVOICE_SMOKE_HASH, 'Preservar billingType e referência legados na edição.');
    invoiceSmokeCheck(($ci->db->payments[42]['asaas_payment_id'] ?? null) === 'pay_created', 'O vínculo do pagamento confirmado deve ser salvo.');
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Não deve haver erro no envio.');
};

$tests['edição preserva cobrança existente quando atualização está desabilitada'] = static function () use ($customer, $payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([$customer, ['data' => [$payment]]], $history);
    asaas_after_invoice_updated(['invoice_id' => 42]);
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'GET /v3/payments'], 'Não deve criar nem atualizar outra cobrança.');
    invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), 'cobrança existente preservada'), 'A opção de preservação deve aparecer no diagnóstico.');
};

$tests['edição atualiza a mesma cobrança e preserva seu billingType'] = static function () use ($customer, $payment): void {
    $history = [];
    $payment['billingType'] = 'PIX';
    $ci = invoiceSmokeFixture([$customer, ['data' => [$payment]], $payment], $history);
    $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_update_charge'] = '1';
    asaas_after_invoice_updated(42);
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'GET /v3/payments', 'POST /v3/payments/pay_created'], 'Atualizar deve reutilizar a cobrança e evitar novo POST na coleção.');
    invoiceSmokeCheck(invoiceSmokePayload($history, 2)['billingType'] === 'PIX', 'A edição deve manter o meio da cobrança existente.');
    invoiceSmokeCheck(invoiceSmokePayload($history, 2)['externalReference'] === INVOICE_SMOKE_HASH, 'O hash da fatura deve continuar no payload.');
};

foreach (['library', 'model'] as $dependency) {
    foreach (['added', 'updated'] as $event) {
        $tests['falha de carga ' . $dependency . ' em invoice_' . $event] = static function () use ($dependency, $event): void {
            $history = [];
            $ci = invoiceSmokeFixture([], $history);
            $ci->load->failure = $dependency;
            $before = serialize($ci->invoice);
            invoiceSmokeReject(static fn () => $event === 'added' ? asaas_after_invoice_added(42) : asaas_after_invoice_updated(['id' => 42]));
            invoiceSmokeCheck(serialize($ci->invoice) === $before, 'O teste de transporte não deve alterar seu objeto de fatura.');
            invoiceSmokeCheck($history === [], 'Falha na carga deve ser capturada antes do HTTP.');
            invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'CLI usa exceção, sem alerta em sessão.');
            invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), 'Falha simulada ao carregar'), 'O erro anteriormente fora do try deve aparecer no diagnóstico.');
        };
    }
}

foreach (['added', 'updated'] as $event) {
    $tests['erro HTTP no envio lança rejeição e não repete POST: ' . $event] = static function () use ($customer, $event): void {
        $history = [];
        $failure = new Response(422, ['Content-Type' => 'application/json'], '{"errors":[{"code":"invalid_value","description":"Valor de cobrança inválido."}]}');
        $responses = $event === 'added' ? [$customer, $failure] : [$customer, ['data' => []], $failure];
        $ci = invoiceSmokeFixture($responses, $history);
        $before = serialize($ci->invoice);
        invoiceSmokeReject(static fn () => $event === 'added' ? asaas_after_invoice_added(42) : asaas_after_invoice_updated(['id' => 42]), 422);
        invoiceSmokeCheck(serialize($ci->invoice) === $before, 'O objeto da fixture permanece disponível para inspecionar o erro.');
        $posts = array_filter(invoiceSmokeRequests($history), static fn (string $request): bool => $request === 'POST /v3/payments');
        invoiceSmokeCheck(count($posts) === 1, 'Não deve haver retry ou criação duplicada após erro HTTP.');
        invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'CLI interrompe por exceção sem depender de flash de sessão.');
        invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), '422') && str_contains(invoiceSmokeLogs($ci), 'Valor de cobrança inválido'), 'O diagnóstico deve conservar status e descrição úteis da API.');
        invoiceSmokeCheck(!str_contains(invoiceSmokeLogs($ci), INVOICE_SMOKE_KEY), 'O diagnóstico nunca deve registrar a API Key.');
    };
}

$tests['resposta sem id não é tratada como cobrança criada'] = static function () use ($customer): void {
    $history = [];
    $ci = invoiceSmokeFixture([$customer, []], $history);
    $error = invoiceSmokeReject(static fn () => asaas_after_invoice_added(42));
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Resposta inválida em CLI é uma exceção, sem flash de sessão.');
    $cause = $error->getPrevious(); $identified = false;
    foreach ($ci->db->logs as $row) {
        $context = json_decode((string) ($row['context_json'] ?? $row['context'] ?? '{}'), true);
        if (($row['level'] ?? '') === 'error' && is_array($context)
            && ($context['invoice_id'] ?? null) === 42 && ($context['exception'] ?? null) === RuntimeException::class
            && $cause instanceof RuntimeException && ($context['message'] ?? null) === asaas_diagnostic_sanitize($cause->getMessage())) {
            $identified = true;
        }
    }
    invoiceSmokeCheck($identified, 'Diagnóstico deve identificar a fatura e a causa da resposta inválida, sem depender da frase literal do gateway.');
    invoiceSmokeCheck(count($history) === 2, 'Não tentar criar novamente depois de resposta ambígua.');
};

$tests['checkout process_payment usa os mesmos endpoints e preserva redirecionamento'] = static function () use ($customer, $payment): void {
    $history = [];
    $ci = invoiceSmokeFixture([['data' => []], $customer, $payment], $history);
    $ci->asaas_gateway->process_payment(['invoice' => $ci->invoice]);
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/payments', 'GET /v3/customers', 'POST /v3/payments'], 'Checkout deve consultar e criar pelos endpoints v3.');
    invoiceSmokeCheck(invoiceSmokePayload($history, 2)['externalReference'] === INVOICE_SMOKE_HASH, 'Checkout deve usar o hash existente.');
    invoiceSmokeCheck($GLOBALS['invoice_smoke_redirects'] === [site_url('asaas/checkout/index/' . INVOICE_SMOKE_HASH)], 'O redirecionamento original do checkout deve ser mantido.');
};

foreach (['process_payment', 'charge_billet', 'charge_pix'] as $method) {
    $tests['diagnóstico habilitado não expõe cadastro ou cobrança em ' . $method] = static function () use ($method, $payment): void {
        $responses = [['totalCount' => 0, 'data' => []], ['id' => 'cus_new_checkout'], $payment];
        if ($method === 'process_payment') {
            array_unshift($responses, ['data' => []]);
        }
        $history = [];
        $ci = invoiceSmokeFixture($responses, $history);
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_debug'] = '1';
        ob_start();
        try {
            $ci->asaas_gateway->$method($method === 'charge_billet' ? $ci->invoice : ['invoice' => $ci->invoice]);
        } finally {
            $output = ob_get_clean();
        }
        $requests = invoiceSmokeRequests($history);
        invoiceSmokeCheck(in_array('POST /v3/customers', $requests, true) && in_array('POST /v3/payments', $requests, true), 'Exercer o cadastro real do cliente e criação da cobrança pelo gateway/SDK.');
        invoiceSmokeCheck($output === '', 'O diagnóstico não pode imprimir cadastro do cliente, retorno da API ou dados financeiros na resposta pública.');
        invoiceSmokeCheck($ci->db->_trans_depth === 0, 'Criação deve finalizar a transação normalmente.');
    };
}

foreach ([['vencida', 4, 0, 'OVERDUE'], ['vencida recorrente', 4, 1, 'OVERDUE'], ['aberta', 1, 0, 'PENDING']] as [$label, $invoiceStatus, $recurring, $paymentStatus]) {
    $tests['Pagar agora preserva boleto existente de fatura ' . $label . ' e GET abre URL original'] = static function () use ($payment, $invoiceStatus, $recurring, $paymentStatus): void {
        $existing = array_replace($payment, [
            'id' => 'pay_checkout_existing', 'status' => $paymentStatus,
            'dueDate' => date('Y-m-d', strtotime($invoiceStatus === 4 ? '-2 days' : '+2 days')),
            'customer' => 'cus_existing', 'externalReference' => INVOICE_SMOKE_HASH,
            'canBePaidAfterDueDate' => true,
            'fine' => ['value' => 2], 'interest' => ['value' => 1],
            'bankSlipUrl' => 'https://www.asaas.com/b/pdf/pay_checkout_existing',
        ]);
        $history = [];
        $ci = invoiceSmokeFixture([['data' => [$existing]], ['data' => [$existing]]], $history);
        $ci->invoice->status = $invoiceStatus;
        $ci->invoice->recurring = $recurring;
        $ci->invoice->duedate = $existing['dueDate'];
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_debug'] = '1';
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_fine_value'] = '2';
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_interest_value'] = '1';
        $before = serialize($ci->invoice);
        $clientBefore = serialize($ci->client);

        ob_start();
        try {
            $ci->asaas_gateway->process_payment(['invoice' => $ci->invoice]);
            invoiceSmokeCheck($GLOBALS['invoice_smoke_redirects'] === [site_url('asaas/checkout/index/' . INVOICE_SMOKE_HASH)], 'Pagar agora deve abrir a escolha de pagamento com a cobrança já existente.');
            $controller = (new ReflectionClass(Checkout::class))->newInstanceWithoutConstructor();
            $controller->db = $ci->db;
            $controller->asaas_gateway = $ci->asaas_gateway;
            $controller->boleto(INVOICE_SMOKE_HASH);
        } finally {
            $output = ob_get_clean();
        }

        invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/payments', 'GET /v3/payments'], 'Pagar agora e abrir boleto existente só consultam; não devem fazer POST, DELETE, mudar vencimento ou recriar cobrança.');
        invoiceSmokeCheck($GLOBALS['invoice_smoke_redirects'][1] === $existing['bankSlipUrl'], 'O boleto vencido pagável deve usar a URL original do Asaas, mantendo os juros/multa remotos.');
        invoiceSmokeCheck(serialize($ci->invoice) === $before, 'Abrir pagamento preserva vencimento, subtotal, total, ajuste e status da fatura.');
        invoiceSmokeCheck(serialize($ci->client) === $clientBefore, 'Abrir cobrança existente não altera cliente ou contato.');
        invoiceSmokeCheck(!array_filter($ci->db->writes, static fn (array $write): bool => $write['table'] === 'tblinvoices'), 'A apresentação do boleto não deve escrever na fatura.');
        invoiceSmokeCheck($ci->db->_trans_depth === 0 && empty(asaas_lifecycle_state()['checkout_invoice']), 'A abertura deve liberar a transação e o contexto do checkout.');
        invoiceSmokeCheck($output === '', 'Debug habilitado não pode imprimir SQL, valor, payload ou dados pessoais no pagamento.');
    };
}

$tests['edição legítima mantém erro HTTP 400 de vencimento e não o transforma em sucesso'] = static function () use ($customer, $payment): void {
    $existing = array_replace($payment, ['externalReference' => INVOICE_SMOKE_HASH]);
    $error = new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'errors' => [['code' => 'invalid_dueDate', 'description' => 'A data mínima de vencimento para novas cobranças é hoje.']],
    ], JSON_THROW_ON_ERROR));
    $history = [];
    $ci = invoiceSmokeFixture([$customer, ['data' => [$existing]], $error], $history);
    $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_update_charge'] = '1';
    $before = serialize($ci->invoice);
    invoiceSmokeReject(static fn () => asaas_after_invoice_updated(42), 400);
    invoiceSmokeCheck(invoiceSmokeRequests($history) === ['GET /v3/customers', 'GET /v3/payments', 'POST /v3/payments/pay_created'], 'Uma edição real continua enviando um único POST para a cobrança e respeitando sua recusa.');
    invoiceSmokeCheck(serialize($ci->invoice) === $before && $ci->db->_trans_depth === 0, 'Recusa de edição deve preservar a fatura e fechar a transação.');
    invoiceSmokeCheck($GLOBALS['invoice_smoke_redirects'] === [], 'Erro em edição não pode redirecionar como sucesso.');
    invoiceSmokeCheck(str_contains(invoiceSmokeLogs($ci), 'data mínima de vencimento'), 'O motivo da recusa deve permanecer disponível no diagnóstico.');
};

foreach (['process_payment', 'charge_pix', 'charge_credit_card'] as $method) {
    $tests['checkout ' . $method . ($method === 'charge_credit_card' ? ' preserva' : ' restaura') . ' fatura vencida após HTTP 400'] = static function () use ($customer, $payment, $method): void {
        $existing = array_replace($payment, ['id' => 'pay_checkout_existing', 'status' => 'OVERDUE',
            'customer' => 'cus_existing', 'description' => 'Original charge', 'externalReference' => INVOICE_SMOKE_HASH]);
        $error = new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'errors' => [['code' => 'invalid_value', 'description' => 'Cobrança recusada no transporte de teste.']],
        ], JSON_THROW_ON_ERROR));
        $responses = $method === 'process_payment'
            ? [['data' => []], $customer, $error]
            : [$customer, ['data' => [$existing]], $error];
        if ($method === 'charge_credit_card') {
            $responses = [['data' => [$existing]], array_replace($existing, ['billingType' => 'CREDIT_CARD']), $error, $existing];
        }
        $history = []; $ci = invoiceSmokeFixture($responses, $history);
        $ci->invoice->status = 4; $ci->invoice->duedate = date('Y-m-d', strtotime('-2 days'));
        $GLOBALS['invoice_smoke_options']['paymentmethod_asaas_fine_value'] = '2';
        $before = serialize($ci->invoice); $clientBefore = serialize($ci->client);
        if ($method === 'charge_credit_card') {
            $rejection = invoiceSmokeCardReject(static fn () => invoiceSmokeCheckout($ci, $method, 1), 400);
            invoiceSmokeCheck($rejection instanceof \Asaas\AsaasCardCheckoutException, 'Recusa HTTP do cartão deve retornar o erro público controlado.');
        } else {
            invoiceSmokeReject(static fn () => invoiceSmokeCheckout($ci, $method), 400);
        }
        invoiceSmokeCheck(serialize($ci->invoice) === $before, 'HTTP 400 deve restaurar total, subtotal, multa, status e vencimento.');
        invoiceSmokeCheck(serialize($ci->client) === $clientBefore, 'Checkout recusado deve preservar cliente e contato.');
        $writes = array_values(array_filter($ci->db->writes, static fn (array $write): bool => $write['table'] === 'tblinvoices'));
        if ($method === 'charge_credit_card') {
            invoiceSmokeCheck($writes === [], 'Cartão recusado não deve regravar total, vencimento, multa ou status da fatura local.');
            invoiceSmokeCheck(invoiceSmokeRequests($history) === [
                'GET /v3/payments', 'POST /v3/payments/pay_checkout_existing',
                'POST /v3/payments/pay_checkout_existing/payWithCreditCard', 'POST /v3/payments/pay_checkout_existing',
            ], 'O cartão deve pagar a cobrança existente uma vez e restaurar seu meio após recusa definitiva.');
            $cached = json_decode($ci->db->payments[42]['payload_cache'], true, 512, JSON_THROW_ON_ERROR);
            invoiceSmokeCheck($ci->db->_trans_depth === 0 && $cached['_asaas_lifecycle']['card_attempt']['state'] === 'declined', 'A recusa deve deixar o marcador durável sem transação aberta.');
            invoiceSmokeCheck($cached['id'] === $existing['id'] && $cached['billingType'] === 'BOLETO', 'A cobrança anterior deve continuar vinculada após compensar a recusa do cartão.');
        } else {
            invoiceSmokeCheck(count($writes) === 1 && $writes[0]['depth'] > 0, 'O teste deve exercer uma atualização real da fixture dentro da transação.');
            invoiceSmokeCheck($writes[0]['payload']['total'] > 149.90 && $writes[0]['payload']['duedate'] !== $ci->invoice->duedate, 'A atualização tentada deve alterar valor e vencimento antes de ser revertida.');
            $expectedPost = $method === 'process_payment' ? 'POST /v3/payments' : 'POST /v3/payments/pay_checkout_existing';
            invoiceSmokeCheck(count($history) === 3 && invoiceSmokeRequests($history)[2] === $expectedPost, 'Executar a requisição real do SDK e não repetir POST recusado.');
            invoiceSmokeCheck($ci->db->_trans_depth === 0 && $ci->db->payments === [], 'Recusa remota não deixa transação nem vínculo novo.');
        }
        invoiceSmokeCheck($GLOBALS['invoice_smoke_redirects'] === [], 'Não redirecionar como sucesso após erro da cobrança.');
    };
}

foreach (['process_payment', 'charge_pix', 'charge_credit_card'] as $method) {
    $tests['checkout ' . $method . ' rejeita R$ 1 no payload antes de POST'] = static function () use ($customer, $method): void {
        $responses = $method === 'process_payment' ? [['data' => []], $customer] : [$customer];
        if ($method === 'charge_credit_card') { $responses = []; }
        $history = []; $ci = invoiceSmokeFixture($responses, $history);
        $ci->invoice->total = '1.00'; $ci->invoice->subtotal = '1.00';
        $before = serialize($ci->invoice);
        if ($method === 'charge_credit_card') {
            invoiceSmokeCardReject(static fn () => invoiceSmokeCheckout($ci, $method), 400);
            invoiceSmokeCheck($history === [], 'O cartão de R$ 1 deve ser recusado antes de consultas ou mutações remotas.');
        } else {
            invoiceSmokeReject(static fn () => invoiceSmokeCheckout($ci, $method), 400);
        }
        invoiceSmokeCheck(!array_filter($history, static fn (array $entry): bool => $entry['request']->getMethod() === 'POST'), 'Valor mínimo inválido não chega ao endpoint de criação.');
        invoiceSmokeCheck(serialize($ci->invoice) === $before, 'Validação do payload preserva a fatura.');
        invoiceSmokeCheck($ci->db->_trans_depth === 0 && $ci->db->payments === [], 'Validação fecha a transação sem gravar cobrança.');
    };
}

$tests['checkout cartão usa saldo líquido e não persiste dados sensíveis'] = static function () use ($payment): void {
    $history = [];
    $first = array_replace($payment, ['id' => 'pay_card_checkout', 'billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED',
        'value' => 50.0, 'customer' => 'cus_checkout', 'externalReference' => INVOICE_SMOKE_HASH,
        'installment' => 'ins_card_checkout', 'installmentNumber' => 1,
        'creditCard' => ['creditCardToken' => 'returned-card-token-must-not-persist', 'creditCardNumber' => '1111']]);
    $second = array_replace($first, ['id' => 'pay_card_checkout_2', 'installmentNumber' => 2]);
    $ci = invoiceSmokeFixture([['data' => []], ['totalCount' => 0, 'data' => []], ['id' => 'cus_checkout'],
        $first, ['data' => [$first, $second], 'hasMore' => false, 'totalCount' => 2]], $history);
    $ci->received = 39.90; $ci->credits = 10.00;
    $before = serialize($ci->invoice); $clientBefore = serialize($ci->client);
    $result = invoiceSmokeCheckout($ci, 'charge_credit_card');
    $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    invoiceSmokeCheck($result['id'] === 'pay_card_checkout', 'Cartão preserva o contrato de retorno JSON.');
    invoiceSmokeCheck(invoiceSmokeRequests($history) === [
        'GET /v3/payments', 'GET /v3/customers', 'POST /v3/customers', 'POST /v3/payments',
        'GET /v3/installments/ins_card_checkout/payments',
    ], 'Cadastro, cobrança única e consulta das parcelas usam o SDK real com transporte controlado.');
    $customerPayload = invoiceSmokePayload($history, 2); $payload = invoiceSmokePayload($history, 3);
    invoiceSmokeCheck($customerPayload['externalReference'] === '7', 'Cliente novo usa clientid escalar como referência externa.');
    invoiceSmokeCheck($customerPayload['notificationDisabled'] === (get_option('paymentmethod_asaas_disable_charge_notification') === '1'), 'Cliente novo deve respeitar a configuração existente de notificações Asaas.');
    invoiceSmokeCheck($customerPayload['email'] === $ci->client->email && $customerPayload['phone'] === $ci->client->phonenumber && $customerPayload['mobilePhone'] === $ci->client->phonenumber, 'Preservar o email e os telefones do contato atual.');
    invoiceSmokeCheck((float) $payload['totalValue'] === 100.0 && $payload['installmentCount'] === 2
        && !array_key_exists('value', $payload) && !array_key_exists('installmentValue', $payload), 'Parcelas usam totalValue do saldo após recebimentos e créditos, sem combinar os contratos monetários.');
    invoiceSmokeCheck(count($result['_asaas_card_receipts']) === 2
        && array_column($result['_asaas_card_receipts'], 'value') === [50, 50], 'As duas parcelas confirmadas de R$ 50 devem ser conferidas individualmente.');
    invoiceSmokeCheck($payload['billingType'] === 'CREDIT_CARD' && $payload['externalReference'] === INVOICE_SMOKE_HASH, 'Preservar modalidade e hash da fatura.');
    invoiceSmokeCheck(serialize($ci->invoice) === $before && serialize($ci->client) === $clientBefore, 'Checkout não regrava dados de cliente nem total original da fatura.');
    invoiceSmokeCheck(!array_filter($ci->db->writes, static fn (array $write): bool => str_contains($write['table'], 'ac_a_ccta')), 'Não inserir cartão na tabela legada ac_a_ccta.');
    $stored = json_encode([$ci->db->logs, $ci->db->payments, $GLOBALS['invoice_smoke_framework_logs']], JSON_THROW_ON_ERROR);
    invoiceSmokeCheck(!str_contains($stored, '4111111111111111') && !str_contains($stored, '"creditCard"') && !str_contains($stored, '"ccv"'), 'PAN e CVV não entram no mapa nem no diagnóstico persistido.');
    invoiceSmokeCheck(!str_contains($stored, 'returned-card-token-must-not-persist')
        && !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'returned-card-token-must-not-persist'), 'O token devolvido pelo Asaas não pode entrar no cache nem no retorno público do gateway.');
    invoiceSmokeCheck($ci->db->_trans_depth === 0, 'Checkout confirma e fecha sua transação antes de retornar.');
};

$tests['falha sem sessão de staff não cria alerta de interface'] = static function (): void {
    $history = [];
    $ci = invoiceSmokeFixture([], $history);
    $GLOBALS['invoice_smoke_staff'] = false;
    $ci->load->failure = 'library';
    invoiceSmokeReject(static fn () => asaas_after_invoice_added(42));
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'Rotina sem staff deve apenas registrar o erro.');
    invoiceSmokeCheck(count($ci->db->logs) > 0, 'A ausência de sessão não deve esconder o diagnóstico.');
};

// Keep CRON last because constants cannot be reset between scenarios.
$tests['falha no CRON é diagnosticada sem flash de sessão'] = static function (): void {
    define('CRON', true);
    $history = [];
    $ci = invoiceSmokeFixture([], $history);
    $ci->load->failure = 'model';
    invoiceSmokeReject(static fn () => asaas_after_invoice_updated(42));
    invoiceSmokeCheck($GLOBALS['invoice_smoke_alerts'] === [], 'CRON não deve criar alerta em sessão.');
    invoiceSmokeCheck(count($ci->db->logs) > 0, 'CRON deve registrar a falha.');
};

$failures = 0; $executed = 0;
foreach ($tests as $name => $test) {
    if (in_array('--checkout', $argv, true) && !str_starts_with($name, 'checkout ')) { continue; }
    ++$executed;
    try {
        $test();
        echo 'OK  ', $name, PHP_EOL;
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        if (in_array('--debug', $argv, true)) {
            fwrite(STDERR, invoiceSmokeLogs(get_instance()) . PHP_EOL);
        }
    }
}
asaas_receipt_lock_shutdown();
echo 'Invoice dispatch smoke: ', $executed, ' cenários executados de ', count($tests), ', ', $GLOBALS['invoice_smoke_assertions'], ' asserções, ', $failures, ' falhas.', PHP_EOL;
exit($failures === 0 ? 0 : 1);
