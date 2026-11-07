<?php

declare(strict_types=1);

/** Real Pix storage, native webhook adapter and lifecycle guards; all writes stay in SQLite. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$temporaryRoot = sys_get_temp_dir() . '/asaas-pix-interlocks-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/modules', 0700, true);
symlink(dirname(__DIR__), $temporaryRoot . '/modules/asaas');
define('FCPATH', $temporaryRoot . '/');
require __DIR__ . '/fixtures/automatic-pix-fixture.php';
register_shutdown_function(static function () use ($temporaryRoot): void {
    unlink($temporaryRoot . '/modules/asaas'); rmdir($temporaryRoot . '/modules'); rmdir($temporaryRoot);
});

/** Adds only the CI query-builder metadata required by the native webhook. */
final class PixInterlockDb
{
    public string $dbdriver = 'pdo';
    public string $subdriver = 'mysql';
    public string $hostname = 'fixture-only';
    public string $database = 'pix-fixture';
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public PDO $conn_id;
    public function __construct(public PixTestDb $inner) { $this->conn_id = $inner->conn_id; }
    public function field_exists(string $field, string $table): bool { return in_array($field, $this->inner->list_fields($table), true); }
    public function where(string $field, $value): self { $this->inner->where($field, $value); return $this; }
    public function __call(string $method, array $arguments) { return $this->inner->$method(...$arguments); }
}

/** The real adapter calls the native writer boundary with remote_payment_depth set. */
class App_gateway
{
    public int $writes = 0;
    public function __construct(private ?PixTestGateway $writer = null) {}
    public function addPayment($data)
    {
        ++$this->writes;
        pixInterlockAssert((asaas_lifecycle_state()['remote_payment_depth'] ?? 0) > 0, 'Authenticated webhook must use the existing remote receipt context.');
        $data = asaas_before_payment_recorded($data);
        return $this->writer->addPayment($data);
    }
    public function isFileLogEnabled(): bool { return false; }
}
require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
require_once dirname(__DIR__) . '/libraries/Asaas_gateway.php';
final class PixInterlockProvider extends AsaasSdkProvider
{
    public function __construct() {}
    public function setting(string $name, ?int $companyId = null): mixed { return $name === 'webhook_secret' ? 'fictional-webhook-secret' : ''; }
    public function client(?int $companyId = null): \Asaas\Sdk\Http\Client { throw new LogicException('Network is forbidden in this test.'); }
}
final class PixInterlockCoreMarker {}
class_alias(PixInterlockCoreMarker::class, 'app\\services\\InvoicePaymentWriter');
final class PixInterlockPaymentsModel { private function insert_payment_atomically(): void {} }

class ClientsController
{
    public object $db;
    public object $load;
    public object $asaas_gateway;
    public function __construct()
    {
        $this->db = get_instance()->db; $this->load = get_instance()->load; $this->asaas_gateway = get_instance()->asaas_gateway;
    }
}
function site_url(string $path): string { return 'https://crm.example.test/' . $path; }
function redirect(string $url, ...$arguments): void { $GLOBALS['pix_interlock_redirects'][] = $url; }
function show_404(): void { throw new RuntimeException('Unexpected not-found response.'); }
require_once dirname(__DIR__) . '/controllers/Checkout.php';

final class PixInterlockCardApi
{
    public int $calls = 0;
    public function __call(string $method, array $arguments): never { ++$this->calls; throw new LogicException('Card mutation must not be reached.'); }
}
final class PixInterlockPixStore extends \Asaas\AsaasAutomaticPixStore
{
    public ?Closure $afterLock = null;
    public function lock(int $client, string $scope): string
    {
        $name = parent::lock($client, $scope);
        if ($this->afterLock !== null) { $callback = $this->afterLock; $this->afterLock = null; $callback(); }
        return $name;
    }
}
final class PixInterlockCardStore extends \Asaas\AsaasRecurringStore
{
    public ?Closure $afterLock = null;
    public function lock(int $clientId, string $scopeKey): string
    {
        $name = parent::lock($clientId, $scopeKey);
        if ($this->afterLock !== null) { $callback = $this->afterLock; $this->afterLock = null; $callback(); }
        return $name;
    }
}

