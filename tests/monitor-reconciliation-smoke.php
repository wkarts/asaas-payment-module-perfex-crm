<?php

declare(strict_types=1);

/** Offline SDK/MockHandler + real gateway receipt path; SQLite persistence and traced MySQL locks. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$temporaryRoot = sys_get_temp_dir() . '/asaas-monitor-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/modules', 0700, true);
symlink(dirname(__DIR__), $temporaryRoot . '/modules/asaas');
register_shutdown_function(static function () use ($temporaryRoot): void {
    unlink($temporaryRoot . '/modules/asaas'); rmdir($temporaryRoot . '/modules'); rmdir($temporaryRoot);
});
define('BASEPATH', __DIR__);
define('FCPATH', $temporaryRoot . '/');
const MONITOR_API_FIXTURE = '$aact_monitor_public_fixture';
$GLOBALS['monitor_options'] = [];
$GLOBALS['monitor_assertions'] = 0;
$GLOBALS['monitor_scenarios'] = 0;
$GLOBALS['monitor_failures'] = 0;
$GLOBALS['monitor_logs'] = [];
$GLOBALS['monitor_admin'] = true;
function &get_instance(): object { return $GLOBALS['monitor_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name): mixed { return $GLOBALS['monitor_options'][$name] ?? ''; }
function add_option(string $name, mixed $value, int $autoload = 1): bool
{
    if (array_key_exists($name, $GLOBALS['monitor_options'])) { return false; }
    $GLOBALS['monitor_options'][$name] = $value; return true;
}
function update_option(string $name, mixed $value): bool { $GLOBALS['monitor_options'][$name] = $value; return true; }
function log_message(string $level, string $message): void { $GLOBALS['monitor_logs'][] = $message; }
function html_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . $path; }
function admin_url(string $path = ''): string { return site_url('admin/' . $path); }
function format_invoice_number($invoice): string { return 'FAT-' . (is_object($invoice) ? $invoice->id : $invoice); }
function is_admin(): bool { return $GLOBALS['monitor_admin']; }
function get_invoice_total_left_to_pay($id, $total = null): string
{
    $GLOBALS['monitor_balance_calls'] = ($GLOBALS['monitor_balance_calls'] ?? 0) + 1;
    $db = get_instance()->db;
    $amount = asaas_payment_amount_to_cents($total);
    foreach (['SELECT amount FROM tblinvoicepaymentrecords WHERE invoiceid = ?', 'SELECT amount FROM tblcredits WHERE invoice_id = ?'] as $sql) {
        foreach ($db->query($sql, [$id])->result_array() as $row) { $amount -= asaas_payment_amount_to_cents($row['amount']); }
    }
    return asaas_card_money(max(0, $amount));
}

class App_gateway
{
    public array $settings = ['sandbox' => '1', 'active' => '1', 'debug' => '0'];
    public array $nativeCalls = [];
    public int $failReceiptAt = 0;
    public bool $failVerificationAfterNative = false;
    public function __construct() {}
    public function setId(string $id): void {}
    public function setName(string $name): void {}
    public function setSettings(array $settings): void {}
    public function getSetting(string $name): mixed { return $this->settings[$name] ?? ''; }
    public function decryptSetting(string $name): string { return MONITOR_API_FIXTURE; }
    public function addPayment($data)
    {
        monitorCheck((asaas_lifecycle_state()['remote_payment_depth'] ?? 0) > 0, 'Receipt must preserve remote origin.');
        $this->nativeCalls[] = $data;
        if ($this->failReceiptAt === count($this->nativeCalls)) { return false; }
        get_instance()->db->insert('tblinvoicepaymentrecords', $data);
        $invoice = get_instance()->invoices_model->get($data['invoiceid']);
        if (get_invoice_total_left_to_pay($invoice->id, $invoice->total) === '0.00') {
            get_instance()->db->query('UPDATE tblinvoices SET status = 2 WHERE id = ?', [$invoice->id]);
        }
        if ($this->failVerificationAfterNative) {
            get_instance()->db->failSqlPrefixes[] = 'SELECT invoiceid, transactionid, amount FROM tblinvoicepaymentrecords';
        }
        return true;
    }
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/Asaas_gateway.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasReconciliation.php';

use Asaas\AsaasReconciliation;
use Asaas\AsaasAdapter;
use Asaas\Sdk\Http\Client as SdkClient;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

final class MonitorResult
{
    public function __construct(private array $rows = []) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function row(): ?object { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
    public function result_array(): array { return $this->rows; }
}

final class MonitorDatabase
{
    protected int $_trans_depth = 0;
    public bool $db_debug = true;
    public bool $trans_enabled = true;
    public string $dbdriver = 'mysqli';
    public string $subdriver = '';
    public string $hostname = 'offline';
    public string $database = 'checkout_receipt_fixture';
    public PDO $conn_id;
    public array $trace = [];
    public array $failSqlPrefixes = [];
    public bool $held = false;
    public bool $denyCronLock = false;
    public array $locks = [];
    private array $whereValues = [];
    public bool $failCommit = false;
    public ?Closure $onLock = null;
    private bool $status = true;
    private int $affected = 0;

    public function __construct()
    {
        $this->conn_id = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->conn_id->sqliteCreateFunction('regexp', static fn ($pattern, $value): int => preg_match('~' . str_replace('~', '\\~', $pattern) . '~D', (string) $value) === 1 ? 1 : 0, 2);
        $this->conn_id->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total TEXT, status TEXT, hash TEXT, clientid INTEGER, date TEXT, duedate TEXT, number INTEGER, number_format INTEGER, prefix TEXT, allowed_payment_modes TEXT)');
        $this->conn_id->exec('CREATE TABLE tblinvoicepaymentrecords (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, amount TEXT, paymentmode TEXT, paymentmethod TEXT, transactionid TEXT, note TEXT, date TEXT)');
        $this->conn_id->exec('CREATE TABLE tblcredits (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount TEXT)');
        $this->conn_id->exec('CREATE TABLE tblclients (userid INTEGER PRIMARY KEY, company TEXT, asaas_customer_id TEXT, vat TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_customers_map (perfex_client_id INTEGER UNIQUE, asaas_customer_id TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_payments_map (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER UNIQUE, asaas_payment_id TEXT, payload_cache TEXT, status TEXT, value TEXT, due_date TEXT, billing_type TEXT, last_sync_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_webhook_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT, event_type TEXT, asaas_payment_id TEXT, invoice_id INTEGER, received_at TEXT, processed_at TEXT, process_status TEXT, error_message TEXT, payload TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    }
    public function depth(): int { return $this->_trans_depth; }
    public function query(string $sql, array $bindings = []): mixed
    {
        $this->trace[] = [$sql, $this->_trans_depth, $this->held];
        foreach ($this->failSqlPrefixes as $index => $prefix) {
            if (str_starts_with($sql, $prefix)) { unset($this->failSqlPrefixes[$index]); $this->status = false; return false; }
        }
        if (in_array($sql, ['SELECT GET_LOCK(?, 5) AS acquired', 'SELECT GET_LOCK(?, 0) AS acquired'], true)) {
            if ($this->denyCronLock && str_contains($bindings[0], 'asaas_reconcile_')) { return new MonitorResult([['acquired' => 0]]); }
            $this->locks[$bindings[0]] = true;
            if ($this->onLock !== null) { $callback = $this->onLock; $this->onLock = null; $callback(); }
            $this->held = true;
            return new MonitorResult([['acquired' => 1]]);
        }
        if ($sql === 'SELECT RELEASE_LOCK(?) AS released') {
            unset($this->locks[$bindings[0]]);
            $this->held = $this->locks !== [];
            return new MonitorResult([['released' => 1]]);
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return new MonitorResult(array_map(static fn (string $table): array => ['table_name' => $table, 'engine' => 'InnoDB'], $bindings));
        }
        try {
            $statement = $this->conn_id->prepare(preg_replace('/ FOR UPDATE$/', '', $sql));
            $statement->execute($bindings);
            $this->affected = $statement->rowCount();
            return str_starts_with($sql, 'SELECT ') ? new MonitorResult($statement->fetchAll(PDO::FETCH_ASSOC)) : true;
        } catch (Throwable $error) {
            $this->status = false;
            if ($this->db_debug) { throw $error; }
            return false;
        }
    }
    public function get_where(string $table, array $where, ?int $limit = null): mixed
    {
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($where)));
        return $this->query($sql . ($limit === null ? '' : ' LIMIT ' . $limit), array_values($where));
    }
    public function insert(string $table, array $data): bool
    {
        return $this->query('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data)) !== false;
    }
    public function where(string $field, mixed $value): self { $this->whereValues[$field] = $value; return $this; }
    public function update(string $table, array $data): bool
    {
        $where = $this->whereValues;
        $this->whereValues = [];
        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn ($key): string => $key . ' = ?', array_keys($data)))
            . ' WHERE ' . implode(' AND ', array_map(static fn ($key): string => $key . ' = ?', array_keys($where)));
        return $this->query($sql, array_merge(array_values($data), array_values($where))) !== false;
    }
    public function insert_id(): int { return (int) $this->conn_id->lastInsertId(); }
    public function affected_rows(): int { return $this->affected; }
    public function table_exists(string $table): bool
    {
        $query = $this->conn_id->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"); $query->execute([$table]);
        return $query->fetchColumn() !== false;
    }
    public function list_fields(string $table): array { return array_column($this->conn_id->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'); }
    public function trans_status(): bool { return $this->status; }
    public function trans_begin(): bool
    {
        $this->trace[] = ['BEGIN', $this->_trans_depth, $this->held];
        if ($this->_trans_depth > 0) { ++$this->_trans_depth; return true; }
        if ($this->conn_id->beginTransaction()) { ++$this->_trans_depth; $this->status = true; return true; }
        return false;
    }
    public function trans_commit(): bool
    {
        $this->trace[] = ['COMMIT', $this->_trans_depth, $this->held];
        if ($this->failCommit || $this->_trans_depth === 0) { return false; }
        if ($this->_trans_depth > 1 || $this->conn_id->commit()) { --$this->_trans_depth; return true; }
        return false;
    }
    public function trans_rollback(): bool
    {
        $this->trace[] = ['ROLLBACK', $this->_trans_depth, $this->held];
        if ($this->_trans_depth === 0) { return false; }
        if ($this->_trans_depth > 1 || $this->conn_id->rollBack()) { --$this->_trans_depth; return true; }
        return false;
    }
}


final class MonitorProvider extends AsaasSdkProvider
{
    public function __construct(App_gateway $gateway, private SdkClient $transport) { parent::__construct($gateway); }
    public function client(?int $companyId = null): SdkClient { return $this->transport; }
    public function reconciliationClient(?int $companyId = null): SdkClient { return $this->transport; }
}
final class MonitorInvoiceModel
{
    public function get($id): ?object { return get_instance()->db->get_where('tblinvoices', ['id' => $id])->row(); }
}
final class MonitorLoader
{
    public int $libraries = 0;
    public function model(string $name): void {}
    public function library(string $name): void { ++$this->libraries; }
}
function monitorPayment(int $invoiceId = 42, array $changes = []): array
{
    return array_replace(['id' => 'pay_' . $invoiceId, 'customer' => 'cus_fixture', 'externalReference' => 'hash-' . $invoiceId,
        'billingType' => 'PIX', 'status' => 'CONFIRMED', 'value' => '100.00', 'deleted' => false], $changes);
}
function monitorResponse(array $body, int $status = 200): Response { return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)); }
function monitorPaymentList(array $payments, bool $more = false, ?int $total = null): Response
{
    return monitorResponse(['data' => $payments, 'hasMore' => $more] + ($total === null ? [] : ['totalCount' => $total]));
}
function monitorUnlink(MonitorDatabase $db, int $id = 42): void
{
    $db->query('DELETE FROM tblasaas_payments_map WHERE invoice_id = ?', [$id]);
}
function monitorUnknownCustomer(MonitorDatabase $db): void
{
    $db->query('DELETE FROM tblasaas_customers_map');
    $db->query('UPDATE tblclients SET asaas_customer_id = NULL, vat = ?', ['12.345.678/0001-95']);
}
function monitorCustomer(array $changes = []): array
{
    return array_replace(['id' => 'cus_fixture', 'externalReference' => '7', 'cpfCnpj' => '12345678000195', 'deleted' => false], $changes);
}
function monitorInvoice(MonitorDatabase $db, int $id, array $cacheChanges = [], array $invoiceChanges = []): void
{
    $db->insert('tblinvoices', array_replace(['id' => $id, 'total' => '100.00', 'status' => '1', 'hash' => 'hash-' . $id,
        'clientid' => 7, 'date' => date('Y-m-d'), 'duedate' => date('Y-m-d'), 'number' => $id, 'number_format' => 1, 'prefix' => 'FAT-', 'allowed_payment_modes' => serialize(['asaas'])], $invoiceChanges));
    $payment = monitorPayment($id, array_replace(['status' => 'PENDING'], $cacheChanges));
    $db->insert('tblasaas_payments_map', ['invoice_id' => $id, 'asaas_payment_id' => $payment['id'], 'payload_cache' => json_encode($payment),
        'status' => $payment['status'], 'value' => $payment['value'], 'billing_type' => $payment['billingType'], 'last_sync_at' => null]);
}
function monitorFixture(array $responses, ?array &$history, array $ids = [42]): array
{
    $history = [];
    $GLOBALS['monitor_options'] = [];
    $GLOBALS['monitor_logs'] = [];
    $GLOBALS['monitor_admin'] = true;
    $state = &asaas_lifecycle_state(); $state = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
    $db = new MonitorDatabase();
    $db->insert('tblclients', ['userid' => 7, 'company' => 'Cliente de teste', 'asaas_customer_id' => 'cus_fixture']);
    $db->insert('tblasaas_customers_map', ['perfex_client_id' => 7, 'asaas_customer_id' => 'cus_fixture']);
    foreach ($ids as $id) { monitorInvoice($db, $id); }
    $GLOBALS['monitor_ci'] = (object) ['db' => $db, 'load' => new MonitorLoader(), 'invoices_model' => new MonitorInvoiceModel(), 'payments_model' => new stdClass()];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $http = new GuzzleClient(['handler' => $stack, 'base_uri' => Environment::Sandbox->value]);
    $client = new SdkClient(MONITOR_API_FIXTURE, Environment::Sandbox, 'MonitorSmoke/1.0', 30, 10, null, $http);
    $gateway = new Asaas_gateway();
    $provider = new MonitorProvider($gateway, $client);
    foreach (['provider' => $provider, 'adapter' => new AsaasAdapter($provider, $gateway)] as $property => $value) {
        $reflection = new ReflectionProperty(Asaas_gateway::class, $property);
        $reflection->setValue($gateway, $value);
    }
    get_instance()->asaas_gateway = $gateway;
    return [new AsaasReconciliation(get_instance(), $gateway), $gateway, $db];
}
function monitorCheck(bool $ok, string $message): void
{
    ++$GLOBALS['monitor_assertions'];
    if (!$ok) { throw new RuntimeException($message); }
}
function monitorTest(string $name, callable $test): void
{
    ++$GLOBALS['monitor_scenarios'];
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['monitor_failures']; echo 'FAIL ' . $name . ': ' . $error->getMessage() . ' @' . $error->getLine() . PHP_EOL; }
}
function monitorAllGets(array $history): void
{
    foreach ($history as $entry) { monitorCheck($entry['request']->getMethod() === 'GET', 'Reconciliation must never mutate the provider.'); }
}
function monitorSetCache(MonitorDatabase $db, array $changes): void
{
    $map = $db->get_where('tblasaas_payments_map', ['invoice_id' => 42])->row_array();
    $cache = array_replace(json_decode($map['payload_cache'], true), $changes);
    $db->query('UPDATE tblasaas_payments_map SET payload_cache = ? WHERE invoice_id = 42', [json_encode($cache)]);
}

function monitorPricedCache(MonitorDatabase $db, array $payments, bool $allocated = true): void
{
    $pricing = ['version' => 1, 'principal_cents' => 10000, 'charged_cents' => 10460, 'fee_cents' => 460, 'installment_count' => count($payments)];
    if ($allocated) { $pricing = asaas_card_allocate_pricing($pricing, $payments); }
    $cache = $payments[0];
    $cache['_asaas_lifecycle'] = ['state' => 'active', 'card_attempt' => ['state' => 'confirmed',
        'customer_id' => 'cus_fixture', 'amount' => '104.60', 'installment_count' => count($payments), 'replaced_ids' => [], 'pricing' => $pricing]];
    monitorSetCache($db, $cache);
}

monitorTest('compare, reconcile and retry use authoritative GET and the real gateway receipt path', static function (): void {
    [$service, $gateway, $db] = monitorFixture(array_fill(0, 3, monitorResponse(monitorPayment())), $history);
    $compared = $service->process([42]);
    monitorCheck($compared['data'][0]['result'] === 'divergent', 'Expected paid/open divergence.');
    monitorCheck($gateway->nativeCalls === [], 'Compare must never register receipts.');
    monitorCheck($db->get_where('tblasaas_payments_map', ['invoice_id' => 42])->row_array()['last_sync_at'] !== null, 'Compare should record its consultation time.');
    $done = $service->process([42], true);
    monitorCheck($done['data'][0]['result'] === 'reconciled' && $done['data'][0]['receipts_added'] === 1, 'One confirmed receipt must be registered.');
    monitorCheck($done['data'][0]['balance'] === '0.00' && $done['data'][0]['crm_status'] === 2, 'Balance and status must reflect the native receipt.');
    $repeat = $service->process([42], true);
    monitorCheck($repeat['data'][0]['result'] === 'already_reconciled' && count($gateway->nativeCalls) === 1, 'Retry must be idempotent.');
    monitorCheck($db->query('SELECT * FROM tblasaas_webhook_events')->result_array() === [], 'Reconciliation must never fabricate webhook delivery.');
    monitorAllGets($history);
});

monitorTest('only settled statuses are eligible; authorized/pending/refunded/deleted never settle', static function (): void {
    foreach (['AUTHORIZED', 'PENDING', 'OVERDUE', 'REFUNDED', 'REPROVED_BY_RISK_ANALYSIS'] as $status) {
        [$service, $gateway] = monitorFixture([monitorResponse(monitorPayment(42, ['status' => $status]))], $history);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'pending', 'Unexpected settlement for ' . $status);
        monitorCheck($gateway->nativeCalls === [], 'No receipt for ' . $status);
    }
    foreach (['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'] as $status) {
        [$service, $gateway] = monitorFixture([monitorResponse(monitorPayment(42, ['status' => $status]))], $history);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'reconciled', 'Eligible status was ignored: ' . $status);
        monitorAllGets($history);
    }
    [$service, $gateway] = monitorFixture([monitorResponse(monitorPayment(42, ['deleted' => true]))], $history);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'error' && $gateway->nativeCalls === [], 'Deleted charge must not settle.');
});

monitorTest('customer, external reference and mapped id are mandatory bindings', static function (): void {
    foreach ([['customer' => 'cus_other'], ['externalReference' => 'hash-other'], ['id' => 'pay_other']] as $change) {
        [$service, $gateway] = monitorFixture([monitorResponse(monitorPayment(42, $change))], $history);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'error' && $gateway->nativeCalls === [], 'Mismatched charge must not settle.');
    }
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment())], $history);
    $db->query('DELETE FROM tblasaas_customers_map');
    monitorSetCache($db, ['customer' => null]);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'reconciled', 'Legacy client column must remain a fallback when map has no row.');
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment())], $history);
    $db->query('DELETE FROM tblasaas_customers_map');
    $db->query('UPDATE tblclients SET asaas_customer_id = NULL');
    monitorSetCache($db, ['customer' => null]);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'error', 'Unknown customer must prevent automatic receipt.');
});

monitorTest('cancelled, draft, retired and manual lifecycle states stay blocked', static function (): void {
    foreach ([5, 6] as $status) {
        [$service, $gateway, $db] = monitorFixture([], $history);
        $db->query('UPDATE tblinvoices SET status = ? WHERE id = 42', [$status]);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'blocked' && $history === [], 'Inactive invoice must not query or settle.');
    }
    foreach (['deleted', 'cancelled', 'manual_received', 'manual_deleted'] as $state) {
        [$service, $gateway, $db] = monitorFixture([], $history);
        monitorSetCache($db, ['_asaas_lifecycle' => ['state' => $state]]);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'blocked' && $gateway->nativeCalls === [], 'Lifecycle decision must be preserved.');
    }
    [$service, $gateway, $db] = monitorFixture([], $history);
    monitorSetCache($db, ['_asaas_lifecycle' => ['retired_payment_ids' => ['pay_42']]]);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'pending' && $history === [], 'Retired payment must not be reused.');
});

monitorTest('a manual receipt during the remote GET prevents overpayment', static function (): void {
    $response = static function (): Response {
        get_instance()->db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'amount' => '40.00', 'transactionid' => 'manual-fixture']);
        return monitorResponse(monitorPayment());
    };
    [$service, $gateway] = monitorFixture([$response], $history);
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'amount_mismatch' && $result['balance'] === '60.00' && $gateway->nativeCalls === [], 'Fresh balance must be checked after network response.');
    monitorAllGets($history);
});

monitorTest('a webhook receipt during the remote GET remains idempotent', static function (): void {
    $response = static function (): Response {
        get_instance()->db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'amount' => '100.00', 'transactionid' => 'pay_42']);
        get_instance()->db->query('UPDATE tblinvoices SET status = 2 WHERE id = 42');
        return monitorResponse(monitorPayment());
    };
    [$service, $gateway] = monitorFixture([$response], $history);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'already_reconciled' && $gateway->nativeCalls === [], 'Concurrent delivery must not duplicate the receipt.');
});

monitorTest('a charge reassignment or cancellation during GET prevents persistence', static function (): void {
    foreach (["UPDATE tblasaas_payments_map SET asaas_payment_id = 'pay_new' WHERE invoice_id = 42", 'UPDATE tblinvoices SET status = 5 WHERE id = 42'] as $sql) {
        [$service, $gateway] = monitorFixture([static function () use ($sql): Response { get_instance()->db->query($sql); return monitorResponse(monitorPayment()); }], $history);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'blocked' && $gateway->nativeCalls === [], 'Changed invoice binding must block persistence.');
    }
});

monitorTest('installments settle only received gross amounts and validate full group', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '50.00', 'installment' => 'ins_fixture', 'installmentNumber' => 1]);
    $second = array_replace($first, ['id' => 'pay_second', 'status' => 'PENDING', 'installmentNumber' => 2]);
    $group = ['id' => 'ins_fixture', 'value' => '100.00', 'installmentCount' => 2, 'billingType' => 'CREDIT_CARD', 'customer' => 'cus_fixture'];
    [$service, $gateway] = monitorFixture([monitorResponse($first), monitorResponse($group), monitorResponse(['hasMore' => false, 'data' => [$first, $second]])], $history);
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'reconciled' && $result['balance'] === '50.00', 'First installment must not settle the whole invoice.');
    monitorCheck($gateway->nativeCalls[0]['amount'] === '50.00', 'Gross installment amount must be used.');
    monitorAllGets($history);
    foreach ([['hasMore' => true, 'data' => [$first, $second]], ['hasMore' => false, 'data' => [$first, $first]],
        ['hasMore' => false, 'data' => [$first, array_replace($second, ['value' => '40.00'])]],
        ['hasMore' => false, 'data' => [$first, array_replace($second, ['customer' => 'cus_other'])]]] as $list) {
        [$service, $gateway] = monitorFixture([monitorResponse($first), monitorResponse($group), monitorResponse($list)], $history);
        monitorCheck($service->process([42], true)['data'][0]['result'] === 'error' && $gateway->nativeCalls === [], 'Invalid group must not settle even its first installment.');
    }
});

monitorTest('card attempt reconciliation reuses existing GET-only checkout recovery', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '50.00', 'installment' => 'ins_fixture', 'installmentNumber' => 1]);
    $second = array_replace($first, ['id' => 'pay_second', 'status' => 'PENDING', 'installmentNumber' => 2]);
    [$service, $gateway, $db] = monitorFixture([monitorResponse($first), monitorResponse(['hasMore' => false, 'data' => [$first, $second]])], $history);
    monitorSetCache($db, ['_asaas_lifecycle' => ['state' => 'active', 'card_attempt' => ['state' => 'ambiguous', 'customer_id' => 'cus_fixture', 'installment_count' => 2, 'amount' => '100.00', 'replaced_ids' => []]]]);
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'reconciled' && $result['balance'] === '50.00', 'Existing checkout should validate the ambiguous installment capture.');
    $cache = asaas_lifecycle_cached_payment(asaas_lifecycle_map(42));
    monitorCheck($cache['_asaas_lifecycle']['card_attempt']['state'] === 'confirmed', 'Existing attempt state must be preserved/updated by its own orchestrator.');
    monitorAllGets($history);
});

monitorTest('an ambiguous card attempt with empty remote id remains reachable and recoverable', static function (): void {
    $payment = monitorPayment(42, ['billingType' => 'CREDIT_CARD']);
    [$service, $gateway, $db] = monitorFixture([monitorResponse(['hasMore' => false, 'data' => [$payment]]), monitorResponse($payment)], $history);
    $db->query("UPDATE tblasaas_payments_map SET asaas_payment_id = '' WHERE invoice_id = 42");
    monitorSetCache($db, ['id' => '', '_asaas_lifecycle' => ['state' => 'active', 'card_attempt' => [
        'state' => 'ambiguous', 'customer_id' => 'cus_fixture', 'installment_count' => 1, 'amount' => '100.00', 'replaced_ids' => [],
    ]]]);
    monitorCheck($service->invoices()['pagination']['total'] === 1, 'An incomplete card binding must remain visible to administrators.');
    monitorCheck($service->process([42])['data'][0]['result'] === 'divergent' && count($history) === 1, 'Compare must recover the ambiguous binding via GET and report the paid/open divergence.');
    monitorCheck($gateway->nativeCalls === [] && $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array() === [], 'Recovering card metadata during Compare must not register a receipt.');
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'reconciled', 'GET-only checkout recovery must locate and validate the charge.');
    monitorCheck(count($gateway->nativeCalls) === 1 && count($history) === 2, 'Subsequent reconciliation must record exactly one receipt after rechecking the provider.');
    monitorAllGets($history);
});

monitorTest('priced card compares provider gross but registers only principal and a transparent note', static function (): void {
    $payment = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '104.60']);
    [$service, $gateway, $db] = monitorFixture(array_fill(0, 3, monitorResponse($payment)), $history);
    monitorPricedCache($db, [$payment], false);
    $comparison = $service->process([42])['data'][0];
    monitorCheck($comparison['result'] === 'divergent' && $comparison['remote_paid_amount'] === '104.60', 'Remote gross must remain visible without being treated as invoice principal.');
    monitorCheck($gateway->nativeCalls === [], 'Compare validates allocation without financial receipt.');
    $cache = asaas_lifecycle_cached_payment(asaas_lifecycle_map(42));
    monitorCheck($cache['_asaas_lifecycle']['card_attempt']['pricing']['allocations']['pay_42']['principal_cents'] === 10000, 'GET recovery must durably map principal before receipts.');
    $done = $service->process([42], true)['data'][0];
    monitorCheck($done['result'] === 'reconciled' && $done['balance'] === '0.00', 'Principal must settle the unchanged invoice total.');
    monitorCheck($gateway->nativeCalls[0]['amount'] === '100.00', 'Provider fee must never become local principal.');
    monitorCheck(str_contains($gateway->nativeCalls[0]['note'], '4,60') && str_contains($gateway->nativeCalls[0]['note'], '104,60'), 'Receipt note must retain nominal fee and gross.');
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'already_reconciled' && count($gateway->nativeCalls) === 1, 'Exact principal receipt must remain idempotent.');
    monitorCheck($db->get_where('tblinvoices', ['id' => 42])->row()->total === '100.00', 'Fee passthrough must never rewrite invoice totals.');
    monitorAllGets($history);
});

monitorTest('priced installments reconcile principal individually without premature settlement', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '52.30', 'installment' => 'ins_fee', 'installmentNumber' => 1]);
    $second = array_replace($first, ['id' => 'pay_second_fee', 'installmentNumber' => 2, 'status' => 'PENDING']);
    $receivedSecond = array_replace($second, ['status' => 'CONFIRMED']);
    [$service, $gateway, $db] = monitorFixture([
        monitorResponse($first), monitorResponse(['data' => [$second, $first], 'hasMore' => false]),
        monitorResponse($first), monitorResponse(['data' => [$first, $receivedSecond], 'hasMore' => false]),
        monitorResponse($first), monitorResponse(['data' => [$receivedSecond, $first], 'hasMore' => false]),
    ], $history);
    monitorPricedCache($db, [$first, $second]);
    $one = $service->process([42], true)['data'][0];
    monitorCheck($one['result'] === 'reconciled' && $one['balance'] === '50.00' && $one['remote_paid_amount'] === '52.30', 'One confirmed installment must credit 50 principal, not 52.30 gross.');
    $two = $service->process([42], true)['data'][0];
    monitorCheck($two['result'] === 'reconciled' && $two['balance'] === '0.00' && $two['receipts_added'] === 1, 'Second installment must credit only remaining principal.');
    monitorCheck(array_column($gateway->nativeCalls, 'amount') === ['50.00', '50.00'], 'All principal receipts must sum to the invoice total.');
    monitorCheck($two['remote_paid_amount'] === '104.60', 'Provider total remains nominal gross for diagnostics.');
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'already_reconciled', 'Reordered provider list cannot change allocations or duplicate receipts.');
    monitorAllGets($history);
});

monitorTest('priced reconciliation detects wrong local gross receipts and changed balances', static function (): void {
    foreach (['gross_receipt', 'lower_balance', 'invalid_snapshot'] as $case) {
        $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '52.30', 'installment' => 'ins_fee', 'installmentNumber' => 1]);
        $second = array_replace($first, ['id' => 'pay_second_fee', 'installmentNumber' => 2, 'status' => 'PENDING']);
        [$service, $gateway, $db] = monitorFixture([monitorResponse($first), monitorResponse(['data' => [$first, $second], 'hasMore' => false])], $history);
        monitorPricedCache($db, [$first, $second]);
        if ($case === 'gross_receipt') { $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'transactionid' => 'pay_42', 'amount' => '52.30']); }
        if ($case === 'lower_balance') { $db->insert('tblcredits', ['invoice_id' => 42, 'amount' => '60.00']); }
        if ($case === 'invalid_snapshot') {
            $cache = asaas_lifecycle_cached_payment(asaas_lifecycle_map(42));
            $cache['_asaas_lifecycle']['card_attempt']['amount'] = '104.61';
            monitorSetCache($db, $cache);
        }
        $before = $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array();
        $result = $service->process([42], true)['data'][0];
        monitorCheck($result['result'] === ($case === 'invalid_snapshot' ? 'error' : 'amount_mismatch'), 'Inconsistent principal must not be accepted or clipped.');
        monitorCheck($gateway->nativeCalls === [] && $before === $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array(), 'No automatic corrective payment is allowed.');
        if ($case === 'invalid_snapshot') { monitorCheck($result['error_code'] === 'card_pricing_invalid' && $history === [], 'Invalid snapshot must fail before the API request.'); }
        monitorAllGets($history);
    }
});

monitorTest('gateway repeats exact principal validation under its receipt lock', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '52.30', 'installment' => 'ins_fee', 'installmentNumber' => 1]);
    $second = array_replace($first, ['id' => 'pay_second_fee', 'installmentNumber' => 2]);
    foreach (['credit_race', 'posted_gross', 'wrong_existing', 'duplicate_existing', 'right_existing', 'valid'] as $case) {
        [$service, $gateway, $db] = monitorFixture([], $history);
        monitorPricedCache($db, [$first, $second]);
        if ($case === 'credit_race') { $db->onLock = static function () use ($db): void { $db->insert('tblcredits', ['invoice_id' => 42, 'amount' => '60.00']); }; }
        if (in_array($case, ['wrong_existing', 'duplicate_existing', 'right_existing'], true)) {
            $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'transactionid' => 'pay_42', 'amount' => $case === 'wrong_existing' ? '52.30' : '50.00']);
        }
        if ($case === 'duplicate_existing') { $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'transactionid' => 'pay_42', 'amount' => '50.00']); }
        $ok = $gateway->addPayment(['invoiceid' => 42, 'transactionid' => 'pay_42', 'amount' => $case === 'posted_gross' ? '52.30' : '50.00',
            'paymentmode' => 'Asaas', 'paymentmethod' => 'CREDIT_CARD']);
        monitorCheck($ok === in_array($case, ['right_existing', 'valid'], true), 'Only exact principal with a consistent locked balance is accepted.');
        monitorCheck(count($gateway->nativeCalls) === ($case === 'valid' ? 1 : 0), 'A race or duplicate must not issue another native receipt.');
        monitorCheck($history === [], 'Validating locked allocation must not call the provider.');
        if ($case === 'valid') { monitorCheck($gateway->nativeCalls[0]['amount'] === '50.00', 'Principal must stay unchanged through the native gateway writer.'); }
    }
});

monitorTest('reverted cash receipt is checked again before applying any local receipt', static function (): void {
    [$service, $gateway, $db] = monitorFixture([
        monitorResponse(monitorPayment(42, ['status' => 'RECEIVED_IN_CASH'])),
        monitorResponse(monitorPayment(42, ['status' => 'PENDING'])),
    ], $history);
    monitorSetCache($db, ['_asaas_lifecycle' => ['state' => 'active', 'cash_receipt_reverted' => true]]);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'blocked' && $gateway->nativeCalls === [], 'Undoing cash receipt must not be silently reversed by reconciliation.');
    monitorCheck(count($history) === 2, 'The lifecycle helper must confirm the current cash state.');
    monitorAllGets($history);
});

monitorTest('provider timeout yields a controlled per-invoice error without a financial side effect', static function (): void {
    $timeout = new \GuzzleHttp\Exception\ConnectException('fixture timeout', new \GuzzleHttp\Psr7\Request('GET', 'https://offline.invalid/v3/payments/pay_42'));
    [$service, $gateway, $db] = monitorFixture([$timeout], $history);
    $result = $service->process([42], true);
    monitorCheck($result['ok'] === true && $result['data'][0]['result'] === 'error' && $result['data'][0]['receipts_added'] === 0, 'Timeout must not imply payment success.');
    monitorCheck($gateway->nativeCalls === [] && $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array() === [], 'No local receipt may be created after an inconclusive GET.');
    monitorAllGets($history);
});

monitorTest('a transaction already attributed to another invoice is never reused', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment())], $history, [42, 43]);
    $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 43, 'amount' => '100.00', 'transactionid' => 'pay_42']);
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'blocked' && $gateway->nativeCalls === [], 'Global transaction binding must be respected.');
});

monitorTest('same transaction with a different local amount is flagged without changing receipts', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment())], $history);
    $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'amount' => '25.00', 'transactionid' => 'pay_42']);
    $before = $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array();
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'amount_mismatch' && $result['error_code'] === 'receipt_amount' && $result['balance'] === '75.00', 'An existing partial receipt must not be reported as fully reconciled.');
    monitorCheck($result['receipts_added'] === 0 && $gateway->nativeCalls === [], 'Mismatch must not insert a corrective receipt.');
    monitorCheck($before === $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array(), 'Existing receipt must remain unchanged.');
    monitorAllGets($history);
});

monitorTest('duplicate local transaction records require review even when the remote payment is settled', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment())], $history);
    for ($i = 0; $i < 2; ++$i) { $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'amount' => '25.00', 'transactionid' => 'pay_42']); }
    $before = $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array();
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'amount_mismatch' && $result['error_code'] === 'receipt_amount' && $result['balance'] === '50.00', 'Duplicate receipts must not be accepted as a reconciliation.');
    monitorCheck($result['receipts_added'] === 0 && $gateway->nativeCalls === [], 'Duplicate receipts must not produce an additional receipt.');
    monitorCheck($before === $db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array(), 'Duplicate receipts must be left intact for review.');
    monitorAllGets($history);
});

monitorTest('a read failure after commit reports uncertainty instead of claiming no receipt was saved', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment()), monitorResponse(monitorPayment())], $history);
    $gateway->failVerificationAfterNative = true;
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'error' && $result['receipt_verification_pending'] === true && $result['error_code'] === 'receipt_verification_pending', 'Post-commit verification failure must be explicitly inconclusive.');
    monitorCheck(!str_contains($result['message'], 'Nenhum recebimento') && str_contains($result['message'], 'não pôde ser confirmado'), 'The response must not falsely deny a possible committed receipt.');
    monitorCheck(count($db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array()) === 1, 'The real gateway must already have committed exactly one receipt in this scenario.');
    $gateway->failVerificationAfterNative = false;
    $retry = $service->process([42], true)['data'][0];
    monitorCheck($retry['result'] === 'already_reconciled' && count($gateway->nativeCalls) === 1, 'Retry after recovery must observe the committed receipt without duplication.');
    monitorAllGets($history);
});

monitorTest('batch isolates remote errors and returns unstarted ids on time budget', static function (): void {
    [$service, $gateway] = monitorFixture([monitorResponse(['errors' => [['description' => 'fixture failure']]], 400), monitorResponse(monitorPayment(43))], $history, [42, 43]);
    $result = $service->process([42, 43], true, 'batch');
    monitorCheck($result['data'][0]['result'] === 'error' && $result['data'][1]['result'] === 'reconciled', 'One remote failure must not abort the batch.');
    monitorAllGets($history);
    $now = 0;
    [$unused, $gateway] = monitorFixture([static function () use (&$now): Response { $now = 46; return monitorResponse(monitorPayment()); }], $history, [42, 43]);
    $service = new AsaasReconciliation(get_instance(), $gateway, static function () use (&$now): float { return (float) $now; });
    $result = $service->process([42, 43]);
    monitorCheck(count($result['data']) === 1 && $result['remaining_ids'] === [43], 'Deadline must return unstarted ids without inventing a failure.');
    monitorCheck($result['data'][0]['result'] === 'divergent' && count($history) === 1, 'The completed authoritative read must retain its actual result.');
});

monitorTest('partial native write failure remains retryable without duplicate first receipt', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '50.00', 'installment' => 'ins_fixture', 'installmentNumber' => 1]);
    $second = array_replace($first, ['id' => 'pay_second', 'installmentNumber' => 2]);
    $group = ['id' => 'ins_fixture', 'value' => '100.00', 'installmentCount' => 2, 'billingType' => 'CREDIT_CARD', 'customer' => 'cus_fixture'];
    $responses = [monitorResponse($first), monitorResponse($group), monitorResponse(['hasMore' => false, 'data' => [$first, $second]])];
    [$service, $gateway] = monitorFixture(array_merge($responses, $responses), $history);
    $gateway->failReceiptAt = 2;
    $partial = $service->process([42], true)['data'][0];
    monitorCheck($partial['result'] === 'partial_reconciled' && $partial['receipts_added'] === 1, 'Partial local persistence must be disclosed.');
    $gateway->failReceiptAt = 0;
    $done = $service->process([42], true)['data'][0];
    monitorCheck($done['result'] === 'reconciled' && $done['receipts_added'] === 1 && $done['balance'] === '0.00', 'Retry should persist only the outstanding installment.');
});

monitorTest('listings paginate/filter in SQL, aggregate page balances and never call SDK', static function (): void {
    [$service, $gateway, $db] = monitorFixture([], $history, range(1, 60));
    for ($i = 1; $i <= 60; ++$i) {
        $db->insert('tblasaas_logs', ['level' => $i % 2 ? 'info' : 'error', 'message' => 'fixture-' . $i,
            'context_json' => json_encode(['source' => $i % 2 ? 'reconciliation' : 'webhook', 'payload' => ['creditCard' => ['number' => '4444333322221111']]]), 'created_at' => date('Y-m-d H:i:s')]);
        $db->insert('tblasaas_webhook_events', ['event_id' => 'evt_' . $i, 'event_type' => 'PAYMENT_RECEIVED', 'invoice_id' => $i,
            'asaas_payment_id' => 'pay_' . $i, 'received_at' => date('Y-m-d H:i:s'), 'process_status' => $i % 2 ? 'processed' : 'error', 'payload' => 'NEVER RETURN THIS PAYLOAD']);
    }
    $db->insert('tblcredits', ['invoice_id' => 60, 'amount' => '25.00']);
    $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 60, 'amount' => '15.00', 'transactionid' => 'manual']);
    $before = count($db->trace);
    $page = $service->invoices();
    monitorCheck(count($page['data']) === 25 && $page['pagination']['has_more'] && $page['pagination']['total'] === 60, 'Default invoice page must be bounded.');
    monitorCheck($page['data'][0]['balance'] === '60.00', 'Page aggregate must account for payments and applied credits.');
    monitorCheck(count($db->trace) - $before <= 5, 'Standard page must not perform N+1 invoice balance or webhook queries.');
    $db->query('UPDATE tblinvoices SET number = 387 WHERE id = 60');
    monitorCheck($service->invoices(['q' => '387'])['data'][0]['invoice_id'] === 60, 'Search must match the displayed native invoice number as well as its database id.');
    $page = $service->logs(['source' => 'webhook', 'level' => 'error', 'page' => 2, 'per_page' => 10]);
    monitorCheck(count($page['data']) === 10 && $page['pagination']['total'] === 30 && $page['data'][0]['id'] === 40, 'Source/level filtering must happen before pagination.');
    $page = $service->events(['status' => 'error', 'per_page' => 500]);
    monitorCheck(count($page['data']) === 30 && $page['pagination']['per_page'] === 50, 'Event limits and error filters must apply.');
    monitorCheck(!str_contains(json_encode($page), 'NEVER RETURN'), 'Events must never expose payload.');
    $overview = $service->overview()['data'];
    monitorCheck($overview['linked_open_invoices'] === 60 && $overview['webhook_errors'] === 30 && $overview['webhook_event_errors'] === 30, 'Delivery errors and event records must be counted separately.');
    monitorCheck($history === [] && get_instance()->load->libraries === 0, 'Opening diagnostics must not load SDK or issue HTTP.');
});

monitorTest('legacy log schema, source matching and sensitive values remain safe', static function (): void {
    [$service, $gateway, $db] = monitorFixture([], $history);
    $db->query('ALTER TABLE tblasaas_logs RENAME COLUMN context_json TO context');
    foreach ([['method' => 'GET', 'path' => '/v3/payments/pay_42'], ['operation' => 'card_get_payment'], ['source' => 'webhook']] as $context) {
        $context['creditCard'] = ['number' => '4444333322221111', 'ccv' => '123'];
        $context['token'] = 'secret_should_not_escape';
        $db->insert('tblasaas_logs', ['level' => 'error', 'message' => 'API key ' . MONITOR_API_FIXTURE . ' cardNumber=4444333322221111 cvv=123', 'context' => json_encode($context, JSON_PRETTY_PRINT), 'created_at' => date('Y-m-d H:i:s')]);
    }
    $page = $service->logs(['source' => 'api']);
    monitorCheck(count($page['data']) === 2 && $page['pagination']['total'] === 2, 'Legacy API and card origins must filter in SQL.');
    $json = json_encode($page);
    monitorCheck(!str_contains($json, MONITOR_API_FIXTURE) && !str_contains($json, '4444333322221111') && !str_contains($json, 'cvv=123') && !str_contains($json, 'secret_should_not_escape'), 'Viewer must not return credentials or card details.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_RUN_OPTION] = '{"status":"completed","started_at":"2026-11-06 10:00:00","processed":1,"token":"not_public"}';
    monitorCheck(!str_contains(json_encode($service->overview()), 'not_public'), 'Cron state must be allowlisted.');
});

monitorTest('missing optional diagnostic tables yield controlled empty data', static function (): void {
    [$unused, $gateway, $db] = monitorFixture([], $history);
    foreach (['tblasaas_logs', 'tblasaas_webhook_events', 'tblasaas_payments_map'] as $table) { $db->query('DROP TABLE ' . $table); }
    $service = new AsaasReconciliation(get_instance());
    foreach (['invoices', 'events', 'logs'] as $method) {
        $page = $service->$method();
        monitorCheck($page['ok'] === true && $page['data'] === [] && $page['storage_available'] === false, 'Missing storage must not render SQL errors.');
    }
    monitorCheck($service->overview()['data']['storage_available'] === [] && $history === [], 'Overview should stay usable without SDK/storage.');
});

monitorTest('cron includes old open invoices and obeys opt-in, fair cursor, cooldown and overlap lock', static function (): void {
    [$service, $gateway, $db] = monitorFixture(array_map(static fn (int $id): Response => monitorResponse(monitorPayment($id, ['status' => 'PENDING'])), [1, 2, 3]), $history, [1, 2, 3]);
    monitorCheck(AsaasReconciliation::runCron(get_instance(), $gateway)['status'] === 'disabled' && $history === [], 'Cron must remain opt-in.');
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_enabled'] = '1';
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_limit'] = '1';
    $db->query("UPDATE tblinvoices SET date = '2000-01-01', duedate = '2000-01-01' WHERE id = 3");
    $first = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($first['processed'] === 1 && $first['next_cursor'] === 1, 'First run should persist the fair cursor.');
    monitorCheck(AsaasReconciliation::runCron(get_instance(), $gateway)['status'] === 'throttled' && count($history) === 1, 'Cron must not poll every minute.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_STARTED_OPTION] = (string) (time() - 301);
    $second = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($second['processed'] === 1 && $second['next_cursor'] === 2, 'Next run must continue, not revisit the first invoice.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_STARTED_OPTION] = (string) (time() - 301);
    $third = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($third['processed'] === 1 && $third['next_cursor'] === 3 && count($history) === 3, 'An old unpaid invoice must not disappear because its creation/due dates are old.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_STARTED_OPTION] = (string) (time() - 301);
    $fourth = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($fourth['processed'] === 0 && $fourth['next_cursor'] === 0 && count($history) === 3, 'Cooldown must prevent a new poll after wrapping the complete cursor.');
    $db->denyCronLock = true;
    monitorCheck(AsaasReconciliation::runCron(get_instance(), $gateway)['status'] === 'busy' && count($history) === 3, 'Concurrent cron execution must stop before HTTP.');
    monitorCheck($db->locks === [], 'Every owned cron lock must be released.');
    monitorAllGets($history);
});

monitorTest('request ID validation rejects injection, nested inputs and oversized batches', static function (): void {
    foreach ([[], ['1 OR 1=1'], [['42']], [0], [-1], ['0042'], [1.0], range(1, 21)] as $invalid) {
        try { AsaasReconciliation::invoiceIds($invalid); throw new LogicException('Accepted malformed input'); }
        catch (InvalidArgumentException $expected) { monitorCheck(true, 'Rejected malformed input.'); }
    }
    monitorCheck(AsaasReconciliation::invoiceIds(['42', 42, '43']) === [42, 43], 'Valid ids should be deduplicated.');
});

monitorTest('old and unmapped invoices are selected by exact serialized/JSON gateway or Asaas receipt history', static function (): void {
    [$service, $gateway, $db] = monitorFixture([], $history, range(1, 12));
    foreach (range(2, 12) as $id) { monitorUnlink($db, $id); }
    $modes = [1 => serialize(['bank']), 2 => serialize(['asaas']), 3 => '["bank","asaas"]', 4 => serialize(['bank']),
        5 => '["notasaas"]', 6 => '{"gateway":"asaas"}', 7 => '["asaas",', 8 => '["asaas",{"id":"bank"}]', 9 => serialize(['asaas_other'])];
    foreach ($modes as $id => $value) { $db->query("UPDATE tblinvoices SET allowed_payment_modes = ?, date = '2000-01-01', duedate = '2000-01-01' WHERE id = ?", [$value, $id]); }
    $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 4, 'amount' => '10.00', 'paymentmode' => 'Asaas', 'transactionid' => 'pay_historical']);
    foreach ([10 => 2, 11 => 5, 12 => 6] as $id => $status) { $db->query('UPDATE tblinvoices SET status = ? WHERE id = ?', [$status, $id]); }
    $page = $service->invoices(['per_page' => 50]);
    monitorCheck(array_column($page['data'], 'invoice_id') === [4, 3, 2, 1], 'Only exact modes, existing bindings or unequivocal Asaas receipts are eligible.');
    monitorCheck($page['data'][0]['balance'] === '90.00' && $page['data'][0]['binding_status'] === 'missing', 'Partial legacy invoices must preserve their actual native balance.');
    monitorCheck($service->invoices(['status' => 'missing'])['pagination']['total'] === 3, 'Missing filter must include every eligible unbound open invoice.');
    monitorCheck($service->invoices(['status' => 'all'])['pagination']['total'] === 7, 'Closed/paid records appear only in the explicit all filter.');
    $overview = $service->overview()['data'];
    monitorCheck([$overview['eligible_open_invoices'], $overview['linked_open_invoices'], $overview['unlinked_open_invoices']] === [4, 1, 3], 'Overview counts must distinguish eligible, linked and missing.');
    monitorCheck(count($db->query('SELECT * FROM tblasaas_payments_map')->result_array()) === 1 && $history === [] && $gateway->nativeCalls === [], 'Local listing must not recover bindings or call the provider.');
});

monitorTest('an old pending charge recovers its binding without settling or modifying customer identity', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment(42, ['status' => 'OVERDUE'])], false, 1)], $history);
    monitorUnlink($db);
    $db->query("UPDATE tblinvoices SET date = '2000-01-01', duedate = '2000-01-01'");
    $clients = $db->query('SELECT * FROM tblclients')->result_array();
    $result = $service->process([42], true)['data'][0];
    $map = $db->get_where('tblasaas_payments_map', ['invoice_id' => 42])->row_array();
    $cache = json_decode($map['payload_cache'], true);
    monitorCheck($result['result'] === 'pending' && $result['binding_recovered'] === true && $map['asaas_payment_id'] === 'pay_42', 'An overdue unpaid remote charge must recover without becoming paid.');
    monitorCheck($cache['_asaas_lifecycle']['recovery_context']['environment'] === 'sandbox', 'Recovered bindings must retain their proved environment.');
    monitorCheck($clients === $db->query('SELECT * FROM tblclients')->result_array() && $gateway->nativeCalls === [], 'Recovery must not rewrite contacts or create receipts for pending charges.');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    monitorCheck($query['externalReference'] === 'hash-42' && $query['limit'] === '100' && $query['offset'] === '0', 'Discovery must query the exact invoice hash with bounded pagination.');
    monitorAllGets($history);
});

monitorTest('paid legacy recovery compares first, receives once and thereafter uses the same exact payment id', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment()], false, 1), monitorResponse(monitorPayment()), monitorResponse(monitorPayment())], $history);
    monitorUnlink($db);
    $result = $service->process([42])['data'][0];
    monitorCheck($result['result'] === 'divergent' && $gateway->nativeCalls === [], 'Comparison may recover metadata but must never create a receipt.');
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'reconciled', 'A proved paid charge must settle through the native gateway.');
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'already_reconciled' && count($gateway->nativeCalls) === 1, 'Repetition must never duplicate the receipt.');
    monitorCheck($history[1]['request']->getUri()->getPath() === '/v3/payments/pay_42' && $history[2]['request']->getUri()->getPath() === '/v3/payments/pay_42', 'Recovered IDs must become stable bindings, not new searches on each reconciliation.');
    monitorAllGets($history);
});

monitorTest('customers without any local Asaas id require GET proof of exact reference and normalized CPF/CNPJ', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment()]), monitorResponse(monitorCustomer())], $history);
    monitorUnlink($db); monitorUnknownCustomer($db);
    $clients = $db->query('SELECT * FROM tblclients')->result_array();
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'reconciled' && count($history) === 2, 'Unknown identity requires one customer lookup and then supports the same native receipt path.');
    monitorCheck($history[1]['request']->getUri()->getPath() === '/v3/customers/cus_fixture', 'Customer proof must read the exact remote customer ID.');
    monitorCheck($db->query('SELECT * FROM tblasaas_customers_map')->result_array() === [] && $clients === $db->query('SELECT * FROM tblclients')->result_array(), 'Proof must never create a customer mapping or mutate contact data.');
    $stored = json_decode($db->get_where('tblasaas_payments_map', ['invoice_id' => 42])->row_array()['payload_cache'], true);
    monitorCheck($stored['customer'] === 'cus_fixture' && !isset($stored['cpfCnpj']), 'Only the necessary payment/customer binding belongs in the recovered payment cache.');
    monitorAllGets($history);
});

monitorTest('unconfirmed documents, references and conflicting local customer ids block legacy recovery', static function (): void {
    foreach ([['externalReference' => ''], ['externalReference' => '8'], ['cpfCnpj' => '98765432000100'], ['id' => 'cus_other'], ['deleted' => true]] as $change) {
        [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment()]), monitorResponse(monitorCustomer($change))], $history);
        monitorUnlink($db); monitorUnknownCustomer($db);
        $result = $service->process([42], true)['data'][0];
        monitorCheck($result['error_code'] === 'customer_identity' && $gateway->nativeCalls === [] && asaas_lifecycle_map(42) === [], 'An unconfirmed customer identity must never create a binding or receipt.');
        monitorAllGets($history);
    }
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment()])], $history);
    monitorUnlink($db);
    $db->query("UPDATE tblclients SET asaas_customer_id = 'cus_other'");
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'customer_conflict' && count($history) === 1, 'Conflicting customer IDs must not be arbitrarily resolved by map priority.');
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment()])], $history);
    monitorUnlink($db); monitorUnknownCustomer($db);
    $db->query('UPDATE tblclients SET vat = NULL');
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'customer_document_missing' && count($history) === 1, 'Missing document must not be guessed or replaced from the provider.');
});

monitorTest('duplicate references and independent charges are ambiguous while wrong hashes remain blocked', static function (): void {
    [$service, $gateway, $db] = monitorFixture([], $history, [42, 43]);
    monitorUnlink($db);
    $db->query("UPDATE tblinvoices SET hash = 'hash-42' WHERE id = 43");
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'reference_ambiguous' && $history === [], 'A local duplicated reference must fail before a provider request.');
    foreach ([
        [monitorPayment(), monitorPayment(42, ['id' => 'pay_independent'])],
        [monitorPayment(), monitorPayment(42, ['id' => 'pay_parcel', 'installment' => 'ins_other', 'installmentNumber' => 1])],
    ] as $payments) {
        [$service, $gateway, $db] = monitorFixture([monitorPaymentList($payments)], $history); monitorUnlink($db);
        monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'payment_ambiguous' && asaas_lifecycle_map(42) === [] && $gateway->nativeCalls === [], 'Multiple independent charges/groups must never be selected by ordering or paid status.');
    }
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment(42, ['externalReference' => 'hash-other'])])], $history); monitorUnlink($db);
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'payment_binding' && asaas_lifecycle_map(42) === [], 'The response must repeat the exact reference even when the request used a filter.');
});

monitorTest('unmapped installments follow all lookup pages, verify the full group and settle only received parcels', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'installment' => 'ins_legacy', 'installmentNumber' => 1, 'value' => '50.00']);
    $second = array_replace($first, ['id' => 'pay_second', 'installmentNumber' => 2, 'status' => 'PENDING']);
    $group = ['id' => 'ins_legacy', 'customer' => 'cus_fixture', 'billingType' => 'CREDIT_CARD', 'installmentCount' => 2, 'value' => '100.00'];
    [$service, $gateway, $db] = monitorFixture([
        monitorPaymentList([$first], true, 2), monitorPaymentList([$second], false, 2), monitorResponse($group),
        monitorPaymentList([$first], true, 2), monitorPaymentList([$second], false, 2),
        monitorResponse($first), monitorResponse($group), monitorPaymentList([$second, $first], false, 2),
    ], $history);
    monitorUnlink($db);
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'reconciled' && $result['receipts_added'] === 1 && $result['balance'] === '50.00', 'One paid installment must not prematurely settle the whole invoice.');
    monitorCheck($gateway->nativeCalls[0]['transactionid'] === 'pay_42' && $gateway->nativeCalls[0]['amount'] === '50.00', 'Only the paid member principal should be received.');
    foreach ([1, 4] as $index) { parse_str($history[$index]['request']->getUri()->getQuery(), $query); monitorCheck($query['offset'] === '1', 'Lookup must follow all pages even when a provider returns fewer than the requested limit.'); }
    monitorCheck($service->process([42], true)['data'][0]['result'] === 'already_reconciled' && count($gateway->nativeCalls) === 1, 'Existing paid installment must stay idempotent if provider order changes.');
    monitorAllGets($history);
});

monitorTest('missing pricing, mismatched principal and incomplete pagination cannot establish a binding', static function (): void {
    foreach ([
        [monitorPaymentList([monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'value' => '105.00'])])],
        [monitorPaymentList([monitorPayment(42, ['value' => '80.00'])])],
        [monitorPaymentList([monitorPayment()], false, 2)],
        [monitorPaymentList([monitorPayment()], true, 2), monitorPaymentList([monitorPayment()], false, 2)],
        [monitorPaymentList([], true, 2)],
    ] as $responses) {
        [$service, $gateway, $db] = monitorFixture($responses, $history); monitorUnlink($db);
        $result = $service->process([42], true)['data'][0];
        monitorCheck($result['result'] === 'error' && asaas_lifecycle_map(42) === [] && $gateway->nativeCalls === [], 'Ambiguous financial value or partial search must never create a guessed binding.');
        monitorCheck(in_array($result['error_code'], ['card_pricing_missing', 'recovery_amount', 'lookup_incomplete'], true), 'A controlled, actionable error must identify why discovery stopped.');
        monitorAllGets($history);
    }
});

monitorTest('an existing partial local receipt is recognized without replaying the same payment', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([monitorPayment(42, ['value' => '80.00'])])], $history);
    monitorUnlink($db);
    $db->insert('tblinvoicepaymentrecords', ['invoiceid' => 42, 'amount' => '20.00', 'paymentmode' => 'cash', 'transactionid' => 'manual']);
    $result = $service->process([42], true)['data'][0];
    monitorCheck($result['result'] === 'reconciled' && $result['balance'] === '0.00' && $gateway->nativeCalls[0]['amount'] === '80.00', 'An old outstanding-balance charge must be matched to the real remaining principal.');
    monitorAllGets($history);
});

monitorTest('scope, customer or binding changes during a recovery lookup preserve the latest local records', static function (): void {
    foreach (['environment', 'customer', 'binding', 'document'] as $kind) {
        $db = $gateway = null;
        $responses = [static function () use (&$db, &$gateway, $kind): Response {
            if ($kind === 'environment') { $gateway->settings['sandbox'] = '0'; }
            if ($kind === 'customer') { $db->query("UPDATE tblclients SET asaas_customer_id = 'cus_other'"); }
            if ($kind === 'binding') { $db->insert('tblasaas_payments_map', ['invoice_id' => 42, 'asaas_payment_id' => 'pay_new', 'payload_cache' => '{"id":"pay_new"}']); }
            return monitorPaymentList([monitorPayment()]);
        }];
        if ($kind === 'document') { $responses[] = static function () use (&$db): Response { $db->query("UPDATE tblclients SET vat = '98765432000100'"); return monitorResponse(monitorCustomer()); }; }
        [$service, $gateway, $db] = monitorFixture($responses, $history); monitorUnlink($db);
        if ($kind === 'document') { monitorUnknownCustomer($db); }
        $result = $service->process([42], true)['data'][0];
        monitorCheck($result['result'] === 'error' && $gateway->nativeCalls === [], 'Changed ' . $kind . ' must stop before native receipts.');
        monitorCheck($kind === 'binding' ? asaas_lifecycle_map(42)['asaas_payment_id'] === 'pay_new' : asaas_lifecycle_map(42) === [], 'The concurrent binding must not be overwritten and other failures must not bind.');
        monitorAllGets($history);
    }
    [$service, $gateway, $db] = monitorFixture([], $history);
    monitorSetCache($db, ['_asaas_lifecycle' => ['recovery_context' => ['environment' => 'production']]]);
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'environment_mismatch' && $history === [], 'A previously recovered foreign-environment binding must fail before network access.');
});

monitorTest('company fallback is accepted only if known aliases resolve to the same account and remain stable', static function (): void {
    foreach (['same', 'different', 'alias', 'changed', 'issuer'] as $kind) {
        $db = null;
        [$service, $gateway, $db] = monitorFixture([static function () use (&$db, $kind): Response {
            if ($kind === 'changed') { $GLOBALS['monitor_options']['paymentmethod_asaas_api_key_sandbox_company_5'] = 'different-account-fixture'; }
            return monitorPaymentList([monitorPayment(42, ['status' => 'PENDING'])]);
        }], $history);
        monitorUnlink($db);
        $db->query('ALTER TABLE tblinvoices ADD COLUMN company_id INTEGER');
        $db->query('UPDATE tblinvoices SET company_id = 5');
        if ($kind === 'different') { $GLOBALS['monitor_options']['paymentmethod_asaas_api_key_sandbox_company_5'] = 'different-account-fixture'; }
        if ($kind === 'alias') { $db->query('ALTER TABLE tblinvoices ADD COLUMN companyid INTEGER'); $db->query('UPDATE tblinvoices SET companyid = 6'); }
        if ($kind === 'issuer') { $db->query('ALTER TABLE tblinvoices ADD COLUMN issuer_id INTEGER'); $db->query('UPDATE tblinvoices SET issuer_id = 6'); }
        $result = $service->process([42], true)['data'][0];
        monitorCheck($kind === 'same' ? $result['result'] === 'pending' && !empty($result['binding_recovered']) : $result['error_code'] === 'scope_unavailable' && asaas_lifecycle_map(42) === [], 'Company context must be proved without assuming new multi-account routing: ' . $kind);
        monitorCheck($gateway->nativeCalls === [], 'Company checks must not create a payment.');
        monitorAllGets($history);
    }
});

monitorTest('systemic API errors stop the batch and retain safe diagnostic codes without provider bodies', static function (): void {
    foreach ([401 => 'api_authentication', 403 => 'api_permission', 429 => 'api_rate_limit', 503 => 'api_unavailable'] as $http => $code) {
        [$service, $gateway, $db] = monitorFixture([monitorResponse(['errors' => [['description' => MONITOR_API_FIXTURE . ' cvv=123 4444333322221111']]], $http)], $history, [42, 43]);
        monitorUnlink($db);
        $result = $service->process([42, 43], true, 'batch');
        monitorCheck($result['interrupted'] === true && $result['remaining_ids'] === [43] && count($history) === 1, 'A systemic response must not be replayed for every invoice in a batch.');
        monitorCheck($result['data'][0]['error_code'] === $code && $result['data'][0]['http_status'] === $http, 'The user needs the specific provider failure category.');
        $json = json_encode($result);
        monitorCheck(!str_contains($json, MONITOR_API_FIXTURE) && !str_contains($json, '4444333322221111') && !str_contains($json, 'cvv=123') && asaas_lifecycle_map(42) === [], 'Provider errors must not leak secrets or leave guessed bindings.');
    }
    [$service, $gateway, $db] = monitorFixture([monitorResponse([], 404)], $history);
    $original = asaas_lifecycle_map(42);
    monitorCheck($service->process([42], true)['data'][0]['error_code'] === 'api_not_found' && count($history) === 1 && asaas_lifecycle_map(42) === $original, 'A mapped 404 must never trigger a hash search or replace its existing ID.');
});

monitorTest('remote browsing associates exact known and recoverable charges but never creates maps or receipts', static function (): void {
    $linked = monitorPayment(42, ['status' => 'PENDING']);
    $recoverable = monitorPayment(43);
    $unmatched = monitorPayment(44, ['externalReference' => 'unrecognized-reference']);
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([$linked, $recoverable, $unmatched], false, 3), monitorPaymentList([$recoverable], false, 1)], $history, [42, 43]);
    monitorUnlink($db, 43);
    $original = $db->query('SELECT * FROM tblasaas_payments_map')->result_array();
    $page = $service->payments(['status' => 'all', 'date_field' => 'due', 'date_from' => '2020-01-01', 'date_to' => '2026-11-06']);
    monitorCheck($page['ok'] === true && array_column($page['data'], 'binding_status') === ['linked', 'recoverable', 'unmatched'], 'Remote statuses must communicate whether a row is bound, recoverable or unrelated.');
    monitorCheck(array_column($page['data'], 'action_eligible') === [true, true, false], 'Remote action eligibility must be explicit and conservative.');
    monitorCheck($page['data'][0]['status'] === 'PENDING' && $page['data'][1]['status'] === 'CONFIRMED' && $page['data'][2]['invoice_id'] === null, 'The real provider payment state and exact invoice identity must remain visible.');
    monitorCheck($original === $db->query('SELECT * FROM tblasaas_payments_map')->result_array() && $gateway->nativeCalls === [], 'Opening remote browsing must not create/update any map or receipt.');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    monitorCheck($query['dueDate'] === ['ge' => '2020-01-01', 'le' => '2026-11-06'] && $query['limit'] === '50' && !isset($query['status']), 'Only official date filters and a bounded page must be sent.');
    monitorAllGets($history);
});

monitorTest('remote installment browsing validates a group once per invoice and rejects foreign independent rows', static function (): void {
    $first = monitorPayment(42, ['billingType' => 'CREDIT_CARD', 'installment' => 'ins_list', 'installmentNumber' => 1, 'value' => '50.00']);
    $second = array_replace($first, ['id' => 'pay_second', 'installmentNumber' => 2, 'status' => 'PENDING']);
    $foreign = monitorPayment(42, ['id' => 'pay_other']);
    $group = ['id' => 'ins_list', 'customer' => 'cus_fixture', 'billingType' => 'CREDIT_CARD', 'installmentCount' => 2, 'value' => '100.00'];
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([$first, $second, $foreign]), monitorResponse($group), monitorPaymentList([$first, $second])], $history);
    $page = $service->payments();
    monitorCheck($page['ok'] && count($history) === 3 && array_column($page['data'], 'action_eligible') === [true, true, false], 'Rows for the same installment group must share one validated in-memory preview.');
    monitorCheck($page['data'][2]['binding_status'] === 'blocked' && $gateway->nativeCalls === [], 'An unrelated same-reference payment must not silently replace an existing binding.');
    monitorAllGets($history);
});

monitorTest('remote browsing shows ambiguity and does not enable unverifiable recovery', static function (): void {
    $first = monitorPayment(); $other = monitorPayment(42, ['id' => 'pay_other']);
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([$first, $other]), monitorPaymentList([$first, $other])], $history);
    monitorUnlink($db);
    $page = $service->payments();
    monitorCheck($page['ok'] && array_column($page['data'], 'binding_status') === ['ambiguous', 'ambiguous'] && array_column($page['data'], 'action_eligible') === [false, false], 'Ambiguous lookup must disable every competing row.');
    monitorCheck(count($history) === 2 && asaas_lifecycle_map(42) === [] && $gateway->nativeCalls === [], 'Ambiguity should be queried once and have no side effects.');
});

monitorTest('remote browsing handles empty later pages and rejects inconsistent provider pagination', static function (): void {
    [$service, $gateway] = monitorFixture([monitorPaymentList([], false, 1)], $history);
    $page = $service->payments(['page' => 3, 'per_page' => 10]);
    monitorCheck($page['ok'] && $page['data'] === [] && $page['pagination'] === ['page' => 3, 'per_page' => 10, 'total' => 1, 'has_more' => false], 'A shrinking result list may legitimately return an empty page after its end.');
    foreach ([monitorPaymentList([monitorPayment()], false, 2), monitorPaymentList([], true, 2), monitorPaymentList([monitorPayment()], true, 1)] as $response) {
        [$service] = monitorFixture([$response], $history);
        $page = $service->payments();
        monitorCheck(!$page['ok'] && $page['error']['code'] === 'lookup_incomplete', 'Inconsistent pagination must not claim a complete remote view.');
    }
    foreach ([['date_field' => 'other'], ['date_from' => '2026-02-30'], ['date_from' => '2026-11-07', 'date_to' => '2026-11-06'], ['status' => 'CONFIRMED OR 1=1']] as $filter) {
        [$service] = monitorFixture([], $history);
        try { $service->payments($filter); throw new LogicException('Invalid filter accepted'); }
        catch (InvalidArgumentException $expected) { monitorCheck($history === [], 'Malformed filters must fail before HTTP.'); }
    }
});

monitorTest('remote API failures have an explicit safe error object for the monitoring UI', static function (): void {
    foreach ([400 => 'api_rejected', 401 => 'api_authentication', 429 => 'api_rate_limit', 503 => 'api_unavailable'] as $status => $code) {
        [$service] = monitorFixture([monitorResponse(['errors' => [['description' => MONITOR_API_FIXTURE]]], $status)], $history);
        $page = $service->payments();
        monitorCheck($page['ok'] === false && $page['error']['code'] === $code && $page['error']['http_status'] === $status && $page['message'] === $page['error']['message'], 'Failure must not masquerade as an empty successful page.');
        monitorCheck(!str_contains(json_encode($page), MONITOR_API_FIXTURE), 'Public failure must not repeat the provider body.');
    }
});

monitorTest('cron progresses past unmapped misses and cooldown does not require manufacturing a map', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([], false, 0), monitorResponse(monitorPayment(2, ['status' => 'PENDING']))], $history, [1, 2]);
    monitorUnlink($db, 1);
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_enabled'] = '1';
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_limit'] = '1';
    $first = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($first['processed'] === 1 && $first['next_cursor'] === 1 && asaas_lifecycle_map(1) === [], 'An unmapped miss must advance the fair cursor without a fake binding.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_STARTED_OPTION] = (string) (time() - 301);
    $second = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($second['processed'] === 1 && $second['next_cursor'] === 2, 'An unresolved old invoice must not monopolize later cron rounds.');
    $GLOBALS['monitor_options'][AsaasReconciliation::LAST_STARTED_OPTION] = (string) (time() - 301);
    $third = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($third['processed'] === 0 && count($history) === 2, 'Unmapped attempts must observe the same cooldown after wrapping.');
    monitorCheck(isset(json_decode(get_option(AsaasReconciliation::RECENT_ATTEMPTS_OPTION), true)[1]), 'The bounded retry cache must remember an unmapped lookup without a payment row.');
    monitorAllGets($history);
});

monitorTest('cron days apply to recent receipts of paid invoices and never to old open creation dates', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment(1)), monitorResponse(monitorPayment(3, ['status' => 'PENDING']))], $history, [1, 2, 3]);
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_enabled'] = '1';
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_days'] = '7';
    $db->query("UPDATE tblinvoices SET date = '2000-01-01', duedate = '2000-01-01'");
    foreach ([1 => date('Y-m-d'), 2 => '2000-01-01'] as $id => $date) {
        $db->query('UPDATE tblinvoices SET status = 2 WHERE id = ?', [$id]);
        $db->insert('tblinvoicepaymentrecords', ['invoiceid' => $id, 'amount' => '100.00', 'paymentmode' => 'Asaas', 'transactionid' => 'pay_' . $id, 'date' => $date]);
    }
    $result = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($result['processed'] === 2 && $gateway->nativeCalls === [], 'Only recently received paid invoices and every open invoice belong in this cron round.');
    monitorCheck(array_map(static fn ($entry): string => $entry['request']->getUri()->getPath(), $history) === ['/v3/payments/pay_1', '/v3/payments/pay_3'], 'Paid invoice creation date must not hide a recent receipt; old historical receipts should stay outside the paid lookback.');
    $overview = $service->overview()['data']['cron'];
    monitorCheck($overview['open_invoices_date_limit'] === false && $overview['days_scope'] === 'recent_receipts_on_paid_invoices', 'UI metadata must accurately explain which invoices use the configured days.');
});

monitorTest('cron retains rate-limit diagnostics and does not retry the rest of a systemic failure batch', static function (): void {
    [$service, $gateway] = monitorFixture([monitorResponse([], 429)], $history, [1, 2]);
    $GLOBALS['monitor_options']['paymentmethod_asaas_sync_enabled'] = '1';
    $result = AsaasReconciliation::runCron(get_instance(), $gateway);
    monitorCheck($result['status'] === 'partial' && $result['processed'] === 1 && $result['remaining'] === 1 && $result['next_cursor'] === 1 && $result['interrupted'], 'Cron must stop the systemic failure without skipping untouched invoices.');
    monitorCheck($result['error_code'] === 'api_rate_limit' && $result['http_status'] === 429 && count($history) === 1, 'Cron state must preserve the useful safe failure category.');
    monitorCheck($service->overview()['data']['cron']['last_run']['error_code'] === 'api_rate_limit', 'Monitoring must surface the last cron error.');
});

monitorTest('legacy diagnostic details preserve validation reasons while redacting credentials and card data', static function (): void {
    [$service, $gateway, $db] = monitorFixture([], $history);
    $message = 'A data mínima de vencimento para novas cobranças é 06/11/2026. API key ' . MONITOR_API_FIXTURE . ' cardNumber=4444333322221111 cvv=123';
    $db->insert('tblasaas_logs', ['level' => 'error', 'message' => 'Erro na API Asaas.', 'context_json' => json_encode(['source' => 'api',
        'message' => $message, 'reason' => 'Referência inválida.', 'body' => 'secret-body-fixture', 'headers' => ['token' => MONITOR_API_FIXTURE], 'http_status' => 400]), 'created_at' => date('Y-m-d H:i:s')]);
    $page = $service->logs(['source' => 'api']);
    $context = $page['data'][0]['context'];
    monitorCheck(str_contains($context['message'], 'A data mínima de vencimento') && $context['reason'] === 'Referência inválida.' && $context['http_status'] === 400, 'Existing adapter validation detail must remain useful in the embedded log viewer.');
    $json = json_encode($page);
    monitorCheck(!str_contains($json, MONITOR_API_FIXTURE) && !str_contains($json, '4444333322221111') && !str_contains($json, 'cvv=123') && !str_contains($json, 'secret-body-fixture'), 'Allowlisting detail must not expose raw bodies, headers, credentials or PAN/CVV.');
});

monitorTest('custom invoice issuer scopes retain the native balance rules', static function (): void {
    class MonitorIssuerScopeFixture {}
    class_alias(MonitorIssuerScopeFixture::class, 'app\\services\\InvoiceIssuerScope');
    [$service, $gateway, $db] = monitorFixture([], $history, [42, 43]);
    $db->insert('tblcredits', ['invoice_id' => 42, 'amount' => '25.00']);
    $before = $GLOBALS['monitor_balance_calls'];
    $page = $service->invoices();
    monitorCheck($GLOBALS['monitor_balance_calls'] - $before === 2, 'Custom issuer installations must retain native scoped balance checks.');
    monitorCheck($page['data'][1]['invoice_id'] === 42 && $page['data'][1]['balance'] === '75.00', 'Native scoped values must be preserved.');
    monitorCheck($history === [], 'Native scoped display must remain offline.');
});

#[AllowDynamicProperties]
class AdminController
{
    public function __construct() { foreach (get_object_vars(get_instance()) as $name => $value) { $this->$name = $value; } }
}
final class MonitorOutput
{
    public int $status = 200;
    public array $headers = [];
    public string $body = '';
    public function set_header(string $header): self { $this->headers[] = $header; return $this; }
    public function set_status_header(int $status): self { $this->status = $status; return $this; }
    public function set_content_type(string $type, string $charset = ''): self { return $this; }
    public function set_output(string $body): self { $this->body = $body; return $this; }
    public function enable_profiler(bool $enabled): self { return $this; }
}
final class MonitorInput
{
    public string $verb = 'GET';
    public array $query = [];
    public array $post = [];
    public function method(bool $upper = false): string { return $this->verb; }
    public function get(string $name): mixed { return $this->query[$name] ?? null; }
    public function post(string $name): mixed { return $this->post[$name] ?? null; }
}
require_once dirname(__DIR__) . '/controllers/Monitor.php';
monitorTest('controller gates admin/method, returns fresh CSRF and avoids SDK bootstrap', static function (): void {
    [$service, $gateway] = monitorFixture([], $history);
    get_instance()->input = new MonitorInput();
    get_instance()->output = new MonitorOutput();
    get_instance()->security = new class {
        public function get_csrf_token_name(): string { return 'csrf_fixture'; }
        public function get_csrf_hash(): string { return 'fresh_hash'; }
    };
    $controller = new Monitor();
    $controller->overview();
    $json = json_decode(get_instance()->output->body, true, 512, JSON_THROW_ON_ERROR);
    monitorCheck($json['ok'] === true && $json['csrf'] === ['name' => 'csrf_fixture', 'hash' => 'fresh_hash'], 'All JSON responses need native CSRF refresh.');
    monitorCheck(get_instance()->load->libraries === 0, 'Controller listing must not initialize SDK.');
    $controller->reconcile();
    monitorCheck(get_instance()->output->status === 405, 'GET must never trigger reconciliation.');
    get_instance()->input->verb = 'POST';
    get_instance()->input->post = ['invoice_ids' => ['1 OR 1=1']];
    $controller->reconcile();
    monitorCheck(get_instance()->output->status === 422, 'Invalid ids must be rejected before HTTP.');
    $GLOBALS['monitor_admin'] = false;
    get_instance()->input->verb = 'GET';
    $controller->overview();
    monitorCheck(get_instance()->output->status === 403 && $history === [], 'Non-admin must not read or reconcile.');
    $source = file_get_contents(dirname(__DIR__) . '/controllers/Monitor.php');
    monitorCheck(!str_contains($source, 'csrf_exclude') && !str_contains($source, 'csrf_protection'), 'Controller must not bypass native CSRF.');
});

monitorTest('controller permits only administrative GET for provider browsing and returns CSRF plus no-store', static function (): void {
    [$service, $gateway] = monitorFixture([monitorPaymentList([], false, 0)], $history);
    get_instance()->input = new MonitorInput();
    get_instance()->output = new MonitorOutput();
    get_instance()->security = new class {
        public function get_csrf_token_name(): string { return 'csrf_fixture'; }
        public function get_csrf_hash(): string { return 'fresh_hash'; }
    };
    $controller = new Monitor();
    get_instance()->input->verb = 'POST';
    $controller->payments();
    monitorCheck(get_instance()->output->status === 405 && $history === [], 'Remote listing must reject POST before initializing HTTP.');
    get_instance()->input->verb = 'GET';
    $GLOBALS['monitor_admin'] = false;
    $controller->payments();
    monitorCheck(get_instance()->output->status === 403 && $history === [], 'Only administrators may inspect provider charges.');
    $GLOBALS['monitor_admin'] = true;
    $controller->payments();
    $json = json_decode(get_instance()->output->body, true, 512, JSON_THROW_ON_ERROR);
    monitorCheck(get_instance()->output->status === 200 && $json['ok'] && $json['data'] === [] && count($history) === 1, 'A legitimate empty page must return a successful, explicit response.');
    monitorCheck($json['csrf'] === ['name' => 'csrf_fixture', 'hash' => 'fresh_hash'] && in_array('Cache-Control: no-store, private, max-age=0', get_instance()->output->headers, true), 'Remote responses must remain private and refresh the native CSRF token.');
    monitorAllGets($history);
});

monitorTest('controller rejects invalid remote dates before HTTP and preserves actionable API failures', static function (): void {
    [$service, $gateway] = monitorFixture([monitorResponse([], 401)], $history);
    get_instance()->input = new MonitorInput();
    get_instance()->output = new MonitorOutput();
    get_instance()->security = new class {
        public function get_csrf_token_name(): string { return 'csrf_fixture'; }
        public function get_csrf_hash(): string { return 'fresh_hash'; }
    };
    $controller = new Monitor();
    get_instance()->input->query = ['date_from' => '2026-02-30'];
    $controller->payments();
    monitorCheck(get_instance()->output->status === 422 && $history === [], 'An invalid calendar date must never be sent to the API.');
    get_instance()->input->query = ['date_field' => ['payment']];
    $controller->payments();
    monitorCheck(get_instance()->output->status === 422 && $history === [], 'Nested query parameters must be rejected before the provider request.');
    get_instance()->input->query = [];
    $controller->payments();
    $json = json_decode(get_instance()->output->body, true, 512, JSON_THROW_ON_ERROR);
    monitorCheck(get_instance()->output->status === 503 && !$json['ok'] && $json['error']['code'] === 'api_authentication' && $json['error']['http_status'] === 401, 'The controller must preserve the safe provider failure instead of reporting an empty result.');
    monitorCheck($json['csrf']['hash'] === 'fresh_hash' && in_array('Cache-Control: no-store, private, max-age=0', get_instance()->output->headers, true), 'Failures must also refresh CSRF and stay uncached.');
    monitorAllGets($history);
});

monitorTest('formatted number and client selection constrain all remote pages without receipts', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorPaymentList([], false, 0)], $history);
    $db->query("UPDATE tblinvoices SET prefix = 'FAT-', number = 387, number_format = 4, date = '2026-11-01' WHERE id = 42");
    $targets = $service->targets(['q' => 'FAT-000387/11/2026', 'search_kind' => 'invoice']);
    monitorCheck(count($targets['data']) === 1 && $targets['data'][0]['id'] === 42 && $history === [], 'Full number lookup must remain local and resolve the native ID.');
    $empty = $service->payments(['search_kind' => 'invoice', 'target_id' => '42']);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    monitorCheck($empty['ok'] && $query['externalReference'] === 'hash-42', 'Selected invoice must filter the remote query by its exact stored reference.');
    monitorCheck($gateway->nativeCalls === [], 'Selection and browsing must not register receipts.');
    monitorAllGets($history);

    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorCustomer()), monitorPaymentList([], false, 0)], $history);
    $targets = $service->targets(['q' => 'Cliente', 'search_kind' => 'client']);
    monitorCheck($targets['data'][0]['id'] === 7, 'Customer names must resolve to explicit CRM choices.');
    $service->payments(['search_kind' => 'client', 'target_id' => '7', 'page' => 2, 'status' => 'RECEIVED']);
    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    monitorCheck($query['customer'] === 'cus_fixture' && $query['status'] === 'RECEIVED' && $query['offset'] === '50', 'Customer and status filters must persist on a later provider page.');
    monitorCheck(count($history) === 2 && $gateway->nativeCalls === [], 'Filter must prove customer identity via GET and perform no mutation.');
    monitorAllGets($history);
});

monitorTest('selected identity cannot silently fall back to an unfiltered account query', static function (): void {
    [$service, $gateway] = monitorFixture([], $history);
    foreach ([['search_kind' => 'client', 'target_id' => '1 OR 1=1'], ['search_kind' => 'invoice', 'target_id' => '99999']] as $filters) {
        $rejected = false;
        try { $service->payments($filters); } catch (InvalidArgumentException) { $rejected = true; }
        monitorCheck($rejected && $history === [], 'An invalid selection must fail before querying all provider payments.');
    }
    [$service, $gateway] = monitorFixture([monitorResponse(monitorCustomer(['externalReference' => '8']))], $history);
    $rejected = false;
    try { $service->payments(['search_kind' => 'client', 'target_id' => '7']); } catch (InvalidArgumentException) { $rejected = true; }
    monitorCheck($rejected && count($history) === 1 && $gateway->nativeCalls === [], 'A customer from another reference/environment must never become the selected filter.');
});

echo sprintf("Monitor reconciliation: %d scenarios, %d assertions, %d failures.\n", $GLOBALS['monitor_scenarios'], $GLOBALS['monitor_assertions'], $GLOBALS['monitor_failures']);
exit($GLOBALS['monitor_failures'] > 0 ? 1 : 0);
