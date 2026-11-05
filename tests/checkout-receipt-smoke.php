<?php

declare(strict_types=1);

/**
 * Real gateway addPayment and lifecycle helpers with SQLite receipt persistence.
 * MySQL named/row locks are traced contract fixtures, not a concurrency emulator.
 * Run: php tests/checkout-receipt-smoke.php (pdo_sqlite; no HTTP or mbstring).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$temporaryRoot = sys_get_temp_dir() . '/asaas-checkout-receipt-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/modules', 0700, true);
symlink(dirname(__DIR__), $temporaryRoot . '/modules/asaas');
define('BASEPATH', __DIR__);
define('FCPATH', $temporaryRoot . '/');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Relevant native App_gateway contract, including its return/mode behavior. */
class App_gateway
{
    public array $nativeCalls = [];
    public function addPayment($data)
    {
        receiptCheck((asaas_lifecycle_state()['remote_payment_depth'] ?? 0) > 0, 'Parent precisa receber origem remota para não executar cash/delete manual.');
        $this->nativeCalls[] = $data;
        $data['paymentmode'] = 'asaas';
        unset($data['payment_attempt_reference']);
        return (bool) get_instance()->payments_model->add($data);
    }
}

require_once dirname(__DIR__) . '/libraries/Asaas_gateway.php';

final class ReceiptGatewayFixture extends Asaas_gateway
{
    public array $readResponses = [];
    public array $remoteReads = [];
    public function __construct() {}
    public function request(string $method, string $path, array $query = [], array $headers = [], ?array $payload = null, bool $expectsBinary = false, ?int $companyId = null): array
    {
        if ($method !== 'GET' || !isset($this->readResponses[$path])) {
            throw new LogicException('Unexpected provider request; the receipt path must not create, delete or settle a charge.');
        }
        $this->remoteReads[] = $path;
        return $this->readResponses[$path];
    }
}

final class ReceiptResult
{
    public function __construct(private array $rows = []) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function row(): ?object { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
    public function result_array(): array { return $this->rows; }
}

final class ReceiptDatabase
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
    public bool $failCommit = false;
    public ?Closure $onLock = null;
    private bool $status = true;
    private int $affected = 0;