$cases = 0; $assertions = 0; $failures = 0;
function pixInterlockAssert(bool $test, string $message): void
{
    ++$GLOBALS['assertions']; if (!$test) { throw new RuntimeException($message); }
}
function pixInterlockCase(string $name, callable $case): void
{
    ++$GLOBALS['cases'];
    try { $case(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['failures']; echo 'FAIL ' . $name . ': ' . $error->getMessage() . ' @ ' . $error->getLine() . PHP_EOL; }
}
function pixInterlockFixture(): array
{
    asaas_receipt_unlock_all(); $locks = &asaas_receipt_lock_state(); $locks = [];
    $state = &asaas_lifecycle_state(); $state = [];
    $fixture = pixFixture(); $ci = get_instance(); $ci->db = new PixInterlockDb($ci->db);
    $ci->payments_model = new PixInterlockPaymentsModel();
    $GLOBALS['pix_options']['paymentmethod_asaas_manual_payment_sync'] = '0';
    $GLOBALS['pix_interlock_redirects'] = [];
    return $fixture;
}
function pixInterlockEnroll(object $service, object $invoice): void
{
    pixInterlockAssert($service->enroll($invoice, pixConsent($service, $invoice))['ok'], 'Initial Pix fixture must enroll.');
}
function pixInterlockRecordId(string $payment): int
{
    return (int) get_instance()->db->get_where('tblinvoicepaymentrecords', ['transactionid' => $payment])->row_array()['id'];
}
function pixInterlockCardAuthorization(object $gateway, object $invoice): void
{
    $scope = asaas_automatic_pix_scope($invoice, $gateway); $now = date('Y-m-d H:i:s');
    (new \Asaas\AsaasRecurringStore(get_instance()->db))->saveAuthorization([
        'client_id' => 7, 'scope_key' => $scope['key'], 'environment' => 'sandbox', 'company_id' => 0, 'customer_id' => 'cus_pix_fixture',
        'revision' => 1, 'state' => 'active', 'token_ciphertext' => 'fictional-sealed-token', 'contact_id' => 9, 'consent_version' => 'fixture',
        'authorized_at' => $now, 'cutoff_invoice_id' => 42, 'pricing_policy_hash' => hash('sha256', 'fixture'), 'updated_at' => $now,
    ], null);
}

pixInterlockCase('Manual receipt blocks every unresolved Pix state even when synchronization is disabled', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); pixInterlockEnroll($service, $invoice);
    foreach (['preparing', 'creating', 'waiting', 'uncertain'] as $state) {
        get_instance()->db->query('UPDATE tblasaas_pix_jobs SET state = ? WHERE invoice_id = ?', [$state, 42]);
        try { asaas_before_payment_recorded(['invoiceid' => 42, 'amount' => '100.00']); throw new LogicException('Manual receipt must be blocked.'); }
        catch (AsaasInvoiceSyncException $error) { pixInterlockAssert(str_contains($error->getMessage(), 'Pix Automático'), 'The Pix guard must be the rejection reason.'); }
    }
    pixInterlockAssert(get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array() === [], 'No manual receipt was written.');
    pixInterlockAssert(count($gateway->api->calls) === 1, 'Manual guards perform no additional API calls.');
});

pixInterlockCase('Native gateway, boleto, Pix QR and direct card capture respect the pending Pix guard', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); pixInterlockEnroll($service, $invoice);
    $native = (new ReflectionClass(Asaas_gateway::class))->newInstanceWithoutConstructor();
    $native->process_payment(['invoice' => $invoice]);
    $controller = new Checkout(); $controller->boleto($invoice->hash); $controller->qrcode($invoice->hash);
    pixInterlockAssert($GLOBALS['pix_interlock_redirects'] === array_fill(0, 3, site_url('asaas/automatic_pix/index/' . $invoice->hash)), 'All original routes direct the customer to the pending Pix.');
    $api = new PixInterlockCardApi(); $checkout = new \Asaas\AsaasCardCheckout($gateway, $api);
    pixInterlockAssert(!$checkout->available($invoice), 'The card read model must hide an unavailable capture.');
    try { $checkout->pay(['invoice' => $invoice]); throw new LogicException('Direct capture must be blocked too.'); }
    catch (\Asaas\AsaasCardCheckoutException $error) { pixInterlockAssert(str_contains($error->getMessage(), 'Pix Automático'), 'Capture rechecks under the invoice lock.'); }
    pixInterlockAssert($api->calls === 0, 'No card tokenization or capture was attempted.');
});

