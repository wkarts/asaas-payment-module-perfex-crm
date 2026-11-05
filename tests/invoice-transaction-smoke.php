<?php

declare(strict_types=1);

/**
 * Real SQLite SQL with CodeIgniter-compatible transaction-depth bookkeeping.
 * Run: php tests/invoice-transaction-smoke.php
 * Requires pdo_sqlite; does not use Perfex, mbstring or external services.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Asaas\InvoiceTransaction;

require_once dirname(__DIR__) . '/libraries/Asaas/InvoiceTransaction.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$GLOBALS['transaction_smoke_databases'] = [];
$GLOBALS['transaction_smoke_logs'] = [];
$GLOBALS['transaction_smoke_assertions'] = 0;

function asaas_diagnostic_log(string $level, string $message, array $context = [], ?bool $fileEnabled = null): void
{
    $GLOBALS['transaction_smoke_logs'][] = compact('level', 'message', 'context');
}

final class InvoiceTransactionDatabaseFixture
{
    protected int $_trans_depth = 0;
    public bool $db_debug = true;
    public bool $trans_enabled = true;
    public string $dbdriver = 'sqlite3';
    public string $subdriver = '';
    public bool $failBegin = false;
    public bool $failCommit = false;
    public bool $commitThenFail = false;
    public bool $failRollback = false;
    public bool $failClose = false;
    public array $failSqlPrefixes = [];
    public array $trace = [];
    public array $sqlDebug = [];
    public array $bindings = [];
    private bool $status = true;
    private ?PDO $connection;
    private string $file;

    public function __construct()
    {
        $this->file = tempnam(sys_get_temp_dir(), 'asaas-transaction-');
        $this->connection = $this->connect();
        $this->connection->exec('CREATE TABLE invoice_state (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $this->connection->exec("INSERT INTO invoice_state VALUES (1, 'original')");
        $GLOBALS['transaction_smoke_databases'][] = $this;
    }

    private function connect(): PDO
    {
        return new PDO('sqlite:' . $this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function query(string $sql, array $bindings = []): mixed
    {
        $this->trace[] = $sql;
        $this->sqlDebug[] = [$sql, $this->db_debug];
        $this->bindings[] = $bindings;
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
            if (str_starts_with($sql, 'SELECT ')) {
                return new class($statement->fetchAll(PDO::FETCH_ASSOC)) {
                    public function __construct(private array $rows) {}
                    public function result_array(): array { return $this->rows; }
                };
            }
            return true;
        } catch (Throwable $exception) {
            $this->status = false;
            if ($this->db_debug) {
                throw $exception;
            }
            return false;
        }
    }

    public function trans_begin(): bool
    {
        $this->trace[] = 'trans_begin:' . $this->_trans_depth;
        if (!$this->trans_enabled || $this->failBegin) {
            return false;
        }
        if ($this->_trans_depth > 0) {
            ++$this->_trans_depth;
            return true;
        }
        if ($this->connection->beginTransaction()) {
            $this->status = true;
            ++$this->_trans_depth;
            return true;
        }
        return false;
    }

    public function trans_commit(): bool
    {
        $this->trace[] = 'trans_commit:' . $this->_trans_depth;
        if ($this->failCommit || $this->_trans_depth === 0) {
            return false;
        }
        if ($this->commitThenFail && $this->_trans_depth === 1) {
            // Model a COMMIT accepted by the server whose confirmation was lost.
            $this->connection->commit();
            return false;
        }
        if ($this->_trans_depth > 1 || $this->connection->commit()) {
            --$this->_trans_depth;
            return true;
        }
        return false;
    }

    public function trans_rollback(): bool
    {
        $this->trace[] = 'trans_rollback:' . $this->_trans_depth;
        if ($this->failRollback || $this->_trans_depth === 0) {
            return false;
        }
        // This intentionally matches CI: a nested rollback alone does not run SQL.
        if ($this->_trans_depth > 1 || $this->connection->rollBack()) {
            --$this->_trans_depth;
            return true;
        }
        return false;
    }

    public function trans_status(): bool { return $this->status; }
    public function depth(): int { return $this->_trans_depth; }
    public function markFailed(): void { $this->status = false; }
    public function isOpen(): bool { return $this->connection !== null; }
    public function inTransaction(): bool { return $this->connection !== null && $this->connection->inTransaction(); }

    /** A small SQL metadata fixture; the transaction tests still execute real SQLite SQL. */
    public function mysqlMetadata(array $tables): void
    {
        $this->dbdriver = 'mysqli';
        $this->connection->sqliteCreateFunction('DATABASE', static fn (): string => 'transaction_smoke', 0);
        $this->connection->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $this->connection->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, ENGINE TEXT)');
        $statement = $this->connection->prepare('INSERT INTO information_schema.TABLES VALUES (?, ?, ?)');
        foreach ($tables as $name => $engine) {
            $statement->execute(['transaction_smoke', $name, $engine]);
        }
        // A same-name table in another schema must never affect the selected connection.
        $statement->execute(['another_schema', 'tblinvoices', 'MyISAM']);
    }

    public function value(): string
    {
        // A separate connection can also verify the state after close() rolled back implicitly.
        $connection = $this->connection ?? $this->connect();
        return (string) $connection->query('SELECT label FROM invoice_state WHERE id = 1')->fetchColumn();
    }

    public function close(): void
    {
        $this->trace[] = 'close';
        if ($this->failClose) {
            throw new RuntimeException('Simulated close failure.');
        }
        // PDO disconnect rolls back a still-active SQL transaction. Keep CI's counter unchanged.
        $this->connection = null;
    }

    public function cleanup(): void
    {
        $this->connection = null;
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}

