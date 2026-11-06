<?php

declare(strict_types=1);

/** Offline integration: real service/store/checkout, SQLite durability and simulated Asaas. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
$GLOBALS['recurring_ci'] = new stdClass();
$GLOBALS['recurring_options'] = ['paymentmethod_asaas_recurring_active' => '1'];
$GLOBALS['recurring_logs'] = [];
$GLOBALS['recurring_assertions'] = 0;
$GLOBALS['recurring_cases'] = 0;
$GLOBALS['recurring_failures'] = 0;
function &get_instance(): object { return $GLOBALS['recurring_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return $GLOBALS['recurring_options'][$name] ?? false; }
function log_message(string $level, string $message): void { $GLOBALS['recurring_logs'][] = $message; }
function format_invoice_number($id): string { return 'FAT-' . $id; }
function is_https(): bool { return true; }
function register_activation_hook(...$arguments): void {}
function register_payment_gateway(...$arguments): void {}
function hooks(): object { static $hooks; return $hooks ??= new class { public function add_action(...$a): void {} public function add_filter(...$a): void {} }; }
function get_invoice_total_left_to_pay($id, $total = null): string { return get_instance()->invoices_model->get($id)->balance; }
require_once dirname(__DIR__) . '/asaas.php';
require_once dirname(__DIR__) . '/helpers/asaas_recurring_schema_helper.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasCardCheckout.php';

function recurringAssert(bool $condition, string $message): void
{
    ++$GLOBALS['recurring_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function recurringCase(string $name, callable $test): void
{
    ++$GLOBALS['recurring_cases'];
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['recurring_failures']; echo 'FAIL ' . $name . ': ' . $error->getMessage() . ' @ ' . $error->getLine() . PHP_EOL; }
}

final class RecurringResult
{
    public function __construct(private array $rows) {}
    public function result_array(): array { return $this->rows; }
    public function row_array(): array { return $this->rows[0] ?? []; }
}
final class RecurringDatabase
{
    public string $dbdriver = 'pdo';
    public string $subdriver = 'sqlite';
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public PDO $conn_id;
    public string $path;
    private int $affected = 0;
    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'asaas-recurring-');
        $this->conn_id = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->query('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, clientid INT, status INT, allowed_payment_modes TEXT, total TEXT, data TEXT)');
        $this->query('CREATE TABLE tblcontacts (id INTEGER PRIMARY KEY, userid INT, active INT)');
        $this->query('CREATE TABLE tbloptions (name TEXT PRIMARY KEY, value TEXT)');
        $this->query("INSERT INTO tbloptions VALUES ('paymentmethod_asaas_active','1'),('paymentmethod_asaas_recurring_active','1')");
        $this->query('CREATE TABLE tblasaas_payments_map (invoice_id INTEGER PRIMARY KEY, asaas_payment_id TEXT, payload_cache TEXT)');
        $this->query('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY, level TEXT, message TEXT, context_json TEXT, created_at TEXT)');
        $this->query('INSERT INTO tblcontacts VALUES (9, 7, 1)');
        asaas_recurring_schema_install($this);
    }
    public function __destruct() { @unlink($this->path); }
    public function query(string $sql, array $bindings = []): RecurringResult
    {
        if (str_contains($sql, 'ENGINE=InnoDB')) {
            // Execute the real additive DDL after translating MySQL-only index/identity syntax.
            $sql = str_replace('INT NOT NULL AUTO_INCREMENT', 'INTEGER', $sql);
            $sql = preg_replace('/UNIQUE KEY `[^`]+`/', 'UNIQUE', $sql);
            $sql = preg_replace('/^\s*KEY `[^`]+`[^\n]*\n/m', '', $sql);
            $sql = preg_replace('/,\s*\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', ');', $sql);
        }
        $statement = $this->conn_id->prepare($sql);
        $statement->execute($bindings);
        $this->affected = $statement->rowCount();
        return new RecurringResult($statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function table_exists(string $table): bool { return $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table])->row_array() !== []; }
    public function affected_rows(): int { return $this->affected; }
    public function list_fields(string $table): array { return array_column($this->query('PRAGMA table_info(' . $table . ')')->result_array(), 'name'); }
    public function get_where(string $table, array $where): RecurringResult
    {
        return $this->query('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn ($key) => $key . ' = ?', array_keys($where))), array_values($where));
    }
    public function insert(string $table, array $data): bool
    {
        $this->query('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
        return true;
    }
    public function invoice(int $id = 42, array $changes = []): object
    {
        $existing = $this->get_where('tblinvoices', ['id' => $id])->row_array();
        $data = $existing === [] ? ['id' => $id, 'hash' => 'recurring-fixture-' . $id, 'clientid' => 7, 'status' => 1,
            'allowed_payment_modes' => serialize(['asaas', 'asaas_recurring']), 'total' => '100.00', 'balance' => '100.00',
            'currency' => 1, 'currency_name' => 'BRL', 'duedate' => date('Y-m-d', time() + 864000)] : json_decode($existing['data'], true, 512, JSON_THROW_ON_ERROR);
        $data = array_replace($data, $changes);
        $this->query('INSERT INTO tblinvoices (id, clientid, status, allowed_payment_modes, total, data) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET clientid=excluded.clientid, status=excluded.status, allowed_payment_modes=excluded.allowed_payment_modes, total=excluded.total, data=excluded.data',
            [$id, $data['clientid'], $data['status'], $data['allowed_payment_modes'], $data['total'], json_encode($data, JSON_THROW_ON_ERROR)]);
        return (object) $data;
    }
}
final class RecurringEncryption
{
    private string $key;
    public function __construct() { $this->key = random_bytes(32); }
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        return base64_encode($iv . openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag)) . '.' . base64_encode($tag);
    }
    public function decrypt(string $cipher)
    {
        $parts = explode('.', $cipher); $value = base64_decode($parts[0], true);
        return $value === false || count($parts) !== 2 ? false : openssl_decrypt(substr($value, 12), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, substr($value, 0, 12), base64_decode($parts[1], true));
    }
}
/** SQL/CAS remain real. MySQL named locks are covered by separate MySQL tests. */
final class RecurringStoreFixture extends \Asaas\AsaasRecurringStore
{
    public bool $busy = false;
    public bool $lost = false;
    public int $depth = 0;
    public function ready(): bool { return true; }
    public function lock(int $clientId, string $scopeKey): string
    {
        if ($this->busy) { throw new RuntimeException('busy'); }
        ++$this->depth; return 'fixture-profile-lock';
    }
    public function assertLock(string $name): void { if ($this->lost || $this->depth < 1) { throw new RuntimeException('busy'); } }
    public function unlock(string $name): void { --$this->depth; }
}
final class RecurringProvider
{
    public string $environment = 'sandbox';
    public string $key = 'fictional-account-key';
    public array $companies = [];
    public function env(?int $company = null): string { return $this->environment; }
    public function apiKey(?int $company = null): string { return $this->companies[$company ?? 0] ?? $this->key; }
}
final class RecurringGateway
{
    public array $settings = ['active' => '1', 'card_only' => '1', 'discount_value' => '0', 'discount_type' => '1', 'installmentCount' => '12'];
    public array $remote = [];
    public array $receipts = [];
    public array $calls = [];
    public bool $receiptFails = false;
    public RecurringApi $api;
    public RecurringProvider $provider;
    public ?Closure $onUpdate = null;
    public function __construct() { $this->provider = new RecurringProvider(); $this->api = new RecurringApi($this); }
    public function getSetting(string $name) { return $this->settings[$name] ?? ''; }
    public function getProvider(): object { return $this->provider; }
    public function card_checkout(): object { return new \Asaas\AsaasCardCheckout($this, $this->api); }
    public function ensure_customer_from_client($client): array { $this->calls[] = 'customer'; return ['customerId' => 'cus_recurring_fixture']; }
    public function get_charge2(string $hash): array
    {
        $this->calls[] = 'list';
        return array_values(array_filter($this->remote, static fn (array $p): bool => $p['externalReference'] === $hash && empty($p['deleted'])));
    }
    public function request(string $method, string $path): array { return $this->api->getPayment(basename($path)); }
    public function update_charge(string $id, string $json): array
    {
        $this->calls[] = 'update';
        $this->remote[$id] = array_replace($this->remote[$id], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        if ($this->onUpdate !== null) { $call = $this->onUpdate; $this->onUpdate = null; $call(); }
        return $this->remote[$id];
    }
    public function upsert_payment_map(int $invoiceId, array $payment): bool
    {
        $old = asaas_lifecycle_cached_payment(asaas_lifecycle_map($invoiceId));
        $payment = asaas_card_safe_payload($payment);
        if (($old['id'] ?? '') === ($payment['id'] ?? '') && !isset($payment['_asaas_lifecycle']) && isset($old['_asaas_lifecycle'])) {
            $payment['_asaas_lifecycle'] = $old['_asaas_lifecycle'];
        }
        get_instance()->db->query('INSERT INTO tblasaas_payments_map (invoice_id, asaas_payment_id, payload_cache) VALUES (?, ?, ?) ON CONFLICT(invoice_id) DO UPDATE SET asaas_payment_id=excluded.asaas_payment_id, payload_cache=excluded.payload_cache',
            [$invoiceId, $payment['id'] ?? '', json_encode($payment, JSON_THROW_ON_ERROR)]);
        return true;
    }
    public function addPayment(array $receipt): bool
    {
        if ($this->receiptFails) { return false; }
        $key = $receipt['transactionid'];
        if (isset($this->receipts[$key])) { return true; }
        $this->receipts[$key] = $receipt;
        $invoice = get_instance()->invoices_model->get($receipt['invoiceid']);
        $balance = asaas_payment_amount_to_cents($invoice->balance) - asaas_payment_amount_to_cents($receipt['amount']);
        get_instance()->db->invoice((int) $invoice->id, ['balance' => asaas_card_money(max(0, $balance)), 'status' => $balance <= 0 ? 2 : 3]);
        return true;
    }
}
final class RecurringApi
{
    public int $tokenizations = 0;
    public int $captures = 0;
    public int $creations = 0;
    public int $gets = 0;
    public string $token = 'opaque-fixture-recurring-token-0001';
    public string $outcome = 'CONFIRMED';
    public ?Throwable $failure = null;
    public ?Throwable $creationFailure = null;
    public ?Closure $onTokenize = null;
    public function __construct(private RecurringGateway $gateway) {}
    public function tokenize(array $payload): string
    {
        ++$this->tokenizations;
        recurringAssert(isset($payload['creditCard'], $payload['creditCardHolderInfo']) && $payload['customer'] === 'cus_recurring_fixture', 'Tokenization binds the verified customer.');
        if ($this->onTokenize !== null) { ($this->onTokenize)(); }
        return $this->token;
    }
    public function createPayment(array $payload): array
    {
        ++$this->creations;
        recurringAssert(!isset($payload['creditCard'], $payload['creditCardToken']) && !isset($payload['creditCardHolderInfo']), 'Debt creation must have no card credentials.');
        $id = 'pay_recurring_' . $this->creations;
        $row = array_replace($payload, ['id' => $id, 'status' => 'PENDING']);
        $this->gateway->remote[$id] = $row;
        if ($this->creationFailure !== null) { throw $this->creationFailure; }
        return $row;
    }
    public function payPayment(string $id, array $payload): array
    {
        ++$this->captures;
        recurringAssert(array_keys($payload) === ['creditCardToken'] && $payload['creditCardToken'] === $this->token, 'Capture must send the token only.');
        $db = get_instance()->db;
        $reader = new PDO('sqlite:' . $db->path);
        $rows = $reader->query("SELECT state FROM tblasaas_recurring_jobs WHERE payment_id='" . $id . "'")->fetchAll(PDO::FETCH_COLUMN);
        recurringAssert($rows === ['processing'] && !$db->conn_id->inTransaction(), 'An independent connection sees the durable job before capture.');
        $this->gateway->remote[$id]['status'] = $this->outcome;
        if ($this->failure !== null) { throw $this->failure; }
        return $this->gateway->remote[$id];
    }
    public function getPayment(string $id): array
    {
        ++$this->gets;
        return $this->gateway->remote[$id] ?? throw new RuntimeException('Not found', 404);
    }
}
function recurringFixture(): array
{
    $GLOBALS['recurring_options'] = ['paymentmethod_asaas_recurring_active' => '1'];
    $GLOBALS['recurring_logs'] = [];
    $ci = get_instance();
    $ci->db = new RecurringDatabase();
    $ci->encryption = new RecurringEncryption();
    $ci->load = new class { public function model($name): void {} public function library($name): void {} };
    $ci->invoices_model = new class {
        public function get($id): ?object {
            $row = get_instance()->db->get_where('tblinvoices', ['id' => $id])->row_array();
            return $row === [] ? null : (object) json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        }
    };
    $gateway = $ci->asaas_gateway = new RecurringGateway();
    $store = new RecurringStoreFixture($ci->db);
    $service = new \Asaas\AsaasRecurring($gateway, $store, $gateway->api);
    return [$service, $store, $gateway, $ci->db->invoice()];
}
function recurringCard(): array
{
    return ['holderName' => 'Titular Fixture', 'number' => '4111111111111111', 'expiryMonth' => '12', 'expiryYear' => (string) ((int) date('Y') + 3),
        'cvv' => '937', 'installmentCount' => '1', 'holderEmail' => 'fixture@example.invalid', 'holderDocument' => '11122233344',
        'holderPostalCode' => '12345678', 'holderAddressNumber' => '10', 'holderPhone' => '11911112222'];
}
function recurringConsent(object $service, object $invoice, array $changes = []): array
{
    return array_replace(['accepted' => true, 'pay_now' => true, 'contact_id' => 9, 'ip' => '192.0.2.1',
        'terms_version' => \Asaas\AsaasRecurring::CONSENT_VERSION, 'quote' => $service->page($invoice)['quote']], $changes);
}
function recurringProfile(): array { return get_instance()->db->query('SELECT * FROM tblasaas_recurring_authorizations LIMIT 1')->row_array(); }

recurringCase('read model and invalid consent never tokenize or capture', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $page = $service->page($invoice);
    recurringAssert($page['can_enroll'] && $page['total'] === '100.00', 'Valid one-payment offer.');
    foreach ([['accepted' => false], ['pay_now' => false], ['terms_version' => 'old'], ['quote' => 'changed'], ['contact_id' => 22]] as $changes) {
        $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice, $changes));
        recurringAssert(!$outcome['ok'] && $gateway->api->tokenizations === 0 && $gateway->api->captures === 0, 'Invalid consent performs no financial request.');
    }
});
recurringCase('explicit current invoice enrolls, captures once, encrypts only a token', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'confirmed', json_encode($outcome));
    recurringAssert($gateway->api->captures === 1 && $gateway->api->creations === 1 && count($gateway->receipts) === 1, 'One debt, one capture, one receipt.');
    $profile = recurringProfile();
    recurringAssert($profile['state'] === 'active' && (int) $profile['cutoff_invoice_id'] === 42, 'Authorization cutoff is frozen.');
    $dump = json_encode([$outcome, $service->page($invoice), $profile, get_instance()->db->query('SELECT * FROM tblasaas_payments_map')->result_array(), $GLOBALS['recurring_logs']]);
    foreach ([$gateway->api->token, recurringCard()['number'], recurringCard()['holderEmail'], recurringCard()['holderName']] as $sensitive) {
        recurringAssert(!str_contains($dump, $sensitive), 'No plaintext credentials/cardholder data in stored or public records.');
    }
    $service->processInvoice(42);
    recurringAssert($gateway->api->captures === 1, 'Completed job never captures again.');
});
recurringCase('new invoices only, automatic hook queues inside transaction without API calls', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    get_instance()->db->invoice(40);
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $next = get_instance()->db->invoice(43);
    get_instance()->db->conn_id->beginTransaction(); get_instance()->db->_trans_depth = 1;
    recurringAssert($service->enqueue(43), 'New eligible invoice queued.');
    recurringAssert($gateway->api->captures === 1 && $gateway->api->creations === 1, 'No request in after-hook transaction.');
    recurringAssert(!$service->enqueue(40) && !$service->enqueue(43, true), 'No historical or forged-explicit enqueue.');
    get_instance()->db->conn_id->commit(); get_instance()->db->_trans_depth = 0;
    $outcome = $service->processInvoice(43);
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->captures === 2, json_encode($outcome));
});
recurringCase('definitive rejection pauses and destroys token; explicit reenrollment may retry', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->outcome = 'PENDING';
    $gateway->api->failure = new \Asaas\AsaasCardRequestException('secret must not surface', 400, true);
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'declined' && recurringProfile()['token_ciphertext'] === null, json_encode($outcome));
    recurringAssert(get_instance()->invoices_model->get(42)->status === 1, 'Refusal leaves the CRM invoice open.');
    $service->processInvoice(42);
    recurringAssert($gateway->api->captures === 1, 'Cron does not retry a refusal.');
    $gateway->api->failure = null; $gateway->api->outcome = 'CONFIRMED';
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'confirmed' && (int) recurringProfile()['revision'] === 2 && $gateway->api->captures === 2, json_encode($outcome));
});
recurringCase('timeout freezes retries and reenrollment until GET confirms outcome', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->outcome = 'PENDING';
    $gateway->api->failure = new \Asaas\AsaasCardRequestException('timeout', 0, true);
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'uncertain', json_encode($outcome));
    $service->processInvoice(42);
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $other = get_instance()->db->invoice(43);
    $service->enroll($other, recurringCard(), recurringConsent($service, $other));
    recurringAssert($gateway->api->captures === 1 && $gateway->api->tokenizations === 1, 'No retry and no cross-invoice reenrollment around an uncertain job.');
    $gateway->api->failure = null;
    $gateway->remote['pay_recurring_1']['status'] = 'CONFIRMED';
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->captures === 1 && count($gateway->receipts) === 1, json_encode($outcome));
});
recurringCase('revocation remains available on paid and uncertain invoices', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $paid = get_instance()->invoices_model->get(42);
    $page = $service->page($paid);
    recurringAssert(!$page['can_enroll'] && $page['can_revoke'], 'Paid invoice still manages authorization.');
    recurringAssert($service->revoke($paid)['ok'] && recurringProfile()['state'] === 'revoked' && recurringProfile()['token_ciphertext'] === null, 'Revocation wipes credential.');
    $next = get_instance()->db->invoice(43);
    recurringAssert(!$service->enqueue(43), 'Revoked authorization cannot queue new invoices.');
});
recurringCase('pricing change pauses future work; unrelated installment policy does not', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->settings['card_fee_mode'] = 'customer';
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $invoice43 = get_instance()->db->invoice(43);
    $gateway->settings['card_fee_percent_2_6'] = '7.00';
    recurringAssert($service->enqueue(43), 'Installment-only settings do not change 1x consent.');
    $gateway->settings['card_fee_percent_1'] = '8.00';
    $outcome = $service->processInvoice(43);
    recurringAssert($outcome['state'] === 'skipped' && recurringProfile()['state'] === 'suspended' && recurringProfile()['token_ciphertext'] === null, json_encode($outcome));
    recurringAssert($gateway->api->captures === 1, 'No capture after effective fee change.');
});
recurringCase('one-payment fees reach Asaas while principal reaches CRM', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->settings['card_fee_mode'] = 'customer';
    $offer = $service->page($invoice);
    recurringAssert((float) $offer['total'] > 100, 'Offer includes configured card cost.');
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->receipts['pay_recurring_1']['amount'] === '100.00', json_encode($outcome));
    recurringAssert($gateway->remote['pay_recurring_1']['value'] === $offer['total'], 'Remote gross is the accepted offer.');
});
recurringCase('account environment and issuer mismatch stop future capture', function (): void {
    foreach (['key', 'environment', 'issuer'] as $change) {
        [$service, $store, $gateway, $invoice] = recurringFixture();
        $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
        $next = get_instance()->db->invoice(43); $service->enqueue(43);
        if ($change === 'key') { $gateway->provider->key = 'changed-account-key'; }
        elseif ($change === 'environment') { $gateway->provider->environment = 'production'; }
        else { get_instance()->db->invoice(43, ['issuer_id' => 3]); }
        $outcome = $service->processInvoice(43);
        recurringAssert($outcome['state'] === 'skipped' && $gateway->api->captures === 1, 'Changed scope blocks capture: ' . $change);
    }
});
recurringCase('failed lock never modifies another worker job', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    get_instance()->db->invoice(43); $service->enqueue(43);
    $before = $store->job(43); $store->busy = true;
    $outcome = $service->processInvoice(43);
    recurringAssert($outcome['error_code'] === 'busy' && $store->job(43) === $before, 'Loser must not touch durable job.');
});
recurringCase('token envelope prevents swapping customers or revisions', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    get_instance()->db->invoice(43); $service->enqueue(43);
    $profile = recurringProfile();
    $plain = json_decode($store->unseal($profile['token_ciphertext']), true, 512, JSON_THROW_ON_ERROR);
    $plain['revision'] = 17;
    get_instance()->db->query('UPDATE tblasaas_recurring_authorizations SET token_ciphertext=?', [$store->seal(json_encode($plain))]);
    $outcome = $service->processInvoice(43);
    recurringAssert($gateway->api->captures === 1 && recurringProfile()['token_ciphertext'] === null, 'Owner-bound vault refuses a swapped revision.');
});
recurringCase('creation timeout never creates again and never captures by inference', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->creationFailure = new \Asaas\AsaasCardRequestException('timeout creating debt', 0, true);
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'uncertain' && $gateway->api->captures === 0, 'Uncertain preparation stops before token capture.');
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'skipped' && $gateway->api->creations === 1 && $gateway->api->captures === 0, json_encode($outcome));
});
recurringCase('CRM receipt failure is retried only as reconciliation', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->receiptFails = true;
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'uncertain' && $outcome['error_code'] === 'receipt_pending', json_encode($outcome));
    $gateway->receiptFails = false;
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->captures === 1, 'No repeated capture after a local persistence failure.');
});
recurringCase('cron repairs missing eligible jobs with a ten-invoice capture bound', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    foreach (range(43, 56) as $id) { get_instance()->db->invoice($id); }
    $outcome = $service->work();
    recurringAssert($outcome['checked'] === 10 && $outcome['confirmed'] === 10 && $gateway->api->captures === 11, json_encode($outcome));
    recurringAssert($store->job(56)['state'] === 'queued', 'Later invoices remain durably queued.');
    $service->work();
    recurringAssert($gateway->api->captures === 15, 'Next round advances without duplicate captures.');
});
recurringCase('transaction active never begins tokenization or capture', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    get_instance()->db->_trans_depth = 1;
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['error_code'] === 'transaction_active' && $gateway->api->captures === 0 && $gateway->api->tokenizations === 0, json_encode($outcome));
    get_instance()->db->_trans_depth = 0;
});
recurringCase('numeric token responses are discarded instead of persisting a PAN', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->token = '4111111111111111';
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert(!$outcome['ok'] && recurringProfile() === [] && $gateway->api->captures === 0, 'An invalid provider token never reaches the vault.');
});
recurringCase('repeat webhook only observes known attempts and does not resuspend newer consent', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $service->observePayment(['id' => 'pay_recurring_1', 'status' => 'REPROVED_BY_RISK_ANALYSIS'], 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED');
    recurringAssert(recurringProfile()['state'] === 'active' && $gateway->api->captures === 1, 'Stale refusal for a confirmed job is ignored.');
    $service->observePayment(['id' => 'pay_unrelated'], 'PAYMENT_CONFIRMED');
    recurringAssert($gateway->api->captures === 1, 'Unknown webhook cannot start recurring work.');
});

