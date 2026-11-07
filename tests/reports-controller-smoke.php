<?php

declare(strict_types=1);

/** Real controller and staff permission helper with minimal Perfex doubles. No remote HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
function &get_instance(): object { return $GLOBALS['reports_controller_ci']; }
function is_admin(): bool { return $GLOBALS['reports_controller_admin']; }
function has_permission(string $feature, string $staff, string $capability): bool { return $GLOBALS['reports_controller_permissions'][$feature][$capability] ?? false; }
function access_denied(string $feature): void { $GLOBALS['reports_controller_denied'][] = $feature; }
function get_option(string $key): string { return ''; }

final class ReportsInputFixture
{
    public function get(): array { return $GLOBALS['reports_controller_input']; }
    public function method(bool $upper = false): string { return $upper ? $GLOBALS['reports_controller_method'] : strtolower($GLOBALS['reports_controller_method']); }
}
final class ReportsOutputFixture
{
    public bool $profiler = true;
    public array $headers = [];
    public int $status = 200;
    public string $body = '';
    public string $type = '';
    public function enable_profiler(bool $value): self { $this->profiler = $value; return $this; }
    public function set_header(string $value): self { $this->headers[] = $value; return $this; }
    public function set_status_header(int $value): self { $this->status = $value; return $this; }
    public function set_content_type(string $value, string $charset = ''): self { $this->type = $value; return $this; }
    public function set_output(string $value): self { $this->body = $value; return $this; }
}
final class ReportsLoadFixture
{
    public int $libraries = 0;
    public array $views = [];
    public function library(string $name): void { $this->libraries++; }
    public function view(string $name, array $data): void { $this->views[] = [$name, $data]; }
}
final class ReportsFinanceFixture
{
    public function retrieveAccountBalance(array $pathParams = [], array $query = [], ?array $payload = null): array
    {
        $GLOBALS['reports_controller_sdk_calls']++;
        if ($GLOBALS['reports_controller_failure']) { throw new RuntimeException('RAW BODY $aact_fixture_secret 4111111111111111'); }
        return ['balance' => 123.45, 'creditCardToken' => 'never-in-report'];
    }
}
final class ReportsProviderFixture
{
    public function sdk(): object { return (object) ['finance' => new ReportsFinanceFixture()]; }
    public function env(): string { return 'sandbox'; }
}
final class ReportsGatewayFixture { public function getProvider(): object { return new ReportsProviderFixture(); } }
class AdminController
{
    public object $input;
    public object $output;
    public object $load;
    public function __construct() { foreach (['input', 'output', 'load'] as $name) { $this->$name = get_instance()->$name; } }
}
require_once dirname(__DIR__) . '/helpers/asaas_staff_helper.php';
require_once dirname(__DIR__) . '/controllers/Reports.php';

function reportsController(array $permissions = [], bool $admin = false, string $method = 'GET', array $input = ['report' => 'balance']): Reports
{
    $GLOBALS['reports_controller_admin'] = $admin;
    $GLOBALS['reports_controller_permissions'] = ['asaas_reports' => $permissions];
    $GLOBALS['reports_controller_method'] = $method;
    $GLOBALS['reports_controller_input'] = $input;
    $GLOBALS['reports_controller_denied'] = [];
    $GLOBALS['reports_controller_sdk_calls'] = 0;
    $GLOBALS['reports_controller_failure'] = false;
    $GLOBALS['reports_controller_ci'] = (object) ['input' => new ReportsInputFixture(), 'output' => new ReportsOutputFixture(),
        'load' => new ReportsLoadFixture(), 'asaas_gateway' => new ReportsGatewayFixture()];
    return new Reports();
}
function reportsControllerCheck(bool $condition, string $message): void
{
    $GLOBALS['reports_controller_assertions']++;
    if (!$condition) { throw new RuntimeException($message); }
}

if (($argv[1] ?? '') === '--download') {
    reportsController(['view' => true, 'export' => true])->export();
    throw new RuntimeException('Controller deveria encerrar após transmitir arquivo completo.');
}

$GLOBALS['reports_controller_assertions'] = 0;
$scenarios = 0;
foreach (['data', 'catalog', 'export'] as $method) {
    $controller = reportsController(); $controller->$method();
    reportsControllerCheck(get_instance()->output->status === 403 && $GLOBALS['reports_controller_sdk_calls'] === 0, 'Sem permissão deve negar ' . $method . ' antes da SDK.');
    reportsControllerCheck(get_instance()->load->libraries === 0, 'Negação não deve carregar credenciais.');
}
reportsController()->index();
reportsControllerCheck($GLOBALS['reports_controller_denied'] === ['asaas_reports'] && get_instance()->load->views === [], 'Tela completa também exige permissão.');
$scenarios++;

reportsController(['export' => true])->export();
reportsControllerCheck(get_instance()->output->status === 403 && $GLOBALS['reports_controller_sdk_calls'] === 0, 'Exportar isoladamente não pode conceder leitura.');
reportsController(['view' => true])->export();
reportsControllerCheck(get_instance()->output->status === 403 && $GLOBALS['reports_controller_sdk_calls'] === 0, 'Visualizar não concede exportar.');
reportsController(['view' => true])->catalog();
$catalog = json_decode(get_instance()->output->body, true, 512, JSON_THROW_ON_ERROR);
reportsControllerCheck($catalog['can_export'] === false && count($catalog['reports']) === 20, 'Catálogo deve respeitar papel de consulta.');
$scenarios++;

foreach (['data', 'catalog', 'export', 'index'] as $method) {
    reportsController(['view' => true, 'export' => true], false, 'POST')->$method();
    reportsControllerCheck(get_instance()->output->status === 405 && $GLOBALS['reports_controller_sdk_calls'] === 0, 'Relatório não pode aceitar POST: ' . $method);
    reportsControllerCheck(in_array('Allow: GET', get_instance()->output->headers, true), 'Método permitido precisa ser informado.');
}
$scenarios++;

reportsController(['view' => true])->data();
$output = get_instance()->output;
$body = json_decode($output->body, true, 512, JSON_THROW_ON_ERROR);
reportsControllerCheck($output->status === 200 && $body['report']['rows'][0]['balance'] === '123.45', 'Colaborador autorizado deve consultar saldo.');
reportsControllerCheck($body['report']['environment'] === 'sandbox' && !str_contains($output->body, 'never-in-report'), 'Ambiente deve vir do provider e extras privados não podem sair.');
reportsControllerCheck(!$output->profiler && in_array('Cache-Control: no-store, private, max-age=0', $output->headers, true), 'Resposta financeira precisa evitar cache e profiler.');
reportsControllerCheck(in_array('X-Content-Type-Options: nosniff', $output->headers, true), 'Resposta deve impedir sniffing de conteúdo.');
$scenarios++;

reportsController([], true)->index();
reportsControllerCheck(get_instance()->load->views[0][0] === 'asaas/reports' && get_instance()->load->libraries === 0, 'Administrador abre tela sem inicializar SDK.');
reportsController(['view' => true], false, 'GET', ['report' => 'balance', 'endpoint' => 'https://example.invalid'])->data();
reportsControllerCheck(get_instance()->output->status === 422 && $GLOBALS['reports_controller_sdk_calls'] === 0, 'URL arbitrária deve ser recusada antes da API.');
reportsController(['view' => true], false, 'GET', ['report' => ['balance']])->data();
reportsControllerCheck(get_instance()->output->status === 422, 'Tipo do relatório precisa ser validado.');
reportsController(['view' => true, 'export' => true], false, 'GET', ['report' => 'refunds'])->export();
reportsControllerCheck(get_instance()->output->status === 422 && get_instance()->output->type === 'application/json', 'Exportação inválida retorna JSON antes dos headers de download.');
$scenarios++;

$controller = reportsController(['view' => true]); $GLOBALS['reports_controller_failure'] = true; $controller->data();
reportsControllerCheck(get_instance()->output->status === 503 && !str_contains(get_instance()->output->body, 'RAW BODY')
    && !str_contains(get_instance()->output->body, '$aact_') && !str_contains(get_instance()->output->body, '4111111111111111'), 'Erros brutos e segredos da SDK precisam ser suprimidos.');
$scenarios++;

$pipes = [];
$process = proc_open([PHP_BINARY, __FILE__, '--download'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Não foi possível testar o download no processo isolado.'); }
$csv = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
reportsControllerCheck($status === 0 && $error === '', 'Download do controller deve encerrar com sucesso: ' . $error);
reportsControllerCheck(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '123,45') && !str_contains($csv, '<html'), 'Download não pode incluir HTML, profiler ou JSON depois do CSV.');
$scenarios++;
echo $scenarios . ' cenários de controller; ' . $GLOBALS['reports_controller_assertions'] . ' verificações de acesso, filtros e download.' . PHP_EOL;