function transactionSmokeCheck(bool $condition, string $message): void
{
    ++$GLOBALS['transaction_smoke_assertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function transactionSmokeThrows(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        ++$GLOBALS['transaction_smoke_assertions'];
        return $exception;
    }
    throw new RuntimeException($message);
}

function transactionSmokeCalls(InvoiceTransactionDatabaseFixture $db, string $prefix): array
{
    return array_values(array_filter($db->trace, static fn (string $call): bool => str_starts_with($call, $prefix)));
}

$tests = [];

$tests['commit próprio confirma SQL e descarta compensações'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'accepted'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    transactionSmokeCheck($guard->isActive() && $db->depth() === 1, 'begin repetido deve reutilizar a mesma transação.');
    $guard->commit();
    $guard->shutdown();
    $guard->rollback();
    transactionSmokeCheck(!$guard->isActive() && !$db->inTransaction(), 'Commit deve encerrar a transação própria.');
    transactionSmokeCheck($db->value() === 'accepted' && $compensated === 0, 'Commit deve persistir sem compensar.');
    transactionSmokeCheck(count(transactionSmokeCalls($db, 'trans_commit:')) === 1, 'Deve haver somente um commit.');
    transactionSmokeCheck($db->db_debug === true, 'A configuração db_debug deve ser restaurada.');
};

$tests['rollback próprio ocorre antes de compensações LIFO isoladas'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $order = [];
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'pending'");
    $guard->afterRollback(static function () use ($db, &$order): void { $order[] = 'first:' . $db->value(); });
    $guard->afterRollback(static function () use (&$order): void {
        $order[] = 'failing';
        throw new RuntimeException('Sensitive callback text must not enter diagnostic context.');
    });
    $guard->afterRollback(static function () use ($db, $guard, &$order): void {
        $order[] = 'last:' . $db->value();
        transactionSmokeCheck(!$guard->isActive(), 'Guard deve estar fechado antes de executar compensações.');
        $guard->rollback();
    });
    $guard->rollback();
    $guard->rollback();
    $guard->shutdown();
    transactionSmokeCheck($order === ['last:original', 'failing', 'first:original'], 'Compensações devem ocorrer em ordem inversa e apenas uma vez.');
    transactionSmokeCheck($db->value() === 'original' && $db->depth() === 0, 'Dados locais devem ser revertidos antes dos callbacks.');
    transactionSmokeCheck(count($GLOBALS['transaction_smoke_logs']) === 1, 'Falha de uma compensação deve ser registrada sem interromper as demais.');
    transactionSmokeCheck(!str_contains(json_encode($GLOBALS['transaction_smoke_logs']), 'Sensitive callback text'), 'Não registrar o texto arbitrário da exceção.');
};

