<?php

declare(strict_types=1);

namespace Asaas;

use LogicException;
use ReflectionObject;
use RuntimeException;
use Throwable;

/**
 * A transaction boundary owned by the invoice integration.
 *
 * Existing CodeIgniter transactions are protected with a real SQL savepoint:
 * CI's nested trans_rollback() alone only decrements its depth counter.
 * Releasing a savepoint does not commit the caller's outer transaction.
 */
final class InvoiceTransaction
{
    private static int $sequence = 0;

    private object $db;
    private bool $active = false;
    private bool $ownsTransaction = false;
    private int $initialDepth = 0;
    private ?string $savepoint = null;
    /** @var array<callable> */
    private array $compensations = [];

    public function __construct(object $db)
    {
        $this->db = $db;
        register_shutdown_function([$this, 'shutdown']);
    }

    /** Begin once; later hooks may reuse an already active request guard. */
    public function begin(): void
    {
        if ($this->active) {
            return;
        }

        if (isset($this->db->trans_enabled) && !$this->db->trans_enabled) {
            throw new RuntimeException('As transações do banco estão desabilitadas; a operação Asaas não foi iniciada.');
        }

        $depth = $this->depth();
        if ($depth > 0 && !$this->db->trans_status()) {
            throw new RuntimeException('A transação externa já está em falha; a operação Asaas não foi iniciada.');
        }

        $savepoint = null;
        if ($depth === 0) {
            if ($this->quiet(fn () => $this->db->trans_begin()) !== true) {
                throw new RuntimeException('Não foi possível iniciar a transação da fatura.');
            }
        } else {
            // Only the fixed prefix and an internal integer enter this SQL identifier.
            $savepoint = 'asaas_invoice_' . ++self::$sequence;
            $this->sql('SAVEPOINT ' . $savepoint);
        }

        $this->initialDepth = $depth;
        $this->ownsTransaction = $depth === 0;
        $this->savepoint = $savepoint;
        $this->compensations = [];
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Optional MySQL preflight for the actual tables changed by the caller.
     * Only existing tables are inspected; no schema or engine is changed.
     * Other drivers keep their own transaction contract (e.g. SQLite in tests).
     *
     * @param string[] $tables Fully qualified Perfex table names, including the prefix.
     */
    public function assertTransactionalTables(array $tables): void
    {
        $driver = strtolower((string) ($this->db->dbdriver ?? ''));
        $subdriver = strtolower((string) ($this->db->subdriver ?? ''));
        if (!in_array($driver, ['mysql', 'mysqli'], true) && !($driver === 'pdo' && $subdriver === 'mysql')) {
            return;
        }

        foreach ($tables as $table) {
            if (!is_string($table) || $table === '' || strpos($table, "\0") !== false) {
                throw new LogicException('Informe nomes de tabelas válidos para verificar o suporte a rollback.');
            }
        }
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $query = $this->quiet(fn () => $this->db->query(
            'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        ));
        if (!is_object($query) || !method_exists($query, 'result_array')) {
            throw new RuntimeException('Não foi possível verificar o suporte a rollback das tabelas da fatura.');
        }
        $rows = $query->result_array();
        if (!is_array($rows)) {
            throw new RuntimeException('A verificação das tabelas da fatura retornou um resultado inválido.');
        }

        $unsupported = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['table_name'] ?? null) || !array_key_exists('engine', $row)) {
                throw new RuntimeException('A verificação das tabelas da fatura retornou metadados inválidos.');
            }
            if (strcasecmp((string) $row['engine'], 'InnoDB') !== 0) {
                $unsupported[] = $row['table_name'];
            }
        }
        if ($unsupported !== []) {
            throw new RuntimeException('As tabelas da fatura precisam usar InnoDB para garantir rollback: ' . implode(', ', $unsupported) . '.');
        }
    }

    /** Register a remote compensation only after that remote operation succeeded. */
    public function afterRollback(callable $compensation): void
    {
        if (!$this->active) {
            throw new LogicException('Não há transação de fatura ativa para registrar uma compensação.');
        }
        $this->compensations[] = $compensation;
    }

    public function commit(): void
    {
        if (!$this->active) {
            return;
        }

        try {
            $expectedDepth = $this->ownsTransaction ? 1 : $this->initialDepth;
            if ($this->depth() !== $expectedDepth || !$this->db->trans_status()) {
                throw new RuntimeException('A transação da fatura está em falha ou possui uma transação interna não concluída.');
            }

            if ($this->ownsTransaction) {
                if ($this->quiet(fn () => $this->db->trans_commit()) !== true) {
                    throw new RuntimeException('O banco não confirmou o commit da fatura.');
                }
            } else {
                $this->sql('RELEASE SAVEPOINT ' . $this->savepoint);
            }
        } catch (Throwable $exception) {
            try {
                $this->rollback();
            } catch (Throwable $rollbackException) {
                $this->diagnostic('Falha ao desfazer a transação após recusa do commit.', $rollbackException);
                throw new RuntimeException('Não foi possível confirmar a transação da fatura; o fluxo foi interrompido.', 0, $exception);
            }
            throw new RuntimeException('O banco não confirmou a fatura; a transação foi revertida.', 0, $exception);
        }

        $this->finish();
    }

