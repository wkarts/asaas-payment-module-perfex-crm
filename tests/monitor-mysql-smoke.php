<?php

declare(strict_types=1);

/** Disposable integration schema. No CRM database or Asaas connection is used. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$dsn = getenv('ASAAS_MONITOR_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    fwrite(STDERR, "Defina ASAAS_MONITOR_TEST_DSN para o banco descartável de integração.\n");
    exit(1);
}
$pdo = new PDO($dsn, (string) getenv('ASAAS_MONITOR_TEST_USER'), (string) getenv('ASAAS_MONITOR_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$GLOBALS['monitor_mysql_prefix'] = 'asaas_test_' . bin2hex(random_bytes(5)) . '_';
$GLOBALS['monitor_mysql_options'] = ['paymentmethod_asaas_sync_enabled' => '0'];
function db_prefix(): string { return $GLOBALS['monitor_mysql_prefix']; }
function get_option(string $name) { return $GLOBALS['monitor_mysql_options'][$name] ?? false; }
function &get_instance(): object { return $GLOBALS['monitor_mysql_ci']; }

final class MonitorMysqlResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
}
final class MonitorMysqlDb
{
    public bool $db_debug = true;
    public array $queries = [];
    public function __construct(private PDO $pdo) {}
    public function query(string $sql, array $bindings = []): MonitorMysqlResult
    {
        $this->queries[] = $sql;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        return new MonitorMysqlResult($statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function table_exists(string $table): bool
    {
        return $this->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->row_array() !== [];
    }
    public function list_fields(string $table): array { return array_column($this->query('SHOW COLUMNS FROM `' . $table . '`')->result_array(), 'Field'); }
}
final class MonitorNoNetworkGateway
{
    public function __call(string $name, array $arguments) { throw new LogicException('A listagem não pode acessar o gateway: ' . $name); }
}
$db = new MonitorMysqlDb($pdo);
$GLOBALS['monitor_mysql_ci'] = (object) ['db' => $db];
require_once dirname(__DIR__) . '/helpers/asaas_monitor_schema_helper.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasReconciliation.php';

$tables = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
};
$create = static function (string $name, string $schema) use ($pdo, &$tables): void {
    $table = db_prefix() . $name;
    $pdo->exec('CREATE TABLE `' . $table . '` (' . $schema . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $tables[] = $table;
};
$insert = static function (string $name, array $row) use ($pdo): void {
    $statement = $pdo->prepare('INSERT INTO `' . db_prefix() . $name . '` (`' . implode('`, `', array_keys($row))
        . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    $statement->execute(array_values($row));
};

try {
    $create('invoices', 'id INT PRIMARY KEY, clientid INT, status INT, total DECIMAL(15,2), number INT, number_format INT, prefix VARCHAR(30), date DATE, duedate DATE');
    $create('clients', 'userid INT PRIMARY KEY, company VARCHAR(200), asaas_customer_id VARCHAR(64)');
    $create('invoicepaymentrecords', 'id INT AUTO_INCREMENT PRIMARY KEY, invoiceid INT, amount DECIMAL(15,2), transactionid VARCHAR(100), INDEX(invoiceid)');
    $create('credits', 'id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT, amount DECIMAL(15,2), INDEX(invoice_id)');
    $create('asaas_payments_map', 'id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNIQUE, asaas_payment_id VARCHAR(64), billing_type VARCHAR(32), status VARCHAR(32), value DECIMAL(15,2), due_date DATE, last_sync_at DATETIME');
    $create('asaas_webhook_events', 'id INT AUTO_INCREMENT PRIMARY KEY, event_id VARCHAR(128) UNIQUE, event_type VARCHAR(64), asaas_payment_id VARCHAR(64), invoice_id INT, received_at DATETIME, processed_at DATETIME, payload LONGTEXT, process_status VARCHAR(32), error_message TEXT');
    $create('asaas_logs', 'id INT AUTO_INCREMENT PRIMARY KEY, level VARCHAR(32), message TEXT, context_json LONGTEXT, created_at DATETIME, correlation_id VARCHAR(64)');
    $insert('clients', ['userid' => 1, 'company' => 'Empresa fixture', 'asaas_customer_id' => 'cus_fixture']);
    for ($id = 1; $id <= 60; ++$id) {
        $insert('invoices', ['id' => $id, 'clientid' => 1, 'status' => 1, 'total' => '50.00', 'number' => $id, 'number_format' => 1, 'prefix' => 'FAT-', 'date' => '2026-11-06', 'duedate' => '2026-11-20']);
        $insert('asaas_payments_map', ['invoice_id' => $id, 'asaas_payment_id' => 'pay_fixture_' . $id, 'billing_type' => 'PIX', 'status' => $id === 60 ? 'CONFIRMED' : 'PENDING', 'value' => '50.00']);
    }
    $insert('invoicepaymentrecords', ['invoiceid' => 60, 'amount' => '30.00', 'transactionid' => 'pay_previous']);
    $insert('credits', ['invoice_id' => 60, 'amount' => '2.00']);
    for ($id = 1; $id <= 1000; ++$id) {
        $insert('asaas_webhook_events', ['event_id' => 'evt_fixture_' . $id, 'event_type' => 'PAYMENT_RECEIVED', 'asaas_payment_id' => 'pay_fixture_' . (1 + $id % 60), 'invoice_id' => 1 + $id % 60, 'received_at' => '2026-11-06 10:00:00', 'process_status' => $id % 100 === 0 ? 'error' : 'processed']);
    }
    foreach ([['info', ['source' => 'api', 'method' => 'GET']], ['info', ['operation' => 'card_lookup']], ['warning', ['source' => 'webhook', 'operation' => 'webhook_callback']], ['error', ['source' => 'reconciliation', 'operation' => 'compare']]] as [$level, $context]) {
        $insert('asaas_logs', ['level' => $level, 'message' => 'Registro fixture', 'context_json' => json_encode($context), 'created_at' => '2026-11-06 10:00:00']);
    }

    asaas_monitor_ensure_indexes($db);
    $alters = count(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')));
    $assert($alters === 2, 'A migração deve agrupar os índices em uma alteração por tabela.');
    asaas_monitor_ensure_indexes($db);
    $assert($alters === count(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE'))), 'Reexecutar a migração não deve repetir ALTER TABLE.');
    $assert($db->db_debug === true, 'O modo de erro do CRM deve ser restaurado.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM `' . db_prefix() . 'asaas_webhook_events`')->fetchColumn() === 1000, 'A migração deve preservar todos os registros existentes.');
    $plan = $db->query('EXPLAIN SELECT invoice_id, MAX(id) FROM `' . db_prefix() . 'asaas_webhook_events` WHERE invoice_id IN (59,60) GROUP BY invoice_id')->row_array();
    $assert(str_contains((string) ($plan['possible_keys'] ?? ''), 'asaas_monitor_invoice'), 'A consulta do último webhook deve ter índice aplicável.');
    $assert(($plan['key'] ?? null) !== null, 'O banco deve usar índice para buscar o último webhook das faturas da página.');

    $service = new \Asaas\AsaasReconciliation(get_instance(), new MonitorNoNetworkGateway());
    $page = $service->invoices(['per_page' => 25]);
    $assert(count($page['data']) === 25 && $page['pagination']['total'] === 60 && $page['pagination']['has_more'], 'A paginação deve limitar os registros no SQL.');
    $assert($page['data'][0]['invoice_id'] === 60 && $page['data'][0]['balance'] === '18.00', 'A listagem deve descontar recebimentos e créditos sem arredondamento indevido.');
    $assert($page['data'][0]['suspected_divergence'] === true && $page['data'][0]['last_webhook_at'] !== null, 'A listagem deve combinar o estado local com o último webhook.');
    $assert($service->invoices(['status' => 'suspected'])['pagination']['total'] === 1, 'O filtro de divergência deve ser aplicado no SQL.');
    $assert($service->invoices(['q' => "' OR 1=1 --"])['pagination']['total'] === 0, 'O texto pesquisado não pode alterar a consulta SQL.');
    $assert($service->invoices(['q' => '%'])['pagination']['total'] === 0, 'A busca deve tratar porcentagem como texto literal.');
    $assert($service->events(['status' => 'error'])['pagination']['total'] === 10, 'O filtro de eventos deve consultar o estado de processamento.');
    $assert($service->logs(['source' => 'api'])['pagination']['total'] === 2, 'O filtro de API deve incluir os registros legados de cartão.');
    $assert($service->logs(['source' => 'webhook'])['pagination']['total'] === 1, 'O filtro de entrega do webhook deve consultar a origem correta.');
    $assert($service->logs(['source' => 'reconciliation', 'level' => 'error'])['pagination']['total'] === 1, 'Nível e origem devem funcionar juntos.');
    $overview = $service->overview()['data'];
    $assert($overview['linked_open_invoices'] === 60 && $overview['webhook_errors'] === 1, 'O resumo deve contar cobranças e falhas de entrega sem duplicar eventos.');

    // Old installations use context instead of context_json.
    $pdo->exec('ALTER TABLE `' . db_prefix() . 'asaas_logs` CHANGE COLUMN context_json context LONGTEXT NULL');
    $legacy = new \Asaas\AsaasReconciliation(get_instance(), new MonitorNoNetworkGateway());
    $assert($legacy->logs(['source' => 'api'])['pagination']['total'] === 2, 'A coluna de contexto das instalações antigas deve continuar suportada.');
    echo "Monitoramento MySQL: 18 cenários, {$assertions} asserções, 0 falhas.\n";
} finally {
    foreach (array_reverse($tables) as $table) { $pdo->exec('DROP TABLE `' . $table . '`'); }
}