$tests['savepoint reverte somente o escopo interno sem commitar transação externa'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'outer'");
    $guard = new InvoiceTransaction($db);
    $order = [];
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'inner'");
    $guard->afterRollback(static function () use ($db, &$order): void { $order[] = $db->value(); });
    $guard->rollback();
    transactionSmokeCheck($db->value() === 'outer' && $order === ['outer'], 'SQL ROLLBACK TO deve preservar os dados anteriores da transação externa.');
    transactionSmokeCheck($db->depth() === 1 && $db->inTransaction(), 'A transação externa deve continuar aberta.');
    transactionSmokeCheck(transactionSmokeCalls($db, 'trans_commit:') === [] && transactionSmokeCalls($db, 'trans_rollback:') === [], 'O guard não deve confirmar nem reverter a transação externa.');
    $savepoint = transactionSmokeCalls($db, 'SAVEPOINT ')[0];
    transactionSmokeCheck((bool) preg_match('/\ASAVEPOINT asaas_invoice_[0-9]+\z/', $savepoint), 'O identificador SQL deve usar somente prefixo fixo e contador interno.');
    foreach ($db->sqlDebug as [$sql, $debug]) {
        if (str_contains($sql, 'SAVEPOINT')) {
            transactionSmokeCheck($debug === false, 'Falhas de savepoint não devem ativar a página de erro fatal do CI.');
        }
    }
    $db->trans_commit();
    transactionSmokeCheck($db->value() === 'outer', 'Somente o dono externo deve confirmar seus dados.');
};

$tests['commit do savepoint não promete observar rollback externo posterior'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'inner-accepted'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    $guard->commit();
    transactionSmokeCheck(!$guard->isActive() && $db->depth() === 1, 'Liberar savepoint não encerra a transação externa.');
    transactionSmokeCheck(transactionSmokeCalls($db, 'trans_commit:') === [], 'O guard não pode emitir commit externo.');
    $db->trans_rollback();
    $guard->shutdown();
    transactionSmokeCheck($db->value() === 'original' && $compensated === 0, 'Rollback posterior pertence ao chamador e requer coordenação própria de compensações.');
};

$tests['dois savepoints independentes usam nomes únicos e respeitam a ordem'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $first = new InvoiceTransaction($db);
    $second = new InvoiceTransaction($db);
    $first->begin();
    $db->query("UPDATE invoice_state SET label = 'first'");
    $second->begin();
    $db->query("UPDATE invoice_state SET label = 'second'");
    $second->rollback();
    transactionSmokeCheck($db->value() === 'first', 'O segundo savepoint deve preservar o primeiro escopo.');
    $first->rollback();
    transactionSmokeCheck($db->value() === 'original', 'O primeiro savepoint deve restaurar seu estado inicial.');
    transactionSmokeCheck(count(array_unique(transactionSmokeCalls($db, 'SAVEPOINT '))) === 2, 'Guards distintos não podem substituir o mesmo savepoint.');
    $db->trans_rollback();
};

$tests['rollback com níveis CI internos não concluídos usa rollback SQL real'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'outer'");
    $guard = new InvoiceTransaction($db);
    $guard->begin();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'inner'");
    $guard->rollback();
    transactionSmokeCheck($db->value() === 'outer' && $db->depth() === 2, 'Rollback deve desfazer SQL interno e restaurar somente a profundidade que o guard recebeu.');
    transactionSmokeCheck(transactionSmokeCalls($db, 'trans_rollback:') === ['trans_rollback:3'], 'Somente o nível CI aberto dentro do guard pode ser desmontado.');
    $db->trans_rollback();
    $db->trans_rollback();
};

$tests['commit com nível interno aberto falha e desfaz toda transação própria'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $guard->begin();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'unbalanced'");
    transactionSmokeThrows(static fn () => $guard->commit(), 'Não confirmar silenciosamente um commit que apenas reduz profundidade.');
    transactionSmokeCheck($db->depth() === 0 && $db->value() === 'original', 'Todos os níveis da transação própria devem ser revertidos.');
    transactionSmokeCheck(transactionSmokeCalls($db, 'trans_commit:') === [], 'O commit inseguro não deve chegar ao driver.');
};

