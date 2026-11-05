<?php

declare(strict_types=1);

/** Real lock helper; controlled MySQL result/connection contracts, no network or MySQL server. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);

final class ReceiptLockResult
{
    public function __construct(private array $row) {}
    public function row_array(): array { return $this->row; }
}

final class ReceiptLockDatabase
{
    public string $dbdriver = 'mysqli';
    public string $subdriver = '';
    public string $hostname = 'database.fixture.invalid';
    public string $database = 'perfex_fixture';
    public bool $db_debug = true;
    public $conn_id;
    public array $calls = [];
    public array $queued = [];
    public function __construct() { $this->conn_id = new stdClass(); }
    public function owner(): string { return is_resource($this->conn_id) ? 'r' . get_resource_id($this->conn_id) : 'o' . spl_object_id($this->conn_id); }
    public function query(string $sql, array $bindings = [])
    {
        $get = $sql === 'SELECT GET_LOCK(?, 5) AS acquired';
        if (!$get && $sql !== 'SELECT RELEASE_LOCK(?) AS released') { throw new LogicException('Unexpected SQL: ' . $sql); }
        $this->calls[] = ['sql' => $sql, 'bindings' => $bindings, 'debug' => $this->db_debug];
        $GLOBALS['receipt_events'][] = $get ? 'GET_LOCK' : 'RELEASE_LOCK';
        if (count($bindings) !== 1 || !is_string($bindings[0])) { throw new LogicException('Lock names must be bound as one string.'); }
        $name = $bindings[0]; $owner = $this->owner();
        if ($this->queued !== []) {
            $result = array_shift($this->queued);
            if ($result instanceof Throwable) { throw $result; }
            if ($result === false || $result === true) { return $result; }
            if (in_array($result['acquired'] ?? null, [1, '1'], true)) {
                $GLOBALS['receipt_server'][$name] = ['owner' => $owner, 'references' => 1];
            }
            return new ReceiptLockResult($result);
        }
        $held = $GLOBALS['receipt_server'][$name] ?? null;
        if ($get) {
            if ($held !== null && $held['owner'] !== $owner) { return new ReceiptLockResult(['acquired' => 0]); }
            $GLOBALS['receipt_server'][$name] = ['owner' => $owner, 'references' => ($held['references'] ?? 0) + 1];
            return new ReceiptLockResult(['acquired' => 1]);
        }
        if ($held === null) { return new ReceiptLockResult(['released' => null]); }
        if ($held['owner'] !== $owner) { return new ReceiptLockResult(['released' => 0]); }
        if (--$GLOBALS['receipt_server'][$name]['references'] === 0) { unset($GLOBALS['receipt_server'][$name]); }
        return new ReceiptLockResult(['released' => 1]);
    }
    public function trans_begin(): void { throw new LogicException('Receipt helper must not begin transactions.'); }
    public function trans_commit(): void { throw new LogicException('Receipt helper must not commit transactions.'); }
    public function trans_rollback(): void { throw new LogicException('Receipt helper must not roll back transactions.'); }
}

final class ReceiptShutdownTransaction
{
    public int $calls = 0;
    public $callback = null;
    public function shutdown(): void
    {
        ++$this->calls; $GLOBALS['receipt_events'][] = 'transaction.shutdown';
        if ($this->callback !== null) { ($this->callback)(); }
    }
}

function get_instance() { return $GLOBALS['receipt_ci']; }
function &asaas_lifecycle_state(): array { return $GLOBALS['receipt_lifecycle']; }
function asaas_atomic_payment_shutdown(): void
{
    ++$GLOBALS['receipt_atomic_calls']; $GLOBALS['receipt_events'][] = 'atomic.shutdown';
    if ($GLOBALS['receipt_atomic_callback'] !== null) { ($GLOBALS['receipt_atomic_callback'])(); }
}
function asaas_diagnostic_log(string $level, string $message, array $context = [], ?bool $fileLogEnabled = null): void
{
    $GLOBALS['receipt_logs'][] = [$level, $message, $context];
    if ($GLOBALS['receipt_logger_throws']) { throw new RuntimeException('Logger fixture failure.'); }
}

$GLOBALS['receipt_ci'] = (object) ['db' => new ReceiptLockDatabase()];
$GLOBALS['receipt_server'] = []; $GLOBALS['receipt_events'] = []; $GLOBALS['receipt_logs'] = [];
$GLOBALS['receipt_lifecycle'] = []; $GLOBALS['receipt_atomic_callback'] = null;
$GLOBALS['receipt_atomic_calls'] = 0; $GLOBALS['receipt_logger_throws'] = false;
require_once dirname(__DIR__) . '/helpers/asaas_receipt_lock_helper.php';

function receiptFixture(): ReceiptLockDatabase
{
    asaas_receipt_unlock_all();
    $state = &asaas_receipt_lock_state(); $state = [];
    $GLOBALS['receipt_ci'] = (object) ['db' => new ReceiptLockDatabase()];
    $GLOBALS['receipt_server'] = []; $GLOBALS['receipt_events'] = []; $GLOBALS['receipt_logs'] = [];
    $GLOBALS['receipt_lifecycle'] = []; $GLOBALS['receipt_atomic_callback'] = null;
    $GLOBALS['receipt_atomic_calls'] = 0; $GLOBALS['receipt_logger_throws'] = false;
    return $GLOBALS['receipt_ci']->db;
}
function receiptCheck(bool $condition, string $message): void
{
    ++$GLOBALS['receipt_assertions'];
    if (!$condition) { throw new LogicException($message); }
}
function receiptSame($expected, $actual, string $message): void { receiptCheck($expected === $actual, $message); }
function receiptReject(callable $operation): RuntimeException
{
    try { $operation(); }
    catch (RuntimeException $error) { ++$GLOBALS['receipt_assertions']; return $error; }
    throw new LogicException('Expected receipt acquisition rejection.');
}
function receiptScenario(string $name, callable $test): void
{
    ++$GLOBALS['receipt_scenarios']; receiptFixture();
    try { $test(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { $GLOBALS['receipt_failures'][] = $name . ': ' . $error->getMessage(); fwrite(STDERR, 'FAIL ' . end($GLOBALS['receipt_failures']) . PHP_EOL); }
}
$GLOBALS['receipt_assertions'] = 0; $GLOBALS['receipt_scenarios'] = 0; $GLOBALS['receipt_failures'] = [];

try {
    foreach ([['mysql', ''], ['mysqli', ''], ['pdo', 'mysql']] as [$driver, $subdriver]) {
        receiptScenario('supported driver ' . $driver . '/' . $subdriver . ' acquires once without reference leak', static function () use ($driver, $subdriver): void {
            $db = get_instance()->db; $db->dbdriver = $driver; $db->subdriver = $subdriver;
            asaas_receipt_lock(42); asaas_receipt_lock(42); asaas_receipt_lock(42);
            receiptSame(1, count($db->calls), 'Nested acquisition must issue GET_LOCK only once.');
            receiptSame(1, current($GLOBALS['receipt_server'])['references'], 'Simulated MySQL reference count remains one.');
            receiptSame('SELECT GET_LOCK(?, 5) AS acquired', $db->calls[0]['sql'], 'Fixed timeout and bound lock name.');
            asaas_receipt_unlock(42); asaas_receipt_unlock(42); asaas_receipt_unlock_all();
            receiptSame(2, count($db->calls), 'A single release matches the single acquisition.');
            receiptSame([], $GLOBALS['receipt_server'], 'No reference remains after release.');
            receiptSame([false, false], array_column($db->calls, 'debug'), 'Queries run with db_debug suppressed.');
            receiptSame(true, $db->db_debug, 'Original db_debug restored.');
        });
    }
    foreach ([['sqlite3', ''], ['postgre', ''], ['pdo', 'sqlite'], ['', '']] as [$driver, $subdriver]) {
        receiptScenario('unsupported driver ' . $driver . '/' . $subdriver . ' is a no-op', static function () use ($driver, $subdriver): void {
            $db = get_instance()->db; $db->dbdriver = $driver; $db->subdriver = $subdriver;
            asaas_receipt_lock(42); asaas_receipt_unlock(42); asaas_receipt_unlock_all();
            receiptSame([], $db->calls, 'Do not send MySQL-specific SQL to other drivers.');
            receiptSame([], asaas_receipt_lock_state()['locks'] ?? [], 'No ownership recorded.');
        });
    }
    foreach ([1, '1'] as $value) {
        receiptScenario('integer/string one is the only successful acquisition: ' . get_debug_type($value), static function () use ($value): void {
            $db = get_instance()->db; $db->queued[] = ['acquired' => $value];
            asaas_receipt_lock(42); asaas_receipt_unlock(42);
            receiptSame(2, count($db->calls), 'Successful value is recorded and released.');
            receiptSame([], $GLOBALS['receipt_server'], 'No held lock remains.');
        });
    }
    foreach ([0, '0', null, false, true, 1.0, 'unexpected'] as $value) {
        receiptScenario('non-confirmed acquired value rejects: ' . get_debug_type($value) . '/' . json_encode($value), static function () use ($value): void {
            $db = get_instance()->db; $db->queued[] = ['acquired' => $value];
            $error = receiptReject(static fn () => asaas_receipt_lock(42));
            receiptCheck(str_contains($error->getMessage(), '5 segundos'), 'Failure explains bounded acquisition wait.');
            asaas_receipt_unlock(42);
            receiptSame(1, count($db->calls), 'An unconfirmed lock must not be released as owned.');
            receiptSame(true, $db->db_debug, 'Debug restored on refused result.');
        });
    }
    foreach ([false, true, []] as $result) {
        receiptScenario('invalid query result rejects: ' . get_debug_type($result), static function () use ($result): void {
            $db = get_instance()->db; $db->queued[] = $result;
            receiptReject(static fn () => asaas_receipt_lock(42));
            receiptSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Invalid result cannot establish ownership.');
        });
    }
    receiptScenario('query exception restores debug and does not expose driver details', static function (): void {
        $db = get_instance()->db; $db->queued[] = new RuntimeException('SECRET credential and unsafe SQL SELECT password');
        $error = receiptReject(static fn () => asaas_receipt_lock(42));
        receiptCheck(!str_contains($error->getMessage(), 'SECRET') && !str_contains($error->getMessage(), 'SELECT'), 'Caller gets a safe error.');
        receiptSame(null, $error->getPrevious(), 'Unsafe driver exception is not retained in a public exception chain.');
        receiptSame(true, $db->db_debug, 'Debug restored after exception.');
        receiptSame([], asaas_receipt_lock_state()['locks'] ?? [], 'No false ownership after exception.');
    });
    receiptScenario('original false debug remains false', static function (): void {
        $db = get_instance()->db; $db->db_debug = false;
        asaas_receipt_lock(42); asaas_receipt_unlock(42);
        receiptSame(false, $db->db_debug, 'Never enable a disabled debug flag.');
    });
    receiptScenario('lock names are bounded opaque bindings and invoice-specific', static function (): void {
        $db = get_instance()->db; $db->hostname = "host' OR 1=1 --"; $db->database = "database'; DROP TABLE invoices; --";
        asaas_receipt_lock(42); asaas_receipt_lock(43);
        $names = array_map(static fn (array $call): string => $call['bindings'][0], $db->calls);
        receiptCheck($names[0] !== $names[1], 'Different invoice IDs use different locks.');
        foreach ($names as $name) {
            receiptSame(64, strlen($name), 'MySQL lock name fits its 64-character maximum.');
            receiptCheck((bool) preg_match('/^asaas_receipt_[a-f0-9]{50}$/D', $name), 'Only a fixed prefix and hash enter the lock name.');
        }
        receiptCheck(!str_contains(json_encode($db->calls), 'DROP TABLE'), 'Raw connection attributes never enter SQL or bound lock names.');
        asaas_receipt_unlock(42);
        receiptSame([$names[1]], array_keys($GLOBALS['receipt_server']), 'Unlock one invoice without touching another.');
        asaas_receipt_unlock_all();
        receiptSame([], $GLOBALS['receipt_server'], 'Unlock all only clears remaining owned locks.');
    });
    receiptScenario('database and hostname distinguish the same invoice number', static function (): void {
        $first = get_instance()->db; asaas_receipt_lock(42); $names = [$first->calls[0]['bindings'][0]];
        foreach ([['database.fixture.invalid', 'another_database'], ['another_host', 'perfex_fixture']] as [$host, $database]) {
            $other = new ReceiptLockDatabase(); $other->hostname = $host; $other->database = $database;
            $GLOBALS['receipt_ci']->db = $other; asaas_receipt_lock(42); $names[] = $other->calls[0]['bindings'][0];
        }
        receiptSame(3, count(array_unique($names)), 'Host/database/invoice form the lock scope.');
        asaas_receipt_unlock_all(); receiptSame([], $GLOBALS['receipt_server'], 'Cleanup uses the original DB objects for all scopes.');
    });
    receiptScenario('another connection times out and cannot release the first connection lock', static function (): void {
        $first = get_instance()->db; asaas_receipt_lock(42);
        $second = new ReceiptLockDatabase(); $GLOBALS['receipt_ci']->db = $second;
        receiptReject(static fn () => asaas_receipt_lock(42));
        asaas_receipt_unlock(42);
        receiptSame(1, count($second->calls), 'Non-owner never sends RELEASE_LOCK.');
        receiptSame(1, count($GLOBALS['receipt_server']), 'First connection still owns its lock.');
        asaas_receipt_unlock_all();
        receiptSame(2, count($first->calls), 'Global cleanup releases on the owning connection.');
        receiptSame([], $GLOBALS['receipt_server'], 'Owning lock is cleaned up.');
    });
    receiptScenario('separate DB wrappers sharing one physical connection are idempotent', static function (): void {
        $first = get_instance()->db; asaas_receipt_lock(42);
        $alias = new ReceiptLockDatabase(); $alias->conn_id = $first->conn_id; $GLOBALS['receipt_ci']->db = $alias;
        asaas_receipt_lock(42); receiptSame([], $alias->calls, 'Do not increase MySQL refcount via another wrapper.');
        asaas_receipt_unlock(42);
        receiptSame([], $GLOBALS['receipt_server'], 'One release fully unlocks shared connection.');
    });
    receiptScenario('native resource connection identity is supported', static function (): void {
        $db = get_instance()->db; $handle = fopen('php://temp', 'r+'); $db->conn_id = $handle;
        try {
            asaas_receipt_lock(42); asaas_receipt_lock(42); asaas_receipt_unlock(42);
            receiptSame(2, count($db->calls), 'Resource handles also acquire/release once.');
        } finally { fclose($handle); }
    });
    receiptScenario('replacement connection is never used to release an old owned lock', static function (): void {
        $db = get_instance()->db; asaas_receipt_lock(42);
        // A real closed MySQL connection releases its named locks automatically.
        $GLOBALS['receipt_server'] = []; $db->conn_id = new stdClass();
        asaas_receipt_unlock_all();
        receiptSame(1, count($db->calls), 'No RELEASE_LOCK on a replacement connection.');
        receiptSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Stale ownership is discarded.');
    });
    receiptScenario('failed explicit release is retried safely by unlock_all', static function (): void {
        $db = get_instance()->db; asaas_receipt_lock(42);
        $db->queued[] = new RuntimeException('SECRET SQL release error');
        asaas_receipt_unlock(42);
        receiptSame(1, count($GLOBALS['receipt_server']), 'Ownership retained after failed release.');
        receiptSame(true, $db->db_debug, 'Debug restored after release failure.');
        asaas_receipt_unlock_all(); asaas_receipt_unlock_all();
        receiptSame(3, count($db->calls), 'One failed release and one successful retry, no extra release.');
        receiptSame([], $GLOBALS['receipt_server'], 'Retry clears the lock.');
        receiptCheck(!str_contains(json_encode($GLOBALS['receipt_logs']), 'SECRET'), 'Cleanup logs do not expose driver detail.');
    });
    foreach (['missing', 'other-owner'] as $state) {
        receiptScenario('release tolerates lock already ' . $state, static function () use ($state): void {
            $db = get_instance()->db; asaas_receipt_lock(42); $name = $db->calls[0]['bindings'][0];
            if ($state === 'missing') { unset($GLOBALS['receipt_server'][$name]); }
            else { $GLOBALS['receipt_server'][$name]['owner'] = 'another-connection'; }
            asaas_receipt_unlock(42); asaas_receipt_unlock_all();
            receiptSame(2, count($db->calls), '0/null release statuses do not create repeated cleanup attempts.');
            receiptSame([], asaas_receipt_lock_state()['locks'] ?? [], 'No stale ownership remains.');
            if ($state === 'other-owner') { receiptSame('another-connection', $GLOBALS['receipt_server'][$name]['owner'], 'Foreign owner is untouched.'); }
        });
    }
    receiptScenario('shutdown runs lifecycle then atomic cleanup then unlock once', static function (): void {
        $db = get_instance()->db; asaas_receipt_lock(42);
        $transaction = new ReceiptShutdownTransaction();
        $transaction->callback = static function (): void { receiptSame(1, count($GLOBALS['receipt_server']), 'Rollback/compensation retains the named lock.'); asaas_receipt_lock_shutdown(); };
        $GLOBALS['receipt_lifecycle']['transaction'] = $transaction;
        $GLOBALS['receipt_atomic_callback'] = static function (): void { receiptSame(1, count($GLOBALS['receipt_server']), 'Atomic writer handoff completes while lock is still held.'); };
        asaas_receipt_lock_shutdown(); asaas_receipt_lock_shutdown();
        receiptSame(['GET_LOCK', 'transaction.shutdown', 'atomic.shutdown', 'RELEASE_LOCK'], $GLOBALS['receipt_events'], 'Required shutdown ordering and re-entrant idempotence.');
        receiptSame(1, $transaction->calls, 'Lifecycle shutdown called once.');
        receiptSame(1, $GLOBALS['receipt_atomic_calls'], 'Atomic shutdown called once.');
        receiptSame([], $GLOBALS['receipt_server'], 'Release occurs after both cleanup phases.');
    });
    foreach (['transaction', 'atomic'] as $phase) {
        receiptScenario('shutdown isolates ' . $phase . ' exception and still runs later cleanup', static function () use ($phase): void {
            $db = get_instance()->db; asaas_receipt_lock(42);
            $transaction = new ReceiptShutdownTransaction(); $GLOBALS['receipt_lifecycle']['transaction'] = $transaction;
            $failure = static function (): void { throw new RuntimeException('SECRET shutdown failure'); };
            if ($phase === 'transaction') { $transaction->callback = $failure; } else { $GLOBALS['receipt_atomic_callback'] = $failure; }
            $GLOBALS['receipt_logger_throws'] = true;
            asaas_receipt_lock_shutdown();
            receiptSame(['GET_LOCK', 'transaction.shutdown', 'atomic.shutdown', 'RELEASE_LOCK'], $GLOBALS['receipt_events'], 'Remaining shutdown phases still execute.');
            receiptSame([], $GLOBALS['receipt_server'], 'Cleanup exceptions do not leak named locks.');
            receiptCheck(!str_contains(json_encode($GLOBALS['receipt_logs']), 'SECRET'), 'No unsafe cleanup exception text in logs.');
        });
    }
    receiptScenario('new acquisition after shutdown cannot leak an unhandled late lock', static function (): void {
        $db = get_instance()->db; asaas_receipt_lock_shutdown();
        receiptReject(static fn () => asaas_receipt_lock(42));
        receiptSame([], $db->calls, 'No late GET_LOCK after cleanup has completed.');
    });
    foreach ([0, -1] as $id) {
        receiptScenario('invalid invoice id is rejected before query: ' . $id, static function () use ($id): void {
            receiptReject(static fn () => asaas_receipt_lock($id));
            receiptSame([], get_instance()->db->calls, 'Invalid invoice has no database side effect.');
        });
    }
} finally {
    asaas_receipt_unlock_all();
    $state = &asaas_receipt_lock_state(); $state['shutdown_done'] = true;
    restore_error_handler();
}
echo 'Receipt lock: ', $GLOBALS['receipt_scenarios'], ' scenarios, ', $GLOBALS['receipt_assertions'], ' assertions, ', count($GLOBALS['receipt_failures']), ' failures.', PHP_EOL;
exit($GLOBALS['receipt_failures'] === [] ? 0 : 1);
