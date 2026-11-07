<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
$GLOBALS['staff_fixture_admin'] = false;
$GLOBALS['staff_fixture_grants'] = [];
$GLOBALS['staff_fixture_registered'] = [];
function is_admin(): bool { return $GLOBALS['staff_fixture_admin']; }
function staff_can(string $action, string $feature): bool { return !empty($GLOBALS['staff_fixture_grants'][$feature][$action]); }
function register_staff_capabilities(string $id, array $config, string $name): void { $GLOBALS['staff_fixture_registered'][$id] = $config; }
function admin_url(string $path): string { return 'https://crm.example.test/admin/' . $path; }
function get_option(string $name) { return false; }
function db_prefix(): string { return 'tbl'; }
function &get_instance(): object { return $GLOBALS['staff_fixture_ci']; }
function access_denied(string $feature): void { $GLOBALS['staff_fixture_ci']->output->set_status_header(403); }
function show_404(): void { $GLOBALS['staff_fixture_ci']->output->set_status_header(404); }
class StaffOutput
{
    public int $status = 200;
    public string $body = '';
    public function set_header(string $value): self { return $this; }
    public function set_content_type(string $type, string $charset = ''): self { return $this; }
    public function set_status_header(int $status): self { $this->status = $status; return $this; }
    public function set_output(string $body): self { $this->body = $body; return $this; }
}
class AdminController
{
    public object $input;
    public StaffOutput $output;
    public object $load;
    public function __construct()
    {
        $this->input = get_instance()->input;
        $this->output = get_instance()->output;
        $this->load = get_instance()->load;
    }
}
$GLOBALS['staff_fixture_ci'] = (object) [
    'app_menu' => new class {
        public array $items = [];
        public function add_sidebar_menu_item(string $id, array $item): void { $this->items[$id] = $item; }
        public function add_sidebar_children_item(string $id, array $item): void { $this->items[$item['slug']] = $item; }
    },
    'input' => new class {
        public string $verb = 'GET';
        public function method(bool $upper = false): string { return $this->verb; }
        public function get(string $field) { return null; }
        public function post(string $field) { return null; }
    },
    'db' => new class { public function table_exists(string $name): bool { return false; } },
    'load' => new class {
        public array $views = [];
        public function view(string $view, array $data): void { $this->views[] = $view; }
    },
    'output' => new StaffOutput(),
];
require_once dirname(__DIR__) . '/helpers/asaas_staff_helper.php';
require_once dirname(__DIR__) . '/controllers/Monitor.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks; if (!$condition) { throw new RuntimeException($message); }
};
asaas_register_staff_permissions();
$assert(array_keys($GLOBALS['staff_fixture_registered']['asaas_monitor']['capabilities']) === ['view', 'reconcile'], 'Monitor permissions must separate observation and receipts.');
$assert(array_keys($GLOBALS['staff_fixture_registered']['asaas_reports']['capabilities']) === ['view', 'export'], 'Report permissions must separate view and export.');
asaas_register_staff_menu();
$assert(get_instance()->app_menu->items === [], 'No financial grant means no Asaas menu.');
$monitor = new Monitor();
$monitor->overview();
$assert($monitor->output->status === 403, 'Direct JSON route must reject an unprivileged staff member.');
$monitor->index();
$assert(get_instance()->load->views === [], 'Direct HTML route must not render financial data without permission.');
$GLOBALS['staff_fixture_grants'] = ['asaas_monitor' => ['reconcile' => true], 'asaas_reports' => ['export' => true]];
$assert(!asaas_staff_can('reconcile', 'asaas_monitor') && !asaas_staff_can('export', 'asaas_reports'), 'Action alone cannot expose data without view.');
$GLOBALS['staff_fixture_grants'] = ['asaas_monitor' => ['view' => true]];
asaas_register_staff_menu();
$assert(isset(get_instance()->app_menu->items['asaas-monitor']) && !isset(get_instance()->app_menu->items['asaas-reports']) && !isset(get_instance()->app_menu->items['asaas-settings']), 'Monitor-only role sees only its module view.');
$monitor->output->status = 200;
$monitor->index();
$assert(get_instance()->load->views === ['asaas/monitor'], 'Delegated staff can open the standalone monitor.');
get_instance()->input->verb = 'POST';
$monitor->reconcile();
$assert($monitor->output->status === 403, 'View permission must not authorize a financial receipt POST.');
$monitor->compare();
$assert($monitor->output->status === 422, 'Read-only comparison reaches validation without requiring reconcile permission.');
$GLOBALS['staff_fixture_grants']['asaas_monitor']['reconcile'] = true;
$monitor->reconcile();
$assert($monitor->output->status === 422, 'Explicit reconcile permission reaches strict invoice-ID validation.');
get_instance()->input->verb = 'GET';
$monitor->reconcile();
$assert($monitor->output->status === 405, 'Even an authorized role cannot mutate by GET.');
$GLOBALS['staff_fixture_admin'] = true;
$assert(asaas_staff_can('export', 'asaas_reports') && asaas_staff_can('reconcile', 'asaas_monitor'), 'Native administrator retains access.');
$assert(!asaas_staff_can('edit_keys', 'asaas_reports'), 'Unknown capabilities fail closed.');
asaas_register_staff_menu();
$assert(isset(get_instance()->app_menu->items['asaas-settings']), 'Settings shortcut remains administrator-only.');
echo "Staff permissions: {$checks} assertions, 0 failures.\n";