$tests['falha de commit próprio desfaz SQL e compensa uma vez'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->db_debug = false;
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'pending'");
    $guard->afterRollback(static function () use ($db, &$compensated): void {
        transactionSmokeCheck($db->value() === 'original', 'Compensação deve observar o rollback local já concluído.');
        ++$compensated;
    });
    $db->failCommit = true;
    transactionSmokeThrows(static fn () => $guard->commit(), 'Driver que recusa commit deve produzir exceção.');
    $guard->rollback();
    transactionSmokeCheck(!$guard->isActive() && $compensated === 1, 'Falha de commit deve fechar o guard e compensar exatamente uma vez.');
    transactionSmokeCheck($db->value() === 'original' && $db->db_debug === false, 'Preservar o estado do banco e db_debug anterior.');
};

$tests['status transacional em falha impede commit'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'failed'");
    $db->markFailed();
    transactionSmokeThrows(static fn () => $guard->commit(), 'Não confirmar trans_status false.');
    transactionSmokeCheck($db->value() === 'original' && transactionSmokeCalls($db, 'trans_commit:') === [], 'Status de falha deve disparar rollback antes de qualquer commit.');
};

$tests['falha de begin não inicia uma operação nem aceita compensação'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->failBegin = true;
    $guard = new InvoiceTransaction($db);
    transactionSmokeThrows(static fn () => $guard->begin(), 'Falha de begin deve interromper a operação.');
    transactionSmokeCheck(!$guard->isActive() && $db->depth() === 0 && $db->db_debug, 'Begin recusado não deve deixar guard ativo ou alterar db_debug.');
    transactionSmokeThrows(static fn () => $guard->afterRollback(static function (): void {}), 'Não aceitar compensação sem transação ativa.');
};

$tests['savepoint recusado preserva a responsabilidade do dono externo'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->failSqlPrefixes = ['SAVEPOINT '];
    $guard = new InvoiceTransaction($db);
    transactionSmokeThrows(static fn () => $guard->begin(), 'Savepoint recusado deve impedir a integração.');
    transactionSmokeCheck(!$guard->isActive() && $db->depth() === 1 && $db->inTransaction(), 'Não assumir controle da transação externa quando begin falha.');
    transactionSmokeCheck($db->db_debug, 'Restaurar db_debug mesmo após erro SQL.');
    $db->trans_rollback();
};

$tests['falha ao liberar savepoint no commit reverte o escopo sem commit externo'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'outer'");
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'inner'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    $db->failSqlPrefixes = ['RELEASE SAVEPOINT '];
    transactionSmokeThrows(static fn () => $guard->commit(), 'Falha de release deve ser reportada.');
    transactionSmokeCheck($db->value() === 'outer' && $compensated === 1, 'O escopo interno deve ser revertido e compensado.');
    transactionSmokeCheck($db->depth() === 1 && !$db->trans_status(), 'Não limpar o estado de falha pertencente ao dono externo.');
    $db->trans_rollback();
};

$tests['falha de release após rollback não impede compensação já segura'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'inner'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    $db->failSqlPrefixes = ['RELEASE SAVEPOINT '];
    $guard->rollback();
    transactionSmokeCheck($db->value() === 'original' && $compensated === 1, 'O rollback SQL confirmado deve permitir compensação mesmo se release falhar.');
    transactionSmokeCheck($db->isOpen() && $db->depth() === 1, 'Não encerrar a conexão externa por uma falha posterior ao rollback bem-sucedido.');
    transactionSmokeCheck(count($GLOBALS['transaction_smoke_logs']) === 1, 'A falha de release deve ser diagnosticada.');
    $db->trans_rollback();
};