pixInterlockCase('Invoice edits and remote removal cannot bypass a pending Pix', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); pixInterlockEnroll($service, $invoice);
    try { asaas_before_update_invoice(['id' => 42, 'data' => ['total' => '1.00']]); throw new LogicException('Edit must be blocked.'); }
    catch (AsaasInvoiceSyncException $error) { pixInterlockAssert(str_contains($error->getMessage(), 'Pix Automático'), 'Edit is stopped before updating the provider.'); }
    try { asaas_lifecycle_remove($invoice, 'cancelled'); throw new LogicException('Removal must be blocked.'); }
    catch (RuntimeException $error) { pixInterlockAssert(str_contains($error->getMessage(), 'Pix Automático'), 'Removal requires authoritative Pix resolution first.'); }
    pixInterlockAssert(count($gateway->api->calls) === 1, 'No DELETE or update escapes the guards.');
});

pixInterlockCase('Authenticated native webhook settles the verified first Pix exactly once', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); pixInterlockEnroll($service, $invoice);
    $payment = pixInitialPayment($gateway, pixProfile());
    pixInterlockAssert($service->resolvePayment($payment)?->id === 42, 'Exact QR correlation establishes the immutable link without writing a receipt.');
    $native = new App_gateway($gateway); $adapter = new \Asaas\AsaasAdapter(new PixInterlockProvider(), $native);
    $body = json_encode(['id' => 'evt_fixture_pix', 'event' => 'PAYMENT_RECEIVED', 'payment' => $payment], JSON_THROW_ON_ERROR);
    $rejected = $adapter->handleWebhook(['asaas-access-token' => 'wrong-fixture-token'], $body);
    pixInterlockAssert($rejected['http_status'] === 401 && $native->writes === 0, 'Authentication still precedes receipt processing.');
    $result = $adapter->handleWebhook(['asaas-access-token' => 'fictional-webhook-secret'], $body);
    pixInterlockAssert($result['processed'] && $result['http_status'] === 200, 'The real webhook adapter must accept confirmed Pix while its job is waiting.');
    $again = $adapter->handleWebhook(['asaas-access-token' => 'fictional-webhook-secret'], $body);
    pixInterlockAssert($again['processed'] && $native->writes === 1, 'Repeated confirmation cannot invoke another native receipt write.');
    pixInterlockAssert(get_instance()->invoices_model->get(42)->status === 2 && $store->receiptExists(42, $payment['id'], 10000), 'The native writer persisted the exact principal and paid the invoice.');
    pixInterlockAssert($service->processInvoice(42, true)['state'] === 'confirmed', 'Pix reconciliation recognizes the native receipt without creating another.');
});

pixInterlockCase('Manual receipt locks the invoice before its first Pix enrollment and releases after persistence', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture();
    $data = ['invoiceid' => 42, 'amount' => '100.00', 'transactionid' => 'manual_fixture'];
    asaas_before_payment_recorded($data);
    pixInterlockAssert($store->job(42) === [] && array_sum(get_instance()->db->inner->locks) === 1, 'Lock is acquired even before any Pix job exists.');
    pixInterlockAssert(empty(asaas_lifecycle_state()['pending_manual_invoices']), 'Disabled synchronization must not create an Asaas settlement transaction.');
    $gateway->addPayment($data); asaas_after_payment_added(pixInterlockRecordId($data['transactionid']));
    pixInterlockAssert(array_sum(get_instance()->db->inner->locks) === 0, 'The independent manual guard is released after the committed receipt.');
    pixInterlockAssert(!$service->enroll($invoice, pixConsent($service, $invoice))['ok'] && $gateway->api->calls === [], 'Enrollment sees the paid balance and does not issue a QR.');
});

pixInterlockCase('Multiple manual writes in one batch retain the shared invoice lock until the last receipt', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture();
    $first = ['invoiceid' => 42, 'amount' => '30.00', 'transactionid' => 'manual_first'];
    $second = ['invoiceid' => 42, 'amount' => '70.00', 'transactionid' => 'manual_second'];
    asaas_before_payment_recorded($first); asaas_before_payment_recorded($second);
    $gateway->addPayment($first); asaas_after_payment_added(pixInterlockRecordId($first['transactionid']));
    pixInterlockAssert(array_sum(get_instance()->db->inner->locks) === 1, 'The first after-hook cannot release the second pending write.');
    $gateway->addPayment($second); asaas_after_payment_added(pixInterlockRecordId($second['transactionid']));
    pixInterlockAssert(array_sum(get_instance()->db->inner->locks) === 0 && get_instance()->invoices_model->get(42)->status === 2, 'Final committed receipt releases exactly one MySQL lock reference.');
});

