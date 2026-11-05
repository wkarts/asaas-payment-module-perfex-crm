<?php

declare(strict_types=1);

/**
 * Real SQLite persistence for the custom-core payment handoff.
 * Run: php tests/atomic-payment-smoke.php (pdo_sqlite; no HTTP/Perfex/mbstring).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('BASEPATH', __DIR__);

require_once dirname(__DIR__) . '/helpers/asaas_atomic_payment_helper.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$GLOBALS['atomic_smoke_logs'] = [];
$GLOBALS['atomic_smoke_assertions'] = 0;
$GLOBALS['atomic_smoke_ci'] = new stdClass();

function &get_instance(): object { return $GLOBALS['atomic_smoke_ci']; }
function db_prefix(): string { return 'tbl'; }
function asaas_diagnostic_log(string $level, string $message, array $context = [], ?bool $fileEnabled = null): void
{
    $GLOBALS['atomic_smoke_logs'][] = compact('level', 'message', 'context');
}

final class AtomicPaymentDatabaseFixture
{
    protected int $_trans_depth = 0;
    public bool $db_debug = true;
    public bool $trans_enabled = true;
    public string $dbdriver = 'sqlite3';
    public string $subdriver = '';
    public bool $failCommit = false;
    public array $failSqlPrefixes = [];
    public array $trace = [];
    public PDO $connection;
    private bool $status = true;
    private int $affected = 0;

    public function __construct()
    {
        $this->connection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->connection->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total TEXT, status INTEGER)');
        $this->connection->exec("INSERT INTO tblinvoices VALUES (42, '100.00', 1)");
        $this->connection->exec('CREATE TABLE tblinvoicepaymentrecords (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, amount TEXT, paymentmode TEXT, transactionid TEXT, date TEXT)');
        $this->connection->exec('CREATE TABLE tblasaas_payments_map (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER UNIQUE, asaas_payment_id TEXT, billing_type TEXT, status TEXT, value TEXT, due_date TEXT, last_sync_at TEXT, payload_cache TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function query(string $sql, array $bindings = []): mixed
    {
        $this->trace[] = ['sql' => $sql, 'depth' => $this->_trans_depth, 'debug' => $this->db_debug];
        foreach ($this->failSqlPrefixes as $index => $prefix) {
            if (str_starts_with($sql, $prefix)) {
                unset($this->failSqlPrefixes[$index]);
                $this->status = false;
                return false;
            }
        }
        try {
            $statement = $this->connection->prepare($sql);
            $statement->execute($bindings);
            $this->affected = $statement->rowCount();
            if (str_starts_with($sql, 'SELECT ')) {
                return new class($statement->fetchAll(PDO::FETCH_ASSOC)) {
                    public function __construct(private array $rows) {}
                    public function result_array(): array { return $this->rows; }
                };
            }
            return true;
        } catch (Throwable $error) {
            $this->status = false;
            if ($this->db_debug) { throw $error; }
            return false;
        }
    }

    public function affected_rows(): int { return $this->affected; }
    public function insert_id(): int { return (int) $this->connection->lastInsertId(); }
    public function trans_status(): bool { return $this->status; }
    public function trans_begin(): bool
    {
        if ($this->_trans_depth > 0) { ++$this->_trans_depth; return true; }
        if ($this->connection->beginTransaction()) { ++$this->_trans_depth; $this->status = true; return true; }
        return false;
    }
    public function trans_commit(): bool
    {
        if ($this->failCommit || $this->_trans_depth === 0) { return false; }
        if ($this->_trans_depth > 1 || $this->connection->commit()) { --$this->_trans_depth; return true; }
        return false;
    }
    public function trans_rollback(): bool
    {
        if ($this->_trans_depth === 0) { return false; }
        if ($this->_trans_depth > 1 || $this->connection->rollBack()) { --$this->_trans_depth; return true; }
        return false;
    }
}

final class AtomicOwnedPaymentsModelFixture
{
    public bool $reject = false;
    public function __construct(private AtomicPaymentDatabaseFixture $db) {}
    private function insert_payment_atomically(array $data): int|false
    {
        atomicCheck(asaas_atomic_payment_depth($this->db) === 0, 'O núcleo não pode receber uma transação externa aberta.');
        if ($this->reject) { return false; }
        $this->db->trans_begin();
        atomicInsertReceipt($this->db, $data);
        $id = $this->db->insert_id();
        $this->db->trans_commit();
        return $id;
    }
    public function add(array $data, bool $notificationFails = false): int|false
    {
        $id = $this->insert_payment_atomically($data);
        if (!$id) { return false; }
        if ($notificationFails) { throw new RuntimeException('Notification failed after the native commit.'); }
        asaas_atomic_payment_complete($id);
        return $id;
    }
}
final class AtomicWriterTypeFixture {}
final class AtomicPublicMethodFixture { public function insert_payment_atomically(): void {} }

function atomicReset(bool $withMap = true): AtomicPaymentDatabaseFixture
{
    $operations = &asaas_atomic_payment_state();
    $operations = [];
    $db = new AtomicPaymentDatabaseFixture();
    $GLOBALS['atomic_smoke_ci'] = (object) ['db' => $db, 'payments_model' => new AtomicOwnedPaymentsModelFixture($db)];
    $GLOBALS['atomic_smoke_logs'] = [];
    if ($withMap) {
        $payload = ['id' => 'pay_original', 'status' => 'PENDING', 'value' => 100,
            '_asaas_lifecycle' => ['state' => 'active', 'retired_payment_ids' => ['pay_older'], 'invoice_snapshot' => ['status' => 1, 'number' => 42]]];
        $db->query('INSERT INTO tblasaas_payments_map (invoice_id, asaas_payment_id, billing_type, status, value, payload_cache) VALUES (?, ?, ?, ?, ?, ?)',
            [42, 'pay_original', 'BOLETO', 'PENDING', '100.00', json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
    return $db;
}

function atomicData(array $changes = []): array
{
    return array_replace(['invoiceid' => 42, 'amount' => '100.00', 'paymentmode' => '2', 'transactionid' => '', 'date' => '2026-11-05'], $changes);
}

function atomicInsertReceipt(AtomicPaymentDatabaseFixture $db, array $data): int
{
    $db->query('INSERT INTO tblinvoicepaymentrecords (invoiceid, amount, paymentmode, transactionid, date) VALUES (?, ?, ?, ?, ?)',
        [$data['invoiceid'], $data['amount'], $data['paymentmode'], $data['transactionid'], $data['date']]);
    return $db->insert_id();
}

/** Simulate root's remote operation/map commit, then detach compensation to this helper. */
function atomicArm(AtomicPaymentDatabaseFixture $db, array &$data, array $callbacks): array
{
    $guard = new \Asaas\InvoiceTransaction($db);
    $guard->begin();
    $operation = asaas_atomic_payment_prepare($data);
    $before = asaas_atomic_payment_map($db, 42);
    $cache = $before === [] ? ['id' => 'pay_original'] : json_decode($before['payload_cache'], true, 512, JSON_THROW_ON_ERROR);
    $cache['status'] = 'RECEIVED_IN_CASH';
    $cache['_asaas_lifecycle']['state'] = 'manual_received';
    $cache['_asaas_lifecycle']['atomic_handoff'] = ['operation_id' => $operation, 'state' => 'pending'];
    if ($before === []) {
        $db->query('INSERT INTO tblasaas_payments_map (invoice_id, asaas_payment_id, status, value, payload_cache) VALUES (?, ?, ?, ?, ?)',
            [42, 'pay_original', 'RECEIVED_IN_CASH', '100.00', json_encode($cache, JSON_THROW_ON_ERROR)]);
    } else {
        $db->query('UPDATE tblasaas_payments_map SET status = ?, payload_cache = ? WHERE invoice_id = ?',
            ['RECEIVED_IN_CASH', json_encode($cache, JSON_THROW_ON_ERROR), 42]);
    }
    $after = asaas_atomic_payment_map($db, 42);
    foreach ($callbacks as $callback) { $guard->afterRollback($callback); }
    $guard->commit();
    asaas_atomic_payment_handoff($operation, $callbacks, $before, $after);
    return [$operation, $before, $after];
}

