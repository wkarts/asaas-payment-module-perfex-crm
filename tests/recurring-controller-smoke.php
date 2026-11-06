<?php

declare(strict_types=1);

/** Actual recurring controller/gateway with narrow native-CRM doubles; no Asaas HTTP. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
$GLOBALS['rc_assertions'] = $GLOBALS['rc_scenarios'] = $GLOBALS['rc_failures'] = 0;

function &get_instance(): object { return $GLOBALS['rc_ci']; }
function db_prefix(): string { return 'tbl'; }
function get_option(string $name) { return $GLOBALS['rc_options'][$name] ?? false; }
function is_client_logged_in(): bool { return $GLOBALS['rc_logged']; }
function get_client_user_id(): int { return $GLOBALS['rc_client']; }
function get_contact_user_id(): int { return $GLOBALS['rc_contact']; }
function has_contact_permission(string $permission): bool { return $permission === 'invoices' && $GLOBALS['rc_permission']; }
function is_https(): bool { return $GLOBALS['rc_https']; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function show_404(): void { ++$GLOBALS['rc_not_found']; }
function show_error(string $message, int $status = 500): void { $GLOBALS['rc_errors'][] = [$status, $message]; }
function redirect(string $url, string $method = 'auto', ?int $code = null): void { $GLOBALS['rc_redirects'][] = [$url, $method, $code]; }
function set_alert(string $type, string $message): void { $GLOBALS['rc_alerts'][] = [$type, $message]; }
function log_message(string $level, string $message): void { $GLOBALS['rc_logs'][] = [$level, $message]; }
function asaas_invoice_payment_modes($modes): array { return is_array($modes) ? $modes : []; }

require_once dirname(__DIR__) . '/helpers/asaas_diagnostics_helper.php';

final class RcDatabase
{
    public ?object $invoice;
    public bool $db_debug = true;
    private array $where = [];
    public function __construct()
    {
        $this->invoice = (object) ['id' => 42, 'hash' => 'hash-recurring-controller', 'clientid' => 7, 'status' => 1,
            'total' => '100.00', 'currency' => 1, 'currency_name' => 'BRL', 'allowed_payment_modes' => ['asaas', 'asaas_recurring'],
            'client' => (object) ['email' => 'profile@example.test', 'vat' => '00000000000', 'zip' => '01001000', 'numero' => '10', 'phonenumber' => '11900000000']];
    }
    public function where(string $key, $value): self { $this->where[$key] = $value; return $this; }
    public function get(string $table): object
    {
        $where = $this->where; $this->where = [];
        $row = $table === 'tblinvoices' && $this->invoice !== null && ($where['hash'] ?? null) === $this->invoice->hash ? clone $this->invoice : null;
        return new class($row) { public function __construct(private ?object $row) {} public function row(): ?object { return $this->row; } };
    }
    public function table_exists(string $table): bool { return false; }
}
final class RcInvoiceModel
{
    public ?string $hashOverride = null;
    public function get($id): ?object
    {
        $invoice = get_instance()->db->invoice;
        if ($invoice === null || (int) $id !== (int) $invoice->id) { return null; }
        $invoice = clone $invoice;
        if ($this->hashOverride !== null) { $invoice->hash = $this->hashOverride; }
        return $invoice;
    }
}
final class RcInput
{
    public function post(?string $name = null, bool $filter = false) { return $name === null ? $_POST : ($_POST[$name] ?? null); }
    public function method(bool $upper = false): string { return $upper ? $_SERVER['REQUEST_METHOD'] : strtolower($_SERVER['REQUEST_METHOD']); }
    public function ip_address(): string { return '192.0.2.15'; }
}
final class RcSession
{
    public array $flash = [];
    public array $data = [];
    public function set_userdata(array $data): void { $this->data = $data + $this->data; }
    public function userdata(string $key) { return $this->data[$key] ?? null; }
    public function unset_userdata(string $key): void { unset($this->data[$key]); }
    public function set_flashdata(string $key, $value): void { $this->flash[$key] = $value; }
    public function flashdata(string $key) { $value = $this->flash[$key] ?? null; unset($this->flash[$key]); return $value; }
}
final class RcOutput
{
    public int $minutes = 60;
    public bool $profiler = true;
    public array $headers = [];
    public function cache($minutes): self { $this->minutes = (int) $minutes; return $this; }
    public function enable_profiler($enabled): self { $this->profiler = (bool) $enabled; return $this; }
    public function set_header(string $header): self { $this->headers[] = $header; return $this; }
}
final class RcSecurity
{
    public string $hash = 'csrf-current';
    public function get_csrf_token_name(): string { return 'csrf_token'; }
    public function get_csrf_hash(): string { return $this->hash; }
}
final class RcMainGateway
{
    public function getSetting(string $name) { return get_option('paymentmethod_asaas_' . $name); }
    public function getEnvironmentLabel(): string { return 'PRODUCTION'; }
}
final class RcService
{
    public int $pages = 0;
    public array $enrollments = [];
    public array $revocations = [];
    public array $globalsAtMutation = [];
    public ?Throwable $pageError = null;
    public ?Throwable $enrollError = null;
    public ?Throwable $revokeError = null;
    public array $pageData = ['enabled' => true, 'state' => 'inactive', 'authorization_state' => 'inactive',
        'attempt_state' => '', 'can_enroll' => true, 'can_revoke' => false, 'can_pay_now' => true,
        'quote' => '', 'consent_version' => '2026-11-v1', 'consent_text' => 'Autorizo esta fatura e as próximas faturas elegíveis em uma parcela.',
        'principal' => '100.00', 'fee' => '3.59', 'total' => '103.59', 'message' => '', 'error_code' => ''];
    public array $result = ['ok' => true, 'state' => 'confirmed', 'message' => 'Autorização registrada; confira a fatura.'];
    public function __construct() { $this->pageData['quote'] = str_repeat('a', 64); }
    public function page(object $invoice): array { ++$this->pages; if ($this->pageError) { throw $this->pageError; } return $this->pageData; }
    public function enroll(object $invoice, array $card, array $consent): array
    {
        $this->globalsAtMutation = [$_POST, $_GET, $_REQUEST, $_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI']];
        $this->enrollments[] = ['invoice' => $invoice, 'card' => $card, 'consent' => $consent];
        if ($this->enrollError) { throw $this->enrollError; }
        return $this->result;
    }
    public function revoke(object $invoice): array
    {
        $this->globalsAtMutation = [$_POST, $_GET, $_REQUEST, $_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI']];
        $this->revocations[] = $invoice->id;
        if ($this->revokeError) { throw $this->revokeError; }
        return ['ok' => true, 'state' => 'revoked', 'message' => 'Cobranças futuras desativadas.'];
    }
}
class ClientsController
{
    public object $db;
    public object $load;
    public object $input;
    public object $session;
    public object $output;
    public object $security;
    public object $asaas_gateway;
    public object $invoices_model;
    public array $viewData = [];
    public ?string $viewName = null;
    public int $layouts = 0;
    public function __construct()
    {
        foreach (['db', 'load', 'input', 'session', 'output', 'security', 'asaas_gateway', 'invoices_model'] as $name) { $this->$name = get_instance()->$name; }
    }
    public function data(array $data): void { $this->viewData = $data; }
    public function view(string $name): void { $this->viewName = $name; }
    public function layout(): void { ++$this->layouts; }
}
class App_gateway
{
    public string $id = '';
    public string $name = '';
    public array $settings = [];
    public function __construct() {}
    public function setId(string $id): void { $this->id = $id; }
    public function setName(string $name): void { $this->name = $name; }
    public function setSettings(array $settings): void
    {
        $this->settings = array_merge([
            ['name' => 'active', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'label', 'type' => 'input', 'default_value' => $this->name],
        ], $settings, [['name' => 'default_selected', 'type' => 'yes_no', 'default_value' => 1]]);
    }
    public function getSetting(string $name)
    {
        $saved = get_option('paymentmethod_' . $this->id . '_' . $name);
        if ($saved !== false) { return $saved; }
        foreach ($this->settings as $setting) { if ($setting['name'] === $name) { return $setting['default_value'] ?? ''; } }
        return '';
    }
}
require_once dirname(__DIR__) . '/controllers/Recurring.php';
require_once dirname(__DIR__) . '/libraries/Asaas_recurring_gateway.php';
final class RcController extends Recurring
{
    protected function service(): object { return $GLOBALS['rc_service']; }
}

function rcAssert(bool $condition, string $message): void
{
    ++$GLOBALS['rc_assertions'];
    if (!$condition) { throw new RuntimeException($message); }
}
function rcTest(string $name, callable $callback): void
{
    ++$GLOBALS['rc_scenarios'];
    try { $callback(); echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { ++$GLOBALS['rc_failures']; echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL; }
}
function rcFixture(): array
{
    $_POST = $_GET = $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['CONTENT_LENGTH'] = '0';
    $_SERVER['QUERY_STRING'] = '';
    $_SERVER['REQUEST_URI'] = '/asaas/recurring/index/hash-recurring-controller';
    $GLOBALS['rc_options'] = ['paymentmethod_asaas_active' => '1', 'paymentmethod_asaas_recurring_active' => '1'];
    $GLOBALS['rc_logged'] = $GLOBALS['rc_permission'] = $GLOBALS['rc_https'] = true;
    $GLOBALS['rc_client'] = 7; $GLOBALS['rc_contact'] = 11;
    $GLOBALS['rc_redirects'] = $GLOBALS['rc_errors'] = $GLOBALS['rc_logs'] = $GLOBALS['rc_alerts'] = [];
    $GLOBALS['rc_not_found'] = 0;
    $GLOBALS['rc_service'] = $service = new RcService();
    $GLOBALS['rc_ci'] = (object) [
        'db' => new RcDatabase(), 'input' => new RcInput(), 'session' => new RcSession(), 'output' => new RcOutput(),
        'security' => new RcSecurity(), 'asaas_gateway' => new RcMainGateway(), 'invoices_model' => new RcInvoiceModel(),
        'load' => new class { public function library(string $name): void {} public function model(string $name): void {} public function helper(string $name): void {} },
    ];
    $GLOBALS['rc_form_nonce'] = bin2hex(random_bytes(32));
    get_instance()->session->data['asaas_recurring_form_nonce'] = [
        'token_hash' => hash('sha256', $GLOBALS['rc_form_nonce']), 'invoice_id' => 42, 'client_id' => 7,
        'invoice_hash' => 'hash-recurring-controller', 'contact_id' => 11, 'expires_at' => time() + 1800,
    ];
    return [new RcController(), $service, get_instance()];
}
function rcPost(array $overrides = []): array
{
    $fields = array_replace([
        'csrf_token' => 'csrf-current', 'recurringNonce' => $GLOBALS['rc_form_nonce'], 'action' => 'enroll', 'recurringConsent' => '1', 'consentVersion' => '2026-11-v1',
        'cardQuote' => str_repeat('a', 64), 'holderName' => 'Titular sintético', 'cardNumber' => '4111111111111111',
        'expirationMonth' => '12', 'expirationYear' => '2035', 'securityCode' => '937', 'holderEmail' => 'holder@example.test',
        'holderDocument' => '00000000000', 'holderPostalCode' => '01001000', 'holderAddressNumber' => '10', 'holderPhone' => '11900000000',
    ], $overrides);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $_REQUEST = $fields;
    return $fields;
}
function rcNoRetainedCredentials(RcController $controller): void
{
    $value = json_encode([$controller->viewData, $controller->session->flash, $controller->session->data,
        $GLOBALS['rc_logs'], $_POST, $_GET, $_REQUEST, $_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI']], JSON_THROW_ON_ERROR);
    foreach (['4111111111111111', 'token_fixture_private', 'RAW_PROVIDER_ERROR', '"securityCode"', '"expiryYear"', 'Titular sintético'] as $needle) {
        rcAssert(!str_contains($value, $needle), 'Credential/raw exception leaked to retained state: ' . $needle);
    }
}

rcTest('public GET offers native login without enrollment or card fields', static function (): void {
    [$controller, $service] = rcFixture();
    $GLOBALS['rc_logged'] = false;
    $controller->index('hash-recurring-controller');
    rcAssert($controller->viewName === 'asaas/recorrente' && $controller->viewData['requires_login'] === true, 'Guest must see an explicit login view.');
    rcAssert($service->pages === 0 && $service->enrollments === [] && $service->revocations === [], 'Guest must not access authorization state or mutate it.');
    rcAssert($controller->session->data['red_url'] === site_url('asaas/recurring/index/hash-recurring-controller'), 'Login return must use the native internal canonical URL.');
    rcAssert($controller->viewData['invoice_url'] === site_url('invoice/42/hash-recurring-controller'), 'Standard public invoice remains reachable.');
});
rcTest('expired login POST is scrubbed and redirected without retaining payment input', static function (): void {
    [$controller, $service] = rcFixture(); $GLOBALS['rc_logged'] = false;
    rcPost(['contact_id' => '999', 'return' => 'https://attacker.example.test']);
    $controller->index('hash-recurring-controller');
    rcAssert($service->enrollments === [] && $service->revocations === [], 'Expired session must not authorize or charge.');
    rcAssert($GLOBALS['rc_redirects'] === [[site_url('authentication/login'), 'location', 303]], 'Expired POST must use PRG to native login.');
    rcAssert($_POST === ['csrf_token' => 'csrf-current'] && $_REQUEST === $_POST, 'Only native CSRF may remain in request globals.');
    rcNoRetainedCredentials($controller);
});
foreach (['wrong_client', 'missing_contact', 'missing_permission'] as $reason) {
    rcTest('authorization denies ' . $reason, static function () use ($reason): void {
        [$controller, $service] = rcFixture();
        if ($reason === 'wrong_client') { $GLOBALS['rc_client'] = 99; }
        if ($reason === 'missing_contact') { $GLOBALS['rc_contact'] = 0; }
        if ($reason === 'missing_permission') { $GLOBALS['rc_permission'] = false; }
        rcPost(); $controller->index('hash-recurring-controller');
        rcAssert(($GLOBALS['rc_errors'][0][0] ?? null) === 403, 'Wrong client/contact permissions must receive 403.');
        rcAssert($service->pages === 0 && $service->enrollments === [] && $service->revocations === [], 'Forbidden caller must not access service.');
        rcNoRetainedCredentials($controller);
    });
}
rcTest('GET remains read-only and sends no-store headers with profiler disabled', static function (): void {
    [$controller, $service] = rcFixture();
    $controller->index('hash-recurring-controller');
    rcAssert($service->pages === 1 && $service->enrollments === [] && $service->revocations === [], 'GET must only read the page state.');
    rcAssert($controller->output->minutes === 0 && !$controller->output->profiler, 'Sensitive form must disable caching and profiler.');
    foreach (['Cache-Control: no-store', 'Pragma: no-cache', 'Expires: 0', 'Referrer-Policy: no-referrer'] as $header) {
        rcAssert(in_array($header, $controller->output->headers, true), 'Missing response protection: ' . $header);
    }
    rcAssert($controller->viewData['recurring_page']['can_enroll'] === true, 'A valid server quote should enable enrollment.');
    $nonce = $controller->viewData['recurring_csrf_nonce'];
    $stored = $controller->session->data['asaas_recurring_form_nonce'];
    rcAssert(preg_match('/^[a-f0-9]{64}$/D', $nonce) === 1 && $nonce !== $GLOBALS['rc_form_nonce'], 'GET must issue a fresh 32-byte random form nonce.');
    rcAssert($stored['token_hash'] === hash('sha256', $nonce) && !str_contains(json_encode($stored), $nonce), 'Only the nonce digest may be retained in session.');
    rcAssert($stored['invoice_id'] === 42 && $stored['invoice_hash'] === 'hash-recurring-controller' && $stored['client_id'] === 7 && $stored['contact_id'] === 11, 'Form nonce must bind invoice identity, customer and authenticated contact.');
    rcAssert($stored['expires_at'] > time() && $stored['expires_at'] <= time() + 1800, 'Form nonce expires within thirty minutes.');
});
rcTest('POST enroll forwards one-parcel consent and server identity while purging globals', static function (): void {
    [$controller, $service] = rcFixture();
    $posted = rcPost(['installmentCount' => '21', 'contact_id' => '999', 'ip' => '203.0.113.200', 'creditCardToken' => 'token_fixture_private']);
    $controller->index('hash-recurring-controller');
    rcAssert(count($service->enrollments) === 1 && $service->revocations === [], 'One native POST must invoke one enrollment.');
    $call = $service->enrollments[0];
    rcAssert($call['card']['installmentCount'] === '1', 'Recurring charge must not accept an injected installment count.');
    rcAssert($call['card']['number'] === $posted['cardNumber'] && $call['card']['cvv'] === $posted['securityCode'], 'Raw card exists only in the local service call.');
    rcAssert(!isset($call['card']['creditCardToken']) && $call['consent']['contact_id'] === 11 && $call['consent']['ip'] === '192.0.2.15', 'Token and identity must never be accepted from form overrides.');
    rcAssert($call['consent']['accepted'] === true && $call['consent']['pay_now'] === true, 'Consent must explicitly cover current payment.');
    rcAssert($call['consent']['terms_version'] === '2026-11-v1' && $call['consent']['quote'] === str_repeat('a', 64), 'Server must receive the displayed terms/price acknowledgement.');
    rcAssert($service->globalsAtMutation[0] === ['csrf_token' => 'csrf-current'] && $service->globalsAtMutation[1] === [], 'Secrets must be removed before the service executes.');
    rcAssert($GLOBALS['rc_redirects'] === [[site_url('asaas/recurring/index/hash-recurring-controller'), 'location', 303]], 'Enrollment must finish with POST/Redirect/GET.');
    rcAssert($controller->viewName === null, 'POST must not render raw entered card values.');
    rcAssert(!isset($controller->session->data['asaas_recurring_form_nonce']), 'Accepted POST must consume its nonce before mutation.');
    rcNoRetainedCredentials($controller);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = $_REQUEST = [];
    $controller->index('hash-recurring-controller');
    rcAssert(count($service->enrollments) === 1 && $service->pages === 1, 'Refreshing the redirected page must not invoke enrollment again.');
});
foreach (['enroll', 'revoke'] as $operation) {
    foreach (['missing', 'tampered', 'expired', 'wrong_invoice', 'wrong_hash', 'wrong_client', 'wrong_contact', 'malformed_session'] as $reason) {
        rcTest('independent CSRF denies ' . $operation . ' with ' . $reason . ' nonce before any service call', static function () use ($operation, $reason): void {
            [$controller, $service, $ci] = rcFixture();
            rcPost(['action' => $operation]);
            if ($reason === 'missing') { unset($_POST['recurringNonce'], $_REQUEST['recurringNonce']); }
            if ($reason === 'tampered') { $_POST['recurringNonce'] = $_REQUEST['recurringNonce'] = str_repeat('f', 64); }
            if ($reason === 'expired') { $ci->session->data['asaas_recurring_form_nonce']['expires_at'] = time() - 1; }
            if ($reason === 'wrong_invoice') { $ci->session->data['asaas_recurring_form_nonce']['invoice_id'] = 43; }
            if ($reason === 'wrong_hash') { $ci->session->data['asaas_recurring_form_nonce']['invoice_hash'] = 'another-invoice-hash'; }
            if ($reason === 'wrong_client') { $ci->session->data['asaas_recurring_form_nonce']['client_id'] = 8; }
            if ($reason === 'wrong_contact') { $ci->session->data['asaas_recurring_form_nonce']['contact_id'] = 12; }
            if ($reason === 'malformed_session') { $ci->session->data['asaas_recurring_form_nonce'] = 'invalid'; }
            $controller->index('hash-recurring-controller');
            rcAssert(($GLOBALS['rc_errors'][0][0] ?? null) === 403, 'Invalid form nonce must fail closed with 403.');
            rcAssert($service->pages === 0 && $service->enrollments === [] && $service->revocations === [], 'Invalid nonce cannot reach service or network.');
            rcAssert(!isset($ci->session->data['asaas_recurring_form_nonce']), 'Rejected form must consume its nonce and require fresh GET.');
            rcNoRetainedCredentials($controller);
        });
    }
    rcTest('independent CSRF rejects replayed ' . $operation . ' without a second financial operation', static function () use ($operation): void {
        [$controller, $service, $ci] = rcFixture();
        rcPost(['action' => $operation]); $controller->index('hash-recurring-controller');
        rcAssert(count($service->enrollments) + count($service->revocations) === 1, 'The first valid operation must run.');
        rcPost(['action' => $operation]); $controller->index('hash-recurring-controller');
        rcAssert(($GLOBALS['rc_errors'][0][0] ?? null) === 403 && count($service->enrollments) + count($service->revocations) === 1, 'Replayed nonce must not repeat enrollment or revocation.');
        rcNoRetainedCredentials($controller);
    });
}
rcTest('independent nonce remains mandatory when the native CSRF hash is disabled or empty', static function (): void {
    [$controller, $service, $ci] = rcFixture();
    $ci->security->hash = '';
    $controller->index('hash-recurring-controller');
    $nonce = $controller->viewData['recurring_csrf_nonce'];
    rcPost(['csrf_token' => '', 'recurringNonce' => $nonce]);
    $controller->index('hash-recurring-controller');
    rcAssert(count($service->enrollments) === 1, 'Valid independent session nonce must work even without the global CSRF hash.');
    rcPost(['csrf_token' => '', 'recurringNonce' => '']);
    $controller->index('hash-recurring-controller');
    rcAssert(($GLOBALS['rc_errors'][0][0] ?? null) === 403 && count($service->enrollments) === 1, 'Disabling global CSRF cannot bypass the recurring nonce.');
    rcNoRetainedCredentials($controller);
});
foreach (['', 'true', 'yes', ['1']] as $consent) {
    rcTest('missing or noncanonical consent cannot enroll ' . json_encode($consent), static function () use ($consent): void {
        [$controller, $service] = rcFixture(); rcPost(['recurringConsent' => $consent]);
        $controller->index('hash-recurring-controller');
        rcAssert($service->enrollments === [], 'Only the explicit checkbox value 1 is accepted.');
        rcAssert(isset($controller->session->flash['asaas_recurring_error']) && $GLOBALS['rc_redirects'][0][2] === 303, 'Validation feedback must use PRG.');
        rcNoRetainedCredentials($controller);
    });
}
rcTest('HTTP cannot tokenize and does not affect revocation eligibility', static function (): void {
    [$controller, $service] = rcFixture(); $GLOBALS['rc_https'] = false;
    rcPost(); $controller->index('hash-recurring-controller');
    rcAssert($service->enrollments === [] && str_contains($controller->session->flash['asaas_recurring_error'], 'HTTPS'), 'Non-TLS card entry must be denied.');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = $_REQUEST = [];
    $service->pageData['can_revoke'] = true;
    $controller->index('hash-recurring-controller');
    rcAssert(!$controller->viewData['recurring_page']['can_enroll'] && $controller->viewData['recurring_page']['can_revoke'], 'TLS validation must not remove local revocation.');
});
rcTest('paid invoice can revoke even while new enrollment and main gateway are disabled', static function (): void {
    [$controller, $service, $ci] = rcFixture();
    $ci->db->invoice->status = 2;
    $GLOBALS['rc_options']['paymentmethod_asaas_active'] = '0';
    rcPost(['action' => 'revoke']); $controller->index('hash-recurring-controller');
    rcAssert($service->revocations === [42] && $service->enrollments === [], 'Paid invoice must retain the ability to stop future charges.');
    rcAssert(isset($controller->session->flash['asaas_recurring_info']) && $GLOBALS['rc_redirects'][0][2] === 303, 'Revocation uses safe feedback and PRG.');
    rcNoRetainedCredentials($controller);
});
rcTest('unknown action and oversized form cannot initiate a financial operation', static function (): void {
    foreach (['action', 'size'] as $condition) {
        [$controller, $service] = rcFixture(); rcPost($condition === 'action' ? ['action' => 'capture'] : []);
        if ($condition === 'size') { $_SERVER['CONTENT_LENGTH'] = '16385'; }
        $controller->index('hash-recurring-controller');
        rcAssert($service->enrollments === [] && $service->revocations === [], 'Invalid action/size must not reach mutation.');
        rcNoRetainedCredentials($controller);
    }
});
rcTest('raw exceptions and accidental token-bearing validation cannot reach session or logs', static function (): void {
    foreach ([new RuntimeException('RAW_PROVIDER_ERROR 4111111111111111 token_fixture_private'),
        new InvalidArgumentException('cardToken=00112233-4455-6677-8899-aabbccddeeff')] as $error) {
        [$controller, $service] = rcFixture(); $service->enrollError = $error;
        rcPost(); $controller->index('hash-recurring-controller');
        rcAssert(isset($controller->session->flash['asaas_recurring_error']), 'Failure must have safe feedback.');
        rcAssert(!str_contains(json_encode($controller->session->flash), '00112233-4455-6677-8899-aabbccddeeff'), 'Opaque card token must not be flashed.');
        rcNoRetainedCredentials($controller);
    }
});
rcTest('query data is discarded and canonicalized before login or rendering', static function (): void {
    [$controller, $service] = rcFixture();
    $_GET = $_REQUEST = ['cardNumber' => '4111111111111111', 'return' => 'https://attacker.example.test'];
    $_SERVER['QUERY_STRING'] = 'cardNumber=4111111111111111&return=https://attacker.example.test';
    $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
    $controller->index('hash-recurring-controller');
    rcAssert($service->pages === 0 && $controller->viewName === null, 'A query-bearing URL must not render a sensitive form.');
    rcAssert($GLOBALS['rc_redirects'] === [[site_url('asaas/recurring/index/hash-recurring-controller'), 'location', 303]], 'Query values cannot influence canonical return URL.');
    rcAssert($_GET === [] && $_SERVER['QUERY_STRING'] === '' && !str_contains($_SERVER['REQUEST_URI'], '?'), 'Query credentials must not remain in request metadata.');
    rcNoRetainedCredentials($controller);
});
rcTest('unsupported methods and malformed or stale hashes are denied after scrubbing', static function (): void {
    foreach (['method', 'malformed', 'missing', 'stale'] as $condition) {
        [$controller, $service, $ci] = rcFixture(); rcPost();
        if ($condition === 'method') { $_SERVER['REQUEST_METHOD'] = 'PUT'; }
        if ($condition === 'stale') { $ci->invoices_model->hashOverride = 'another-hash'; }
        $hash = $condition === 'malformed' ? '../unsafe?cardNumber=4111111111111111' : ($condition === 'missing' ? 'unknown' : 'hash-recurring-controller');
        $controller->index($hash);
        rcAssert($service->pages === 0 && $service->enrollments === [] && $service->revocations === [], 'Invalid method/hash must not reach authorization.');
        rcAssert($condition === 'method' ? ($GLOBALS['rc_errors'][0][0] ?? null) === 405 : $GLOBALS['rc_not_found'] === 1, 'Expected method/not-found response.');
        rcNoRetainedCredentials($controller);
    }
});
rcTest('display allowlist removes credential fields and uncertain state disables alternate payment', static function (): void {
    [$controller, $service] = rcFixture();
    $service->pageData += ['creditCardToken' => 'token_fixture_private', 'creditCardNumber' => '1111', 'creditCardBrand' => 'VISA', 'creditCard' => ['number' => '4111111111111111']];
    $service->pageData['state'] = 'uncertain'; $service->pageData['attempt_state'] = 'ambiguous'; $service->pageData['can_revoke'] = true;
    $controller->index('hash-recurring-controller'); $page = $controller->viewData['recurring_page'];
    foreach (['creditCardToken', 'creditCardNumber', 'creditCardBrand', 'creditCard'] as $field) { rcAssert(!array_key_exists($field, $page), 'Unexpected backend secret field must not reach the view.'); }
    rcAssert(!$page['can_enroll'] && !$page['can_pay_now'] && $page['can_revoke'], 'Uncertain payment must block new payment while permitting future revocation.');
    rcNoRetainedCredentials($controller);
});
rcTest('missing quote or failed page state cannot enable enrollment', static function (): void {
    foreach (['quote', 'principal', 'consent_version', 'failure'] as $condition) {
        [$controller, $service] = rcFixture();
        if ($condition === 'failure') { $service->pageError = new RuntimeException('RAW_PROVIDER_ERROR token_fixture_private'); }
        else { $service->pageData[$condition] = ''; }
        $controller->index('hash-recurring-controller');
        rcAssert(!$controller->viewData['recurring_page']['can_enroll'], 'Incomplete pricing/terms/state must not enable a card form.');
        rcAssert($service->enrollments === [], 'Reading a failed state never captures.');
        rcNoRetainedCredentials($controller);
    }
});
rcTest('separate native gateway starts inactive and delegates credentials to the main Asaas settings', static function (): void {
    rcFixture(); unset($GLOBALS['rc_options']['paymentmethod_asaas_recurring_active']);
    $gateway = new Asaas_recurring_gateway();
    rcAssert($gateway->id === 'asaas_recurring' && str_contains($gateway->name, 'recorrente'), 'Gateway must have its own native identity/title.');
    rcAssert((string) $gateway->getSetting('active') === '0' && (string) $gateway->getSetting('default_selected') === '0', 'New recurring gateway cannot activate or preselect itself.');
    rcAssert($gateway->getSetting('currencies') === 'BRL', 'Recurring currency defaults to BRL.');
    $names = array_column($gateway->settings, 'name');
    rcAssert(count(array_filter($names, static fn ($v) => $v === 'active')) === 1 && count(array_filter($names, static fn ($v) => $v === 'label')) === 1, 'Native core controls must not be duplicated.');
    rcAssert(count(array_filter($names, static fn ($v) => $v === 'default_selected')) === 1, 'Native default_selected must be overridden without adding a duplicate field.');
    foreach (['api_key', 'api_key_sandbox', 'webhook_secret', 'cardToken'] as $name) { rcAssert(!in_array($name, $names, true), 'Gateway must not introduce another credential set.'); }
});
rcTest('selecting the new gateway only redirects to enrollment after reloading invoice identity', static function (): void {
    [$controller, $service, $ci] = rcFixture();
    $gateway = new Asaas_recurring_gateway();
    $gateway->process_payment(['invoice' => (object) ['id' => 42, 'hash' => 'hash-recurring-controller', 'clientid' => 999]]);
    rcAssert($GLOBALS['rc_redirects'][0][0] === site_url('asaas/recurring/index/hash-recurring-controller'), 'Gateway must route to separate enrollment, not standard Pix/card/boleto view.');
    rcAssert($service->enrollments === [] && $service->revocations === [], 'Gateway selection cannot use a stored card or authorize a mandate.');
});
rcTest('gateway denies disabled flags, non-BRL, missing mode and closed invoice', static function (): void {
    foreach (['main_disabled', 'recurring_disabled', 'currency', 'mode', 'paid', 'cancelled', 'draft'] as $condition) {
        [$controller, $service, $ci] = rcFixture();
        if ($condition === 'main_disabled') { $GLOBALS['rc_options']['paymentmethod_asaas_active'] = '0'; }
        if ($condition === 'recurring_disabled') { $GLOBALS['rc_options']['paymentmethod_asaas_recurring_active'] = '0'; }
        if ($condition === 'currency') { $ci->db->invoice->currency_name = 'USD'; }
        if ($condition === 'mode') { $ci->db->invoice->allowed_payment_modes = ['asaas']; }
        if (in_array($condition, ['paid', 'cancelled', 'draft'], true)) { $ci->db->invoice->status = ['paid' => 2, 'cancelled' => 5, 'draft' => 6][$condition]; }
        (new Asaas_recurring_gateway())->process_payment(['invoice' => clone $ci->db->invoice]);
        rcAssert($GLOBALS['rc_redirects'][0][0] === site_url('invoice/42/hash-recurring-controller'), 'Unavailable gateway must return to the native invoice: ' . $condition);
        rcAssert(count($GLOBALS['rc_alerts']) === 1 && $service->enrollments === [], 'Unavailable selection must explain and never capture.');
    }
});
rcTest('gateway rejects forged or absent invoice identity instead of constructing untrusted URLs', static function (): void {
    foreach ([[], ['invoice' => ['id' => 42]], ['invoice' => (object) ['id' => 42, 'hash' => '../invalid']],
        ['invoice' => (object) ['id' => 43, 'hash' => 'hash-recurring-controller']],
        ['invoice' => (object) ['id' => 42, 'hash' => 'wrong-hash']]] as $data) {
        rcFixture(); (new Asaas_recurring_gateway())->process_payment($data);
        rcAssert($GLOBALS['rc_not_found'] === 1 && $GLOBALS['rc_redirects'] === [], 'Forged identity must not redirect or mutate.');
    }
});

echo sprintf('Recurring controller/gateway: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['rc_scenarios'], $GLOBALS['rc_assertions'], $GLOBALS['rc_failures']);
exit($GLOBALS['rc_failures'] === 0 ? 0 : 1);
