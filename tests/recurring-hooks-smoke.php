<?php

declare(strict_types=1);

/**
 * Real module bootstrap, recurring hooks/service/store and schema, without network.
 * SQLite executes persistence/transaction assertions; named locks are explicit doubles.
 * This does not assert MySQL lock contention or provider/card approval.
 * Run: php tests/recurring-hooks-smoke.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);

$GLOBALS['recurring_hooks_ci'] = new stdClass();
$GLOBALS['recurring_hooks_options'] = [];
$GLOBALS['recurring_hooks_log'] = [];
$GLOBALS['recurring_hooks_registry'] = ['action' => [], 'filter' => [], 'gateway' => []];
$GLOBALS['recurring_hooks_files'] = [];
$GLOBALS['recurring_hooks_assertions'] = 0;
$GLOBALS['recurring_hooks_scenarios'] = 0;
$GLOBALS['recurring_hooks_failures'] = 0;

function &get_instance(): object { return $GLOBALS['recurring_hooks_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return $GLOBALS['recurring_hooks_options'][$name] ?? false; }
function log_message(string $level, string $message): void { $GLOBALS['recurring_hooks_log'][] = [$level, $message]; }
function is_client_logged_in(): bool { return $GLOBALS['recurring_hooks_client'] > 0; }
function get_client_user_id(): int { return $GLOBALS['recurring_hooks_client']; }
function get_invoice_total_left_to_pay($id, $total = null): string { return '100.00'; }
function get_currency($id): object { return (object) ['name' => (int) $id === 1 ? 'BRL' : 'USD']; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . $path; }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function register_activation_hook(string $module, callable $callback): void {}
function register_payment_gateway(string $gateway, string $module): void
{
    $GLOBALS['recurring_hooks_registry']['gateway'][] = [$gateway, $module];
}
function hooks(): object
{
    static $registry;
    return $registry ??= new class {
        public function add_action(string $hook, $callback, int $priority = 10, int $arguments = 1): void
        { $GLOBALS['recurring_hooks_registry']['action'][$hook][] = [$callback, $priority, $arguments]; }
        public function add_filter(string $hook, $callback, int $priority = 10, int $arguments = 1): void
        { $GLOBALS['recurring_hooks_registry']['filter'][$hook][] = [$callback, $priority, $arguments]; }
    };
}

require_once dirname(__DIR__) . '/asaas.php';
require_once dirname(__DIR__) . '/helpers/asaas_recurring_schema_helper.php';

final class RecurringHooksResult
{
    public function __construct(private array $rows = []) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
}

final class RecurringHooksDatabase
{
    public string $dbdriver = 'pdo';
    public string $subdriver = 'mysql'; // Explicit lock double, never presented as MySQL coverage.
    public string $hostname = 'offline-fixture';
    public string $database = 'recurring-hooks';
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public PDO $conn_id;
    public string $path;
    public array $trace = [];
    public array $observed = [];
    public bool $failObserver = false;
    private int $affected = 0;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'asaas-recurring-hooks-');
        $GLOBALS['recurring_hooks_files'][] = $this->path;
        $this->conn_id = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->conn_id->sqliteCreateFunction('GET_LOCK', static fn (string $name, int $seconds): int => 1, 2);
        $this->conn_id->sqliteCreateFunction('IS_USED_LOCK', static fn (string $name): int => 71, 1);
        $this->conn_id->sqliteCreateFunction('CONNECTION_ID', static fn (): int => 71, 0);
        $this->conn_id->sqliteCreateFunction('RELEASE_LOCK', static fn (string $name): int => 1, 1);
        $this->conn_id->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $this->conn_id->exec('CREATE TABLE tbloptions (name TEXT PRIMARY KEY, value TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_payments_map (invoice_id INTEGER PRIMARY KEY, payload_cache TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY, level TEXT, message TEXT, context_json TEXT, created_at TEXT, correlation_id TEXT)');
        asaas_recurring_schema_install($this);
    }
    public function query(string $sql, array $bindings = []): RecurringHooksResult
    {
        foreach (debug_backtrace(0) as $frame) {
            if (($frame['class'] ?? '') === Asaas\AsaasRecurring::class && ($frame['function'] ?? '') === 'observePayment') {
                $this->observed = [$frame['args']];
                if ($this->failObserver) { throw new RuntimeException('secret-provider-body-must-not-escape'); }
            }
        }
        $this->trace[] = [$sql, $bindings, $this->_trans_depth];
        if (str_starts_with($sql, 'CREATE TABLE')) {
            // Adapt only MySQL DDL syntax; all production columns/constraints are read from the installer.
            $sql = preg_replace('/`id` INT NOT NULL AUTO_INCREMENT,/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT,', $sql);
            $sql = preg_replace('/\s*PRIMARY KEY \(`id`\),/', '', $sql);
            $sql = preg_replace('/UNIQUE KEY `[^`]+` (\([^\n]+\))/', 'UNIQUE $1', $sql);
            $sql = preg_replace('/\s*KEY `[^`]+` \([^\n]+\),?/', '', $sql);
            $sql = preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', ');', $sql);
            $sql = preg_replace('/,\s*\)/', ')', $sql);
        }
        $statement = $this->conn_id->prepare($sql);
        $statement->execute($bindings);
        $this->affected = $statement->rowCount();
        return new RecurringHooksResult($statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function affected_rows(): int { return $this->affected; }
    public function table_exists(string $table): bool
    { return $this->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->row_array() !== []; }
    public function list_fields(string $table): array
    { return array_column($this->query('PRAGMA table_info(' . $table . ')')->result_array(), 'name'); }
    public function insert(string $table, array $data): bool
    {
        $this->query('INSERT INTO ' . $table . ' (`' . implode('`, `', array_keys($data)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
        return true;
    }
    public function get_where(string $table, array $where): RecurringHooksResult
    {
        return $this->query('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(
            static fn (string $name): string => '`' . $name . '` = ?', array_keys($where))), array_values($where));
    }
}

final class RecurringHooksGateway
{
    public array $calls = [];
    public function getSetting(string $name) { return get_option('paymentmethod_asaas_' . $name); }
    public function getProvider(): object
    {
        return new class {
            public function env(?int $company = null): string { return 'sandbox'; }
            public function apiKey(?int $company = null): string { return 'offline-recurring-hooks-key'; }
        };
    }
    public function card_checkout(): object
    {
        return new class($this) {
            public function __construct(private RecurringHooksGateway $gateway) {}
            public function discount(): array { return []; }
            public function reconcile(object $invoice): ?array
            {
                $this->gateway->calls[] = ['reconcile', (int) $invoice->id];
                throw new LogicException('The webhook observer must never perform payment HTTP requests.');
            }
            public function payAuthorizedToken(...$arguments): never { throw new LogicException('Hooks must not capture.'); }
        };
    }
    public function __call(string $method, array $arguments): never
    { $this->calls[] = [$method]; throw new LogicException('Unexpected gateway/network operation: ' . $method); }
}

function recurringHooksAssert(bool $condition, string $message): void
{
    ++$GLOBALS['recurring_hooks_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function recurringHooksTest(string $name, callable $operation): void
{
    ++$GLOBALS['recurring_hooks_scenarios'];
    try { $operation(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['recurring_hooks_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function recurringHooksFixture(bool $enabled = true): array
{
    $GLOBALS['recurring_hooks_options'] = ['paymentmethod_asaas_active' => '1', 'paymentmethod_asaas_recurring_active' => $enabled ? '1' : '0'];
    $GLOBALS['recurring_hooks_client'] = 0;
    $GLOBALS['recurring_hooks_log'] = [];
    $state = &asaas_lifecycle_state(); $state = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
    $db = new RecurringHooksDatabase();
    foreach ($GLOBALS['recurring_hooks_options'] as $name => $value) {
        $db->insert('tbloptions', ['name' => $name, 'value' => $value]);
    }
    $gateway = new RecurringHooksGateway();
    $invoice = (object) ['id' => 42, 'hash' => 'recurring-hooks-invoice', 'clientid' => 7, 'currency' => 1,
        'currency_name' => 'BRL', 'total' => '100.00', 'status' => 1, 'duedate' => '2026-12-15',
        'allowed_payment_modes' => serialize(['asaas', 'asaas_recurring'])];
    $db->insert('tblinvoices', ['id' => 42, 'data' => json_encode($invoice, JSON_THROW_ON_ERROR)]);
    $ci = (object) ['db' => $db, 'asaas_gateway' => $gateway];
    $ci->invoices_model = new class($invoice) {
        public function __construct(public object $invoice) {}
        public function get($id): ?object { return (int) $id === (int) $this->invoice->id ? clone $this->invoice : null; }
    };
    $ci->load = new class {
        public array $views = [];
        public function model(string $name): void {}
        public function library(string $name): void {}
        public function view(string $view, array $data): void
        {
            $this->views[] = $view;
            if ($view !== 'asaas/partials/recurring_payment_option') { throw new LogicException('Unexpected view.'); }
            extract($data, EXTR_SKIP);
            require dirname(__DIR__) . '/views/partials/recurring_payment_option.php';
        }
    };
    $GLOBALS['recurring_hooks_ci'] = $ci;
    $store = new Asaas\AsaasRecurringStore($db);
    $service = new Asaas\AsaasRecurring($gateway, $store);
    $scope = (new ReflectionMethod($service, 'scope'))->invoke($service, $invoice);
    $policy = (new ReflectionMethod($service, 'policy'))->invoke($service);
    $authorization = $store->saveAuthorization(['client_id' => 7, 'scope_key' => $scope['key'], 'environment' => 'sandbox',
        'company_id' => 0, 'customer_id' => 'cus_hooks', 'revision' => 1, 'state' => 'active',
        'token_ciphertext' => 'ciphertext-fixture-not-a-card-token', 'contact_id' => 9,
        'consent_version' => Asaas\AsaasRecurring::CONSENT_VERSION, 'authorized_at' => '2026-11-06 01:00:00',
        'cutoff_invoice_id' => 41, 'pricing_policy_hash' => $policy, 'reason_code' => null,
        'revoked_at' => null, 'updated_at' => '2026-11-06 01:00:00'], null);
    $db->trace = [];
    return [$ci, $db, $gateway, $invoice, $store, $authorization];
}

recurringHooksTest('bootstrap registers separate gateways and additive hooks', static function (): void {
    $registry = $GLOBALS['recurring_hooks_registry'];
    recurringHooksAssert($registry['gateway'] === [['asaas_gateway', 'asaas'], ['asaas_recurring_gateway', 'asaas']], 'The original gateway must remain registered beside the new gateway.');
    foreach ([['filter', 'before_invoice_added', 'asaas_recurring_before_invoice_added', 5],
        ['action', 'after_invoice_added', 'asaas_recurring_invoice_added', 20],
        ['action', 'after_cron_run', 'asaas_recurring_after_cron', 20],
        ['action', 'after_total_summary_invoicehtml', 'asaas_recurring_invoice_option', 10]] as [$kind, $hook, $callback, $priority]) {
        recurringHooksAssert(in_array([$callback, $priority, 1], $registry[$kind][$hook] ?? [], true), 'Missing additive native hook: ' . $callback);
    }
    recurringHooksAssert(in_array(['asaas_after_invoice_added', 10, 1], $registry['action']['after_invoice_added'], true)
        && in_array(['asaas_reconcile_after_cron', 10, 1], $registry['action']['after_cron_run'], true), 'Existing issuance and reconciliation hooks must remain registered.');
});

recurringHooksTest('disabled recurring hooks preserve ordinary invoices and perform no work', static function (): void {
    [$ci, $db, $gateway, $invoice] = recurringHooksFixture(false);
    $hook = ['data' => ['clientid' => 7, 'allowed_payment_modes' => serialize(['asaas']), 'custom' => 'keep'], 'items' => [['description' => 'keep']]];
    recurringHooksAssert(asaas_recurring_before_invoice_added($hook) === $hook, 'Disabled recurrence cannot alter the ordinary invoice payload.');
    asaas_recurring_invoice_added(42);
    asaas_recurring_after_cron();
    asaas_recurring_observe_webhook(['event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_hooks']]);
    ob_start(); asaas_recurring_invoice_option($invoice); $html = ob_get_clean();
    $writes = array_filter($db->trace, static fn (array $entry): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE)\b/i', $entry[0]) === 1);
    recurringHooksAssert($gateway->calls === [] && $writes === [] && $html === '', 'With no outstanding attempt, disabled hooks must not mutate persistence, call payment APIs or render enrollment.');
});

foreach ([false, true] as $serialized) {
    recurringHooksTest('before hook preserves payload and mode encoding ' . (int) $serialized, static function () use ($serialized): void {
        [, $db, $gateway] = recurringHooksFixture();
        $modes = ['asaas', 'bank-transfer'];
        $hook = ['data' => ['clientid' => 7, 'currency' => 1, 'allowed_payment_modes' => $serialized ? serialize($modes) : $modes, 'memo' => 'keep'], 'items' => ['untouched']];
        $actual = asaas_recurring_before_invoice_added($hook);
        $expected = $hook;
        $modes[] = 'asaas_recurring';
        $expected['data']['allowed_payment_modes'] = $serialized ? serialize($modes) : $modes;
        recurringHooksAssert($actual === $expected, 'Only the recurring mode may be added; array/serialization and other keys must survive.');
        recurringHooksAssert($gateway->calls === [], 'Consent lookup during invoice validation must not issue API calls.');
        recurringHooksAssert(count(array_filter($db->trace, static fn (array $entry): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE)\b/i', $entry[0]) === 1)) === 0, 'The before hook must be read-only.');
    });
}

recurringHooksTest('new mode preserves Asaas fallback without duplicating modes', static function (): void {
    [, $db, $gateway] = recurringHooksFixture();
    $hook = ['data' => ['clientid' => 7, 'allowed_payment_modes' => serialize(['asaas_recurring', 'bank'])]];
    $result = asaas_recurring_before_invoice_added($hook);
    recurringHooksAssert(asaas_invoice_payment_modes($result['data']['allowed_payment_modes']) === ['asaas_recurring', 'bank', 'asaas'], 'A recurring invoice must retain its ordinary payment fallback.');
    recurringHooksAssert(asaas_recurring_before_invoice_added($result) === $result, 'Repeated filters cannot duplicate the fallback.');
    recurringHooksAssert($db->trace === [] && $gateway->calls === [], 'Fallback mode enrichment is local only.');
});

recurringHooksTest('unrelated invoice and absent consent never gain recurring mode', static function (): void {
    [, $db, $gateway] = recurringHooksFixture();
    foreach ([['clientid' => 7, 'allowed_payment_modes' => ['bank']], ['clientid' => 99, 'allowed_payment_modes' => ['asaas']]] as $data) {
        $hook = ['data' => $data];
        recurringHooksAssert(asaas_recurring_before_invoice_added($hook) === $hook, 'A mode or customer without consent cannot be silently enrolled.');
    }
    recurringHooksAssert($gateway->calls === [], 'A missing mandate cannot trigger customer creation or capture.');
});

recurringHooksTest('after hook enqueues inside the native transaction without capture or commit', static function (): void {
    [, $db, $gateway, , $store] = recurringHooksFixture();
    $db->conn_id->beginTransaction(); $db->_trans_depth = 1;
    asaas_recurring_invoice_added('42');
    $job = $store->job(42);
    recurringHooksAssert(($job['state'] ?? '') === 'queued' && (int) $job['authorization_revision'] === 1
        && (int) $job['explicit_current'] === 0, 'The after hook must record only the consent revision and queued work.');
    recurringHooksAssert($db->_trans_depth === 1 && $db->conn_id->inTransaction(), 'The native writer must retain ownership of its transaction.');
    $reader = new PDO('sqlite:' . $db->path);
    recurringHooksAssert((int) $reader->query('SELECT COUNT(*) FROM tblasaas_recurring_jobs')->fetchColumn() === 0, 'Another connection must not see the job before native commit.');
    $db->conn_id->commit(); $db->_trans_depth = 0;
    recurringHooksAssert((int) $reader->query('SELECT COUNT(*) FROM tblasaas_recurring_jobs')->fetchColumn() === 1, 'Native commit makes the queued job durable.');
    asaas_recurring_invoice_added(42);
    recurringHooksAssert((int) $reader->query('SELECT COUNT(*) FROM tblasaas_recurring_jobs')->fetchColumn() === 1 && $gateway->calls === [], 'Repeated after hooks cannot duplicate jobs or capture.');
});

recurringHooksTest('after hook excludes historical and malformed invoice identifiers', static function (): void {
    [$ci, , $gateway, $invoice, $store] = recurringHooksFixture();
    $ci->invoices_model->invoice->id = 41;
    foreach ([41, '', '42x', -1, null, ['42'], '0'] as $id) { asaas_recurring_invoice_added($id); }
    recurringHooksAssert($store->job(41) === [] && $store->job(42) === [] && $gateway->calls === [], 'Historical or malformed identities must never be queued or charged.');
});

recurringHooksTest('invoice-page CTA is scoped and avoids duplicating the native gateway option', static function (): void {
    [$ci, , $gateway, $invoice] = recurringHooksFixture();
    $render = static function (object $row): string { ob_start(); asaas_recurring_invoice_option($row); return ob_get_clean(); };
    recurringHooksAssert($render($invoice) === '', 'A native recurring option already present must not be duplicated.');
    $invoice->allowed_payment_modes = serialize(['asaas']);
    $html = $render($invoice);
    recurringHooksAssert(str_contains($html, '/asaas/recurring/index/recurring-hooks-invoice') && str_contains($html, 'Cadastrar cartão recorrente'), 'Existing Asaas invoices need an explicit route to separate enrollment.');
    $invoice->allowed_payment_modes = serialize(['bank']);
    recurringHooksAssert($render($invoice) === '', 'Unrelated gateways cannot receive the recurring CTA.');
    $invoice->allowed_payment_modes = serialize(['asaas']); $invoice->status = 2;
    recurringHooksAssert($render($invoice) === '', 'A visitor cannot see paid-invoice authorization management.');
    $GLOBALS['recurring_hooks_client'] = 7;
    recurringHooksAssert(str_contains($render($invoice), 'Gerenciar autorização recorrente'), 'The authenticated owning client can manage consent on a paid invoice.');
    $invoice->status = 5;
    recurringHooksAssert($render($invoice) === '' && $gateway->calls === [], 'Cancelled invoices must not offer enrollment or invoke payment APIs.');
});

recurringHooksTest('webhook observer forwards only safe fields and signals the queue without HTTP', static function (): void {
    [, $db, $gateway, , $store] = recurringHooksFixture();
    asaas_recurring_invoice_added(42);
    $store->updateJob(42, ['queued'], ['state' => 'uncertain', 'phase' => 'capturing', 'payment_id' => 'pay_hooks',
        'next_check_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $db->insert('tblasaas_payments_map', ['invoice_id' => 42, 'payload_cache' => json_encode([
        'id' => 'pay_hooks', 'customer' => 'cus_hooks', 'externalReference' => 'recurring-hooks-invoice',
        '_asaas_lifecycle' => ['card_attempt' => ['state' => 'ambiguous']],
    ], JSON_THROW_ON_ERROR)]);
    $safe = ['id' => 'pay_hooks', 'customer' => 'cus_hooks', 'externalReference' => 'recurring-hooks-invoice',
        'billingType' => 'CREDIT_CARD', 'status' => 'PENDING', 'value' => '100.00'];
    $unsafe = ['creditCard' => ['creditCardToken' => 'secret-reusable-card-token', 'creditCardNumber' => '4111111111111111'],
        'creditCardHolderInfo' => ['name' => 'secret-holder'], 'ccv' => '937', 'debug' => 'secret-unexpected-field'];
    asaas_recurring_observe_webhook(['event' => 'PAYMENT_UPDATED', 'payment' => $safe + $unsafe]);
    recurringHooksAssert($db->observed === [[$safe, 'PAYMENT_UPDATED']], 'The real observer must receive the whitelist only, including no card/holder/token fields.');
    $job = $store->job(42);
    recurringHooksAssert($gateway->calls === [] && $job['state'] === 'uncertain'
        && strtotime($job['next_check_at']) <= time(), 'The observer only advances reconciliation eligibility; no HTTP or financial-state conclusion may delay the native webhook ACK.');
    recurringHooksAssert(count(array_filter($db->trace, static fn (array $entry): bool => str_contains($entry[0], 'GET_LOCK('))) === 0,
        'The optional webhook observer cannot wait for a long-running capture lock.');
    $recorded = json_encode([$db->trace, $GLOBALS['recurring_hooks_log']], JSON_THROW_ON_ERROR);
    foreach (['secret-reusable-card-token', '4111111111111111', 'secret-holder', 'secret-unexpected-field'] as $secret) {
        recurringHooksAssert(!str_contains($recorded, $secret), 'Webhook credentials must not reach SQL or diagnostics.');
    }
});

recurringHooksTest('observer failure is isolated from the successful native webhook', static function (): void {
    [, $db, $gateway, , $store] = recurringHooksFixture();
    asaas_recurring_invoice_added(42);
    $store->updateJob(42, ['queued'], ['state' => 'uncertain', 'phase' => 'capturing', 'payment_id' => 'pay_hooks']);
    $db->insert('tblasaas_payments_map', ['invoice_id' => 42, 'payload_cache' => json_encode([
        '_asaas_lifecycle' => ['card_attempt' => ['state' => 'ambiguous']],
    ], JSON_THROW_ON_ERROR)]);
    $db->failObserver = true;
    asaas_recurring_observe_webhook(['event' => 'PAYMENT_UPDATED', 'payment' => ['id' => 'pay_hooks']]);
    recurringHooksAssert($store->job(42)['state'] === 'uncertain' && $gateway->calls === [], 'Storage failure must remain unresolved without throwing into the native webhook or making HTTP calls.');
    recurringHooksAssert(!str_contains(json_encode($GLOBALS['recurring_hooks_log'], JSON_THROW_ON_ERROR), 'secret-provider-body-must-not-escape'), 'Raw provider failures cannot enter diagnostics.');
});

foreach ($GLOBALS['recurring_hooks_files'] as $path) { if (is_file($path)) { unlink($path); } }
echo json_encode(['suite' => 'recurring-hooks', 'scenarios' => $GLOBALS['recurring_hooks_scenarios'],
    'assertions' => $GLOBALS['recurring_hooks_assertions'], 'failures' => $GLOBALS['recurring_hooks_failures'],
    'real_network_calls' => 0], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
exit($GLOBALS['recurring_hooks_failures'] === 0 ? 0 : 1);
