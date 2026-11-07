<?php

declare(strict_types=1);

/** Offline fixture: real schema/store/CAS/helpers with SQLite; MySQL locks are separately exercised. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('BASEPATH')) { define('BASEPATH', __DIR__); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$GLOBALS['pix_ci'] = new stdClass(); $GLOBALS['pix_options'] = []; $GLOBALS['pix_now'] = '2026-11-09 10:00:00';
function &get_instance(): object { return $GLOBALS['pix_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return $GLOBALS['pix_options'][$name] ?? false; }
function is_https(): bool { return $GLOBALS['pix_https'] ?? true; }
function format_invoice_number($id): string { return 'FAT-' . $id; }
function log_message(string $level, string $message): void {}
function get_invoice_total_left_to_pay($id, $total = null): string { return get_instance()->invoices_model->get($id)->balance; }
function register_activation_hook(...$args): void {}
function register_payment_gateway(...$args): void {}
function hooks(): object { static $hooks; return $hooks ??= new class { public function add_action(...$args): void {} public function add_filter(...$args): void {} }; }
require_once dirname(__DIR__, 2) . '/asaas.php';
require_once dirname(__DIR__, 2) . '/helpers/asaas_automatic_pix_helper.php';
require_once dirname(__DIR__, 2) . '/helpers/asaas_automatic_pix_schema_helper.php';
require_once dirname(__DIR__, 2) . '/helpers/asaas_recurring_schema_helper.php';

final class PixTestResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
    public function row(): ?object { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
}
final class PixTestDb
{
    public string $dbdriver = 'pdo'; public string $subdriver = 'mysql';
    public string $hostname = 'fixture-only'; public string $database = 'pix-fixture';
    public bool $db_debug = true; public int $_trans_depth = 0;
    public PDO $conn_id; public string $path; public array $locks = []; public array $queries = [];
    public bool $lost = false; private int $affected = 0;
    private array $where = [];
    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'asaas-pix-');
        $this->conn_id = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->query('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, hash TEXT, clientid INT, status INT, allowed_payment_modes TEXT, total TEXT, data TEXT)');
        $this->query('CREATE TABLE tblcontacts (id INTEGER PRIMARY KEY, userid INT, active INT)');
        $this->query('CREATE TABLE tbloptions (name TEXT PRIMARY KEY, value TEXT)');
        $this->query("INSERT INTO tbloptions VALUES ('paymentmethod_asaas_active','1'),('paymentmethod_asaas_automatic_pix_active','1'),('paymentmethod_asaas_recurring_active','1')");
        $this->query('CREATE TABLE tblasaas_payments_map (invoice_id INTEGER PRIMARY KEY, asaas_payment_id TEXT, payload_cache TEXT)');
        $this->query('CREATE TABLE tblinvoicepaymentrecords (id INTEGER PRIMARY KEY, invoiceid INT, transactionid TEXT UNIQUE, amount TEXT)');
        $this->query('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY, level TEXT, message TEXT, context_json TEXT, created_at TEXT)');
        $this->query('INSERT INTO tblcontacts VALUES (9,7,1),(10,8,1),(11,7,0)');
        asaas_recurring_schema_install($this); asaas_automatic_pix_schema_install($this);
    }
    public function __destruct() { @unlink($this->path); }
    public function query(string $sql, array $bind = []): PixTestResult
    {
        $this->queries[] = $sql;
        // Emulate only MySQL's primitives; production lock ownership code still runs unchanged.
        if (str_contains($sql, 'GET_LOCK(')) { $this->locks[$bind[0]] = ($this->locks[$bind[0]] ?? 0) + 1; return new PixTestResult([['acquired' => 1]]); }
        if (str_contains($sql, 'IS_USED_LOCK(')) { return new PixTestResult([['owned' => !$this->lost && !empty($this->locks[$bind[0]]) ? 1 : 0]]); }
        if (str_contains($sql, 'RELEASE_LOCK(')) { $this->locks[$bind[0]] = max(0, ($this->locks[$bind[0]] ?? 0) - 1); return new PixTestResult([['released' => 1]]); }
        if (str_contains($sql, 'ENGINE=InnoDB')) {
            $sql = str_replace('INT NOT NULL AUTO_INCREMENT', 'INTEGER', $sql);
            $sql = preg_replace('/UNIQUE KEY `[^`]+`/', 'UNIQUE', $sql);
            $sql = preg_replace('/^\s*KEY `[^`]+`[^\n]*\n/m', '', $sql);
            $sql = preg_replace('/,\s*\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', ');', $sql);
        }
        $statement = $this->conn_id->prepare($sql); $statement->execute($bind); $this->affected = $statement->rowCount();
        return new PixTestResult($statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function table_exists(string $table): bool { return $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table])->row_array() !== []; }
    public function affected_rows(): int { return $this->affected; }
    public function list_fields(string $table): array { return array_column($this->query('PRAGMA table_info(' . $table . ')')->result_array(), 'name'); }
    public function get_where(string $table, array $where): PixTestResult
    {
        return $this->query('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn ($key): string => $key . ' = ?', array_keys($where))), array_values($where));
    }
    public function where(string $field, $value): self { $this->where[$field] = $value; return $this; }
    public function get(string $table): PixTestResult { $where = $this->where; $this->where = []; return $this->get_where($table, $where); }
    public function insert(string $table, array $row): bool
    {
        $this->query('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row)); return true;
    }
    public function invoice(int $id = 42, array $changes = []): object
    {
        $old = $this->get_where('tblinvoices', ['id' => $id])->row_array();
        $row = $old === [] ? ['id' => $id, 'hash' => 'pix-fixture-' . $id, 'clientid' => 7, 'status' => 1,
            'allowed_payment_modes' => serialize(['asaas', 'asaas_automatic_pix']), 'total' => '100.00', 'balance' => '100.00',
            'currency' => 1, 'currency_name' => 'BRL', 'duedate' => '2026-11-15'] : json_decode($old['data'], true, 64, JSON_THROW_ON_ERROR);
        $row = array_replace($row, $changes);
        $this->query('INSERT INTO tblinvoices (id,hash,clientid,status,allowed_payment_modes,total,data) VALUES (?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET hash=excluded.hash,clientid=excluded.clientid,status=excluded.status,allowed_payment_modes=excluded.allowed_payment_modes,total=excluded.total,data=excluded.data',
            [$id,$row['hash'],$row['clientid'],$row['status'],$row['allowed_payment_modes'],$row['total'],json_encode($row, JSON_THROW_ON_ERROR)]);
        return (object) $row;
    }
}
final class PixTestCipher
{
    private string $key;
    public function __construct() { $this->key = random_bytes(32); }
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }
    public function decrypt(string $cipher)
    {
        $raw = base64_decode($cipher, true);
        return is_string($raw) ? openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)) : false;
    }
}
final class PixTestProvider
{
    public string $environment = 'sandbox'; public string $key = 'fictional-key';
    public function env(?int $company = null): string { return $this->environment; }
    public function apiKey(?int $company = null): string { return $this->key; }
}
final class PixTestGateway
{
    public PixTestProvider $provider; public PixTestApi $api; public array $settings = ['active' => '1','webhook_secret' => 'fictional-webhook-secret'];
    public bool $failReceipt = false; public bool $pretendReceipt = false;
    public function __construct() { $this->provider = new PixTestProvider(); $this->api = new PixTestApi(); }
    public function getProvider(): object { return $this->provider; }
    public function getSetting(string $name) { return $this->settings[$name] ?? ''; }
    public function ensure_customer_from_client($client): array { return ['customerId' => 'cus_pix_fixture']; }
    public function get_charge2(string $hash): array { return array_values(array_filter($this->api->remotePayments, static fn ($p): bool => ($p['externalReference'] ?? '') === $hash && empty($p['deleted']))); }
    public function request(string $method, string $path): array { return $this->api->payment(basename($path)); }
    public function upsert_payment_map(int $id, array $payment): bool
    {
        get_instance()->db->query('INSERT INTO tblasaas_payments_map VALUES (?,?,?) ON CONFLICT(invoice_id) DO UPDATE SET asaas_payment_id=excluded.asaas_payment_id,payload_cache=excluded.payload_cache', [$id,$payment['id'],json_encode($payment,JSON_THROW_ON_ERROR)]); return true;
    }
    public function addPayment(array $data): bool
    {
        if ($this->failReceipt) { return false; }
        if ($this->pretendReceipt) { return true; }
        $db = get_instance()->db;
        if ($db->get_where('tblinvoicepaymentrecords',['transactionid'=>$data['transactionid']])->row_array() !== []) { return true; }
        $db->insert('tblinvoicepaymentrecords',['invoiceid'=>$data['invoiceid'],'transactionid'=>$data['transactionid'],'amount'=>$data['amount']]);
        $invoice = get_instance()->invoices_model->get($data['invoiceid']);
        $left = max(0, asaas_payment_amount_to_cents($invoice->balance)-asaas_payment_amount_to_cents($data['amount']));
        $db->invoice((int)$invoice->id,['balance'=>asaas_card_money($left),'status'=>$left === 0 ? 2 : 3]); return true;
    }
}
final class PixTestApi
{
    public array $remoteAuthorizations = []; public array $remotePayments = []; public array $remoteTransactions = []; public array $remoteInstructions = [];
    public array $calls = []; public ?Throwable $authorizationError = null; public ?Throwable $paymentError = null; public ?Throwable $deleteError = null;
    public ?Throwable $cancelError = null; public ?Closure $beforeCreate = null; public ?Closure $beforePayment = null;
    public ?Throwable $authorizationReadError = null; public ?Throwable $instructionsReadError = null;
    public bool $omitPaymentAuthorizationId = false; public ?array $paymentsPageOverride = null;
    public function createAuthorization(array $payload): array
    {
        $this->calls[] = 'POST authorization';
        if ($this->beforeCreate) { ($this->beforeCreate)($payload); }
        if ($this->authorizationError instanceof \Asaas\AsaasAutomaticPixRequestException && $this->authorizationError->rejected()) { throw $this->authorizationError; }
        $id = 'auth_fixture_' . (count($this->remoteAuthorizations)+1);
        $now = new DateTimeImmutable($GLOBALS['pix_now'], new DateTimeZone('America/Sao_Paulo'));
        $response = $payload + ['id'=>$id,'status'=>'CREATED','payload'=>'000201fixture-pix-authorization-' . $id,'encodedImage'=>base64_encode("\x89PNG\r\n\x1a\nfixture")];
        $response['immediateQrCode'] = ['conciliationIdentifier'=>'txid_' . $id,'expirationDate'=>$now->modify('+10 minutes')->format('Y-m-d H:i:s')];
        $this->remoteAuthorizations[$id] = $response;
        if ($this->authorizationError) { throw $this->authorizationError; }
        return $response;
    }
    public function authorization(string $id): array { $this->calls[]='GET authorization'; if($this->authorizationReadError){throw $this->authorizationReadError;} return $this->remoteAuthorizations[$id]; }
    public function authorizations(array $query): array
    {
        $this->calls[]='GET authorizations';
        return $this->page(array_values(array_filter($this->remoteAuthorizations,static fn($a):bool=>$a['customerId']===$query['customerId'])),$query);
    }
    public function cancelAuthorization(string $id): array
    {
        $this->calls[]='DELETE authorization';
        if ($this->cancelError instanceof \Asaas\AsaasAutomaticPixRequestException && $this->cancelError->rejected()) { throw $this->cancelError; }
        $this->remoteAuthorizations[$id]['status']='CANCELLED';
        if ($this->cancelError) { throw $this->cancelError; }
        return $this->remoteAuthorizations[$id];
    }
    public function createPayment(array $payload): array
    {
        $this->calls[]='POST payment';
        if ($this->beforePayment) { ($this->beforePayment)($payload); }
        if ($this->paymentError instanceof \Asaas\AsaasAutomaticPixRequestException && $this->paymentError->rejected()) { throw $this->paymentError; }
        $id='pay_pix_cycle_' . (count($this->remotePayments)+1);
        $payment=$payload+['id'=>$id,'status'=>'PENDING']; $this->remotePayments[$id]=$payment;
        $this->remoteInstructions[$id]=[['id'=>'instruction_'.$id,'status'=>'SCHEDULED','paymentId'=>$id,'authorization'=>['id'=>$payload['pixAutomaticAuthorizationId']]]];
        if ($this->paymentError) { throw $this->paymentError; }
        if ($this->omitPaymentAuthorizationId) { unset($payment['pixAutomaticAuthorizationId']); }
        return $payment;
    }
    public function payment(string $id): array { $this->calls[]='GET payment'; return $this->remotePayments[$id]; }
    public function payments(array $query): array
    {
        $this->calls[]='GET payments';
        if ($this->paymentsPageOverride !== null) { return $this->paymentsPageOverride; }
        $rows=array_values(array_filter($this->remotePayments,static function($p)use($query):bool{
            foreach(['customer','billingType','externalReference']as$key){if(isset($query[$key])&&($p[$key]??'')!==$query[$key])return false;}
            return true;
        }));
        return $this->page($rows,$query);
    }
    public function deletePayment(string $id): array
    {
        $this->calls[]='DELETE payment';
        if ($this->deleteError instanceof \Asaas\AsaasAutomaticPixRequestException && $this->deleteError->rejected()) { throw $this->deleteError; }
        $this->remotePayments[$id]['deleted']=true;
        if ($this->deleteError) { throw $this->deleteError; }
        return ['id'=>$id,'deleted'=>true];
    }
    public function transaction(string $id): array { $this->calls[]='GET transaction'; return $this->remoteTransactions[$id]; }
    public function instructions(string $id): array { $this->calls[]='GET instructions'; if($this->instructionsReadError){throw $this->instructionsReadError;} return $this->page($this->remoteInstructions[$id]??[],['limit'=>100]); }
    private function page(array $rows,array $query):array
    {
        $offset=(int)($query['offset']??0); $limit=(int)($query['limit']??10);
        return ['data'=>array_slice($rows,$offset,$limit),'hasMore'=>$offset+$limit<count($rows),'offset'=>$offset,'limit'=>$limit,'totalCount'=>count($rows)];
    }
}
function pixFixture(): array
{
    asaas_receipt_unlock_all();
    $GLOBALS['pix_options'] = []; $GLOBALS['pix_now']='2026-11-09 10:00:00';
    $ci = get_instance(); $ci->db = new PixTestDb(); $ci->encryption = new PixTestCipher();
    $ci->load = new class { public function model($name):void{} public function library($name):void{} public function helper($name):void{} };
    $ci->invoices_model = new class { public function get($id):?object{
        $row=get_instance()->db->get_where('tblinvoices',['id'=>$id])->row_array();
        return $row===[]?null:(object)json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
    }};
    $gateway=$ci->asaas_gateway=new PixTestGateway();
    $store=new \Asaas\AsaasAutomaticPixStore($ci->db);
    $service=new \Asaas\AsaasAutomaticPix($gateway,$store,$gateway->api,static fn():DateTimeImmutable=>new DateTimeImmutable($GLOBALS['pix_now'],new DateTimeZone('America/Sao_Paulo')));
    return [$service,$store,$gateway,$ci->db->invoice()];
}
function pixConsent(object $service,object $invoice,array $changes=[]):array
{
    return array_replace(['accepted'=>true,'terms_version'=>\Asaas\AsaasAutomaticPix::CONSENT_VERSION,'frequency'=>'MONTHLY','contact_id'=>9,'quote'=>$service->page($invoice)['quote']],$changes);
}
function pixProfile():array { return get_instance()->db->query('SELECT * FROM tblasaas_pix_authorizations ORDER BY id DESC LIMIT 1')->row_array(); }
function pixInitialPayment(object $gateway,array $profile,string $status='RECEIVED',string $id='pay_pix_initial'):array
{
    $transaction='tx_'.$id;
    $payment=['id'=>$id,'customer'=>$profile['customer_id'],'billingType'=>'PIX','status'=>$status,'value'=>asaas_card_money((int)$profile['initial_amount_cents']),
        'externalReference'=>null,'pixTransaction'=>$transaction,'paymentDate'=>'2026-11-09'];
    $gateway->api->remotePayments[$id]=$payment;
    $gateway->api->remoteTransactions[$transaction]=['id'=>$transaction,'payment'=>$id,'type'=>'CREDIT','status'=>'DONE','conciliationIdentifier'=>$profile['conciliation_identifier']];
    return $payment;
}
