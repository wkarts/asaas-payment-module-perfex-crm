<?php
/** Real helper, controller and view; controlled CI services, local files, no network. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$testDirectory = sys_get_temp_dir() . '/asaas-diagnostics-' . bin2hex(random_bytes(8));
mkdir($testDirectory . '/logs', 0700, true);
define('BASEPATH', $testDirectory . '/system/');
define('APPPATH', $testDirectory . '/');
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $testDirectory . '/php-error.log');
const DIAGNOSTIC_SECRET = 'WEBHOOK-CUSTOM-SECRET-FIXTURE-8b0123456789';
const DIAGNOSTIC_API_KEY = 'CUSTOM-API-KEY-DECRYPTED-FIXTURE-8c0123456789';

final class DiagnosticDb
{
    public bool $db_debug = true;
    public bool $exists = true;
    public array $columns = ['id', 'level', 'message', 'context_json', 'correlation_id', 'created_at'];
    public array $records = [];
    public array $inserts = [];
    public array $calls = [];
    public ?string $throwAt = null;
    public bool $insertResult = true;
    public bool $queryResult = true;
    private function call(string $name, array $args = []): void
    {
        $this->calls[] = [$name, $args, $this->db_debug];
        if ($this->throwAt === $name) { throw new RuntimeException('Unsafe DB detail ' . DIAGNOSTIC_SECRET); }
    }
    public function table_exists(string $table): bool { $this->call('table_exists', [$table]); return $this->exists; }
    public function list_fields(string $table): array { $this->call('list_fields', [$table]); return $this->columns; }
    public function insert(string $table, array $data): bool { $this->call('insert', [$table]); $this->inserts[] = [$table, $data]; return $this->insertResult; }
    public function select(string $fields): self { $this->call('select', [$fields]); return $this; }
    public function order_by(string $field, string $direction): self { $this->call('order_by', [$field, $direction]); return $this; }
    public function limit(int $limit): self { $this->call('limit', [$limit]); return $this; }
    public function get(string $table) { $this->call('get', [$table]); return $this->queryResult ? new DiagnosticResult($this->records) : false; }
}
final class DiagnosticResult
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function result_array(): array { return $this->rows; }
}
final class DiagnosticConfig
{
    public string $directory = '';
    public function item(string $name): string { return $name === 'log_path' ? $this->directory : ''; }
}
final class DiagnosticEncryption
{
    public function decrypt(string $value): string { return $value === 'encrypted-key-fixture' ? DIAGNOSTIC_API_KEY : ''; }
}
final class DiagnosticRouter
{
    public string $method = 'logs';
    public function fetch_method(): string { return $this->method; }
}
final class DiagnosticLoader
{
    public array $libraries = [];
    public array $views = [];
    public bool $failLibrary = true;
    public function library(string $name): void
    {
        $this->libraries[] = $name;
        if ($this->failLibrary) { throw new RuntimeException('SDK missing in fixture'); }
    }
    public function view(string $name, array $data): void { $this->views[] = [$name, $data]; }
}
final class DiagnosticInput
{
    public string $requestMethod = 'GET';
    public array $query = [];
    public function method(bool $uppercase = false): string { return $uppercase ? strtoupper($this->requestMethod) : strtolower($this->requestMethod); }
    public function get($key = null) { throw new RuntimeException('The diagnostic endpoint must not read a request-supplied path.'); }
}
final class DiagnosticOutput
{
    public array $headers = [];
    public int $status = 200;
    public string $contentType = '';
    public string $content = '';
    public bool $profiler = true;
    public function enable_profiler(bool $enabled): self { $this->profiler = $enabled; return $this; }
    public function set_header(string $header): self { $this->headers[] = $header; return $this; }
    public function set_status_header(int $status): self { $this->status = $status; return $this; }
    public function set_content_type(string $type, string $charset = ''): self { $this->contentType = $type; return $this; }
    public function set_output(string $content): self { $this->content = $content; return $this; }
}
final class DiagnosticSecurity
{
    public string $hash = 'csrf-diagnostics-current';
    public function get_csrf_token_name(): string { return 'csrf_diagnostics'; }
    public function get_csrf_hash(): string { return $this->hash; }
}
class AdminController
{
    public DiagnosticLoader $load;
    public DiagnosticRouter $router;
    public DiagnosticInput $input;
    public DiagnosticOutput $output;
    public DiagnosticSecurity $security;
    public $asaas_gateway;
    public function __construct()
    {
        $this->load = $GLOBALS['diagLoader'];
        $this->router = $GLOBALS['diagRouter'];
        $this->input = $GLOBALS['diagInput'];
        $this->output = $GLOBALS['diagOutput'];
        $this->security = $GLOBALS['diagSecurity'];
        $this->asaas_gateway = new class {
            public function getApiKey(): string { ++$GLOBALS['diagGatewayCalls']; return 'fixture-api-key'; }
        };
    }
}
function get_instance() { if ($GLOBALS['diagInstanceThrows']) { throw new RuntimeException(DIAGNOSTIC_SECRET); } return $GLOBALS['diagCi']; }
function get_option(string $name)
{
    if ($GLOBALS['diagOptionThrows']) { throw new RuntimeException(DIAGNOSTIC_SECRET); }
    if (isset($GLOBALS['diagCi']->db->db_debug)) { $GLOBALS['diagOptionDebug'][] = $GLOBALS['diagCi']->db->db_debug; }
    return $GLOBALS['diagOptions'][$name] ?? '';
}
function db_prefix(): string { return 'tbl'; }
function log_message(string $level, string $message): void
{
    if ($GLOBALS['diagCiLogThrows']) { throw new RuntimeException(DIAGNOSTIC_SECRET); }
    $GLOBALS['diagCiMessages'][] = [$level, $message];
    if ($GLOBALS['diagRecurse']) { asaas_diagnostic_log('error', 'Recursive write'); }
}
function is_admin(): bool { return $GLOBALS['diagIsAdmin']; }
function access_denied(string $resource): void { $GLOBALS['diagDenied'][] = $resource; }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admin_url(string $path = ''): string { return '/admin/' . $path; }
function init_head(): void { ++$GLOBALS['diagHeadCalls']; echo '<html><body>'; }
function init_tail(): void { ++$GLOBALS['diagTailCalls']; }
require_once dirname(__DIR__) . '/helpers/asaas_diagnostics_helper.php';
require_once dirname(__DIR__) . '/controllers/Asaas.php';
$scenarioCount = 0; $assertionCount = 0; $failures = [];
function check($condition, string $message): void
{
    ++$GLOBALS['assertionCount'];
    if (!$condition) { throw new RuntimeException($message); }
}
function same($expected, $actual, string $message): void { check($expected === $actual, $message); }
function resetDiagnostics(): void
{
    $GLOBALS['diagCi'] = (object) ['db' => new DiagnosticDb(), 'config' => new DiagnosticConfig(), 'encryption' => new DiagnosticEncryption()];
    $GLOBALS['diagOptions'] = ['paymentmethod_asaas_webhook_secret' => DIAGNOSTIC_SECRET,
        'paymentmethod_asaas_api_key' => 'encrypted-key-fixture', 'paymentmethod_asaas_file_log_enabled' => '0'];
    foreach (['diagInstanceThrows', 'diagOptionThrows', 'diagCiLogThrows', 'diagRecurse'] as $flag) { $GLOBALS[$flag] = false; }
    foreach (['diagCiMessages', 'diagOptionDebug', 'diagDenied'] as $list) { $GLOBALS[$list] = []; }
    foreach (['diagHeadCalls', 'diagTailCalls', 'diagGatewayCalls'] as $counter) { $GLOBALS[$counter] = 0; }
    $GLOBALS['diagIsAdmin'] = true;
    $GLOBALS['diagLoader'] = new DiagnosticLoader(); $GLOBALS['diagRouter'] = new DiagnosticRouter();
    $GLOBALS['diagInput'] = new DiagnosticInput(); $GLOBALS['diagOutput'] = new DiagnosticOutput();
    $GLOBALS['diagSecurity'] = new DiagnosticSecurity();
    $path = APPPATH . 'logs/asaas-' . date('Y-m-d') . '.php';
    if (is_file($path) || is_link($path)) { unlink($path); }
    if (is_file(APPPATH . 'php-error.log')) { unlink(APPPATH . 'php-error.log'); }
}
function scenario(string $name, callable $test): void
{
    ++$GLOBALS['scenarioCount']; resetDiagnostics();
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $exception) { $GLOBALS['failures'][] = $name . ': ' . $exception->getMessage(); echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL; }
}
function fallbackText(): string { return is_file(APPPATH . 'php-error.log') ? (string) file_get_contents(APPPATH . 'php-error.log') : ''; }
function assertNoSecret(string $text): void
{
    foreach ([DIAGNOSTIC_SECRET, DIAGNOSTIC_API_KEY, 'person@example.test', '123.456.789-09', '12.345.678/0001-95'] as $secret) {
        check(strpos($text, $secret) === false, 'Sensitive fixture must not be present');
    }
}
function fixtureRecord(int $id = 1): array
{
    return ['id' => $id, 'level' => 'error', 'message' => 'Falha controlada',
        'context_json' => json_encode(['http_status' => 422, 'correlation_id' => '739ff335d4fa4c635a12345678901ccb']),
        'correlation_id' => '739ff335d4fa4c635a12345678901ccb', 'created_at' => '2026-01-02 03:04:05'];
}
function renderDiagnosticView(array $data): string
{
    ob_start();
    try { extract($data, EXTR_SKIP); include dirname(__DIR__) . '/views/logs.php'; return (string) ob_get_clean(); }
    catch (Throwable $exception) { ob_end_clean(); throw $exception; }
}
try {
    scenario('nested redaction preserves operational API diagnostics', static function (): void {
        $source = ['authToken' => 'unknown', 'Authorization' => 'unknown', 'API-Key' => 'unknown', 'cpfCnpj' => '12345678909',
            'customer_name' => 'Private Name', 'nested' => ['customerEmail' => 'person@example.test', 'payload' => ['unknown' => 'private']],
            'http_status' => 422, 'invoice_id' => 17, 'errors' => [['code' => 'invalid_value', 'description' => 'Campo obrigatório ausente']]];
        $clean = asaas_diagnostic_sanitize($source);
        foreach (['authToken', 'Authorization', 'API-Key', 'cpfCnpj', 'customer_name'] as $key) { same('[REDACTED]', $clean[$key], 'Sensitive key redacted'); }
        same('[REDACTED]', $clean['nested']['payload'], 'Payload entirely redacted');
        same('[REDACTED]', $clean['nested']['customerEmail'], 'Nested email redacted');
        same(422, $clean['http_status'], 'HTTP status retained'); same(17, $clean['invoice_id'], 'Invoice retained');
        same($source['errors'], $clean['errors'], 'Safe API errors retained');
    });
    scenario('stored and decrypted secrets redacted in free text', static function (): void {
        $clean = asaas_diagnostic_sanitize(DIAGNOSTIC_SECRET . ' ' . DIAGNOSTIC_API_KEY . ' encrypted-key-fixture');
        assertNoSecret($clean); check(strpos($clean, 'encrypted-key-fixture') === false, 'Ciphertext masked');
        check(strpos($clean, '[REDACTED]') !== false, 'Mask present');
    });
    scenario('provider tokens authorization email CPF and CNPJ redacted', static function (): void {
        $clean = asaas_diagnostic_sanitize('Bearer abc123 Basic b64value $aact_prod_12345 whsec_abc token_private person@example.test 123.456.789-09 12.345.678/0001-95 cpf=12345678909 api_key=unknownvalue');
        assertNoSecret($clean);
        foreach (['abc123', 'b64value', '$aact_prod_12345', 'whsec_abc', 'token_private', '12345678909', 'unknownvalue'] as $item) {
            check(strpos($clean, $item) === false, 'Sensitive pattern removed');
        }
    });
    scenario('hexadecimal correlations and opaque IDs remain intact', static function (): void {
        $id = '739ff335d4fa4c635a12345678901ccb';
        $clean = asaas_diagnostic_sanitize(['correlation_id' => $id, 'id' => 'pay_12345678901234', 'message' => 'id=' . $id]);
        same($id, $clean['correlation_id'], 'Correlation usable'); same('pay_12345678901234', $clean['id'], 'Opaque ID usable');
        same('id=' . $id, $clean['message'], 'Correlation in message usable');
    });
    scenario('cyclic oversized and unsupported context is bounded', static function (): void {
        $cycle = []; $cycle['again'] = &$cycle;
        check(strlen(json_encode(asaas_diagnostic_sanitize($cycle))) < 300, 'Recursive input bounded');
        $clean = asaas_diagnostic_sanitize(array_fill(0, 150, 'ok'));
        same(101, count($clean), 'Context count bounded'); same(true, $clean['_truncated'], 'Truncation visible');
        same('[UNSUPPORTED]', asaas_diagnostic_sanitize(new stdClass()), 'Objects not serialized');
        check(strlen(asaas_diagnostic_sanitize(str_repeat('a', 6000))) < 4200, 'Text bounded');
    });
    scenario('redaction survives settings and container failure', static function (): void {
        $GLOBALS['diagOptionThrows'] = true;
        same('[REDACTED]', asaas_diagnostic_sanitize(['token' => 'private'])['token'], 'Key masking remains active');
        same(true, $GLOBALS['diagCi']->db->db_debug, 'Debug restored after options fail');
        $GLOBALS['diagInstanceThrows'] = true;
        same('[REDACTED]', asaas_diagnostic_sanitize('person@example.test'), 'Pattern masking works without CI');
    });
    scenario('new schema write suppresses db_debug and retains correlation', static function (): void {
        $db = $GLOBALS['diagCi']->db; $id = '739ff335d4fa4c635a12345678901ccb';
        asaas_diagnostic_log('info', 'Payment accepted', ['correlation_id' => $id, 'http_status' => 201, 'token' => DIAGNOSTIC_SECRET], false);
        same(1, count($db->inserts), 'Single insert'); same('tblasaas_logs', $db->inserts[0][0], 'Existing table used');
        $saved = $db->inserts[0][1]; same($id, $saved['correlation_id'], 'Persisted correlation intact');
        same('[REDACTED]', json_decode($saved['context_json'], true)['token'], 'DB context redacted');
        same('info', $GLOBALS['diagCiMessages'][0][0], 'CI severity retained'); same(true, $db->db_debug, 'Debug restored');
        foreach ($db->calls as $call) { same(false, $call[2], 'DB calls suppress CI error page'); }
        foreach ($GLOBALS['diagOptionDebug'] as $debug) { same(false, $debug, 'Option calls suppress CI error page'); }
        same('', fallbackText(), 'Healthy storage does not fallback');
    });
    scenario('legacy context column and originally false db_debug supported', static function (): void {
        $db = $GLOBALS['diagCi']->db; $db->db_debug = false; $db->columns = ['id', 'level', 'message', 'context', 'created_at'];
        asaas_diagnostic_log('warning', 'Rejected', ['http_status' => 422], false);
        check(isset($db->inserts[0][1]['context']), 'Legacy column used'); check(!isset($db->inserts[0][1]['context_json']), 'Absent new column avoided');
        same(false, $db->db_debug, 'False debug preserved'); same('error', $GLOBALS['diagCiMessages'][0][0], 'Warning mapped to CI error');
    });
    scenario('base schema without context remains writable', static function (): void {
        $db = $GLOBALS['diagCi']->db; $db->columns = ['id', 'level', 'message', 'created_at'];
        asaas_diagnostic_log('error', 'Failure', ['http_status' => 500], false);
        same(['level', 'message', 'created_at'], array_keys($db->inserts[0][1]), 'Existing columns only');
    });
    scenario('partial driver using field_exists remains supported', static function (): void {
        $db = new class {
            public bool $db_debug = true; public array $saved = [];
            public function table_exists(string $table): bool { return true; }
            public function field_exists(string $field, string $table): bool { return in_array($field, ['id', 'level', 'message', 'created_at', 'context'], true); }
            public function insert(string $table, array $payload): bool { $this->saved[] = $payload; return true; }
        };
        $GLOBALS['diagCi']->db = $db; asaas_diagnostic_log('error', 'Partial driver', ['code' => 'invalid'], false);
        same(1, count($db->saved), 'Partial driver works'); check(isset($db->saved[0]['context']), 'Legacy field detected');
        same(true, $db->db_debug, 'Debug restored');
    });
    scenario('missing table emits one info warning and every error', static function (): void {
        $db = $GLOBALS['diagCi']->db; $db->exists = false;
        asaas_diagnostic_log('info', 'Normal request one', [], false); asaas_diagnostic_log('info', 'Normal request two', [], false);
        same(1, substr_count(fallbackText(), 'Armazenamento de diagnóstico indisponível'), 'Repeated info warnings suppressed per request');
        check(strpos(fallbackText(), 'Normal request') === false, 'Info payload not repeated');
        asaas_diagnostic_log('error', 'Relevant failure one ' . DIAGNOSTIC_SECRET, [], false);
        asaas_diagnostic_log('error', 'Relevant failure two', [], false);
        check(strpos(fallbackText(), 'Relevant failure one') !== false && strpos(fallbackText(), 'Relevant failure two') !== false, 'All failures retained');
        assertNoSecret(fallbackText()); same([], $db->inserts, 'No runtime DDL or insert on missing table'); same(true, $db->db_debug, 'Debug restored');
    });
    foreach (['table_exists', 'list_fields', 'insert'] as $stage) {
        scenario('write exception at ' . $stage . ' cannot escape', static function () use ($stage): void {
            $db = $GLOBALS['diagCi']->db; $db->throwAt = $stage;
            asaas_diagnostic_log('error', 'Operation failed ' . DIAGNOSTIC_SECRET, ['token' => DIAGNOSTIC_SECRET], false);
            same(true, $db->db_debug, 'Debug restored'); assertNoSecret(fallbackText());
            check(strpos(fallbackText(), 'Operation failed') !== false, 'Safe diagnostic retained');
        });
    }
    scenario('false insert result invokes safe fallback', static function (): void {
        $GLOBALS['diagCi']->db->insertResult = false; asaas_diagnostic_log('error', 'Insert returned false', [], false);
        check(strpos(fallbackText(), 'Insert returned false') !== false, 'False insert handled'); same(true, $GLOBALS['diagCi']->db->db_debug, 'Debug restored');
    });
    scenario('CI logger failure does not prevent database logging', static function (): void {
        $GLOBALS['diagCiLogThrows'] = true; asaas_diagnostic_log('error', 'Still persisted', [], false);
        same(1, count($GLOBALS['diagCi']->db->inserts), 'DB destination independent'); assertNoSecret(fallbackText());
    });
    scenario('absent CI and recursive logger callbacks cannot break flow', static function (): void {
        $GLOBALS['diagCi'] = null; asaas_diagnostic_log('error', 'CI missing', ['Authorization' => DIAGNOSTIC_SECRET], false);
        check(strpos(fallbackText(), 'CI missing') !== false, 'No-CI fallback'); assertNoSecret(fallbackText());
        resetDiagnostics(); $GLOBALS['diagRecurse'] = true; asaas_diagnostic_log('error', 'Outer operation', [], false);
        same(1, count($GLOBALS['diagCiMessages']), 'Recursion prevented'); same(1, count($GLOBALS['diagCi']->db->inserts), 'Outer write retained');
    });
    scenario('disabled option and explicit false create no file', static function (): void {
        asaas_diagnostic_log('info', 'Default disabled'); check(!is_file(asaas_diagnostic_file_path()), 'Off by default');
        $GLOBALS['diagOptions']['paymentmethod_asaas_file_log_enabled'] = '1'; asaas_diagnostic_log('info', 'Explicit disabled', [], false);
        check(!is_file(asaas_diagnostic_file_path()), 'Explicit false wins');
    });
    scenario('enabled file contains protected sanitized append entries', static function (): void {
        $GLOBALS['diagOptions']['paymentmethod_asaas_file_log_enabled'] = '1'; $GLOBALS['diagCiLogThrows'] = true;
        asaas_diagnostic_log('error', 'File failure ' . DIAGNOSTIC_API_KEY, ['email' => 'person@example.test']);
        asaas_diagnostic_log('info', 'File info', ['correlation_id' => '739ff335d4fa4c635a12345678901ccb']);
        $file = file_get_contents(asaas_diagnostic_file_path());
        same(0, strpos($file, "<?php exit; ?>\n"), 'Direct PHP execution protected');
        same(1, substr_count($file, '<?php exit; ?>'), 'Header once'); same(2, substr_count($file, '[Asaas]['), 'Entries appended');
        assertNoSecret($file); check(strpos($file, '739ff335d4fa4c635a12345678901ccb') !== false, 'File correlation intact');
    });
    scenario('explicit true honors configured local log_path', static function (): void {
        $directory = APPPATH . 'configured'; mkdir($directory, 0700); $GLOBALS['diagCi']->config->directory = $directory . '/';
        asaas_diagnostic_log('info', 'Configured file', [], true);
        same($directory . '/asaas-' . date('Y-m-d') . '.php', asaas_diagnostic_file_path(), 'CI directory honored');
        check(is_file(asaas_diagnostic_file_path()), 'Explicit true overrides off');
    });
    scenario('file failure and nonlocal paths remain safe', static function (): void {
        $GLOBALS['diagCi']->config->directory = APPPATH . 'missing-directory';
        asaas_diagnostic_log('error', 'File unavailable ' . DIAGNOSTIC_SECRET, [], true);
        check(!is_dir(APPPATH . 'missing-directory'), 'No arbitrary directory creation');
        check(strpos(fallbackText(), 'File unavailable') !== false, 'File fault safely retained'); assertNoSecret(fallbackText());
        foreach (['php://memory', '//server/share', '\\\\server\\share', "bad\0path"] as $path) {
            $GLOBALS['diagCi']->config->directory = $path; same(null, asaas_diagnostic_file_path(), 'Nonlocal path rejected');
            same(false, asaas_diagnostic_write_file('test'), 'No unsupported writes');
        }
    });
    scenario('symlink target not followed for reads or writes', static function (): void {
        $target = APPPATH . 'unrelated.txt'; file_put_contents($target, 'Keep this file'); symlink($target, asaas_diagnostic_file_path());
        same(false, asaas_diagnostic_write_file('Do not write'), 'Symlink write rejected'); same([], asaas_diagnostic_file_rows(), 'Symlink read rejected');
        same('Keep this file', file_get_contents($target), 'Unrelated file preserved');
    });
    scenario('admin query bounds100 and sanitizes old records', static function (): void {
        $db = $GLOBALS['diagCi']->db;
        for ($id = 120; $id >= 1; --$id) { $db->records[] = fixtureRecord($id); }
        $db->records[0]['message'] = 'Old secret ' . DIAGNOSTIC_SECRET;
        $db->records[0]['context_json'] = json_encode(['email' => 'person@example.test', 'http_status' => 422]);
        $report = asaas_diagnostic_report(); same('ok', $report['database_state'], 'Database available'); same('database', $report['source'], 'Source identified');
        same(100, count($report['rows']), 'Read bound enforced'); same(120, $report['rows'][0]['id'], 'Newest retained'); assertNoSecret(json_encode($report['rows']));
        $calls = array_column($db->calls, 1, 0); same([100], $calls['limit'], 'Query bounded'); same(['id', 'DESC'], $calls['order_by'], 'Newest requested');
        same(['id,level,message,created_at,context_json,correlation_id'], $calls['select'], 'Only necessary columns'); same(true, $db->db_debug, 'Read debug restored');
    });
    scenario('legacy and malformed database context supported', static function (): void {
        $db = $GLOBALS['diagCi']->db; $db->columns = ['id', 'level', 'message', 'context', 'created_at'];
        $one = fixtureRecord(); unset($one['context_json']); $one['context'] = json_encode(['password' => 'private']);
        $two = fixtureRecord(2); unset($two['context_json']); $two['context'] = '{malformed';
        $db->records = [$one, $two]; $report = asaas_diagnostic_report();
        same('[REDACTED]', $report['rows'][0]['context']['password'], 'Legacy context sanitized'); same([], $report['rows'][1]['context'], 'Malformed JSON hidden');
    });
    foreach (['list_fields', 'select', 'get'] as $stage) {
        scenario('read exception at ' . $stage . ' is contained', static function () use ($stage): void {
            $db = $GLOBALS['diagCi']->db; $db->throwAt = $stage; $report = asaas_diagnostic_report();
            same('unavailable', $report['database_state'], 'Read failure identified'); same([], $report['rows'], 'No raw error exposed'); same(true, $db->db_debug, 'Debug restored');
        });
    }
    scenario('file fallback reads only bounded tail newest100', static function (): void {
        $GLOBALS['diagCi']->db->exists = false; $file = "<?php exit; ?>\n" . str_repeat('x', 270000) . "\n";
        for ($id = 1; $id <= 120; ++$id) {
            $file .= '2026-01-02 03:04:05 [Asaas][ERROR] ' . json_encode(['message' => 'Failure ' . $id . ' ' . DIAGNOSTIC_SECRET,
                'context' => ['invoice_id' => $id, 'email' => 'person@example.test']]) . "\n";
        }
        file_put_contents(asaas_diagnostic_file_path(), $file . "unparseable raw response\n");
        $report = asaas_diagnostic_report(); same('missing', $report['database_state'], 'Missing table identified'); same('file', $report['source'], 'File fallback selected');
        same(100, count($report['rows']), 'File output bounded'); same(120, $report['rows'][0]['context']['invoice_id'], 'Newest first');
        same(21, $report['rows'][99]['context']['invoice_id'], 'Old entries excluded'); assertNoSecret(json_encode($report['rows']));
        check(strpos(json_encode($report), 'unparseable') === false, 'Malformed lines hidden');
    });
    scenario('file fallback works without full CI bootstrap', static function (): void {
        asaas_diagnostic_log('error', 'Available without DB', [], true); $GLOBALS['diagCi'] = null;
        $report = asaas_diagnostic_report(); same('unavailable', $report['database_state'], 'No DB reported'); same('file', $report['source'], 'APPPATH fallback');
        same('Available without DB', $report['rows'][0]['message'], 'File diagnostic visible');
    });
    scenario('logs constructor bypasses unavailable SDK only for logs', static function (): void {
        $controller = new Asaas(); $controller->logs(); same([], $GLOBALS['diagLoader']->libraries, 'No SDK load'); same(0, $GLOBALS['diagGatewayCalls'], 'No API-key fetch');
        same('asaas/logs', $GLOBALS['diagLoader']->views[0][0], 'Module view'); same('Diagnóstico da integração Asaas', $GLOBALS['diagLoader']->views[0][1]['title'], 'Title supplied');
        $GLOBALS['diagRouter']->method = 'charges'; $GLOBALS['diagLoader']->failLibrary = false; new Asaas();
        same(['asaas/asaas_gateway'], $GLOBALS['diagLoader']->libraries, 'Other actions retain initialization'); same(1, $GLOBALS['diagGatewayCalls'], 'Other actions retain getApiKey');
    });
    scenario('non-admin blocked before any data or view access', static function (): void {
        $GLOBALS['diagIsAdmin'] = false; $controller = new Asaas(); $controller->logs();
        same(['Asaas'], $GLOBALS['diagDenied'], 'Permission denied'); same([], $GLOBALS['diagCi']->db->calls, 'No diagnostic query');
        same([], $GLOBALS['diagLoader']->views, 'No view render'); same([], $GLOBALS['diagLoader']->libraries, 'Denial needs no SDK');
    });
    scenario('embedded logs return bounded redacted JSON without the SDK or request paths', static function (): void {
        $GLOBALS['diagRouter']->method = 'logs_data';
        $GLOBALS['diagInput']->query = ['path' => '/etc/passwd', 'file' => '../../private.php'];
        $record = fixtureRecord();
        $record['message'] = 'Falha ' . DIAGNOSTIC_SECRET . ' ' . DIAGNOSTIC_API_KEY;
        $record['context_json'] = json_encode(['invoice_id' => 42, 'api_key' => DIAGNOSTIC_API_KEY,
            'creditCard' => ['number' => '4111111111111111', 'ccv' => '123'], 'email' => 'person@example.test']);
        $GLOBALS['diagCi']->db->records = array_fill(0, 120, $record);
        $controller = new Asaas(); $controller->logs_data();
        $output = $GLOBALS['diagOutput']; $payload = json_decode($output->content, true, 512, JSON_THROW_ON_ERROR);
        same(200, $output->status, 'Success status'); same('application/json', $output->contentType, 'JSON response');
        same(false, $output->profiler, 'No profiler appended to the diagnostic response');
        check(in_array('Cache-Control: no-store, private, max-age=0', $output->headers, true), 'No browser cache');
        check(in_array('X-Content-Type-Options: nosniff', $output->headers, true), 'No MIME sniffing');
        same(true, $payload['ok'], 'Response contract'); same('ok', $payload['report']['database_state'], 'Database state');
        same(['name' => 'csrf_diagnostics', 'hash' => 'csrf-diagnostics-current'], $payload['csrf'], 'Native CSRF returned for form renewal');
        same(100, count($payload['report']['rows']), 'Existing record bound preserved');
        same(42, $payload['report']['rows'][0]['context']['invoice_id'], 'Useful correlation retained');
        assertNoSecret($output->content); check(strpos($output->content, '4111111111111111') === false, 'Card number redacted');
        same([], $GLOBALS['diagLoader']->libraries, 'Endpoint needs no SDK'); same(0, $GLOBALS['diagGatewayCalls'], 'No API-key fetch');
        same([], $GLOBALS['diagLoader']->views, 'No full page or nested form rendered');
    });
    scenario('embedded logs deny non-admin without reading data', static function (): void {
        $GLOBALS['diagRouter']->method = 'logs_data'; $GLOBALS['diagIsAdmin'] = false;
        $controller = new Asaas(); $controller->logs_data();
        $output = $GLOBALS['diagOutput']; $payload = json_decode($output->content, true, 512, JSON_THROW_ON_ERROR);
        same(403, $output->status, 'Forbidden'); same(false, $payload['ok'], 'Error contract');
        same([], $GLOBALS['diagCi']->db->calls, 'No diagnostic reads'); same([], $GLOBALS['diagLoader']->libraries, 'No SDK');
        check(!isset($payload['report']), 'No private report in denial');
        same(['name' => 'csrf_diagnostics', 'hash' => 'csrf-diagnostics-current'], $payload['csrf'], 'CSRF returned even with authorization error');
        check(in_array('Cache-Control: no-store, private, max-age=0', $output->headers, true), 'Denied response not cached');
    });
    foreach (['POST', 'DELETE', 'HEAD'] as $requestMethod) {
        scenario('embedded logs reject ' . $requestMethod . ' before reading data', static function () use ($requestMethod): void {
            $GLOBALS['diagRouter']->method = 'logs_data'; $GLOBALS['diagInput']->requestMethod = $requestMethod;
            $controller = new Asaas(); $controller->logs_data(); $output = $GLOBALS['diagOutput'];
            same(405, $output->status, 'Method rejected'); check(in_array('Allow: GET', $output->headers, true), 'Allowed method advertised');
            same([], $GLOBALS['diagCi']->db->calls, 'No diagnostic reads'); same([], $GLOBALS['diagLoader']->libraries, 'No SDK');
            same('csrf-diagnostics-current', json_decode($output->content, true, 512, JSON_THROW_ON_ERROR)['csrf']['hash'], 'Error response can renew CSRF');
        });
    }
    scenario('embedded logs return the current native CSRF after a GET cookie refresh', static function (): void {
        $GLOBALS['diagRouter']->method = 'logs_data';
        $GLOBALS['diagSecurity']->hash = 'csrf-refreshed-before-controller';
        $controller = new Asaas(); $controller->logs_data();
        $payload = json_decode($GLOBALS['diagOutput']->content, true, 512, JSON_THROW_ON_ERROR);
        same('csrf-refreshed-before-controller', $payload['csrf']['hash'], 'Uses the current security state, not a cached view token');
        same('csrf_diagnostics', $payload['csrf']['name'], 'Preserves the configured native name');
        same([], $GLOBALS['diagLoader']->libraries, 'Token refresh still needs no SDK');
    });
    scenario('embedded logs retain file fallback and directory diagnostics', static function (): void {
        $GLOBALS['diagRouter']->method = 'logs_data'; $GLOBALS['diagCi']->db->exists = false;
        $GLOBALS['diagOptions']['paymentmethod_asaas_file_log_enabled'] = '1';
        asaas_diagnostic_log('error', 'File fallback ' . DIAGNOSTIC_SECRET, ['invoice_id' => 42], true);
        $controller = new Asaas(); $controller->logs_data();
        $output = $GLOBALS['diagOutput']; $payload = json_decode($output->content, true, 512, JSON_THROW_ON_ERROR);
        same(200, $output->status, 'Fallback is usable'); same('missing', $payload['report']['database_state'], 'Missing DB explicit');
        same('file', $payload['report']['source'], 'File source visible'); same(true, $payload['report']['file_enabled'], 'Saved setting visible');
        same(true, $payload['report']['file_exists'], 'File existence visible'); same(true, $payload['report']['directory_writable'], 'Directory permission visible');
        same(asaas_diagnostic_file_path(), $payload['report']['file_path'], 'Only module-owned daily file');
        same(42, $payload['report']['rows'][0]['context']['invoice_id'], 'Fallback details retained'); assertNoSecret($output->content);
    });
    scenario('embedded logs report unavailable storage without leaking database failures', static function (): void {
        $GLOBALS['diagRouter']->method = 'logs_data'; $GLOBALS['diagCi']->db->throwAt = 'get';
        $controller = new Asaas(); $controller->logs_data();
        $output = $GLOBALS['diagOutput']; $payload = json_decode($output->content, true, 512, JSON_THROW_ON_ERROR);
        same(200, $output->status, 'Diagnostics remain accessible'); same('unavailable', $payload['report']['database_state'], 'Unavailable storage explicit');
        same('none', $payload['report']['source'], 'No source invented'); same([], $payload['report']['rows'], 'No rows invented');
        assertNoSecret($output->content); check(strpos($output->content, 'Unsafe DB detail') === false, 'Private database exception omitted');
    });
    scenario('view escapes HTML and blocks direct unauthorized inclusion', static function (): void {
        $data = asaas_diagnostic_report(); $data['title'] = '<script>alert(1)</script>';
        $data['rows'] = [['created_at' => '<img src=x>', 'level' => '<b>error</b>', 'message' => '<script>payload</script>',
            'context' => ['code' => '<svg onload=alert(1)>'], 'correlation_id' => '<b>abc</b>']];
        $html = renderDiagnosticView($data);
        foreach (['<script>', '<img src=x>', '<svg onload='] as $tag) { check(strpos($html, $tag) === false, 'Markup escaped'); }
        check(strpos($html, '&lt;script&gt;payload&lt;/script&gt;') !== false, 'Safe readable escaped message');
        check(strpos($html, '/admin/asaas/asaas/logs') !== false, 'Canonical module/controller/action URL');
        same(1, $GLOBALS['diagHeadCalls'], 'Native head'); same(1, $GLOBALS['diagTailCalls'], 'Native tail');
        $GLOBALS['diagIsAdmin'] = false; same('', renderDiagnosticView($data), 'Direct unauthorized view empty');
        same(1, $GLOBALS['diagHeadCalls'], 'Denied before header'); same(['Asaas'], $GLOBALS['diagDenied'], 'Direct view denied');
    });
    scenario('view explains missing table and file source', static function (): void {
        $GLOBALS['diagCi']->db->exists = false; asaas_diagnostic_log('error', 'Visible file diagnostic', [], true);
        $data = asaas_diagnostic_report(); $data['title'] = 'Diagnostics'; $html = renderDiagnosticView($data);
        check(strpos($html, 'migrations') !== false, 'Migration remediation visible'); check(strpos($html, '256 KiB') !== false, 'File bound explained');
        check(strpos($html, 'Visible file diagnostic') !== false, 'Fallback entry rendered'); assertNoSecret($html);
    });
} finally {
    ini_set('error_log', $previousErrorLog); restore_error_handler();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($testDirectory);
}
echo PHP_EOL . sprintf('%d scenarios, %d assertions, %d failures', $scenarioCount, $assertionCount, count($failures)) . PHP_EOL;
exit($failures === [] ? 0 : 1);