function atomicCheck(bool $condition, string $message): void
{
    ++$GLOBALS['atomic_smoke_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function atomicThrows(callable $operation): void
{
    try { $operation(); } catch (Throwable $error) { ++$GLOBALS['atomic_smoke_assertions']; return; }
    throw new RuntimeException('A operação deveria ter sido rejeitada.');
}

$tests = [];
$tests['detecção exige contrato privado e a classe do núcleo personalizado'] = static function (): void {
    atomicReset();
    atomicCheck(!asaas_atomic_payment_writer_detected(new stdClass()), 'Modelo nativo não ativa compatibilidade.');
    atomicCheck(!asaas_atomic_payment_writer_detected(new AtomicPublicMethodFixture()), 'Um método público de mesmo nome não confirma este contrato.');
    atomicCheck(!asaas_atomic_payment_writer_detected(), 'Método privado sem a classe do writer não confirma este contrato.');
    class_alias(AtomicWriterTypeFixture::class, 'app\\services\\InvoicePaymentWriter');
    atomicCheck(asaas_atomic_payment_writer_detected(), 'O contrato conhecido habilita a compatibilidade.');
};
$tests['prepare preserva referências existentes e altera somente referência vazia'] = static function (): void {
    $db = atomicReset();
    $original = $data = atomicData();
    $operation = asaas_atomic_payment_prepare($data);
    atomicCheck($data['transactionid'] === 'asaas-manual-' . $operation, 'Referência gerada identifica a operação sem alterar outros campos.');
    unset($data['transactionid'], $original['transactionid']);
    atomicCheck($data === $original && asaas_atomic_payment_depth($db) === 0, 'Prepare não altera a transação nem outros dados.');
    $data = atomicData(['transactionid' => 'external-existing-reference']);
    $next = asaas_atomic_payment_prepare($data);
    atomicCheck($operation !== $next && $data['transactionid'] === 'external-existing-reference', 'Preservar referência externa enquanto cada tentativa tem operationId próprio.');
};
$tests['handoff recusa transação aberta e ausência do marcador durável'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $operation = asaas_atomic_payment_prepare($data);
    $db->trans_begin();
    atomicThrows(static fn () => asaas_atomic_payment_handoff($operation, [], [], []));
    $db->trans_rollback();
    atomicThrows(static fn () => asaas_atomic_payment_handoff($operation, [static function (): void {}], [], []));
};
$tests['after confirma recibo durável sem reverter operação nem perder metadados'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $id = get_instance()->payments_model->add($data);
    atomicCheck(is_int($id) && $calls === 0, 'Recebimento do núcleo confirmado não deve ser desfeito.');
    $cache = json_decode(asaas_atomic_payment_map($db, 42)['payload_cache'], true, 512, JSON_THROW_ON_ERROR);
    atomicCheck($cache['_asaas_lifecycle']['atomic_handoff'] === ['operation_id' => $operation, 'state' => 'confirmed', 'payment_id' => $id], 'Concluir exatamente o marcador da operação gravada.');
    atomicCheck($cache['_asaas_lifecycle']['state'] === 'manual_received' && $cache['_asaas_lifecycle']['retired_payment_ids'] === ['pay_older'], 'Preservar decisões do ciclo da cobrança.');
    asaas_atomic_payment_shutdown(); asaas_atomic_payment_complete($id);
    atomicCheck($calls === 0 && asaas_atomic_payment_depth($db) === 0, 'Após conclusão não abrir compensações tardias.');
};
$tests['falha de email após commit do núcleo não desfaz recebimento'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    atomicThrows(static fn () => get_instance()->payments_model->add($data, true));
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 0 && count(asaas_atomic_payment_receipts($db, 42)) === 1, 'Shutdown precisa reler o recibo em vez de depender do afterhook.');
    atomicCheck(asaas_atomic_payment_map_marker(asaas_atomic_payment_map($db, 42))['state'] === 'confirmed', 'O mapa confirma o recibo mesmo após erro posterior de notificação.');
};
$tests['recusa do writer compensa LIFO uma vez e restaura mapa original'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = [];
    [$operation, $before] = atomicArm($db, $data, [
        static function () use (&$calls): void { $calls[] = 'first'; },
        static function () use (&$calls): void { $calls[] = 'last'; },
    ]);
    get_instance()->payments_model->reject = true;
    atomicCheck(get_instance()->payments_model->add($data) === false, 'A recusa do núcleo não cria recibo.');
    asaas_atomic_payment_shutdown(); asaas_atomic_payment_shutdown();
    atomicCheck($calls === ['last', 'first'], 'Compensar uma vez em ordem inversa.');
    atomicCheck(asaas_atomic_payment_map($db, 42) === $before, 'Restauração recupera o vínculo anterior completo.');
    atomicCheck(asaas_atomic_payment_state()[$operation]['state'] === 'compensated', 'Marcar a decisão já executada.');
};
$tests['snapshot com JSON reordenado e whitespace é financeiramente equivalente'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [, $before, $after] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $cache = json_decode($after['payload_cache'], true, 512, JSON_THROW_ON_ERROR);
    $cache = array_reverse($cache, true);
    $cache['_asaas_lifecycle'] = array_reverse($cache['_asaas_lifecycle'], true);
    $db->query('UPDATE tblasaas_payments_map SET payload_cache = ? WHERE invoice_id = ?', [json_encode($cache, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 42]);
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 1 && asaas_atomic_payment_map($db, 42) === $before, 'Formatação JSON não pode ocultar prova segura de ausência do recibo.');
};
$tests['mapa alterado após handoff exige conciliação sem compensação'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $db->query('UPDATE tblasaas_payments_map SET asaas_payment_id = ? WHERE invoice_id = ?', ['pay_another', 42]);
    asaas_atomic_payment_shutdown(); asaas_atomic_payment_shutdown();
    atomicCheck($calls === 0 && asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Não reverter estado concorrente ou desconhecido.');
    atomicCheck(asaas_atomic_payment_map($db, 42)['asaas_payment_id'] === 'pay_another', 'Não sobrescrever outro vínculo.');
    atomicCheck($GLOBALS['atomic_smoke_logs'] !== [], 'Preservar diagnóstico com IDs de conciliação.');
};
$tests['consulta de recibos recusada não equivale a ausência de pagamento'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation, , $after] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $db->failSqlPrefixes = ['SELECT id, invoiceid'];
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 0 && asaas_atomic_payment_map($db, 42) === $after, 'Banco indisponível exige conciliação; nunca desfazer por suposição.');
    atomicCheck($db->db_debug && asaas_atomic_payment_depth($db) === 0 && asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Fechar somente a transação da verificação e restaurar db_debug.');
};
$tests['referência reutilizada não confunde recibo antigo com INSERT novo'] = static function (): void {
    $db = atomicReset(); $data = atomicData(['transactionid' => 'reused']); $calls = 0;
    atomicInsertReceipt($db, $data);
    atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 1 && count(asaas_atomic_payment_receipts($db, 42)) === 1, 'Sem novo recibo, a referência já existente não impede compensação.');
};
$tests['outra mutação financeira impede compensação mesmo sem recibo correspondente'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    atomicInsertReceipt($db, atomicData(['amount' => '10.00', 'transactionid' => 'different-receipt']));
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 0 && asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Mudança financeira não relacionada exige reconciliação, não undo automático.');
};
$tests['undo remoto falho preserva marcador e executa cada callback no máximo uma vez'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = [];
    [$operation, , $after] = atomicArm($db, $data, [
        static function () use (&$calls): void { $calls[] = 'first'; },
        static function () use (&$calls): void { $calls[] = 'failing'; throw new RuntimeException('Sensitive API response must not be logged verbatim.'); },
    ]);
    asaas_atomic_payment_shutdown(); asaas_atomic_payment_shutdown();
    atomicCheck($calls === ['failing', 'first'] && asaas_atomic_payment_map($db, 42) === $after, 'Continuar callbacks isolados e não declarar o mapa restaurado quando undo falha.');
    atomicCheck(asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Erro remoto mantém pendência.');
    atomicCheck(!str_contains(json_encode($GLOBALS['atomic_smoke_logs']), 'Sensitive API response'), 'Helper registra classe/IDs, não payload arbitrário da exceção.');
};
$tests['recibo confirmado prevalece sobre falha ao concluir metadata'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $db->failSqlPrefixes = ['UPDATE tblasaas_payments_map'];
    $id = get_instance()->payments_model->add($data);
    asaas_atomic_payment_shutdown();
    atomicCheck(is_int($id) && $calls === 0 && count(asaas_atomic_payment_receipts($db, 42)) === 1, 'Nunca desfazer recebimento já persistido por falha na anotação auxiliar.');
    atomicCheck(asaas_atomic_payment_state()[$operation]['state'] === 'completed' && $GLOBALS['atomic_smoke_logs'] !== [], 'Manter prova do recibo e diagnosticar o marcador pendente.');
};
$tests['afterhook de outro recibo não finaliza operação ainda pendente'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    $oldId = atomicInsertReceipt($db, atomicData(['amount' => '1.00', 'transactionid' => 'old']));
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    asaas_atomic_payment_complete($oldId);
    atomicCheck(asaas_atomic_payment_state()[$operation]['state'] === 'armed', 'Aguardar o recibo da própria operação.');
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 1, 'Shutdown continua capaz de resolver a operação correta.');
};
$tests['novo vínculo criado pelo handoff é removido quando recibo não existe'] = static function (): void {
    $db = atomicReset(false); $data = atomicData(); $calls = 0;
    atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 1 && asaas_atomic_payment_map($db, 42) === [], 'Compensação restaura também a ausência original do vínculo.');
};
$tests['commit da conciliação recusado não repete undo já executado'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation, , $after] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $db->failCommit = true;
    asaas_atomic_payment_shutdown(); asaas_atomic_payment_shutdown();
    atomicCheck($calls === 1 && asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Falha após o undo exige revisão, não segunda tentativa automática.');
    atomicCheck(asaas_atomic_payment_map($db, 42) === $after && asaas_atomic_payment_depth($db) === 0, 'Rollback preserva marcador pendente para conciliação externa.');
};
$tests['operação com transação externa ainda aberta não presume persistência ou ausência'] = static function (): void {
    $db = atomicReset(); $data = atomicData(); $calls = 0;
    [$operation] = atomicArm($db, $data, [static function () use (&$calls): void { ++$calls; }]);
    $db->trans_begin();
    asaas_atomic_payment_shutdown();
    atomicCheck($calls === 0 && asaas_atomic_payment_state()[$operation]['state'] === 'needs_review', 'Não decidir compensação usando dados ainda não confirmados de outra transação.');
    atomicCheck(asaas_atomic_payment_depth($db) === 1, 'Não controlar nem finalizar transação alheia.');
    $db->trans_rollback();
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'OK  ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$failures; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
}
$operations = &asaas_atomic_payment_state();
$operations = [];
echo 'Atomic payment smoke: ', count($tests), ' cenários, ', $GLOBALS['atomic_smoke_assertions'], ' asserções, ', $failures, ' falhas.', PHP_EOL;
exit($failures === 0 ? 0 : 1);