$tests['falha de rollback fecha conexão sem presumir confirmação para compensar'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $order = [];
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'pending'");
    $guard->afterRollback(static function () use ($db, &$order): void {
        $order[] = $db->isOpen() ? 'open' : 'closed';
        $order[] = $db->value();
    });
    $db->failRollback = true;
    transactionSmokeThrows(static fn () => $guard->rollback(), 'Falha de rollback deve interromper o fluxo mesmo após close.');
    transactionSmokeCheck($order === [] && $db->value() === 'original', 'Mesmo havendo rollback implícito nesta conexão, close não autoriza compensação remota.');
    transactionSmokeCheck(!$guard->isActive() && !$db->isOpen(), 'O guard e a conexão devem estar fechados.');
    transactionSmokeCheck(str_contains(json_encode($GLOBALS['transaction_smoke_logs'], JSON_UNESCAPED_UNICODE), 'pendente de conciliação'), 'O estado inconclusivo deve permanecer diagnosticado para conciliação.');
    $guard->shutdown();
    transactionSmokeCheck($order === [], 'Shutdown não deve compensar uma operação inconclusiva.');
};

$tests['commit e rollback não confirmados nunca compensam somente por close'] = static function (): void {
    foreach ([false, true] as $commitAccepted) {
        $db = new InvoiceTransactionDatabaseFixture();
        $guard = new InvoiceTransaction($db);
        $compensated = 0;
        $guard->begin();
        $db->query("UPDATE invoice_state SET label = 'possibly-committed'");
        $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
        $db->failCommit = !$commitAccepted;
        $db->commitThenFail = $commitAccepted;
        $db->failRollback = true;
        transactionSmokeThrows(static fn () => $guard->commit(), 'Commit false seguido de rollback false exige interrupção, mesmo quando close funciona.');
        transactionSmokeCheck($compensated === 0 && !$guard->isActive() && !$db->isOpen(), 'Não compensar nenhum dos estados possíveis após falha de commit/rollback e fechamento da conexão.');
        transactionSmokeCheck($db->value() === ($commitAccepted ? 'possibly-committed' : 'original'), 'A fixture deve cobrir tanto commit aceito sem confirmação quanto commit não executado.');
        $guard->shutdown();
        transactionSmokeCheck($compensated === 0, 'O shutdown não pode decidir compensar posteriormente sem evidência do banco.');
    }
};

$tests['falha do rollback para savepoint aborta também a conexão externa'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->query("UPDATE invoice_state SET label = 'outer'");
    $guard = new InvoiceTransaction($db);
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'inner'");
    $db->failSqlPrefixes = ['ROLLBACK TO SAVEPOINT '];
    transactionSmokeThrows(static fn () => $guard->rollback(), 'Uma falha de rollback real deve ser explícita.');
    transactionSmokeCheck(!$db->isOpen() && $db->value() === 'original', 'Fail-closed deve abortar toda conexão quando não consegue isolar o rollback.');
};

$tests['rollback e close não confirmados não executam compensação remota'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'pending'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    $db->failRollback = true;
    $db->failClose = true;
    transactionSmokeThrows(static fn () => $guard->rollback(), 'Falha dupla deve exigir interrupção e revisão.');
    transactionSmokeCheck($compensated === 0 && !$guard->isActive(), 'Não compensar sem confirmação de que os dados locais foram desfeitos.');
    transactionSmokeCheck(count($GLOBALS['transaction_smoke_logs']) >= 2, 'A falha de encerramento deve permanecer diagnosticada.');
    $db->failRollback = false;
    $db->trans_rollback();
};

$tests['shutdown reverte uma operação incompleta e é idempotente'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $compensated = 0;
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'unfinished'");
    $guard->afterRollback(static function () use (&$compensated): void { ++$compensated; });
    $guard->shutdown();
    $guard->shutdown();
    transactionSmokeCheck($db->value() === 'original' && $compensated === 1 && !$guard->isActive(), 'Shutdown deve desfazer operação incompleta exatamente uma vez.');
};

$tests['profundidade desconhecida e transação externa falha são rejeitadas'] = static function (): void {
    $guard = new InvoiceTransaction(new stdClass());
    transactionSmokeThrows(static fn () => $guard->begin(), 'Não assumir profundidade zero em um driver desconhecido.');
    $db = new InvoiceTransactionDatabaseFixture();
    $db->trans_begin();
    $db->markFailed();
    $guard = new InvoiceTransaction($db);
    transactionSmokeThrows(static fn () => $guard->begin(), 'Não iniciar integração dentro de uma transação externa em falha.');
    transactionSmokeCheck(transactionSmokeCalls($db, 'SAVEPOINT ') === [], 'Não deve alterar a transação externa falha.');
    $db->trans_rollback();
};

