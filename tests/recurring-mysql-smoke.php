<?php

declare(strict_types=1);

/** Real disposable MariaDB schema; never connects to the CRM or Asaas. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$dsn = getenv('ASAAS_MONITOR_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    fwrite(STDERR, "Defina ASAAS_MONITOR_TEST_DSN para o banco descartável.\n"); exit(1);
}
$connection = static fn (): PDO => new PDO($dsn, (string) getenv('ASAAS_MONITOR_TEST_USER'),
    (string) getenv('ASAAS_MONITOR_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo = $connection();
$GLOBALS['recurring_mysql_prefix'] = 'asaas_rc_' . bin2hex(random_bytes(5)) . '_';
function db_prefix(): string { return $GLOBALS['recurring_mysql_prefix']; }
function get_option(string $name) { return false; }
function &get_instance(): object { return $GLOBALS['recurring_mysql_ci']; }

final class RecurringMysqlResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
    public function result_array(): array { return $this->rows; }
}
final class RecurringMysqlDb
{
    public string $dbdriver = 'pdo';
    public string $subdriver = 'mysql';
    public string $hostname = 'fixture-local';
    public string $database = 'recurring-fixture';
    public bool $db_debug = true;
    public int $_trans_depth = 0;
    public array $queries = [];
    private int $affected = 0;
    public function __construct(public PDO $conn_id) {}
    public function query(string $sql, array $bindings = []): RecurringMysqlResult
    {
        $this->queries[] = $sql;
        $statement = $this->conn_id->prepare($sql); $statement->execute($bindings);
        $this->affected = $statement->rowCount();
        return new RecurringMysqlResult($statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : []);
    }
    public function affected_rows(): int { return $this->affected; }
    public function table_exists(string $table): bool
    {
        return $this->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->row_array() !== [];
    }
}
$db = new RecurringMysqlDb($pdo);
$GLOBALS['recurring_mysql_ci'] = (object) ['db' => $db];
require_once dirname(__DIR__) . '/helpers/asaas_recurring_schema_helper.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasRecurringStore.php';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions; if (!$condition) { throw new RuntimeException($message); }
};
$tables = array_map(static fn ($name): string => db_prefix() . $name,
    ['asaas_recurring_jobs', 'asaas_recurring_authorizations', 'invoices', 'contacts', 'options']);
try {
    $pdo->exec('CREATE TABLE `' . db_prefix() . 'invoices` (id INT PRIMARY KEY, clientid INT, status INT, allowed_payment_modes TEXT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `' . db_prefix() . 'contacts` (id INT PRIMARY KEY, userid INT, active INT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `' . db_prefix() . 'options` (name VARCHAR(100) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO `' . db_prefix() . 'options` VALUES (\'paymentmethod_asaas_active\',\'1\'),(\'paymentmethod_asaas_recurring_active\',\'1\')');
    $pdo->exec('INSERT INTO `' . db_prefix() . 'contacts` VALUES (1,7,1),(2,8,1),(3,7,0)');
    $store = new \Asaas\AsaasRecurringStore($db);
    $assert(!$store->ready(), 'Uma instalação legada sem schema deve permanecer indisponível para recorrência.');
    asaas_recurring_schema_install($db);
    $creates = count(array_filter($db->queries, static fn ($sql): bool => str_starts_with($sql, 'CREATE TABLE')));
    asaas_recurring_schema_install($db);
    $assert($creates === 2 && $creates === count(array_filter($db->queries, static fn ($sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'Instalação e atualização devem ser idempotentes e aditivas.');
    $assert($store->ready() && $store->transactionDepth() === 0, 'Schema e driver devem permitir o worker fora de transação.');
    $assert($store->freshGatewayActive(), 'Ativação precisa ser lida da configuração persistida.');
    $pdo->exec('UPDATE `' . db_prefix() . 'options` SET value = \'0\' WHERE name = \'paymentmethod_asaas_recurring_active\'');
    $assert(!$store->freshGatewayActive(), 'Desativação durante a mesma requisição precisa ser observada antes da próxima captura.');
    $pdo->exec('UPDATE `' . db_prefix() . 'options` SET value = \'1\' WHERE name = \'paymentmethod_asaas_recurring_active\'');
    $assert($store->contactBelongs(1, 7) && !$store->contactBelongs(2, 7) && !$store->contactBelongs(3, 7), 'Só um contato ativo do mesmo cliente pode autorizar.');
    $now = date('Y-m-d H:i:s');
    $scope = hash('sha256', 'fixture-account-sandbox');
    $profile = ['client_id' => 7, 'scope_key' => $scope, 'environment' => 'sandbox', 'company_id' => 0,
        'customer_id' => 'cus_fixture', 'revision' => 1, 'state' => 'active', 'token_ciphertext' => 'ciphertext-fixture-only',
        'contact_id' => 1, 'consent_version' => 'fixture-v1', 'authorized_at' => $now, 'cutoff_invoice_id' => 10,
        'pricing_policy_hash' => hash('sha256', 'fixture-policy'), 'reason_code' => null, 'revoked_at' => null, 'updated_at' => $now];
    $lock = $store->lock(7, $scope);
    $store->assertLock($lock);
    $assert($store->lock(7, $scope) === $lock, 'Operação aninhada deve compartilhar o bloqueio da autorização.');
    $store->unlock($lock); $store->assertLock($lock);
    $saved = $store->saveAuthorization($profile, null);
    $assert((int) $saved['id'] > 0 && (int) $saved['revision'] === 1, 'A autorização deve ser persistida antes de qualquer trabalho.');
    $assert($store->authorization(7, hash('sha256', 'other-account')) === [] && $store->authorization(8, $scope) === [], 'Cliente e conta/ambiente devem isolar a autorização.');
    $secondDb = new RecurringMysqlDb($connection());
    $second = new \Asaas\AsaasRecurringStore($secondDb);
    $assert($second->authorization(7, $scope)['token_ciphertext'] === 'ciphertext-fixture-only', 'Uma segunda conexão deve observar a autorização já durável.');
    $caught = false;
    try { $second->lock(7, $scope); } catch (RuntimeException $error) { $caught = $error->getMessage() === 'busy'; }
    $assert($caught, 'Dois processos não podem trabalhar na mesma autorização simultaneamente.');
    $newProfile = array_replace($profile, ['revision' => 2, 'token_ciphertext' => 'ciphertext-revision-two']);
    $saved2 = $store->saveAuthorization($newProfile, 1);
    $caught = false;
    try { $store->saveAuthorization(array_replace($newProfile, ['revision' => 3]), 1); }
    catch (RuntimeException $error) { $caught = $error->getMessage() === 'authorization_changed'; }
    $assert($caught && (int) $store->authorization(7, $scope)['revision'] === 2, 'CAS deve rejeitar uma atualização de revisão antiga.');
    $job = static fn (int $id, string $state = 'queued', int $revision = 2): array => [
        'invoice_id' => $id, 'invoice_hash' => 'fixture-invoice-' . $id, 'client_id' => 7,
        'authorization_id' => (int) $saved['id'], 'authorization_revision' => $revision, 'scope_key' => $scope,
        'customer_id' => 'cus_fixture', 'explicit_current' => 0, 'state' => $state, 'phase' => 'queued', 'payment_id' => null,
        'reason_code' => null, 'next_check_at' => null, 'started_at' => null, 'created_at' => $now, 'updated_at' => $now];
    $assert($store->insertJob($job(11)) && !$store->insertJob($job(11)), 'O identificador da fatura deve deduplicar eventos de emissão repetidos.');
    $assert($store->updateJob(11, ['queued'], ['state' => 'processing', 'phase' => 'capturing', 'payment_id' => 'pay_fixture_11']), 'A transição deve ser persistida antes de capturar.');
    $assert(!$store->updateJob(11, ['queued'], ['state' => 'processing']), 'Outro processo não pode reivindicar o trabalho já iniciado.');
    $assert($second->job(11)['state'] === 'processing' && $second->jobByPayment('pay_fixture_11')['invoice_hash'] === 'fixture-invoice-11', 'Outra conexão deve ver o marcador e o vínculo com a cobrança.');
    $assert($store->hasBlockingJob((int) $saved['id'], 2, 12) && !$store->hasBlockingJob((int) $saved['id'], 2, 11), 'Outra fatura incerta deve bloquear novas capturas da autorização.');
    $store->insertJob($job(12));
    $store->insertJob($job(13, 'queued', 1));
    $store->stopAuthorization($saved, 'suspended', 'declined');
    $assert($store->authorization(7, $scope)['state'] === 'active' && $store->job(12)['state'] === 'queued'
        && $store->job(13)['state'] === 'skipped', 'Uma recusa atrasada deve atingir somente a revisão antiga.');
    for ($id = 14; $id <= 29; ++$id) { $store->insertJob($job($id)); }
    $assert(count($store->dueJobs(999)) === 10, 'Cada rodada deve limitar a quantidade de consultas/capturas.');
    $store->updateJob(12, ['queued'], ['next_check_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $assert(!in_array(12, array_map('intval', array_column($store->dueJobs(10), 'invoice_id')), true), 'Trabalho adiado deve respeitar sua próxima verificação.');
    $modes = serialize(['asaas', 'asaas_recurring']);
    $insert = $pdo->prepare('INSERT INTO `' . db_prefix() . 'invoices` VALUES (?, ?, ?, ?)');
    foreach ([[9,7,1,$modes], [30,7,1,$modes], [31,7,2,$modes], [32,8,1,$modes], [33,7,1,serialize(['asaas'])], [34,7,6,$modes], [35,7,1,$modes]] as $row) { $insert->execute($row); }
    $assert(array_map('intval', array_column($store->missingJobs(40), 'id')) === [30, 35], 'Recuperação deve limitar-se a faturas novas, abertas, do cliente autorizado e com o modo explícito.');
    $assert($store->maxInvoiceId() === 35, 'O marco de consentimento deve contemplar as faturas já existentes.');
    $store->stopAuthorization($saved2, 'revoked', 'revoked');
    $revoked = $store->authorization(7, $scope);
    $assert($revoked['state'] === 'revoked' && $revoked['token_ciphertext'] === null, 'Revogar deve excluir a credencial utilizável local.');
    $assert($store->job(12)['state'] === 'skipped' && $store->job(11)['state'] === 'processing', 'Revogação cancela somente fila não enviada, mantendo uma captura em conferência.');
    $assert($store->missingJobs(40) === [], 'Autorização revogada não pode recuperar novas cobranças.');
    $store->unlock($lock);
    $nextLock = $second->lock(7, $scope); $second->assertLock($nextLock); $second->unlock($nextLock);
    $assert($db->db_debug && $secondDb->db_debug, 'Consultas protegidas devem restaurar a configuração do driver.');
    $indexes = $db->query('SHOW INDEX FROM `' . db_prefix() . 'asaas_recurring_jobs`')->result_array();
    $assert(in_array('state_next_check', array_column($indexes, 'Key_name'), true)
        && in_array('payment_id', array_column($indexes, 'Key_name'), true), 'Fila e sinal do webhook devem possuir índices próprios.');
    $columns = array_column($db->query('SHOW COLUMNS FROM `' . db_prefix() . 'asaas_recurring_authorizations`')->result_array(), 'Field');
    $assert(array_intersect(['number', 'cvv', 'ccv', 'expiryMonth', 'expiryYear', 'creditCard', 'creditCardToken'], $columns) === [], 'O schema não deve criar colunas de cartão ou token em texto simples.');
    echo "Recurring MariaDB: {$assertions} assertions, 0 failures.\n";
} finally {
    unset($store, $second);
    foreach ($tables as $table) { $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`'); }
}