recurringCase('foreign currency cannot enroll or enter automatic collection through direct calls', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $invoice = get_instance()->db->invoice(42, ['currency' => 2, 'currency_name' => 'USD']);
    recurringAssert(!$service->available($invoice) && !$service->authorizesNewInvoice((array) $invoice), 'Foreign currency is not eligible.');
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert(!$outcome['ok'] && $gateway->api->tokenizations === 0 && $gateway->api->captures === 0, 'No request for USD invoice.');
});
recurringCase('consent and invoice are revalidated after tokenization and just before capture', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->onTokenize = static function (): void { get_instance()->db->invoice(42, ['balance' => '120.00', 'total' => '120.00']); };
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['error_code'] === 'quote_changed' && $gateway->api->captures === 0 && recurringProfile() === [], json_encode($outcome));
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->onUpdate = static function () use ($gateway): void { $gateway->provider->key = 'changed-mid-flight-account'; };
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($gateway->api->captures === 0 && recurringProfile()['state'] === 'suspended', json_encode($outcome));
});
recurringCase('disabled gateway still reconciles prior attempts but skips queued captures', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->outcome = 'PENDING'; $gateway->api->failure = new \Asaas\AsaasCardRequestException('timeout', 0, true);
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    get_instance()->db->invoice(43); $service->enqueue(43);
    $GLOBALS['recurring_options']['paymentmethod_asaas_recurring_active'] = '0';
    get_instance()->db->query("UPDATE tbloptions SET value='0' WHERE name='paymentmethod_asaas_recurring_active'");
    $gateway->remote['pay_recurring_1']['status'] = 'CONFIRMED';
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->captures === 1, 'Disabling new captures does not disable GET-only recovery.');
    $outcome = $service->processInvoice(43);
    recurringAssert($outcome['state'] === 'skipped' && $gateway->api->captures === 1, 'Disabled gateway never captures a queued debt.');
});
recurringCase('a historical job cannot suspend or decrypt a newer authorization revision', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->outcome = 'PENDING'; $gateway->api->failure = new \Asaas\AsaasCardRequestException('timeout', 0, true);
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    // Simulate an already-existing newer revision, independent of current UI guards.
    $old = recurringProfile();
    get_instance()->db->query("UPDATE tblasaas_recurring_authorizations SET revision=2, state='active', token_ciphertext='newer-encrypted-credential'");
    $gateway->remote['pay_recurring_1']['status'] = 'REPROVED_BY_RISK_ANALYSIS';
    $getsBefore = $gateway->api->gets;
    $service->observePayment(['id' => 'pay_recurring_1'], 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED');
    recurringAssert($gateway->api->gets === $getsBefore && $store->job(42)['state'] === 'uncertain'
        && $store->job(42)['next_check_at'] <= date('Y-m-d H:i:s'), 'Observer must only schedule review without HTTP or changing financial state.');
    $service->processInvoice(42);
    $profile = recurringProfile();
    recurringAssert($profile['state'] === 'active' && $profile['token_ciphertext'] === 'newer-encrypted-credential' && (int) $profile['revision'] === 2,
        'Result from revision1 cannot invalidate revision2.');
    recurringAssert($store->job(42)['state'] === 'declined' && $gateway->api->captures === 1, 'Old attempt closes without retry.');
});
recurringCase('capture phase without marker may confirm through GET but pending cannot unlock retry', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->api->outcome = 'PENDING'; $gateway->api->failure = new \Asaas\AsaasCardRequestException('timeout', 0, true);
    $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    $cache = $store->cache(42); unset($cache['_asaas_lifecycle']['card_attempt']);
    $gateway->upsert_payment_map(42, $cache);
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'uncertain' && $gateway->api->captures === 1, 'Pending is not proof of an uncharged card.');
    $gateway->remote['pay_recurring_1']['status'] = 'CONFIRMED';
    $outcome = $service->processInvoice(42);
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->captures === 1, 'Known provider receipt can resolve the missing marker.');
});
recurringCase('persisted deactivation overrides a stale request cache before capture', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $gateway->onUpdate = static function (): void {
        // Keep get_option() cache enabled and change only the persisted flag.
        get_instance()->db->query("UPDATE tbloptions SET value='0' WHERE name='paymentmethod_asaas_recurring_active'");
    };
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert(get_option('paymentmethod_asaas_recurring_active') === '1' && $gateway->api->captures === 0,
        'Fresh SQL activation check must beat the cached flag at the last capture gate.');
});
recurringCase('existing overdue boleto is captured without recreating or changing due date', function (): void {
    [$service, $store, $gateway, $invoice] = recurringFixture();
    $invoice = get_instance()->db->invoice(42, ['allowed_payment_modes' => serialize(['asaas']), 'duedate' => '2020-01-01', 'status' => 4]);
    $gateway->remote['pay_existing_overdue'] = ['id' => 'pay_existing_overdue', 'customer' => 'cus_recurring_fixture',
        'externalReference' => $invoice->hash, 'billingType' => 'BOLETO', 'status' => 'OVERDUE', 'value' => '100.00', 'dueDate' => '2020-01-01'];
    $outcome = $service->enroll($invoice, recurringCard(), recurringConsent($service, $invoice));
    recurringAssert($outcome['state'] === 'confirmed' && $gateway->api->creations === 0 && $gateway->api->captures === 1, json_encode($outcome));
    recurringAssert($gateway->remote['pay_existing_overdue']['dueDate'] === '2020-01-01' && !in_array('customer', $gateway->calls, true),
        'Existing charge/customer preferences and original due date are preserved.');
});

echo $GLOBALS['recurring_cases'] . ' scenarios; ' . $GLOBALS['recurring_assertions'] . ' assertions; ' . $GLOBALS['recurring_failures'] . ' failures.' . PHP_EOL;
exit($GLOBALS['recurring_failures'] === 0 ? 0 : 1);
