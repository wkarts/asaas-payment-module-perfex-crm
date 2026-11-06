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
        $this->conn_id->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total TEXT, status TEXT, hash TEXT, clientid INTEGER, date TEXT, duedate TEXT, number INTEGER, number_format INTEGER, prefix TEXT, allowed_payment_modes TEXT)');
        $this->conn_id->exec('CREATE TABLE tblinvoicepaymentrecords (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, amount TEXT, paymentmode TEXT, paymentmethod TEXT, transactionid TEXT, note TEXT)');
        $this->conn_id->exec('CREATE TABLE tblcredits (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount TEXT)');
        $this->conn_id->exec('CREATE TABLE tblclients (userid INTEGER PRIMARY KEY, company TEXT, asaas_customer_id TEXT)');
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
    [$unused, $gateway] = monitorFixture([monitorResponse(monitorPayment())], $history, [42, 43]);
    $times = [0, 0, 46];
    $service = new AsaasReconciliation(get_instance(), $gateway, static function () use (&$times): float { return (float) array_shift($times); });
    $result = $service->process([42, 43]);
    monitorCheck(count($result['data']) === 1 && $result['remaining_ids'] === [43], 'Deadline must return unstarted ids without inventing a failure.');
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

monitorTest('cron obeys enabled state, fair cursor, cooldown, window and overlap lock', static function (): void {
    [$service, $gateway, $db] = monitorFixture([monitorResponse(monitorPayment(1, ['status' => 'PENDING'])), monitorResponse(monitorPayment(2, ['status' => 'PENDING']))], $history, [1, 2, 3]);
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
    monitorCheck($third['processed'] === 0 && $third['next_cursor'] === 0 && count($history) === 2, 'Cooldown and lookback must prevent repeated polling.');
    $db->denyCronLock = true;
    monitorCheck(AsaasReconciliation::runCron(get_instance(), $gateway)['status'] === 'busy' && count($history) === 2, 'Concurrent cron execution must stop before HTTP.');
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

echo sprintf("Monitor reconciliation: %d scenarios, %d assertions, %d failures.\n", $GLOBALS['monitor_scenarios'], $GLOBALS['monitor_assertions'], $GLOBALS['monitor_failures']);
exit($GLOBALS['monitor_failures'] > 0 ? 1 : 0);
