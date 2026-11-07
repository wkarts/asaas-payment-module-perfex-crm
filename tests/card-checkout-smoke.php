<?php

declare(strict_types=1);

/**
 * Real checkout/lifecycle/card helpers with fake Asaas operations and a durable SQLite map.
 * A second SQLite connection verifies the attempt before each simulated card POST.
 * SQLite does not emulate MySQL named locks; their separate contract tests still apply.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);

$GLOBALS['card_checkout_ci'] = new stdClass();
$GLOBALS['card_checkout_trace'] = [];
$GLOBALS['card_checkout_logs'] = [];
$GLOBALS['card_checkout_files'] = [];
$GLOBALS['card_checkout_assertions'] = 0;
$GLOBALS['card_checkout_scenarios'] = 0;
$GLOBALS['card_checkout_failures'] = 0;

function &get_instance(): object { return $GLOBALS['card_checkout_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return $GLOBALS['card_checkout_options'][$name] ?? false; }
function log_message(string $level, string $message): void { $GLOBALS['card_checkout_logs'][] = $message; }
function format_invoice_number($id): string { return 'FAT-' . $id; }
function is_https(): bool { return $GLOBALS['card_checkout_https'] ?? true; }
function register_activation_hook(string $module, callable $callback): void {}
function register_payment_gateway(string $gateway, string $module): void {}
function hooks(): object
{
    static $hooks;
    return $hooks ??= new class {
        public function add_action(...$arguments): void {}
        public function add_filter(...$arguments): void {}
    };
}
function get_invoice_total_left_to_pay($id, $total = null): string
{
    return get_instance()->invoices_model->get($id)->balance;
}

require_once dirname(__DIR__) . '/libraries/Asaas/AsaasCardCheckout.php';
require_once dirname(__DIR__) . '/asaas.php';

final class CardCheckoutResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
}

final class CardCheckoutDatabase
{
    public int $_trans_depth = 0;
    public string $dbdriver = 'pdo';
    public string $subdriver = 'sqlite';
    public bool $db_debug = true;
    public PDO $conn_id;
    public string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'asaas-card-checkout-');
        $GLOBALS['card_checkout_files'][] = $this->path;
        $this->conn_id = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->conn_id->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $this->conn_id->exec('CREATE TABLE tblasaas_payments_map (invoice_id INTEGER PRIMARY KEY, asaas_payment_id TEXT, payload_cache TEXT NOT NULL)');
        $this->conn_id->exec('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY, level TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    }
    public function query(string $sql, array $bindings = []): CardCheckoutResult
    {
        $statement = $this->conn_id->prepare($sql);
        $statement->execute($bindings);
        return new CardCheckoutResult($statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function table_exists(string $table): bool
    {
        return $this->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->row_array() !== [];
    }
    public function list_fields(string $table): array
    {
        return array_column($this->query('PRAGMA table_info(' . $table . ')')->result_array(), 'name');
    }
    public function get_where(string $table, array $where): CardCheckoutResult
    {
        return $this->query('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn ($key): string => $key . ' = ?', array_keys($where))), array_values($where));
    }
    public function insert(string $table, array $data): bool
    {
        $this->query('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
        return true;
    }
    public function invoice(array $changes = []): object
    {
        $previous = $this->get_where('tblinvoices', ['id' => 42])->row_array();
        $data = $previous === [] ? [
            'id' => 42, 'hash' => 'hash-card-checkout', 'clientid' => 7, 'status' => 1,
            'allowed_payment_modes' => serialize(['asaas']), 'total' => '150.00', 'balance' => '100.00',
            'duedate' => date('Y-m-d', strtotime('+10 days')),
        ] : json_decode($previous['data'], true, 512, JSON_THROW_ON_ERROR);
        $data = array_replace($data, $changes);
        $this->query('INSERT INTO tblinvoices (id, data) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET data = excluded.data', [42, json_encode($data, JSON_THROW_ON_ERROR)]);
        return (object) $data;
    }
    public function cache(bool $independentConnection = false): array
    {
        $connection = $independentConnection
            ? new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            : $this->conn_id;
        $json = $connection->query('SELECT payload_cache FROM tblasaas_payments_map WHERE invoice_id = 42')->fetchColumn();
        return $json === false ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

final class CardCheckoutInvoiceModel
{
    public function get($id): ?object
    {
        $row = get_instance()->db->get_where('tblinvoices', ['id' => $id])->row_array();
        return $row === [] ? null : (object) json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
}

final class CardCheckoutGateway
{
    public array $settings = ['card_only' => '1', 'active' => '1', 'installmentCount' => '12', 'discount_type' => '1', 'discount_value' => '0', 'description' => 'Fatura {invoice_number}'];
    public array $remote = [];
    public int $mapWrites = 0;
    public ?int $failMapWrite = null;
    public ?Closure $beforeUpdate = null;

    public function getSetting(string $name) { return $this->settings[$name] ?? ''; }
    public function get_charge2(string $hash): array
    {
        $GLOBALS['card_checkout_trace'][] = ['list', $hash];
        return array_values(array_filter($this->remote, static fn (array $charge): bool => ($charge['externalReference'] ?? '') === $hash && empty($charge['deleted'])));
    }
    public function request(string $method, string $path): array
    {
        $GLOBALS['card_checkout_trace'][] = ['gateway_get', $path];
        if ($method !== 'GET') { throw new LogicException('Unexpected fake gateway mutation.'); }
        return $this->remote[basename($path)] ?? throw new RuntimeException('Not found', 404);
    }
    public function ensure_customer_from_client($client): array
    {
        $GLOBALS['card_checkout_trace'][] = ['customer'];
        return ['customerId' => 'cus_fixture'];
    }
    public function update_charge(string $id, string $json): array
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $GLOBALS['card_checkout_trace'][] = ['update', $id, $payload];
        if ($this->beforeUpdate !== null) { ($this->beforeUpdate)($id, $payload); }
        return $this->remote[$id] = array_replace($this->remote[$id], $payload);
    }
    public function delete_charge(string $id): array
    {
        $GLOBALS['card_checkout_trace'][] = ['delete', $id];
        $this->remote[$id]['deleted'] = true;
        return ['id' => $id, 'deleted' => true];
    }
    public function restore_charge(string $id): array
    {
        $GLOBALS['card_checkout_trace'][] = ['restore', $id];
        $this->remote[$id]['deleted'] = false;
        return $this->remote[$id];
    }
    public function upsert_payment_map(int $invoiceId, array $payment): bool
    {
        ++$this->mapWrites;
        if ($this->mapWrites === $this->failMapWrite) { return false; }
        $GLOBALS['card_checkout_trace'][] = ['map', $payment['_asaas_lifecycle']['card_attempt']['state'] ?? null];
        get_instance()->db->query('INSERT INTO tblasaas_payments_map (invoice_id, asaas_payment_id, payload_cache) VALUES (?, ?, ?) ON CONFLICT(invoice_id) DO UPDATE SET asaas_payment_id = excluded.asaas_payment_id, payload_cache = excluded.payload_cache', [$invoiceId, $payment['id'] ?? '', json_encode($payment, JSON_THROW_ON_ERROR)]);
        return true;
    }
}

final class CardCheckoutApi
{
    public array $posts = [];
    public array $gets = [];
    public array $responseOverrides = [];
    public string $status = 'CONFIRMED';
    public ?Throwable $postFailure = null;
    public ?Throwable $listFailure = null;
    public ?Closure $beforePost = null;
    public ?Closure $listTransform = null;

    public function __construct(private CardCheckoutGateway $gateway) {}
    private function posting(string $operation, ?string $id, array $payload): void
    {
        $db = get_instance()->db;
        $cache = $db->cache(true);
        cardCheckoutAssert(($cache['_asaas_lifecycle']['card_attempt']['state'] ?? '') === 'processing', 'A second SQLite connection must see processing before the POST.');
        cardCheckoutAssert(!$db->conn_id->inTransaction() && $db->_trans_depth === 0, 'The attempt marker must already be committed.');
        $this->posts[] = ['operation' => $operation, 'id' => $id, 'payload' => $payload];
        $GLOBALS['card_checkout_trace'][] = [$operation, $id];
        if ($this->beforePost !== null) {
            $callback = $this->beforePost;
            $this->beforePost = null;
            $callback();
        }
        if ($this->postFailure !== null) { throw $this->postFailure; }
    }
    public function createPayment(array $payload): array
    {
        $this->posting('create', null, $payload);
        $count = (int) ($payload['installmentCount'] ?? 1);
        $total = asaas_payment_amount_to_cents($payload['totalValue'] ?? $payload['value']);
        for ($number = 1; $number <= $count; ++$number) {
            $id = 'pay_new_' . $number;
            $payment = cardCheckoutPayment($id, [
                'billingType' => 'CREDIT_CARD', 'customer' => $payload['customer'],
                'value' => asaas_card_money(intdiv($total, $count) + ($number === 1 ? $total % $count : 0)),
                'status' => $number === 1 ? $this->status : 'PENDING',
            ]);
            if ($count > 1) { $payment['installment'] = 'ins_new'; $payment['installmentNumber'] = $number; }
            $this->gateway->remote[$id] = array_replace($payment, $this->responseOverrides);
        }
        return $this->gateway->remote['pay_new_1'];
    }
    public function payPayment(string $id, array $payload): array
    {
        $this->posting('pay', $id, $payload);
        return $this->gateway->remote[$id] = array_replace($this->gateway->remote[$id], ['billingType' => 'CREDIT_CARD', 'status' => $this->status], $this->responseOverrides);
    }
    public function getPayment(string $id): array
    {
        $this->gets[] = $id;
        $GLOBALS['card_checkout_trace'][] = ['get', $id];
        return $this->gateway->remote[$id] ?? throw new RuntimeException('Not found', 404);
    }
    public function listInstallmentPayments(string $id): array
    {
        $GLOBALS['card_checkout_trace'][] = ['installments', $id];
        if ($this->listFailure !== null) { throw $this->listFailure; }
        $response = ['hasMore' => false, 'data' => array_values(array_filter($this->gateway->remote, static fn (array $payment): bool => ($payment['installment'] ?? '') === $id && empty($payment['deleted'])))];
        return $this->listTransform !== null ? ($this->listTransform)($response) : $response;
    }
}

function cardCheckoutAssert(bool $condition, string $message): void
{
    ++$GLOBALS['card_checkout_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function cardCheckoutThrows(callable $operation, string $contains = ''): Throwable
{
    try { $operation(); } catch (Throwable $error) {
        cardCheckoutAssert($contains === '' || str_contains($error->getMessage(), $contains), 'Unexpected rejection: ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException('Operation should have been rejected.');
}
function cardCheckoutTest(string $name, callable $operation): void
{
    ++$GLOBALS['card_checkout_scenarios'];
    try { $operation(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['card_checkout_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function cardCheckoutFixture(array $invoiceChanges = [], array $settings = []): array
{
    $lifecycle = &asaas_lifecycle_state(); $lifecycle = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
    $handoffs = &asaas_atomic_payment_state(); $handoffs = [];
    $GLOBALS['card_checkout_trace'] = [];
    $GLOBALS['card_checkout_logs'] = [];
    $GLOBALS['card_checkout_options'] = [];
    $GLOBALS['card_checkout_https'] = true;
    $db = new CardCheckoutDatabase();
    $invoice = $db->invoice($invoiceChanges);
    $gateway = new CardCheckoutGateway();
    $gateway->settings = array_replace($gateway->settings, $settings);
    $GLOBALS['card_checkout_ci'] = (object) [
        'db' => $db, 'asaas_gateway' => $gateway, 'invoices_model' => new CardCheckoutInvoiceModel(),
        'load' => new class { public function model(string $name): void {} public function library(string $name): void {} },
        'input' => new class { public function ip_address(): string { return '203.0.113.10'; } },
    ];
    $api = new CardCheckoutApi($gateway);
    return [new Asaas\AsaasCardCheckout($gateway, $api), $gateway, $api, $db, $invoice];
}
function cardCheckoutData(object $invoice, array $cardChanges = []): array
{
    return ['invoice' => $invoice, 'card' => array_replace([
        'holderName' => 'Titular de Teste', 'number' => '4111 1111 1111 1111',
        'expiryMonth' => '12', 'expiryYear' => (string) ((int) date('Y') + 2), 'cvv' => '937', 'installmentCount' => '1',
        'holderEmail' => 'holder@example.test', 'holderDocument' => '123.456.789-09',
        'holderPostalCode' => '01001-000', 'holderAddressNumber' => '100', 'holderPhone' => '(11) 99999-0000',
    ], $cardChanges)];
}
function cardCheckoutPayment(string $id = 'pay_old', array $changes = []): array
{
    return array_replace(['id' => $id, 'externalReference' => 'hash-card-checkout', 'customer' => 'cus_fixture', 'billingType' => 'BOLETO', 'status' => 'PENDING', 'value' => '150.00', 'dueDate' => date('Y-m-d', strtotime('+10 days'))], $changes);
}
function cardCheckoutSeed(CardCheckoutGateway $gateway, array $payment): void
{
    $gateway->remote[$payment['id']] = $payment;
    $gateway->upsert_payment_map(42, $payment);
}
function cardCheckoutAttempts(array $changes = []): array
{
    return array_replace(['id' => 'attempt_fixture', 'state' => 'pending', 'customer_id' => 'cus_fixture', 'amount' => '100.00', 'installment_count' => 1, 'replaced_ids' => []], $changes);
}

foreach ([['card_only' => '0'], ['active' => '0']] as $settings) {
    cardCheckoutTest('disabled setting ' . array_key_first($settings) . ' prevents card HTTP', static function () use ($settings): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], $settings);
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'não está disponível');
        cardCheckoutAssert($api->posts === [] && $db->cache() === [], 'Disabled card checkout cannot start an attempt.');
    });
}
foreach ([['allowed_payment_modes' => serialize(['bank'])], ['status' => 2], ['status' => 5], ['status' => 6]] as $change) {
    cardCheckoutTest('invoice availability ' . json_encode($change), static function () use ($change): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture($change);
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'não está disponível');
        cardCheckoutAssert($api->posts === [] && $db->cache() === [], 'Unavailable invoice cannot change the remote debt.');
    });
}
foreach ([['installmentCount' => '13'], ['installmentCount' => '0'], ['number' => '123456789012'], ['number' => '411111111111A111'], ['cvv' => '12'], ['cvv' => '1a3'], ['expiryMonth' => '13']] as $change) {
    cardCheckoutTest('invalid posted card field ' . array_key_first($change) . '=' . current($change), static function () use ($change): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, $change)));
        cardCheckoutAssert($api->posts === [] && $db->cache() === [], 'Invalid card input must fail before a durable attempt or HTTP.');
    });
}
cardCheckoutTest('configured installment ceiling cannot be exceeded', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['installmentCount' => '3']);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '4'])), 'parcelas');
    cardCheckoutAssert($api->posts === [] && asaas_card_max_installments(100) === 21, 'Posted count and configured maximum must remain bounded.');
});
foreach ([['5.00', '1', true], ['10.00', '2', true], ['60.00', '12', true], ['4.99', '1', false], ['9.99', '2', false]] as [$balance, $count, $accepted]) {
    cardCheckoutTest('minimum value balance=' . $balance . ' count=' . $count, static function () use ($balance, $count, $accepted): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture(['balance' => $balance]);
        if ($accepted) { $service->pay(cardCheckoutData($invoice, ['installmentCount' => $count])); }
        else { cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => $count])), '5,00'); }
        cardCheckoutAssert(count($api->posts) === ($accepted ? 1 : 0), 'Exactly R$5 per payment/parcel is valid; below it cannot reach HTTP.');
    });
}

cardCheckoutTest('single payment converts and pays the linked debt without creating another', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $result = $service->pay(cardCheckoutData($invoice));
    cardCheckoutAssert(count($api->posts) === 1 && $api->posts[0]['operation'] === 'pay' && $api->posts[0]['id'] === 'pay_old', 'Capture must keep the existing payment ID.');
    cardCheckoutAssert($gateway->remote['pay_old']['value'] === '100.00' && $gateway->remote['pay_old']['billingType'] === 'CREDIT_CARD', 'Conversion must use the latest outstanding balance.');
    cardCheckoutAssert(count($gateway->remote) === 1 && count($result['_asaas_card_receipts']) === 1, 'One confirmed debt should yield one receipt candidate.');
    $operations = array_column($GLOBALS['card_checkout_trace'], 0);
    cardCheckoutAssert(array_search('update', $operations, true) < array_search('pay', $operations, true), 'The existing debt must be updated before capture.');
});
cardCheckoutTest('installment replacement removes old debt and posts totalValue without duplicated value fields', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $result = $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2']));
    $payload = $api->posts[0]['payload'];
    cardCheckoutAssert($payload['installmentCount'] === 2 && $payload['totalValue'] === '100.00', 'Installments must use count and the outstanding total.');
    cardCheckoutAssert(!isset($payload['value'], $payload['installmentValue']) && !array_key_exists('value', $payload) && !array_key_exists('installmentValue', $payload), 'No conflicting single/parcel amount may be sent.');
    $operations = array_column($GLOBALS['card_checkout_trace'], 0);
    cardCheckoutAssert(array_search('delete', $operations, true) < array_search('create', $operations, true), 'Old debt must be removed before the new installment request.');
    cardCheckoutAssert($gateway->remote['pay_old']['deleted'] === true && in_array('pay_old', $db->cache()['_asaas_lifecycle']['retired_payment_ids'], true), 'Old payment must remain retired.');
    cardCheckoutAssert(count($result['_asaas_card_receipts']) === 1 && asaas_card_receipt_amount($result['_asaas_card_receipts'][0], '100.00') === '50.00', 'Only the confirmed parcel can become a receipt.');
    cardCheckoutAssert(asaas_card_receipt_amount($result['_asaas_card_receipts'][0], '10.00') === '10.00', 'A parcel receipt must also respect the current invoice balance.');
});
cardCheckoutTest('definitive refusal restores the removed predecessor', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->postFailure = new RuntimeException('Controlled refusal', 400);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'não foi autorizado');
    cardCheckoutAssert($gateway->remote['pay_old']['deleted'] === false && in_array('restore', array_column($GLOBALS['card_checkout_trace'], 0), true), 'A definitive refusal should restore the original unpaid debt.');
    $cache = $db->cache(true);
    cardCheckoutAssert($cache['_asaas_lifecycle']['card_attempt']['state'] === 'declined' && !in_array('pay_old', $cache['_asaas_lifecycle']['retired_payment_ids'], true), 'The restored payment must not remain retired.');
});
cardCheckoutTest('request preparation failure with status zero is definitive when nothing was sent', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->postFailure = new Asaas\AsaasCardRequestException('Controlled transport preparation failure', 0, false);
    $error = cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'não foi autorizado');
    $cache = $db->cache(true);
    cardCheckoutAssert($error->getCode() === 0 && $cache['_asaas_lifecycle']['card_attempt']['state'] === 'declined', 'An unsent request must not be mistaken for an ambiguous timeout.');
    cardCheckoutAssert($gateway->remote['pay_old']['deleted'] === false && !asaas_card_attempt_blocks($cache), 'Preparation failure must restore the unpaid predecessor and release the attempt block.');
    cardCheckoutAssert($api->gets === [], 'No reconciliation GET is needed for a request known not to have been sent.');
});
cardCheckoutTest('ambiguous installment timeout never restores or captures again', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->postFailure = new RuntimeException('Controlled transport timeout');
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'aguardando confirmação');
    $firstCount = count($api->posts);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'aguardando confirmação');
    cardCheckoutAssert($firstCount === 1 && count($api->posts) === 1, 'Retry after an ambiguous request may only reconcile with reads.');
    cardCheckoutAssert($gateway->remote['pay_old']['deleted'] === true && !in_array('restore', array_column($GLOBALS['card_checkout_trace'], 0), true), 'Ambiguous capture must never resurrect the predecessor.');
    cardCheckoutAssert($db->cache(true)['_asaas_lifecycle']['card_attempt']['state'] === 'ambiguous', 'The unresolved attempt must survive a new SQLite connection.');
});
cardCheckoutTest('parcel listing error after accepted capture cannot restore the old debt', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->listFailure = new RuntimeException('Controlled listing failure', 400);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'aguardando confirmação');
    cardCheckoutAssert(count($api->posts) === 1 && $gateway->remote['pay_old']['deleted'] === true, 'A read failure must not undo an accepted capture.');
    cardCheckoutAssert(!in_array('restore', array_column($GLOBALS['card_checkout_trace'], 0), true) && asaas_card_attempt_blocks($db->cache()), 'The accepted attempt must continue blocking a second payment.');
});
foreach (['count', 'sum', 'duplicate_ids', 'missing_first'] as $problem) {
    cardCheckoutTest('invalid installment listing ' . $problem . ' requires reconciliation without restoring debt', static function () use ($problem): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
        cardCheckoutSeed($gateway, cardCheckoutPayment());
        $api->listTransform = static function (array $response) use ($problem): array {
            if ($problem === 'count') {
                $response['data'][1]['value'] = '25.00';
                $response['data'][] = array_replace($response['data'][1], ['id' => 'pay_extra', 'installmentNumber' => 3]);
            } elseif ($problem === 'sum') {
                $response['data'][1]['value'] = '49.00';
            } elseif ($problem === 'duplicate_ids') {
                $response['data'][1]['id'] = $response['data'][0]['id'];
            } else {
                $response['data'][0]['id'] = 'pay_missing_first';
            }
            return $response;
        };
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'aguardando confirmação');
        cardCheckoutAssert(count($api->posts) === 1 && $gateway->remote['pay_old']['deleted'] === true, 'Invalid listing cannot cause another capture or restore the predecessor.');
        cardCheckoutAssert(!in_array('restore', array_column($GLOBALS['card_checkout_trace'], 0), true) && asaas_card_attempt_blocks($db->cache()), 'Unverified installment receipts must remain blocked for reconciliation.');
    });
}
cardCheckoutTest('single payment with a divergent confirmed value cannot return a receipt', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $api->responseOverrides = ['value' => '99.00'];
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'aguardando confirmação');
    cardCheckoutAssert(count($api->posts) === 1 && asaas_card_attempt_blocks($db->cache()), 'A divergent amount needs reconciliation, never a second payment attempt.');
});
cardCheckoutTest('multiple installments without an installment ID cannot return a receipt', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $api->responseOverrides = ['installment' => null];
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2'])), 'aguardando confirmação');
    cardCheckoutAssert(count($api->posts) === 1 && asaas_card_attempt_blocks($db->cache()), 'Missing installment identity must prevent any receipt candidate.');
});
cardCheckoutTest('timeout on existing payment retries only GET and never captures twice', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->postFailure = new RuntimeException('Controlled timeout');
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'aguardando confirmação');
    $payment = $service->pay(cardCheckoutData($invoice));
    cardCheckoutAssert(count($api->posts) === 1 && count($api->gets) >= 2, 'The second submit must only read the known payment.');
    cardCheckoutAssert($payment['_asaas_card_receipts'] === [], 'An unconfirmed payment cannot yield a receipt.');
});
foreach (['AWAITING_RISK_ANALYSIS', 'AUTHORIZED', 'PENDING'] as $status) {
    cardCheckoutTest($status . ' cannot settle the invoice', static function () use ($status): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
        $api->status = $status;
        $payment = $service->pay(cardCheckoutData($invoice));
        cardCheckoutAssert($payment['_asaas_card_receipts'] === [] && !asaas_card_is_received($payment), 'Analysis/authorization is not a confirmed receipt.');
        cardCheckoutAssert($db->cache()['_asaas_lifecycle']['card_attempt']['state'] === 'pending' && get_instance()->invoices_model->get(42)->status === 1, 'Pending card state must preserve the unpaid invoice.');
    });
}
cardCheckoutTest('two submits while the durable marker is processing produce one card POST', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $api->beforePost = static function () use ($service, $invoice): void {
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'aguardando confirmação');
    };
    $first = $service->pay(cardCheckoutData($invoice));
    $second = $service->pay(cardCheckoutData($invoice));
    cardCheckoutAssert(count($api->posts) === 1 && $first['id'] === $second['id'], 'Overlapping/repeated submit after the marker must reconcile the same payment.');
});
cardCheckoutTest('existing declined installment is reused without deleting or recreating its group', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $first = cardCheckoutPayment('pay_parcel_1', ['billingType' => 'CREDIT_CARD', 'installment' => 'ins_existing', 'installmentNumber' => 1, 'value' => '50.00']);
    $first['_asaas_lifecycle'] = ['state' => 'active', 'card_attempt' => cardCheckoutAttempts(['state' => 'declined', 'installment_count' => 2])];
    cardCheckoutSeed($gateway, $first);
    $gateway->remote['pay_parcel_2'] = cardCheckoutPayment('pay_parcel_2', ['billingType' => 'CREDIT_CARD', 'installment' => 'ins_existing', 'installmentNumber' => 2, 'value' => '50.00']);
    $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2']));
    cardCheckoutAssert(count($api->posts) === 1 && $api->posts[0]['operation'] === 'pay' && $api->posts[0]['id'] === 'pay_parcel_1', 'Existing installment must be captured through its first payment.');
    cardCheckoutAssert(!in_array('delete', array_column($GLOBALS['card_checkout_trace'], 0), true) && count($gateway->remote) === 2, 'Retry cannot create another installment group.');
});
cardCheckoutTest('response for a different customer remains unconfirmed', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $api->responseOverrides = ['customer' => 'cus_other'];
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'aguardando confirmação');
    cardCheckoutAssert(count($api->posts) === 1 && asaas_card_attempt_blocks($db->cache()), 'Customer mismatch must require reconciliation, never another capture.');
});
cardCheckoutTest('retired cached payment cannot return a receipt during reconciliation', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $payment = cardCheckoutPayment('pay_retired', ['billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED', 'value' => '100.00']);
    $payment['_asaas_lifecycle'] = ['state' => 'active', 'retired_payment_ids' => ['pay_retired'], 'card_attempt' => cardCheckoutAttempts()];
    cardCheckoutSeed($gateway, $payment);
    $result = $service->reconcile($invoice);
    cardCheckoutAssert($result === null || ($result['_asaas_card_receipts'] ?? []) === [], 'A retired payment must never be offered for a new receipt.');
    cardCheckoutAssert($api->posts === [], 'Reconciliation cannot initiate a new capture.');
});
cardCheckoutTest('card secrets and reusable tokens are excluded from map, return and diagnostics', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $api->responseOverrides = [
        'creditCard' => ['number' => '4111111111111111', 'ccv' => '937', 'creditCardToken' => 'fixture-secret-card-token'],
        'creditCardToken' => 'fixture-secret-card-token',
        'nested' => ['cardNumber' => '4111111111111111', 'cvv' => '937', 'cardToken' => 'fixture-secret-card-token'],
    ];
    $result = $service->pay(cardCheckoutData($invoice));
    $stored = json_encode([$db->cache(true), $result, $GLOBALS['card_checkout_logs'], $db->query('SELECT * FROM tblasaas_logs')->result_array()], JSON_THROW_ON_ERROR);
    cardCheckoutAssert(!str_contains($stored, '4111111111111111') && !str_contains($stored, 'fixture-secret-card-token'), 'PAN and reusable tokens cannot be persisted, logged or returned.');
    cardCheckoutAssert(!preg_match('/"(?:ccv|cvv|securityCode|creditCard)"\s*:/i', $stored), 'Stored/safe responses must not retain card credentials.');
});
cardCheckoutTest('flat aliases and JSON objects cannot retain cardholder details in a completed payment', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $aliases = [
        'creditCardNumber', 'CREDIT_CARD_NUMBER', 'credit-card-number', 'creditCardBrand', 'credit_card_brand',
        'holderName', 'holder_name', 'holder-name', 'expiryMonth', 'expiry_month', 'expiry-month',
        'expiryYear', 'expiry_year', 'expiry-year', 'expirationMonth', 'expiration_month', 'expiration-month',
        'expirationYear', 'expiration_year', 'expiration-year', 'creditCardToken', 'credit_card_token', 'credit-card-token',
        'cardNumber', 'card_number', 'card-number', 'securityCode', 'security_code', 'security-code',
        'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone',
        'card-holder-name', 'card_holder_info', 'credit_card_holder_info', 'credit_card', 'card_token',
    ];
    $api->responseOverrides = array_fill_keys($aliases, 'private-card-data-fixture');
    $api->responseOverrides += [
        'invoiceNumber' => '1234567890123456', 'number' => '9876543210987654',
        'metadata' => json_decode('{"holder_name":"private-card-data-fixture","receipt":{"number":"1234567890123456","credit-card-token":"private-card-data-fixture"},"empty":{"security_code":"private-card-data-fixture"}}', false, 512, JSON_THROW_ON_ERROR),
    ];
    $result = $service->pay(cardCheckoutData($invoice));
    $cached = $db->cache(true);
    $stored = json_encode([$cached, $result, $GLOBALS['card_checkout_logs'], $db->query('SELECT * FROM tblasaas_logs')->result_array()], JSON_THROW_ON_ERROR);
    cardCheckoutAssert(!str_contains($stored, 'private-card-data-fixture'), 'All supported card/holder aliases must be removed before persistence and public return.');
    foreach ($aliases as $alias) {
        cardCheckoutAssert(!array_key_exists($alias, $cached) && !array_key_exists($alias, $result), 'Sensitive alias retained: ' . $alias);
    }
    foreach (['id' => 'pay_new_1', 'customer' => 'cus_fixture', 'value' => '100.00', 'billingType' => 'CREDIT_CARD',
        'invoiceNumber' => '1234567890123456', 'number' => '9876543210987654'] as $field => $expected) {
        cardCheckoutAssert($cached[$field] === $expected && $result[$field] === $expected, 'Financial/reference field must survive sanitization: ' . $field);
    }
    cardCheckoutAssert(json_encode($result['metadata'], JSON_THROW_ON_ERROR) === '{"receipt":{"number":"1234567890123456"},"empty":{}}', 'Nested JSON objects must be sanitized without changing their JSON shape.');
    cardCheckoutAssert($api->responseOverrides['metadata']->holder_name === 'private-card-data-fixture', 'Sanitization must not mutate the SDK response object.');
});
cardCheckoutTest('payload sanitization preserves opaque application objects without invoking their methods', static function (): void {
    $opaque = new class implements JsonSerializable {
        public function jsonSerialize(): mixed { throw new LogicException('Serialization is not allowed during sanitization.'); }
        public function __clone() { throw new LogicException('Cloning is not allowed during sanitization.'); }
        public function __toString(): string { throw new LogicException('String conversion is not allowed during sanitization.'); }
    };
    $payload = ['customer' => $opaque, 'nested' => (object) ['application' => $opaque, 'holder_name' => 'private-holder-fixture'],
        'installment' => 'ins_1234567890123456', 'installmentNumber' => 1, 'number' => '1234567890123456'];
    $safe = asaas_card_safe_payload($payload);
    cardCheckoutAssert($safe['customer'] === $opaque && $safe['nested']->application === $opaque, 'Opaque application objects must retain identity without executing code.');
    cardCheckoutAssert(!isset($safe['nested']->holder_name) && isset($payload['nested']->holder_name), 'JSON object properties must be filtered on a new object.');
    cardCheckoutAssert($safe['installment'] === $payload['installment'] && $safe['installmentNumber'] === 1 && $safe['number'] === $payload['number'], 'Numeric-looking references and installment metadata must not be treated as card numbers.');
});
cardCheckoutTest('failed processing marker write prevents the card POST', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $gateway->failMapWrite = 2;
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)));
    cardCheckoutAssert($api->posts === [], 'A non-durable attempt cannot reach the card API.');
});
cardCheckoutTest('outer native transaction prevents a card request before any side effect', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $db->_trans_depth = 1;
    try { cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice)), 'Conclua a alteração'); }
    finally { $db->_trans_depth = 0; }
    cardCheckoutAssert($api->posts === [] && $db->cache() === [] && $GLOBALS['card_checkout_trace'] === [], 'Outer transaction must reject before map or remote changes.');
});

cardCheckoutTest('priced offers disclose server total and respect configured minimum', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['installmentCount' => '21', 'card_min_installment' => '25.00']);
    $offers = $service->installmentOptions($invoice);
    cardCheckoutAssert(array_column($offers, 'count') === [1, 2, 3, 4], 'The minimum must restrict offers without an API request.');
    cardCheckoutAssert($offers[0]['total'] === '100.00' && $offers[0]['fee'] === '0.00'
        && strlen($offers[0]['quote']) === 64, 'Absorbed fees keep the invoice amount and produce a price acknowledgement.');
    cardCheckoutAssert($api->posts === [] && $GLOBALS['card_checkout_trace'] === [], 'Rendering prices must not create or alter a remote charge.');
});
foreach (['missing', 'changed', 'tampered'] as $mode) {
    cardCheckoutTest('surcharge acknowledgement rejects ' . $mode . ' before remote changes', static function () use ($mode): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['card_fee_mode' => 'customer']);
        $offer = $service->installmentOptions($invoice)[0];
        $quote = $mode === 'missing' ? '' : ($mode === 'tampered' ? str_repeat('a', 64) : $offer['quote']);
        if ($mode === 'changed') { $gateway->settings['card_fee_percent_1'] = '4.00'; }
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['quote' => $quote])), 'condições');
        cardCheckoutAssert($api->posts === [] && $db->cache() === [] && $GLOBALS['card_checkout_trace'] === [], 'Unaccepted conditions cannot reach lookup, capture or a durable attempt.');
    });
}
cardCheckoutTest('accepted surcharge captures gross and allocates only the invoice principal', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['card_fee_mode' => 'customer']);
    $offer = $service->installmentOptions($invoice)[0];
    $payment = $service->pay(cardCheckoutData($invoice, ['quote' => $offer['quote']]));
    $cache = $db->cache();
    cardCheckoutAssert($offer['total'] === '103.59' && $offer['fee'] === '3.59', 'The configured rate and fixed fee must be included in the accepted total.');
    cardCheckoutAssert($api->posts[0]['payload']['value'] === $offer['total'], 'The server must capture exactly the acknowledged nominal amount.');
    cardCheckoutAssert(asaas_card_receipt_amount($payment, '100.00', $cache) === '100.00', 'Fees must never become additional invoice principal.');
    cardCheckoutAssert(str_contains(asaas_card_receipt_note($payment, $cache), '3,59'), 'The native receipt note must explain the surcharge.');
    cardCheckoutThrows(static fn () => asaas_card_receipt_amount($payment, '99.99', $cache), 'saldo');
    // A short digit sequence can occur by chance inside the quote fingerprint.
    // Verify sensitive field names and complete leaf values instead of substrings of hashes.
    $retained = false;
    array_walk_recursive($cache, static function ($value, $key) use (&$retained): void {
        if (in_array((string) $value, ['4111111111111111', '4111', 'holder@example.test', '937'], true)
            || in_array(strtolower((string) $key), ['creditcard', 'creditcardholderinfo', 'creditcardtoken', 'ccv', 'cvv', 'holdername'], true)) {
            $retained = true;
        }
    });
    cardCheckoutAssert(!$retained, 'The frozen pricing metadata cannot contain card or cardholder credentials.');
});
cardCheckoutTest('priced installments preserve cent totals and do not settle principal early', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['card_fee_mode' => 'customer']);
    $offers = array_column($service->installmentOptions($invoice), null, 'count');
    $payment = $service->pay(cardCheckoutData($invoice, ['installmentCount' => '3', 'quote' => $offers[3]['quote']]));
    $cache = $db->cache();
    $allocation = $cache['_asaas_lifecycle']['card_attempt']['pricing']['allocations'];
    cardCheckoutAssert(array_sum(array_column($allocation, 'principal_cents')) === 10000, 'The complete installment allocation must equal the exact outstanding principal.');
    cardCheckoutAssert(array_sum(array_column($allocation, 'gross_cents')) === asaas_payment_amount_to_cents($offers[3]['total']), 'The gross allocation must equal the captured total.');
    cardCheckoutAssert(count($payment['_asaas_card_receipts']) === 1
        && asaas_payment_amount_to_cents(asaas_card_receipt_amount($payment, '100.00', $cache)) < 3400, 'A confirmed first parcel may settle only its principal share.');
    $gateway->settings['card_fee_percent_2_6'] = '50.00';
    $recovered = $service->reconcile($invoice);
    cardCheckoutAssert($db->cache()['_asaas_lifecycle']['card_attempt']['pricing']['allocations'] === $allocation,
        'Changing settings after capture must not reprice or reallocate the existing debt.');
    cardCheckoutAssert(count($api->posts) === 1 && $recovered !== null, 'Recovery remains GET-only.');
});
// Independent decimal reference vectors: net card proceeds are discounted by the
// monthly anticipation rate and average commercial term, then the gross is ceiled.
foreach ([
    'manual' => [1 => '104.90', 3 => '107.78', 6 => '110.68', 12 => '117.61', 21 => '129.03'],
    'automatic' => [1 => '104.79', 3 => '107.55', 6 => '110.28', 12 => '116.76', 21 => '127.32'],
] as $mode => $totals) {
    foreach ($totals as $count => $total) {
        cardCheckoutTest('anticipation ' . $mode . ' ' . $count . 'x is aggregated and frozen before capture', static function () use ($mode, $count, $total): void {
            [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], [
                'installmentCount' => '21', 'card_fee_mode' => 'customer', 'card_anticipation_mode' => $mode,
            ]);
            $offers = array_column($service->installmentOptions($invoice), null, 'count');
            $offer = $offers[$count];
            cardCheckoutAssert($offer['total'] === $total
                && asaas_payment_amount_to_cents($offer['fee']) === asaas_payment_amount_to_cents($total) - 10000,
                'The accepted offer must contain one total fee including the anticipation estimate.');
            cardCheckoutAssert(array_keys($offer) === ['count', 'label', 'principal', 'fee', 'total', 'schedule', 'quote'],
                'Internal anticipation rates, mode and cost must not be returned to the payment view.');
            cardCheckoutAssert($api->posts === [] && $GLOBALS['card_checkout_trace'] === [],
                'Quoting anticipation cannot contact or configure the Asaas account.');
            $payment = $service->pay(cardCheckoutData($invoice, ['installmentCount' => (string) $count, 'quote' => $offer['quote']]));
            $cache = $db->cache();
            $price = $cache['_asaas_lifecycle']['card_attempt']['pricing'];
            $payload = $api->posts[0]['payload'];
            cardCheckoutAssert(count($api->posts) === 1 && $api->posts[0]['operation'] === 'create'
                && ($payload['totalValue'] ?? $payload['value']) === $total,
                'Only the existing card capture can be sent, with the acknowledged aggregate total.');
            cardCheckoutAssert(!array_key_exists('anticipation', $payload) && !array_key_exists('creditCardAutomaticEnabled', $payload)
                && !array_filter(array_keys($payload), static fn (string $key): bool => str_contains(strtolower($key), 'anticip')),
                'Pricing configuration must not become a financial API instruction.');
            cardCheckoutAssert($price['anticipation']['mode'] === $mode
                && array_sum(array_column($price['allocations'], 'principal_cents')) === 10000,
                'Immutable financial metadata must allocate only the original invoice principal.');
            $receipt = asaas_card_receipt_amount($payment, '100.00', $cache);
            cardCheckoutAssert(asaas_payment_amount_to_cents($receipt) === $price['allocations'][$payment['id']]['principal_cents'],
                'A confirmed installment must settle only its principal, never the anticipation fee.');
            cardCheckoutAssert(!str_contains(strtolower(asaas_card_receipt_note($payment, $cache)), 'antecipa'),
                'The receipt must keep the aggregate card surcharge, without a separate anticipation line.');
            $gateway->settings['card_anticipation_mode'] = 'none';
            $gateway->settings['card_anticipation_manual_rate_installments'] = '2.00';
            $recovered = $service->reconcile($invoice);
            cardCheckoutAssert($recovered !== null && count($api->posts) === 1
                && $db->cache()['_asaas_lifecycle']['card_attempt']['pricing'] === $price,
                'Changing anticipation settings must not recapture or reprice a recorded attempt.');
            cardCheckoutAssert(get_instance()->invoices_model->get(42) == $invoice,
                'Calculating and capturing the surcharge cannot modify invoice values or dates.');
        });
    }
}
foreach (['card_anticipation_mode' => 'automatic', 'card_anticipation_manual_rate_1' => '1.26',
    'card_anticipation_first_days' => '31'] as $field => $changed) {
    cardCheckoutTest('changed anticipation ' . $field . ' rejects the previous quote before side effects', static function () use ($field, $changed): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], [
            'card_fee_mode' => 'customer', 'card_anticipation_mode' => 'manual',
            // Equal rates prove that changing the chosen mode invalidates even an equal total.
            'card_anticipation_automatic_rate_1' => '1.25',
        ]);
        $offer = $service->installmentOptions($invoice)[0];
        $gateway->settings[$field] = $changed;
        cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['quote' => $offer['quote']])), 'condições');
        cardCheckoutAssert($api->posts === [] && $db->cache() === [] && $GLOBALS['card_checkout_trace'] === [],
            'A stale anticipation quote cannot reach lookup, capture or durable writes.');
    });
}
foreach (['absorb' => ['card_fee_mode' => 'absorb'], 'exempt' => ['card_fee_mode' => 'customer', 'card_free_installments' => '3']] as $name => $settings) {
    cardCheckoutTest('anticipation respects ' . $name . ' without increasing the charge', static function () use ($settings): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], $settings + ['card_anticipation_mode' => 'automatic']);
        $offer = $service->installmentOptions($invoice)[0];
        $payment = $service->pay(cardCheckoutData($invoice, ['quote' => $offer['quote']]));
        cardCheckoutAssert($offer['total'] === '100.00' && $offer['fee'] === '0.00'
            && $api->posts[0]['payload']['value'] === '100.00', 'Absorbed anticipation costs cannot increase the customer charge.');
        cardCheckoutAssert(!isset($db->cache()['_asaas_lifecycle']['card_attempt']['pricing'])
            && asaas_card_receipt_amount($payment, '100.00', $db->cache()) === '100.00',
            'The established receipt flow must stay intact when no surcharge is passed through.');
    });
}
cardCheckoutTest('declined surcharge restores the original boleto without card pricing', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['card_fee_mode' => 'customer']);
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $offers = array_column($service->installmentOptions($invoice), null, 'count');
    $api->postFailure = new RuntimeException('Refused', 400);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['installmentCount' => '2', 'quote' => $offers[2]['quote']])));
    $cache = $db->cache();
    cardCheckoutAssert($cache['billingType'] === 'BOLETO' && !isset($cache['_asaas_lifecycle']['card_attempt']['pricing'])
        && !$gateway->remote['pay_old']['deleted'], 'The restored boleto must not inherit the failed card allocation.');
    cardCheckoutAssert(asaas_card_receipt_amount($gateway->remote['pay_old'], '100.00', $cache) === '100.00', 'A later boleto receipt must continue using its established settlement path.');
});
foreach (['4111111111111111', '5555555555554444', '2221000000000009'] as $number) {
    cardCheckoutTest('21 installments are accepted for eligible brand ' . substr($number, 0, 4), static function () use ($number): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture(['balance' => '210.00'], ['installmentCount' => '21']);
        $service->pay(cardCheckoutData($invoice, ['number' => $number, 'installmentCount' => '21']));
        cardCheckoutAssert($api->posts[0]['payload']['installmentCount'] === 21, 'Eligible cards may use the configured maximum.');
    });
}
cardCheckoutTest('other brands cannot post more than 12 installments', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture(['balance' => '210.00'], ['installmentCount' => '21']);
    cardCheckoutThrows(static fn () => $service->pay(cardCheckoutData($invoice, ['number' => '371449635398431', 'installmentCount' => '13'])), 'Visa ou Mastercard');
    cardCheckoutAssert($api->posts === [] && $db->cache() === [], 'Unsupported brand/count must be rejected before charging.');
});

cardCheckoutTest('settled or overpaid invoice offers no installment options', static function (): void {
    foreach (['0.00', '-0.01', '-20.00'] as $balance) {
        cardCheckoutAssert(asaas_card_installment_options($balance, 12) === [], 'Non-positive balance cannot offer another card payment.');
    }
});

cardCheckoutTest('authorized recurring token pays the existing charge without retaining card data or a payer IP', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture([], ['card_only' => '0', 'card_fee_mode' => 'customer']);
    $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
    $GLOBALS['card_checkout_https'] = false; // Cron has no browser request.
    get_instance()->input = new class { public function ip_address(): string { throw new RuntimeException('No payer is present in cron.'); } };
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $authorizations = 0;
    $token = 'opaque-recurring-token-fixture';
    $payment = $service->payAuthorizedToken($invoice, $token, 'cus_fixture', static function (object $fresh) use (&$authorizations): void {
        ++$authorizations; cardCheckoutAssert((int) $fresh->id === 42, 'Revalidate the same invoice with the authorization.');
    });
    cardCheckoutAssert(count($api->posts) === 1 && $api->posts[0]['operation'] === 'pay'
        && $api->posts[0]['id'] === 'pay_old' && $api->posts[0]['payload'] === ['creditCardToken' => $token],
        'Recurring capture must use only the token on the existing payment ID.');
    cardCheckoutAssert($authorizations === 2 && asaas_card_is_received($payment), 'Consent must be revalidated before preparation and capture.');
    $stored = json_encode([$db->cache(), $payment, $GLOBALS['card_checkout_logs']], JSON_THROW_ON_ERROR);
    cardCheckoutAssert(!str_contains($stored, $token), 'The recurring token must never enter payment cache, receipt result or diagnostic.');
    cardCheckoutAssert(asaas_card_receipt_principal($payment, $db->cache()) === 10000, 'Recurring surcharge must settle only the invoice principal.');
});
foreach (['missing', 'different_customer', 'installment'] as $case) {
    cardCheckoutTest('recurring capture rejects ' . $case . ' without creating a second debt', static function () use ($case): void {
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
        $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
        if ($case !== 'missing') {
            $change = $case === 'different_customer' ? ['customer' => 'cus_other'] : ['installment' => 'ins_existing'];
            cardCheckoutSeed($gateway, cardCheckoutPayment('pay_old', $change));
        }
        cardCheckoutThrows(static fn () => $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function (): void {}));
        cardCheckoutAssert($api->posts === [] && !isset($db->cache()['_asaas_lifecycle']['card_attempt']),
            'An ineligible recurring charge must not reach capture or durable card attempt.');
    });
}
cardCheckoutTest('revoked recurring authorization before capture restores boleto and sends no card POST', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $calls = 0;
    cardCheckoutThrows(static function () use ($service, $invoice, &$calls): void {
        $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function () use (&$calls): void {
            if (++$calls === 2) { throw new RuntimeException('Consent changed.'); }
        });
    });
    cardCheckoutAssert($api->posts === [] && $gateway->remote['pay_old']['billingType'] === 'BOLETO'
        && $db->cache()['_asaas_lifecycle']['card_attempt']['state'] === 'declined',
        'Revocation before sending cannot capture and must preserve the previous payment option.');
});
cardCheckoutTest('recurring timeout keeps the same attempt and never repeats token capture', static function (): void {
    [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture();
    $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
    cardCheckoutSeed($gateway, cardCheckoutPayment());
    $api->postFailure = new RuntimeException('Timeout');
    cardCheckoutThrows(static fn () => $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function (): void {}));
    $api->postFailure = null;
    $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function (): void {});
    cardCheckoutAssert(count($api->posts) === 1 && !asaas_card_is_received($db->cache())
        && asaas_card_attempt_blocks($db->cache()), 'An inconclusive token capture can only be inspected, never automatically repeated.');
});

foreach (['one-time', 'recurring'] as $mode) {
    cardCheckoutTest('declined ' . $mode . ' card restores overdue boleto without resubmitting its expired due date', static function () use ($mode): void {
        $dueDate = date('Y-m-d', strtotime('-2 days'));
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture(['status' => 4, 'duedate' => $dueDate], ['card_fee_mode' => 'customer']);
        $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
        $previous = cardCheckoutPayment('pay_old', ['status' => 'OVERDUE', 'dueDate' => $dueDate,
            'canBePaidAfterDueDate' => true, 'discount' => ['type' => 'FIXED', 'value' => 0, 'dueDateLimitDays' => 0],
            'fine' => ['value' => 2], 'interest' => ['value' => 1]]);
        cardCheckoutSeed($gateway, $previous);
        $gateway->beforeUpdate = static function (string $id, array $payload): void {
            // Model the provider's minimum-date validation only when a date is sent.
            if (isset($payload['dueDate']) && $payload['dueDate'] < date('Y-m-d')) {
                throw new RuntimeException('The submitted due date is earlier than the provider minimum.', 400);
            }
        };
        $api->postFailure = new RuntimeException('Card refused', 400);
        $quote = $service->installmentOptions($invoice)[0]['quote'];
        $pay = static fn () => $mode === 'recurring'
            ? $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function (): void {})
            : $service->pay(cardCheckoutData($invoice, ['quote' => $quote]));
        $error = cardCheckoutThrows($pay, 'não foi autorizado');
        $cache = $db->cache();
        cardCheckoutAssert($error->getCode() === 400 && count($api->posts) === 1 && $api->posts[0]['id'] === 'pay_old',
            'A definitive refusal must remain a single attempted capture on the existing charge.');
        cardCheckoutAssert($gateway->remote['pay_old'] === $previous,
            'Rollback must restore boleto, original value and charge conditions while preserving the past due date and overdue status.');
        cardCheckoutAssert(($cache['_asaas_lifecycle']['card_attempt']['state'] ?? '') === 'declined'
            && !asaas_card_attempt_blocks($cache), 'Successful rollback must keep the refusal definitive and allow the original payment alternatives.');
        cardCheckoutAssert(!isset($cache['_asaas_lifecycle']['card_attempt']['pricing']) && $cache['dueDate'] === $dueDate,
            'The restored boleto cannot inherit the card surcharge or a new due date.');
    });

    cardCheckoutTest('failed ' . $mode . ' boleto restoration keeps confirmation pending and never repeats capture', static function () use ($mode): void {
        $dueDate = date('Y-m-d', strtotime('-2 days'));
        [$service, $gateway, $api, $db, $invoice] = cardCheckoutFixture(['status' => 4, 'duedate' => $dueDate]);
        $GLOBALS['card_checkout_options']['paymentmethod_asaas_recurring_active'] = '1';
        cardCheckoutSeed($gateway, cardCheckoutPayment('pay_old', ['status' => 'OVERDUE', 'dueDate' => $dueDate]));
        $gateway->beforeUpdate = static function (string $id, array $payload): void {
            if (($payload['billingType'] ?? '') === 'BOLETO') { throw new RuntimeException('Provider unavailable during rollback.', 503); }
        };
        $api->postFailure = new RuntimeException('Card refused', 400);
        $pay = static fn () => $mode === 'recurring'
            ? $service->payAuthorizedToken($invoice, 'opaque-recurring-token-fixture', 'cus_fixture', static function (): void {})
            : $service->pay(cardCheckoutData($invoice));
        cardCheckoutThrows($pay, 'aguardando confirmação');
        cardCheckoutAssert(in_array(['map', 'ambiguous'], $GLOBALS['card_checkout_trace'], true),
            'An actual restoration failure must durably record ambiguity before any recovery GET.');
        cardCheckoutAssert(asaas_card_attempt_blocks($db->cache()) && $gateway->remote['pay_old']['billingType'] === 'CREDIT_CARD',
            'A GET that still finds the prepared card debt cannot release another payment.');
        $pay(); // A later invocation may only inspect the known charge.
        cardCheckoutAssert(count($api->posts) === 1 && asaas_card_attempt_blocks($db->cache())
            && $gateway->remote['pay_old']['dueDate'] === $dueDate,
            'Unsuccessful restoration must not cause another capture or silently postpone the existing charge.');
    });
}

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['card_checkout_files'] as $path) { if (is_file($path)) { unlink($path); } }
});
echo sprintf('Card checkout: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['card_checkout_scenarios'], $GLOBALS['card_checkout_assertions'], $GLOBALS['card_checkout_failures']);
exit($GLOBALS['card_checkout_failures'] === 0 ? 0 : 1);