    /** Roll back local data first, then compensate accepted remote operations in reverse order. */
    public function rollback(): void
    {
        if (!$this->active) {
            return;
        }

        $compensations = $this->compensations;
        $rollbackException = null;
        $databaseReverted = false;
        try {
            $this->rollbackDatabase();
            $databaseReverted = true;
        } catch (Throwable $exception) {
            $rollbackException = $exception;
            // Stop further writes, but do not infer rollback from a closed connection.
            // In particular, a lost COMMIT response may hide an already committed invoice.
            $this->closeAfterFailure($exception);
        }

        // Close before callbacks, so re-entrant rollback/shutdown cannot compensate twice.
        $this->finish();

        if ($databaseReverted) {
            foreach (array_reverse($compensations) as $compensation) {
                try {
                    $compensation();
                } catch (Throwable $exception) {
                    $this->diagnostic('Falha na compensação de uma operação Asaas; revisão manual necessária.', $exception);
                }
            }
        } else {
            $this->diagnostic('Rollback local não confirmado; a operação está pendente de conciliação. Nenhuma compensação Asaas foi executada.');
        }

        if ($rollbackException !== null) {
            throw new RuntimeException('O rollback da fatura falhou; a operação foi interrompida e exige conciliação antes de uma nova tentativa.', 0, $rollbackException);
        }
    }

    /** Handles early redirects, uncaught exceptions and unfinished controller operations. */
    public function shutdown(): void
    {
        if (!$this->active) {
            return;
        }
        try {
            $this->rollback();
        } catch (Throwable $exception) {
            $this->diagnostic('Falha ao encerrar a transação incompleta da fatura.', $exception);
        }
    }

    private function rollbackDatabase(): void
    {
        $depth = $this->depth();
        if ($this->ownsTransaction) {
            if ($depth < 1) {
                throw new RuntimeException('A transação da fatura foi encerrada fora do controle da integração.');
            }
            $this->unwindDepth(0);
            return;
        }

        if ($depth < $this->initialDepth) {
            throw new RuntimeException('A transação externa foi encerrada antes do rollback da fatura.');
        }
        $this->sql('ROLLBACK TO SAVEPOINT ' . $this->savepoint);
        $this->unwindDepth($this->initialDepth);
        try {
            $this->sql('RELEASE SAVEPOINT ' . $this->savepoint);
        } catch (Throwable $exception) {
            // Local writes have already been reverted. The outer owner may clear the savepoint.
            $this->diagnostic('Rollback da fatura concluído, mas não foi possível liberar seu savepoint.', $exception);
        }
    }

    private function unwindDepth(int $target): void
    {
        while (($before = $this->depth()) > $target) {
            if ($this->quiet(fn () => $this->db->trans_rollback()) !== true || $this->depth() >= $before) {
                throw new RuntimeException('O banco não confirmou o rollback da transação da fatura.');
            }
        }
    }

    /** Read CI's protected bookkeeping without changing the caller's transaction state. */
    private function depth(): int
    {
        $reflection = new ReflectionObject($this->db);
        if (!$reflection->hasProperty('_trans_depth')) {
            throw new RuntimeException('Não foi possível identificar a profundidade transacional do CodeIgniter.');
        }
        $property = $reflection->getProperty('_trans_depth');
        $property->setAccessible(true);
        $depth = $property->getValue($this->db);
        if (!is_int($depth) || $depth < 0) {
            throw new RuntimeException('A profundidade transacional do CodeIgniter é inválida.');
        }
        return $depth;
    }

    private function sql(string $sql): void
    {
        if ($this->quiet(fn () => $this->db->query($sql)) === false) {
            throw new RuntimeException('O banco recusou uma operação do savepoint da fatura.');
        }
    }

    /** Avoid CI's exit-on-query-error while restoring its original diagnostic setting. */
    private function quiet(callable $operation): mixed
    {
        $hasDebug = property_exists($this->db, 'db_debug');
        $debug = $hasDebug ? $this->db->db_debug : null;
        try {
            if ($hasDebug) {
                $this->db->db_debug = false;
            }
            return $operation();
        } finally {
            if ($hasDebug) {
                $this->db->db_debug = $debug;
            }
        }
    }

    private function closeAfterFailure(Throwable $exception): void
    {
        $this->diagnostic('O rollback da fatura falhou; encerrando a conexão para impedir a continuação do fluxo. O estado final do banco permanece não confirmado.', $exception);
        if (!method_exists($this->db, 'close')) {
            return;
        }
        try {
            if ($this->quiet(fn () => $this->db->close()) === false) {
                $this->diagnostic('Não foi possível encerrar a conexão após falha do rollback.');
            }
        } catch (Throwable $closeException) {
            $this->diagnostic('Não foi possível encerrar a conexão após falha do rollback.', $closeException);
        }
    }

    private function finish(): void
    {
        $this->active = false;
        $this->ownsTransaction = false;
        $this->initialDepth = 0;
        $this->savepoint = null;
        $this->compensations = [];
    }

    private function diagnostic(string $message, ?Throwable $exception = null): void
    {
        try {
            $context = $exception ? ['exception' => get_class($exception), 'code' => $exception->getCode()] : [];
            if (function_exists('asaas_diagnostic_log')) {
                \asaas_diagnostic_log('error', $message, $context);
            } else {
                error_log('[Asaas] ' . $message);
            }
        } catch (Throwable $ignored) {
            // Diagnostics must not prevent the remaining compensations from running.
        }
    }
}