$tests['guard pode iniciar uma nova operação após concluir a anterior'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'committed'");
    $guard->commit();
    $guard->begin();
    $db->query("UPDATE invoice_state SET label = 'second'");
    $guard->rollback();
    transactionSmokeCheck($db->value() === 'committed' && !$guard->isActive(), 'A segunda operação deve preservar o commit anterior.');
};

$tests['preflight InnoDB consulta somente tabelas existentes do banco atual'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->mysqlMetadata(['tblinvoices' => 'InnoDB', 'tblitemable' => 'InnoDB', 'unrelated' => 'MyISAM']);
    $guard = new InvoiceTransaction($db);
    $guard->assertTransactionalTables(['tblinvoices', 'tblitemable', 'tblmissing', 'tblinvoices']);
    transactionSmokeCheck(count($db->trace) === 1 && str_starts_with($db->trace[0], 'SELECT '), 'O preflight deve fazer apenas uma leitura, sem alterar engines.');
    transactionSmokeCheck($db->bindings[0] === ['tblinvoices', 'tblitemable', 'tblmissing'], 'Os nomes devem ser deduplicados e enviados como parâmetros SQL.');
    transactionSmokeCheck(!str_contains($db->trace[0], 'tblinvoices'), 'Nomes de tabela não devem ser interpolados no SQL.');
    transactionSmokeCheck($db->db_debug && !$guard->isActive(), 'A checagem não deve iniciar transação nem alterar db_debug.');
};

$tests['preflight bloqueia engine não transacional sem alterar tabelas'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->mysqlMetadata(['tblinvoices' => 'InnoDB', 'tblitemable' => 'MyISAM', 'tblitem_tax' => 'MEMORY']);
    $guard = new InvoiceTransaction($db);
    $exception = transactionSmokeThrows(static fn () => $guard->assertTransactionalTables(['tblinvoices', 'tblitemable', 'tblitem_tax']), 'Uma engine sem rollback deve impedir a operação.');
    transactionSmokeCheck(str_contains($exception->getMessage(), 'tblitemable') && str_contains($exception->getMessage(), 'tblitem_tax'), 'O erro deve identificar as tabelas incompatíveis.');
    transactionSmokeCheck(count($db->trace) === 1 && $db->value() === 'original' && !$guard->isActive(), 'O bloqueio não pode executar DDL ou mutações de fatura.');
};

$tests['preflight também cobre PDO MySQL e rejeita falha de consulta'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $db->mysqlMetadata(['tblinvoices' => 'InnoDB']);
    $db->dbdriver = 'pdo';
    $db->subdriver = 'mysql';
    $guard = new InvoiceTransaction($db);
    $guard->assertTransactionalTables(['tblinvoices']);
    transactionSmokeCheck(count($db->trace) === 1, 'PDO MySQL deve receber a mesma verificação.');
    $db->failSqlPrefixes = ['SELECT TABLE_NAME'];
    transactionSmokeThrows(static fn () => $guard->assertTransactionalTables(['tblinvoices']), 'Falha ao verificar engine não pode ser tratada como confirmação.');
    transactionSmokeCheck($db->db_debug, 'Falha de metadados deve restaurar db_debug.');
};

$tests['preflight de outros drivers não consulta information_schema'] = static function (): void {
    $db = new InvoiceTransactionDatabaseFixture();
    $guard = new InvoiceTransaction($db);
    $guard->assertTransactionalTables(['invoice_state']);
    transactionSmokeCheck($db->trace === [], 'A consulta MySQL não deve ser enviada a SQLite ou outros drivers.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    $GLOBALS['transaction_smoke_logs'] = [];
    try {
        $test();
        echo 'OK  ', $name, PHP_EOL;
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
}

// Registered after all guards, so their real PHP shutdown callbacks execute first.
register_shutdown_function(static function (): void {
    foreach ($GLOBALS['transaction_smoke_databases'] as $db) {
        $db->cleanup();
    }
});
echo 'Invoice transaction smoke: ', count($tests), ' cenários, ', $GLOBALS['transaction_smoke_assertions'], ' asserções, ', $failures, ' falhas.', PHP_EOL;
exit($failures === 0 ? 0 : 1);