pixInterlockCase('External transaction stays protected through the after-hook and shutdown without module commit or rollback', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); $db = get_instance()->db;
    $db->conn_id->beginTransaction(); $db->_trans_depth = 1;
    $data = ['invoiceid' => 42, 'amount' => '100.00', 'transactionid' => 'manual_uncommitted'];
    asaas_before_payment_recorded($data); $gateway->addPayment($data); asaas_after_payment_added(pixInterlockRecordId($data['transactionid']));
    pixInterlockAssert(array_sum($db->inner->locks) === 1, 'After-hook inside the outer transaction must retain the lock.');
    $reader = new PDO('sqlite:' . $db->inner->path);
    pixInterlockAssert((int) $reader->query('SELECT COUNT(*) FROM tblinvoicepaymentrecords')->fetchColumn() === 0, 'An independent connection cannot see the uncommitted receipt.');
    asaas_receipt_lock_shutdown();
    pixInterlockAssert($db->conn_id->inTransaction() && $db->_trans_depth === 1, 'The module must not commit or roll back the core transaction.');
    pixInterlockAssert(array_sum($db->inner->locks) === 1, 'Shutdown leaves the named lock for connection teardown while core data is uncommitted.');
    $db->conn_id->rollBack(); $db->_trans_depth = 0; asaas_receipt_unlock_all();
});

pixInterlockCase('Rejected later operation cannot release a manual receipt from an unfinished core transaction', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture(); $db = get_instance()->db;
    $db->conn_id->beginTransaction(); $db->_trans_depth = 1;
    $data = ['invoiceid' => 42, 'amount' => '100.00', 'transactionid' => 'manual_rejected_request'];
    asaas_before_payment_recorded($data); $gateway->addPayment($data);
    try { asaas_lifecycle_reject(new RuntimeException('Later operation rejected.'), 42); }
    catch (AsaasInvoiceSyncException $error) { pixInterlockAssert(str_contains($error->getMessage(), 'Later operation rejected.'), 'The original operation rejection remains explicit.'); }
    pixInterlockAssert($db->conn_id->inTransaction() && $db->_trans_depth === 1, 'Rejection cannot commit or roll back a transaction owned by the core.');
    pixInterlockAssert(array_sum($db->inner->locks) === 1, 'Rejection must retain the invoice lock while a manual receipt is uncommitted.');
    $reader = new PDO('sqlite:' . $db->inner->path);
    pixInterlockAssert((int) $reader->query('SELECT COUNT(*) FROM tblinvoicepaymentrecords')->fetchColumn() === 0, 'Another connection still cannot see the receipt.');
    $db->conn_id->rollBack(); $db->_trans_depth = 0; asaas_receipt_unlock_all();
});

pixInterlockCase('Pix rechecks a newly activated card mandate after acquiring the shared profile lock', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture();
    $raceStore = new PixInterlockPixStore(get_instance()->db);
    $raceStore->afterLock = static fn () => pixInterlockCardAuthorization($gateway, $invoice);
    $race = new \Asaas\AsaasAutomaticPix($gateway, $raceStore, $gateway->api);
    $result = $race->enroll($invoice, pixConsent($service, $invoice));
    pixInterlockAssert($result['error_code'] === 'other_mandate' && pixProfile() === [], 'Pix must not enroll after a card mandate won the lock.');
    pixInterlockAssert($gateway->api->calls === [], 'The losing Pix path sends no authorization POST.');
});

pixInterlockCase('Recurring card rechecks Pix enrollment after acquiring the shared profile lock', static function (): void {
    [$service, $store, $gateway, $invoice] = pixInterlockFixture();
    $raceStore = new PixInterlockCardStore(get_instance()->db);
    $raceStore->afterLock = static function () use ($service, $invoice): void { pixInterlockEnroll($service, $invoice); };
    $api = new PixInterlockCardApi(); $card = new \Asaas\AsaasRecurring($gateway, $raceStore, $api);
    pixInterlockAssert($card->available($invoice), 'Card was initially eligible before competing enrollment.');
    $result = $card->enroll($invoice, [], ['accepted' => true, 'pay_now' => true, 'contact_id' => 9, 'terms_version' => \Asaas\AsaasRecurring::CONSENT_VERSION]);
    pixInterlockAssert($result['error_code'] === 'other_mandate' && $api->calls === 0, 'Card rechecks under lock before tokenization or charging.');
    pixInterlockAssert(get_instance()->db->query('SELECT * FROM tblasaas_recurring_authorizations')->result_array() === [], 'Only the winning Pix mandate exists.');
});

asaas_receipt_unlock_all();
echo $cases . ' scenarios; ' . $assertions . ' assertions; ' . $failures . ' failures' . PHP_EOL;
exit($failures ? 1 : 0);