    public function __construct()
    {
        $this->conn_id = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->conn_id->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total TEXT, status TEXT)');
        $this->conn_id->exec("INSERT INTO tblinvoices VALUES (42, '100.00', '1'), (99, '100.00', '1')");
        $this->conn_id->exec('CREATE TABLE tblinvoicepaymentrecords (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, amount TEXT, paymentmode TEXT, paymentmethod TEXT, transactionid TEXT, note TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_payments_map (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER UNIQUE, asaas_payment_id TEXT, payload_cache TEXT)');
        $this->conn_id->exec('CREATE TABLE tblasaas_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    }
    public function depth(): int { return $this->_trans_depth; }
    public function query(string $sql, array $bindings = []): mixed
    {
        $this->trace[] = [$sql, $this->_trans_depth, $this->held];
        foreach ($this->failSqlPrefixes as $index => $prefix) {
            if (str_starts_with($sql, $prefix)) { unset($this->failSqlPrefixes[$index]); $this->status = false; return false; }
        }
        if ($sql === 'SELECT GET_LOCK(?, 5) AS acquired') {
            if ($this->onLock !== null) { $callback = $this->onLock; $this->onLock = null; $callback(); }
            $this->held = true;
            return new ReceiptResult([['acquired' => 1]]);
        }
        if ($sql === 'SELECT RELEASE_LOCK(?) AS released') {
            $this->held = false;
            return new ReceiptResult([['released' => 1]]);
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return new ReceiptResult(array_map(static fn (string $table): array => ['table_name' => $table, 'engine' => 'InnoDB'], $bindings));
        }
        try {
            $statement = $this->conn_id->prepare(preg_replace('/ FOR UPDATE$/', '', $sql));
            $statement->execute($bindings);
            $this->affected = $statement->rowCount();
            return str_starts_with($sql, 'SELECT ') ? new ReceiptResult($statement->fetchAll(PDO::FETCH_ASSOC)) : true;
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

class ReceiptNativeModel
{
    public bool $failBeforeInsert = false;
    public bool $throwAfterInsert = false;
    public function __construct(protected ReceiptDatabase $db) {}
    public function add(array $data): int|false
    {
        if ($this->failBeforeInsert) { return false; }
        if (!$this->db->insert('tblinvoicepaymentrecords', $data)) { return false; }
        $id = $this->db->insert_id();
        if ($this->throwAfterInsert) { throw new RuntimeException('Native notification failed.'); }
        return $id;
    }
}
final class ReceiptAtomicModel extends ReceiptNativeModel
{
    public bool $throwAfterCommit = false;
    private function insert_payment_atomically(array $data): int|false
    {
        receiptCheck($this->db->depth() === 0 && $this->db->held, 'Writer atômico precisa de depth zero e advisory ainda adquirido.');
        $this->db->trans_begin();
        try {
            $id = parent::add($data);
            if (!$id) { $this->db->trans_rollback(); return false; }
            $this->db->trans_commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            throw $error;
        }
    }
    public function add(array $data): int|false
    {
        $id = $this->insert_payment_atomically($data);
        if ($id && $this->throwAfterCommit) { throw new RuntimeException('Email failed after core-owned commit.'); }
        return $id;
    }
}
final class ReceiptAtomicWriterSentinel {}
class_alias(ReceiptAtomicWriterSentinel::class, 'app\\services\\InvoicePaymentWriter');

final class ReceiptInvoiceModel
{
    public bool $integerStatus = false;
    public function get($id): ?object
    {
        $row = get_instance()->db->get_where('tblinvoices', ['id' => $id])->row();
        if ($row && $this->integerStatus) { $row->status = (int) $row->status; }
        return $row;
    }
}
final class ReceiptLoader
{
    public function model(string $name): void {}
    public function library(string $name): void {}
}

$GLOBALS['receipt_ci'] = new stdClass();
$GLOBALS['receipt_assertions'] = 0;
$GLOBALS['receipt_logs'] = [];
function &get_instance(): object { return $GLOBALS['receipt_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $key) { return false; }
function log_message(string $level, string $message): void { $GLOBALS['receipt_logs'][] = [$level, $message]; }
function get_invoice_total_left_to_pay($id, $total = null): string
{
    $db = get_instance()->db;
    $paid = $db->query('SELECT amount FROM tblinvoicepaymentrecords WHERE invoiceid = ?', [$id])->result_array();
    $cents = asaas_payment_amount_to_cents($total);
    foreach ($paid as $row) { $cents -= asaas_payment_amount_to_cents($row['amount']); }
    return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
}
function receiptCheck(bool $condition, string $message): void
{
    ++$GLOBALS['receipt_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function receiptReset(bool $atomic = false): array
{
    $state = &asaas_lifecycle_state(); $state = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
    $handoffs = &asaas_atomic_payment_state(); $handoffs = [];
    $db = new ReceiptDatabase(); $gateway = new ReceiptGatewayFixture();
    $GLOBALS['receipt_ci'] = (object) ['db' => $db, 'load' => new ReceiptLoader(), 'invoices_model' => new ReceiptInvoiceModel(),
        'payments_model' => $atomic ? new ReceiptAtomicModel($db) : new ReceiptNativeModel($db), 'asaas_gateway' => $gateway];
    $GLOBALS['receipt_logs'] = [];
    return [$gateway, $db];
}
function receiptData(array $changes = []): array
{
    return array_replace(['invoiceid' => 42, 'amount' => '100.00', 'paymentmode' => 'Asaas', 'paymentmethod' => 'CREDIT_CARD', 'transactionid' => 'pay_checkout', 'note' => 'Checkout receipt'], $changes);
}
function receiptRows(ReceiptDatabase $db): array { return $db->query('SELECT * FROM tblinvoicepaymentrecords ORDER BY id')->result_array(); }
function receiptSetMap(ReceiptDatabase $db, array $metadata): void
{
    $db->insert('tblasaas_payments_map', ['invoice_id' => 42, 'asaas_payment_id' => 'pay_checkout', 'payload_cache' => json_encode(['id' => 'pay_checkout', '_asaas_lifecycle' => $metadata], JSON_THROW_ON_ERROR)]);
}

$tests = [];
$tests['nativo confirma recibo sob bloqueio e só libera após commit'] = static function (): void {
    [$gateway, $db] = receiptReset();
    receiptCheck($gateway->addPayment(receiptData()) === true, 'Recebimento nativo precisa retornar true.');
    $rows = receiptRows($db);
    receiptCheck(count($rows) === 1 && $rows[0]['amount'] === '100.00' && $rows[0]['paymentmode'] === 'asaas', 'Persistir o recibo pelo contrato nativo.');
    $sql = array_column($db->trace, 0);
    receiptCheck(array_search('SELECT GET_LOCK(?, 5) AS acquired', $sql, true) < array_search('BEGIN', $sql, true), 'Advisory vem antes da transação e leituras.');
    receiptCheck(array_search('SELECT id FROM tblinvoices WHERE id = ? FOR UPDATE', $sql, true) < array_search('SELECT * FROM tblinvoices WHERE id = ?', $sql, true), 'Recarregar fatura somente após o row lock.');
    receiptCheck(array_search('COMMIT', $sql, true) < array_search('SELECT RELEASE_LOCK(?) AS released', $sql, true), 'Confirmar antes de liberar advisory.');
};
$tests['total antigo do checkout é limitado ao saldo atual sem alterar outros campos'] = static function (): void {
    [$gateway, $db] = receiptReset();
    $db->insert('tblinvoicepaymentrecords', receiptData(['amount' => '30.00', 'transactionid' => 'pay_manual', 'paymentmode' => '2']));
    receiptCheck($gateway->addPayment(receiptData()), 'Saldo remanescente precisa ser registrado.');
    receiptCheck($gateway->nativeCalls === [receiptData(['amount' => '70.00'])], 'Preservar meio/método/referência/nota e ajustar somente valor ao saldo.');
    receiptCheck(count(receiptRows($db)) === 2, 'Manter recebimento parcial anterior.');
};
$tests['valor menor solicitado permanece parcial'] = static function (): void {
    [$gateway, $db] = receiptReset();
    receiptCheck($gateway->addPayment(receiptData(['amount' => '40.00'])), 'Recebimento parcial continua permitido.');
    receiptCheck(receiptRows($db)[0]['amount'] === '40.00', 'Não converter pagamento menor em quitação integral.');
};
$tests['webhook gravado enquanto checkout aguarda lock não duplica recebimento'] = static function (): void {
    [$gateway, $db] = receiptReset();
    $db->onLock = static function () use ($db): void {
        $db->insert('tblinvoicepaymentrecords', receiptData());
        $db->query('UPDATE tblinvoices SET status = ? WHERE id = ?', ['2', 42]);
    };
    receiptCheck($gateway->addPayment(receiptData()), 'Duplicata já persistida deve ser idempotente.');
    receiptCheck($gateway->nativeCalls === [] && count(receiptRows($db)) === 1, 'Não chamar parent quando invoiceid+transactionid já existem.');
};
$tests['deduplicação inclui a fatura'] = static function (): void {
    [$gateway, $db] = receiptReset();
    $db->insert('tblinvoicepaymentrecords', receiptData(['invoiceid' => 99]));
    receiptCheck($gateway->addPayment(receiptData()) && count(receiptRows($db)) === 2, 'Recibo de outra fatura não comprova o recebimento desta.');
};
foreach ([false, true] as $integerStatus) {
    $tests['status pago ' . ($integerStatus ? 'inteiro' : 'string') . ' não adiciona recibo'] = static function () use ($integerStatus): void {
        [$gateway, $db] = receiptReset(); get_instance()->invoices_model->integerStatus = $integerStatus;
        $db->query('UPDATE tblinvoices SET status = ? WHERE id = ?', ['2', 42]);
        receiptCheck($gateway->addPayment(receiptData()) && $gateway->nativeCalls === [] && receiptRows($db) === [], 'Status pago deve impedir segunda baixa independente do tipo PHP.');
    };
}
foreach ([5, 6] as $status) {
    $tests['status indisponível ' . $status . ' exige conciliação sem INSERT'] = static function () use ($status): void {
        [$gateway, $db] = receiptReset(); $db->query('UPDATE tblinvoices SET status = ? WHERE id = ?', [$status, 42]);
        receiptCheck(!$gateway->addPayment(receiptData()) && $gateway->nativeCalls === [] && receiptRows($db) === [], 'Não baixar fatura cancelada ou em rascunho.');
    };
}
$tests['consulta de dedup falha sem autorizar novo recibo'] = static function (): void {
    [$gateway, $db] = receiptReset(); $db->failSqlPrefixes = ['SELECT * FROM tblinvoicepaymentrecords'];
    receiptCheck(!$gateway->addPayment(receiptData()) && $gateway->nativeCalls === [], 'Ausência de resposta não é ausência de recibo.');
};
foreach (['before', 'after', 'commit'] as $failure) {
    $tests['falha nativa ' . $failure . ' retorna false e desfaz recibo local'] = static function () use ($failure): void {
        [$gateway, $db] = receiptReset();
        get_instance()->payments_model->failBeforeInsert = $failure === 'before';
        get_instance()->payments_model->throwAfterInsert = $failure === 'after';
        $db->failCommit = $failure === 'commit';
        receiptCheck(!$gateway->addPayment(receiptData()) && receiptRows($db) === [], 'Não confirmar nem manter INSERT quando o caminho nativo falha.');
    };
}
foreach (['success', 'reject', 'email'] as $outcome) {
    $tests['writer atômico ' . $outcome . ' recebe transação externa zero'] = static function () use ($outcome): void {
        [$gateway, $db] = receiptReset(true);
        get_instance()->payments_model->failBeforeInsert = $outcome === 'reject';
        get_instance()->payments_model->throwAfterCommit = $outcome === 'email';
        $expected = $outcome !== 'reject';
        receiptCheck($gateway->addPayment(receiptData()) === $expected, 'Preservar sucesso durável mesmo quando a notificação posterior falha.');
        receiptCheck(count(receiptRows($db)) === ($expected ? 1 : 0), 'A persistência deve corresponder ao resultado do writer.');
        if ($expected) {
            receiptCheck($gateway->addPayment(receiptData()) && count(receiptRows($db)) === 1, 'Repetição posterior não insere outro recibo.');
        }
    };
}
foreach (['retired', 'manual_deleted', 'cash_reverted'] as $case) {
    $tests['decisão anterior ' . $case . ' bloqueia payload antigo do checkout'] = static function () use ($case): void {
        [$gateway, $db] = receiptReset();
        $metadata = match ($case) {
            'retired' => ['state' => 'active', 'retired_payment_ids' => ['pay_checkout']],
            'manual_deleted' => ['state' => 'manual_deleted'],
            default => ['state' => 'active', 'cash_receipt_reverted' => true],
        };
        receiptSetMap($db, $metadata);
        if ($case === 'cash_reverted') { $gateway->readResponses['/payments/pay_checkout'] = ['id' => 'pay_checkout', 'status' => 'PENDING']; }
        receiptCheck(!$gateway->addPayment(receiptData()) && $gateway->nativeCalls === [] && receiptRows($db) === [], 'Checkout precisa respeitar a mesma decisão de exclusão/substituição/reversão usada no webhook.');
        receiptCheck(count($gateway->remoteReads) === ($case === 'cash_reverted' ? 1 : 0), 'Somente reversão em dinheiro requer a releitura remota existente.');
    };
}
$tests['identificador ausente não permite recibo impossível de deduplicar'] = static function (): void {
    [$gateway, $db] = receiptReset();
    receiptCheck(!$gateway->addPayment(receiptData(['transactionid' => ''])) && $gateway->nativeCalls === [] && !$db->held, 'Checkout confirmado sempre precisa identificar a cobrança.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        receiptCheck(get_instance()->db->depth() === 0 && !get_instance()->db->held, 'O método deve concluir a transação e liberar seu bloqueio.');
        receiptCheck(empty(asaas_lifecycle_state()['remote_payment_depth']), 'A origem remota não pode vazar para a próxima operação.');
        echo 'OK  ', $name, PHP_EOL;
    } catch (Throwable $error) { ++$failed; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
}
register_shutdown_function(static function () use ($temporaryRoot): void {
    unlink($temporaryRoot . '/modules/asaas'); rmdir($temporaryRoot . '/modules'); rmdir($temporaryRoot);
});
echo 'Checkout receipt smoke: ', count($tests), ' cenários, ', $GLOBALS['receipt_assertions'], ' asserções, ', $failed, ' falhas.', PHP_EOL;
exit($failed === 0 ? 0 : 1);
