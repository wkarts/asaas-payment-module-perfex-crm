<?php

declare(strict_types=1);

/**
 * Stateful persistence contract for the real module hooks and lifecycle helper.
 * The fixture reproduces the relevant Perfex hook order; it is not the Perfex core.
 * No external requests are made. Run: php tests/invoice-lifecycle-smoke.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('CI') && !extension_loaded('mbstring')) {
    fwrite(STDERR, "A extensão mbstring real é obrigatória no CI; cenários de lifecycle não podem ser omitidos.\n");
    exit(1);
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('LIFECYCLE_TEMP_ROOT', sys_get_temp_dir() . '/asaas-lifecycle-' . bin2hex(random_bytes(8)));
mkdir(LIFECYCLE_TEMP_ROOT . '/logs', 0700, true);
define('BASEPATH', __DIR__);
define('FCPATH', dirname(__DIR__) . '/');
define('APPPATH', LIFECYCLE_TEMP_ROOT . '/');
$previousErrorLog = ini_get('error_log');
ini_set('error_log', LIFECYCLE_TEMP_ROOT . '/php-error.log');
require_once __DIR__ . '/fixtures/invoice-lifecycle-fixture.php';
$GLOBALS['lifecycle_trace'] = [];
$GLOBALS['lifecycle_alerts'] = [];
$GLOBALS['lifecycle_logs'] = [];
$GLOBALS['lifecycle_options'] = [];
$GLOBALS['lifecycle_ci'] = new LifecycleCi();
$GLOBALS['lifecycle_hooks'] = new LifecycleHooks();
require_once dirname(__DIR__) . '/asaas.php';
require_once dirname(__DIR__) . '/helpers/asaas_lifecycle_helper.php';

function lifecycleReset(bool $withInvoice = true, bool $withCharge = true): LifecycleCi
{
    lifecycleResetRequestState();
    $GLOBALS['lifecycle_ci'] = $ci = new LifecycleCi();
    $GLOBALS['lifecycle_options'] = [];
    foreach ([
        'billet_only' => '1', 'update_charge' => '1', 'delete_charge' => '0',
        'sync_invoice_lifecycle' => '1', 'manual_payment_sync' => '1', 'manual_payment_receive_in_cash' => '1',
        'discount_type' => '1', 'discount_value' => '0', 'discount_days' => '0',
        'fine_value' => '0', 'interest_value' => '0', 'description' => 'Fatura {invoice_number}',
        'file_log_enabled' => '0', 'api_key' => 'fixture-api-key-never-real',
    ] as $name => $value) { $GLOBALS['lifecycle_options']['paymentmethod_asaas_' . $name] = $value; }
    $ci->db->tables['tblclients'][7] = ['userid' => 7, 'company' => 'Fixture company', 'vat' => '12345678901',
        'zip' => '12345000', 'address' => 'Fixture street', 'numero' => '7', 'complemento' => '', 'phonenumber' => '11999999999'];
    $ci->db->tables['tblcontacts'] = [
        1 => ['id' => 1, 'userid' => 7, 'is_primary' => 1, 'email' => 'primary@example.invalid'],
        2 => ['id' => 2, 'userid' => 7, 'is_primary' => 0, 'email' => 'secondary@example.invalid'],
    ];
    if ($withInvoice) {
        $ci->db->tables['tblinvoices'][42] = lifecycleInvoiceData(['id' => 42, 'hash' => 'lifecycle-invoice-42']);
        $ci->db->tables['tblitemable'][1] = ['id' => 1, 'rel_id' => 42, 'rel_type' => 'invoice', 'description' => 'Original item', 'qty' => 1, 'rate' => '100.00'];
        $ci->db->tables['tblitem_tax'][1] = ['id' => 1, 'itemid' => 1, 'rel_id' => 42, 'rel_type' => 'invoice', 'taxrate' => '0.00'];
        $ci->db->tables['tblcustomfieldsvalues'][1] = ['id' => 1, 'relid' => 42, 'fieldto' => 'invoice', 'value' => 'Original custom field'];
        $ci->db->tables['tbltaggables'][1] = ['id' => 1, 'rel_id' => 42, 'rel_type' => 'invoice', 'tag_id' => 9];
        if ($withCharge) {
            $charge = ['id' => 'pay_original', 'status' => 'PENDING', 'deleted' => false, 'billingType' => 'BOLETO',
                'value' => '100.00', 'dueDate' => '2026-12-15', 'externalReference' => 'lifecycle-invoice-42',
                'description' => 'Original description', 'discount' => ['type' => 'FIXED', 'value' => 0, 'dueDateLimitDays' => 0]];
            $ci->asaas_gateway->remote['pay_original'] = $charge;
            asaas_lifecycle_store_payment(42, $charge);
        }
    }
    $GLOBALS['lifecycle_trace'] = []; $GLOBALS['lifecycle_logs'] = []; $GLOBALS['lifecycle_alerts'] = [];
    $ci->asaas_gateway->calls = [];
    return $ci;
}

/** Model distinct PHP requests while executing the real ordered shutdown coordinator. */
function lifecycleResetRequestState(): void
{
    asaas_receipt_lock_shutdown();
    $state = &asaas_lifecycle_state(); $state = [];
    $atomic = &asaas_atomic_payment_state(); $atomic = [];
    $locks = &asaas_receipt_lock_state(); $locks = [];
}

function lifecycleEnableAtomicWriter(LifecycleCi $ci): void
{
    if (!class_exists('app\\services\\InvoicePaymentWriter', false)) {
        class_alias(LifecycleInvoicePaymentWriterSentinel::class, 'app\\services\\InvoicePaymentWriter');
    }
    $ci->payments_model = new LifecycleAtomicPaymentsModel();
    $ci->db->dbdriver = 'mysqli';
}

function lifecycleInvoiceData(array $overrides = []): array
{
    return array_replace(['clientid' => 7, 'prefix' => 'INV-', 'number' => 42, 'hash' => 'lifecycle-new-invoice',
        'duedate' => '2026-12-15', 'date' => '2026-11-05', 'total' => '100.00', 'subtotal' => '100.00',
        'status' => 1, 'sent' => 0, 'adminnote' => '', 'allowed_payment_modes' => serialize(['asaas'])], $overrides);
}

/** Simulate the end of one PHP request, including the actual transaction shutdown guard. */
function lifecycleRequest(string $method, array $post, ?int $id, callable $controller, bool $admin = true)
{
    $state = &asaas_lifecycle_state();
    if (isset($state['transaction']) && $state['transaction']->isActive()) {
        throw new LogicException('A previous request left its transaction active.');
    }
    lifecycleResetRequestState();
    $ci = get_instance(); $ci->router->class = $admin ? 'invoices' : 'callback'; $ci->router->method = $method;
    $ci->input->posted = $post; $ci->input->verb = $post === [] ? 'get' : 'post';
    $ci->uri->segments = [1 => 'admin', 2 => $ci->router->class, 3 => $method];
    if ($id !== null) { $ci->uri->segments[4] = (string) $id; }
    try {
        if ($admin) {
            hooks()->do_action('pre_admin_init');
            hooks()->do_action('admin_init');
        }
        return $controller();
    } finally {
        asaas_receipt_lock_shutdown();
    }
}

function lifecycleCreate(array $overrides = [], bool $admin = true)
{
    $data = lifecycleInvoiceData($overrides);
    return lifecycleRequest('invoice', $data, null, static function () use ($data) {
        $db = get_instance()->db;
        $hook = hooks()->apply_filters('before_invoice_added', ['data' => $data, 'items' => [['description' => 'Created item', 'rate' => $data['total'], 'qty' => 1]]]);
        if (!$db->insert('tblinvoices', $hook['data'])) { return false; }
        $id = $db->insert_id();
        foreach ($hook['items'] as $item) {
            if (!$db->insert('tblitemable', $item + ['rel_id' => $id, 'rel_type' => 'invoice'])) { return false; }
            $itemId = $db->insert_id();
            if (!$db->insert('tblitem_tax', ['itemid' => $itemId, 'rel_id' => $id, 'rel_type' => 'invoice', 'taxrate' => '0.00'])) { return false; }
        }
        if (!$db->insert('tblcustomfieldsvalues', ['relid' => $id, 'fieldto' => 'invoice', 'value' => 'Created field'])) { return false; }
        if (!$db->insert('tbltaggables', ['rel_id' => $id, 'rel_type' => 'invoice', 'tag_id' => 10])) { return false; }
        hooks()->do_action('after_invoice_added', $id);
        lifecycleTrace('native.email', ['id' => $id]);
        return $id;
    }, $admin);
}

function lifecycleUpdate(array $changes, bool $admin = true)
{
    return lifecycleRequest('invoice', $changes, 42, static function () use ($changes) {
        $db = get_instance()->db;
        // Perfex mutates these before its before_update_invoice filter.
        if (!$db->where('relid', 42)->update('tblcustomfieldsvalues', ['value' => 'Edited field'])) { return false; }
        if (!$db->where('rel_id', 42)->update('tbltaggables', ['tag_id' => 10])) { return false; }
        $hook = hooks()->apply_filters('before_update_invoice', ['id' => 42, 'data' => $changes,
            'items' => [['itemid' => 1, 'description' => 'Edited item']], 'new_items' => [], 'removed_items' => [],
            'custom_fields' => [], 'billed_tasks' => [], 'billed_expenses' => [], 'invoices_to_merge' => [], 'tags' => '', 'cancel_merged_invoices' => false], 42);
        if (!$db->where('id', 42)->update('tblinvoices', $hook['data'])) { return false; }
        if (!$db->where('rel_id', 42)->update('tblitemable', ['description' => 'Edited item', 'rate' => $hook['data']['total'] ?? '100.00'])) { return false; }
        if (!$db->where('rel_id', 42)->update('tblitem_tax', ['taxrate' => '1.00'])) { return false; }
        lifecycleTrace('native.email', ['id' => 42]);
        hooks()->do_action('invoice_updated', $hook + ['updated' => true]);
        return true;
    }, $admin);
}

function lifecycleDelete()
{
    return lifecycleRequest('delete', [], 42, static function () {
        $db = get_instance()->db;
        hooks()->do_action('before_invoice_deleted', 42);
        if (!$db->where('id', 42)->delete('tblinvoices')) { return false; }
        if (!$db->where('rel_id', 42)->delete('tblitemable')) { return false; }
        if (!$db->where('rel_id', 42)->delete('tblitem_tax')) { return false; }
        hooks()->do_action('after_invoice_deleted', 42);
        return true;
    });
}

function lifecycleCancel(bool $reactivate = false, bool $admin = true)
{
    return lifecycleRequest($reactivate ? 'unmark_as_cancelled' : 'mark_as_cancelled', [], 42, static function () use ($reactivate) {
        $changes = $reactivate ? ['status' => 1] : ['status' => 5, 'sent' => 1];
        if (!get_instance()->db->where('id', 42)->update('tblinvoices', $changes)) { return false; }
        hooks()->do_action($reactivate ? 'invoice_unmarked_as_cancelled' : 'invoice_marked_as_cancelled', 42);
        return true;
    }, $admin);
}

function lifecyclePersistPayment(array $data)
{
    $ci = get_instance(); $db = $ci->db;
    $filtered = hooks()->apply_filters('before_payment_recorded', $data);
    if ($ci->payments_model instanceof LifecycleAtomicPaymentsModel) {
        $id = $ci->payments_model->persist($filtered);
        if ($id === false) { return false; }
    } else {
        if (!$db->insert('tblinvoicepaymentrecords', $filtered)) { return false; }
        $id = $db->insert_id();
        update_invoice_status($data['invoiceid']);
    }
    hooks()->do_action('after_payment_added', $id);
    return $id;
}

function lifecyclePayment(string $amount)
{
    $data = ['invoiceid' => 42, 'amount' => $amount, 'date' => '2026-11-05', 'paymentmode' => '2', 'transactionid' => ''];
    return lifecycleRequest('record_payment', $data, 42, static fn () => lifecyclePersistPayment($data));
}

function lifecycleBusinessSnapshot(LifecycleCi $ci): array
{
    $tables = $ci->db->tables; unset($tables['tblasaas_logs']); return $tables;
}
function lifecycleAssertRevertedCash(LifecycleCi $ci, array $before): void
{
    $after = lifecycleBusinessSnapshot($ci);
    unset($before['tblasaas_payments_map'], $after['tblasaas_payments_map']);
    lifecycleSame($before, $after, 'Invoice, receipts, items, contacts and other native rows remain unchanged.');
    $map = asaas_lifecycle_map(42); $metadata = asaas_lifecycle_cached_payment($map)['_asaas_lifecycle'] ?? [];
    lifecycleSame('pay_original', $map['asaas_payment_id'] ?? null, 'The same remote charge remains linked.');
    lifecycleSame('active', $metadata['state'] ?? null, 'Undone receipt restores the active map.');
    lifecycleSame(true, $metadata['cash_receipt_reverted'] ?? null, 'Map records the undo to suppress stale cash webhook events.');
}
function lifecycleRemoteCalls(LifecycleCi $ci, string $operation): array
{
    return array_values(array_filter($ci->asaas_gateway->calls, static fn (array $call): bool => $call['operation'] === $operation));
}
function lifecycleTracePosition(string $operation, ?string $table = null): int
{
    foreach ($GLOBALS['lifecycle_trace'] as $index => $row) {
        if ($row['operation'] === $operation && ($table === null || ($row['details']['table'] ?? null) === $table)) { return $index; }
    }
    return -1;
}
function lifecycleQueryPosition(string $contains): int
{
    foreach ($GLOBALS['lifecycle_trace'] as $index => $row) {
        if ($row['operation'] === 'db.query' && str_contains($row['details']['sql'], $contains)) { return $index; }
    }
    return -1;
}
function lifecycleCheck(bool $condition, string $message): void
{
    ++$GLOBALS['lifecycle_assertions'];
    if (!$condition) { throw new LogicException($message); }
}
function lifecycleSame($expected, $actual, string $message): void { lifecycleCheck($expected === $actual, $message); }
function lifecycleReject(callable $operation, ?int $code = null): AsaasInvoiceSyncException
{
    try { $operation(); }
    catch (AsaasInvoiceSyncException $error) {
        lifecycleCheck(str_contains($error->getMessage(), 'bloqueado'), 'CLI rejection must explain the blocked operation.');
        if ($code !== null) { lifecycleSame($code, $error->getCode(), 'Remote HTTP status survives rejection.'); }
        return $error;
    }
    throw new LogicException('Expected AsaasInvoiceSyncException, but writer returned normally.');
}

$tests = [];
function lifecycleTest(string $name, callable $callback, bool $needsMbstring = false): void
{
    $GLOBALS['tests'][] = ['name' => $name, 'callback' => $callback, 'mbstring' => $needsMbstring];
}

lifecycleTest('module registers pre-save and lifecycle hooks exactly once', static function (): void {
    foreach (['before_invoice_added', 'before_update_invoice', 'before_payment_recorded'] as $name) {
        lifecycleCheck(isset(hooks()->filters[$name]), 'Missing required filter: ' . $name);
        lifecycleSame(1, array_sum(array_map('count', hooks()->filters[$name])), 'Filter must not dispatch twice: ' . $name);
    }
    foreach (['after_invoice_added', 'invoice_updated', 'before_invoice_deleted', 'after_invoice_deleted',
        'invoice_marked_as_cancelled', 'invoice_unmarked_as_cancelled', 'after_payment_added'] as $name) {
        lifecycleCheck(isset(hooks()->actions[$name]), 'Missing required action: ' . $name);
        lifecycleSame(1, array_sum(array_map('count', hooks()->actions[$name])), 'Action must not dispatch twice: ' . $name);
    }
});

foreach (['4.99', '0.00', '-1.00'] as $amount) {
    lifecycleTest('creation rejects amount ' . $amount . ' before invoice/items/customer writes', static function () use ($amount): void {
        $ci = lifecycleReset(false); $before = lifecycleBusinessSnapshot($ci);
        lifecycleReject(static fn () => lifecycleCreate(['total' => $amount]));
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Rejected invoice must leave all business tables unchanged.');
        lifecycleSame(-1, lifecycleTracePosition('db.insert', 'tblinvoices'), 'Validation must precede invoice INSERT.');
        lifecycleSame([], $ci->asaas_gateway->calls, 'Invalid amount must not trigger remote operations.');
    });
}
foreach ([['FIXED', '5.01'], ['PERCENTAGE', '50.10']] as [$type, $discount]) {
    lifecycleTest('creation rejects net amount below five after ' . $type . ' discount', static function () use ($type, $discount): void {
        $ci = lifecycleReset(false);
        $GLOBALS['lifecycle_options']['paymentmethod_asaas_discount_type'] = $type === 'FIXED' ? '1' : '0';
        $GLOBALS['lifecycle_options']['paymentmethod_asaas_discount_value'] = $discount;
        lifecycleReject(static fn () => lifecycleCreate(['total' => '10.00']));
        lifecycleSame([], $ci->db->tables['tblinvoices'], 'Discounted invalid invoice must not persist.');
        lifecycleSame([], $ci->asaas_gateway->calls, 'No remote charge for invalid net amount.');
    });
}
foreach (['5.00', '5.01'] as $amount) {
    lifecycleTest('creation commits exact threshold ' . $amount . ' only after remote success', static function () use ($amount): void {
        $ci = lifecycleReset(false); $contacts = $ci->db->tables['tblcontacts'];
        $id = lifecycleCreate(['total' => $amount]);
        lifecycleCheck(is_int($id) && isset($ci->db->tables['tblinvoices'][$id]), 'Valid invoice is persisted.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'create')), 'Create exactly one charge.');
        lifecycleCheck(lifecycleTracePosition('db.begin') < lifecycleTracePosition('db.insert', 'tblinvoices'), 'Start before native INSERT.');
        lifecycleCheck(lifecycleTracePosition('db.insert', 'tblitemable') < lifecycleTracePosition('remote.create'), 'Creation HTTP sees invoice and items inside transaction.');
        lifecycleCheck(lifecycleTracePosition('remote.create') < lifecycleTracePosition('db.commit'), 'Commit only after API confirmation.');
        lifecycleCheck(lifecycleTracePosition('db.commit') < lifecycleTracePosition('native.email'), 'Creation email follows successful commit.');
        lifecycleSame(0, $ci->db->_trans_depth, 'No transaction leaks.');
        lifecycleSame($contacts, $ci->db->tables['tblcontacts'], 'Contact data is unchanged.');
    });
}

foreach ([400, 401, 422, 500] as $status) {
    lifecycleTest('creation remote HTTP ' . $status . ' rolls back header items fields tags', static function () use ($status): void {
        $ci = lifecycleReset(false); $before = lifecycleBusinessSnapshot($ci);
        $ci->asaas_gateway->responses['create'][] = new RuntimeException('Valor ou credencial recusados pela API.', $status);
        lifecycleReject(static fn () => lifecycleCreate(), $status);
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'API rejection leaves no invoice or dependent rows.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'create')), 'Never retry POST automatically.');
        lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'Do not compensate an unconfirmed remote create.');
        lifecycleSame(-1, lifecycleTracePosition('native.email'), 'No creation email for rejected invoice.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Rollback closes transaction.');
    });
}

lifecycleTest('creation response without id is rejected and not retried', static function (): void {
    $ci = lifecycleReset(false); $ci->asaas_gateway->responses['create'][] = [];
    lifecycleReject(static fn () => lifecycleCreate());
    lifecycleSame([], $ci->db->tables['tblinvoices'], 'Ambiguous response cannot persist invoice.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'create')), 'No retry after ambiguous response.');
});

foreach (['insert:tblinvoices', 'insert:tblitemable', 'insert:tblitem_tax'] as $fault) {
    lifecycleTest('creation DB refusal at ' . $fault . ' rolls back at request shutdown', static function () use ($fault): void {
        $ci = lifecycleReset(false); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults[$fault] = true;
        lifecycleSame(false, lifecycleCreate(), 'Native writer reports storage refusal.');
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Unfinished transaction rolls back partial local writes.');
        lifecycleSame([], lifecycleRemoteCalls($ci, 'create'), 'Remote create must not run before native insert/items succeed.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Shutdown ends transaction.');
    });
}
foreach (['insert:tblasaas_payments_map', 'commit'] as $fault) {
    lifecycleTest('accepted creation compensates once when ' . $fault . ' fails', static function () use ($fault): void {
        $ci = lifecycleReset(false); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults[$fault] = true;
        lifecycleReject(static fn () => lifecycleCreate());
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Local data reverts after post-HTTP failure.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'create')), 'Exactly one original create.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'Exactly one compensation delete.');
        lifecycleCheck(current($ci->asaas_gateway->remote)['deleted'] === true, 'Accepted orphan charge is removed.');
        lifecycleCheck(lifecycleTracePosition('db.rollback') < lifecycleTracePosition('remote.delete'), 'Rollback local data before compensating remotely.');
        asaas_lifecycle_transaction()->shutdown();
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'Repeated shutdown cannot compensate twice.');
    });
}

lifecycleTest('creation without Asaas accepts a small invoice and leaves contacts unchanged', static function (): void {
    $ci = lifecycleReset(false); $contacts = $ci->db->tables['tblcontacts'];
    $id = lifecycleCreate(['total' => '1.00', 'allowed_payment_modes' => serialize(['bank_transfer'])]);
    lifecycleCheck(isset($ci->db->tables['tblinvoices'][$id]), 'Non-Asaas invoice must persist normally.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'Unrelated payment method makes no Asaas calls.');
    lifecycleSame($contacts, $ci->db->tables['tblcontacts'], 'No contact mutations.');
});

lifecycleTest('editing rejects minimum and reverts native early fields tags', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    lifecycleReject(static fn () => lifecycleUpdate(['total' => '4.99']));
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Early custom field/tag writes must also roll back.');
    lifecycleSame(-1, lifecycleTracePosition('db.update', 'tblinvoices'), 'No invoice update after failed validation.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'Minimum validation avoids remote operations.');
});

lifecycleTest('editing sends API before invoice write and post-hook does not resend', static function (): void {
    $ci = lifecycleReset(); $contacts = $ci->db->tables['tblcontacts'];
    lifecycleSame(true, lifecycleUpdate(['total' => '120.00', 'duedate' => '2026-12-20']), 'Edit succeeds.');
    lifecycleSame('120.00', $ci->db->tables['tblinvoices'][42]['total'], 'Edited total persists.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'update')), 'Exactly one remote update across both hooks.');
    lifecycleCheck(lifecycleTracePosition('remote.update') < lifecycleTracePosition('db.update', 'tblinvoices'), 'API validates before invoice write.');
    lifecycleCheck(lifecycleTracePosition('remote.update') < lifecycleTracePosition('native.email'), 'API validation precedes native email.');
    lifecycleSame($contacts, $ci->db->tables['tblcontacts'], 'Primary/secondary contact rows remain unchanged.');
    $customerCalls = array_values(array_filter($GLOBALS['lifecycle_trace'], static fn (array $row): bool => $row['operation'] === 'gateway.customer'));
    lifecycleSame('primary@example.invalid', $customerCalls[0]['details']['client']['email'] ?? null, 'Keep the primary contact used by existing update flow.');
}, true);

lifecycleTest('editing remote rejection preserves invoice items tags custom fields', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['update'][] = new RuntimeException('Valor mínimo da cobrança recusado.', 422);
    lifecycleReject(static fn () => lifecycleUpdate(['total' => '120.00']), 422);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'No local mutation survives rejected update.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'update')), 'No repeated update.');
    lifecycleSame(-1, lifecycleTracePosition('native.email'), 'No email after failed preflight.');
}, true);

foreach (['update:tblinvoices', 'update:tblitemable', 'update:tblitem_tax'] as $fault) {
    lifecycleTest('editing compensates remote update after DB failure at ' . $fault, static function () use ($fault): void {
        $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults[$fault] = true;
        lifecycleSame(false, lifecycleUpdate(['total' => '120.00']), 'Native edit reports storage refusal.');
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Local edit must revert.');
        lifecycleSame(2, count(lifecycleRemoteCalls($ci, 'update')), 'One update and one restoration, no create.');
        lifecycleSame('100.00', $ci->asaas_gateway->remote['pay_original']['value'], 'Remote amount restored.');
        lifecycleSame([], lifecycleRemoteCalls($ci, 'create'), 'Compensation does not create replacement charge.');
    }, true);
}

lifecycleTest('delete with sync enabled removes charge even with legacy delete option off', static function (): void {
    $ci = lifecycleReset();
    lifecycleSame(true, lifecycleDelete(), 'Deletion succeeds.');
    lifecycleCheck(!isset($ci->db->tables['tblinvoices'][42]), 'Invoice deleted locally.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'Lifecycle option drives one remote removal.');
    lifecycleCheck($ci->asaas_gateway->remote['pay_original']['deleted'], 'Remote charge deleted.');
    lifecycleCheck(lifecycleTracePosition('remote.delete') < lifecycleTracePosition('db.delete', 'tblinvoices'), 'Remote refusal can block native invoice deletion.');
});

lifecycleTest('delete API failure preserves invoice and dependent rows', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['delete'][] = new RuntimeException('Remoção recusada.', 400);
    lifecycleReject(static fn () => lifecycleDelete(), 400);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Remote deletion failure leaves native invoice intact.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'restore'), 'No restoration after unconfirmed removal.');
});

lifecycleTest('delete local failure restores removed charge', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults['delete:tblinvoices'] = true;
    lifecycleSame(false, lifecycleDelete(), 'Native deletion refuses failed storage.');
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Invoice and map restored locally.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'restore')), 'Restore remote charge once.');
    lifecycleSame(false, $ci->asaas_gateway->remote['pay_original']['deleted'], 'Charge remains active.');
});

lifecycleTest('cancel is idempotent across repeated requests', static function (): void {
    $ci = lifecycleReset();
    lifecycleSame(true, lifecycleCancel(), 'First cancellation succeeds.');
    lifecycleSame(true, lifecycleCancel(), 'Repeated callback converges.');
    lifecycleSame(5, $ci->db->tables['tblinvoices'][42]['status'], 'Invoice remains cancelled.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'No second remote delete.');
    lifecycleSame('cancelled', asaas_lifecycle_cached_payment(asaas_lifecycle_map(42))['_asaas_lifecycle']['state'] ?? null, 'Map keeps cancellation state.');
});

lifecycleTest('cancel API failure reverts status changed before native post-hook', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['delete'][] = new RuntimeException('Remoção recusada.', 422);
    lifecycleReject(static fn () => lifecycleCancel(), 422);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Early admin transaction protects post-only cancellation hook.');
});

lifecycleTest('cancel refuses a charge belonging to another invoice', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['list'][] = [['id' => 'pay_unrelated', 'externalReference' => 'another-invoice']];
    lifecycleReject(static fn () => lifecycleCancel());
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Unrelated reference cannot alter invoice state.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'Never delete another invoice charge.');
});

lifecycleTest('reactivation creates one new charge preserves hash and retires previous id', static function (): void {
    $ci = lifecycleReset(); lifecycleCancel();
    lifecycleSame(true, lifecycleCancel(true), 'Reactivation succeeds.');
    lifecycleSame(true, lifecycleCancel(true), 'Repeated reactivation converges.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'create')), 'Only one replacement charge.');
    $created = lifecycleRemoteCalls($ci, 'create')[0]['arguments']['payload'];
    lifecycleSame('lifecycle-invoice-42', $created['externalReference'], 'Reactivation preserves invoice hash.');
    $map = asaas_lifecycle_map(42); $metadata = asaas_lifecycle_cached_payment($map)['_asaas_lifecycle'];
    lifecycleSame('active', $metadata['state'], 'New charge becomes active mapping.');
    lifecycleCheck(in_array('pay_original', $metadata['retired_payment_ids'], true), 'Previous charge id retained as retired.');
    lifecycleCheck(asaas_lifecycle_should_ignore_payment(42, 'pay_original'), 'Old charge cannot create a new local receipt.');
    lifecycleSame(false, asaas_lifecycle_should_ignore_payment(42, $map['asaas_payment_id']), 'Current active charge remains processable.');
}, true);

lifecycleTest('reactivation API failure keeps invoice cancelled and map retired', static function (): void {
    $ci = lifecycleReset(); lifecycleCancel(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['create'][] = new RuntimeException('Recriação recusada.', 422);
    lifecycleReject(static fn () => lifecycleCancel(true), 422);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Failed reactivation restores cancelled snapshot.');
}, true);

lifecycleTest('manual partial payment keeps remote charge open', static function (): void {
    $ci = lifecycleReset();
    lifecycleCheck(is_int(lifecyclePayment('20.00')), 'Partial receipt persists.');
    lifecycleSame(80.0, get_invoice_total_left_to_pay(42), 'Remaining balance preserved.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'cash'), 'Partial receipt must not mark entire remote charge paid.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'Partial receipt must not remove open balance.');
    lifecycleSame('PENDING', $ci->asaas_gateway->remote['pay_original']['status'], 'Remote charge stays pending.');
});

lifecycleTest('manual final receipt sends accumulated cash excluding credit notes', static function (): void {
    $ci = lifecycleReset();
    $ci->db->tables['tblinvoicepaymentrecords'][7] = ['id' => 7, 'invoiceid' => 42, 'amount' => '30.00', 'date' => '2026-11-01', 'paymentmode' => '2'];
    $ci->db->tables['tblcredits'][1] = ['id' => 1, 'invoice_id' => 42, 'amount' => '20.00'];
    lifecycleCheck(is_int(lifecyclePayment('50.00')), 'Final receipt persists.');
    $cash = lifecycleRemoteCalls($ci, 'cash');
    lifecycleSame(1, count($cash), 'One remote cash operation.');
    lifecycleSame(80.0, (float) $cash[0]['arguments']['value'], 'Only received cash, not credits, is reported.');
    lifecycleSame('2026-11-05', $cash[0]['arguments']['date'], 'Payment date is preserved.');
    lifecycleSame(false, $cash[0]['arguments']['notify'], 'Manual cash notification is disabled.');
    lifecycleSame(0.0, get_invoice_total_left_to_pay(42), 'Invoice is fully settled locally.');
    lifecycleCheck(asaas_lifecycle_should_ignore_payment(42, 'pay_original'), 'Cash webhook echo cannot add another receipt.');
});

lifecycleTest('manual final delete mode removes charge without receiveInCash', static function (): void {
    $ci = lifecycleReset(); $GLOBALS['lifecycle_options']['paymentmethod_asaas_manual_payment_receive_in_cash'] = '0';
    lifecycleCheck(is_int(lifecyclePayment('100.00')), 'Final receipt persists.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'One removal for delete mode.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'cash'), 'Delete mode cannot call receiveInCash.');
    lifecycleCheck(asaas_lifecycle_should_ignore_payment(42, 'pay_original'), 'Retired manual charge cannot cause duplicate payment.');
});

lifecycleTest('manual delete mode preserves a charge already received remotely', static function (): void {
    $ci = lifecycleReset(); $GLOBALS['lifecycle_options']['paymentmethod_asaas_manual_payment_receive_in_cash'] = '0';
    $ci->asaas_gateway->remote['pay_original']['status'] = 'RECEIVED';
    lifecycleCheck(is_int(lifecyclePayment('100.00')), 'Native manual receipt still settles the invoice.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'A delayed callback cannot cause deletion of an already received charge.');
    lifecycleSame(false, $ci->asaas_gateway->remote['pay_original']['deleted'], 'Confirmed remote payment remains intact.');
    lifecycleSame(1, count($ci->db->tables['tblinvoicepaymentrecords']), 'Exactly one native receipt is recorded.');
});

lifecycleTest('manual API rejection prevents payment insertion and status change', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['cash'][] = new RuntimeException('Recebimento recusado.', 400);
    lifecycleReject(static fn () => lifecyclePayment('100.00'), 400);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'No local receipt for rejected remote settlement.');
    lifecycleSame(-1, lifecycleTracePosition('db.insert', 'tblinvoicepaymentrecords'), 'Remote refusal precedes native payment insertion.');
});

lifecycleTest('manual payment DB refusal undoes accepted receiveInCash', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults['insert:tblinvoicepaymentrecords'] = true;
    lifecycleSame(false, lifecyclePayment('100.00'), 'Native payment insertion fails.');
    lifecycleAssertRevertedCash($ci, $before);
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'undo_cash')), 'Undo confirmed cash operation once.');
    lifecycleSame('PENDING', $ci->asaas_gateway->remote['pay_original']['status'], 'Remote charge restored to unpaid.');
});

lifecycleTest('manual cash refuses multiple active charges without changing payment', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->remote['pay_second'] = array_replace($ci->asaas_gateway->remote['pay_original'], ['id' => 'pay_second']);
    lifecycleReject(static fn () => lifecyclePayment('100.00'));
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Ambiguous active charges cannot create local payment.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'cash'), 'No arbitrary cash settlement among multiple charges.');
});

lifecycleTest('remote webhook guard suppresses manual synchronization through real hooks', static function (): void {
    $ci = lifecycleReset();
    $id = lifecycleRequest('index', [], 42, static fn () => asaas_lifecycle_remote_payment(static fn () => lifecyclePersistPayment([
        'invoiceid' => 42, 'amount' => '100.00', 'date' => '2026-11-05', 'paymentmode' => 'Asaas', 'transactionid' => 'pay_original',
    ])), false);
    lifecycleCheck(is_int($id), 'Webhook-origin receipt is recorded locally.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'cash'), 'Webhook must not echo receiveInCash.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'Webhook must not echo delete.');
    lifecycleSame(0, asaas_lifecycle_state()['remote_payment_depth'] ?? null, 'Origin guard restored after success.');
});

lifecycleTest('remote origin guard restores in finally after nested exception', static function (): void {
    $ci = lifecycleReset();
    lifecycleRequest('index', [], 42, static function (): void {
        try {
            asaas_lifecycle_remote_payment(static fn () => asaas_lifecycle_remote_payment(static function (): void { throw new RuntimeException('Controlled callback failure.'); }));
        } catch (RuntimeException $error) { lifecycleSame('Controlled callback failure.', $error->getMessage(), 'Original exception preserved.'); }
        lifecycleSame(0, asaas_lifecycle_state()['remote_payment_depth'] ?? null, 'Nested guard unwinds to zero.');
        lifecycleCheck(is_int(lifecyclePersistPayment(['invoiceid' => 42, 'amount' => '100.00', 'date' => '2026-11-05', 'paymentmode' => '2'])), 'Subsequent manual payment in the same request is not suppressed.');
    }, false);
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'cash')), 'Manual synchronization still runs after guard exception.');
});

lifecycleTest('disabled manual synchronization preserves native payment without API', static function (): void {
    $ci = lifecycleReset(); $GLOBALS['lifecycle_options']['paymentmethod_asaas_manual_payment_sync'] = '0';
    lifecycleCheck(is_int(lifecyclePayment('100.00')), 'Native receipt remains available.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'Disabled option prevents all remote operations.');
});

lifecycleTest('outer transaction is protected by savepoint and not committed by module', static function (): void {
    $ci = lifecycleReset(false); $ci->db->trans_begin();
    $ci->db->insert('tblcustomfieldsvalues', ['relid' => 999, 'fieldto' => 'invoice', 'value' => 'Outer owner']);
    $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['create'][] = new RuntimeException('Rejeição controlada.', 422);
    lifecycleReject(static fn () => lifecycleCreate(), 422);
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Rollback to savepoint retains caller writes.');
    lifecycleSame(1, $ci->db->_trans_depth, 'External transaction remains open.');
    lifecycleSame(-1, lifecycleTracePosition('db.commit'), 'Module never commits external transaction.');
    $queries = array_column(array_column(array_values(array_filter($GLOBALS['lifecycle_trace'], static fn (array $row): bool => $row['operation'] === 'db.query')), 'details'), 'sql');
    lifecycleCheck((bool) array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'ROLLBACK TO SAVEPOINT ')), 'Real savepoint SQL is exercised.');
    $ci->db->trans_rollback();
});

lifecycleTest('failed remote compensation keeps local rollback and exposes payment id', static function (): void {
    $ci = lifecycleReset(false); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults['commit'] = true;
    $ci->asaas_gateway->responses['delete'][] = new RuntimeException('Compensação recusada pelo Asaas.', 500);
    lifecycleReject(static fn () => lifecycleCreate());
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Compensation failure cannot re-persist the rejected invoice.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'delete')), 'Failed compensation is attempted only once.');
    $logs = json_encode([$GLOBALS['lifecycle_logs'], $ci->db->tables['tblasaas_logs']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    lifecycleCheck(str_contains($logs, 'pay_lifecycle_1') && str_contains($logs, 'compensa'), 'Recovery diagnostic identifies the unresolved remote payment.');
    lifecycleCheck(!str_contains($logs, 'fixture-api-key-never-real'), 'Recovery logs must not expose credentials.');
    lifecycleSame(false, $ci->asaas_gateway->remote['pay_lifecycle_1']['deleted'], 'Fixture preserves the unresolved remote side effect for review.');
});

foreach ([1, 2, 3, 4] as $status) {
    lifecycleTest('cancel outside admin restores prior financial status ' . $status . ' after API refusal', static function () use ($status): void {
        $ci = lifecycleReset();
        $ci->db->tables['tblinvoices'][42]['status'] = $status;
        if ($status === 4) { $ci->db->tables['tblinvoices'][42]['duedate'] = '2020-01-01'; }
        if ($status === 2 || $status === 3) {
            $ci->db->tables['tblinvoicepaymentrecords'][7] = ['id' => 7, 'invoiceid' => 42, 'amount' => $status === 2 ? '100.00' : '20.00'];
        }
        $before = lifecycleBusinessSnapshot($ci);
        $ci->asaas_gateway->responses['delete'][] = new RuntimeException('Cancelamento recusado.', 422);
        lifecycleReject(static fn () => lifecycleCancel(false, false), 422);
        lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Fallback restores status and stored native invoice metadata.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Post-action fallback closes its owned transaction.');
    });
}

lifecycleTest('reactivation outside admin restores cancelled status after minimum rejection', static function (): void {
    $ci = lifecycleReset(); lifecycleCancel();
    $ci->db->tables['tblinvoices'][42]['total'] = '4.99';
    $before = lifecycleBusinessSnapshot($ci);
    lifecycleReject(static fn () => lifecycleCancel(true, false));
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Post-only reactivation restores cancellation on validation failure.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'create'), 'Invalid reactivation never creates a replacement charge.');
});

lifecycleTest('manual final delete mode compensates local payment failure', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $GLOBALS['lifecycle_options']['paymentmethod_asaas_manual_payment_receive_in_cash'] = '0';
    $ci->db->faults['insert:tblinvoicepaymentrecords'] = true;
    lifecycleSame(false, lifecyclePayment('100.00'), 'Failed native receipt cannot report success.');
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Payment and map are restored locally.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'restore')), 'Removed remote payment is restored once.');
    lifecycleSame(false, $ci->asaas_gateway->remote['pay_original']['deleted'], 'Remote charge remains collectible.');
});

foreach (['delete', 'cash'] as $operation) {
    lifecycleTest($operation . ' commit refusal is a typed rejection with compensation', static function () use ($operation): void {
        $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci); $ci->db->faults['commit'] = true;
        lifecycleReject(static fn () => $operation === 'delete' ? lifecycleDelete() : lifecyclePayment('100.00'));
        if ($operation === 'cash') { lifecycleAssertRevertedCash($ci, $before); }
        else { lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Commit refusal restores all local rows.'); }
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, $operation === 'delete' ? 'restore' : 'undo_cash')), 'Accepted remote operation is compensated once.');
    });
}

lifecycleTest('mapped payment fallback verifies invoice reference before deletion', static function (): void {
    $ci = lifecycleReset(); $before = lifecycleBusinessSnapshot($ci);
    $ci->asaas_gateway->responses['list'][] = [];
    $ci->asaas_gateway->remote['pay_original']['externalReference'] = 'different-invoice';
    lifecycleReject(static fn () => lifecycleCancel());
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Stale or incorrect map must not cancel the local invoice.');
    lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'get')), 'Fallback consults the mapped payment.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'delete'), 'Mapped payment for another invoice must never be deleted.');
});

foreach (['delete', 'cancel', 'payment'] as $operation) {
    lifecycleTest('non-Asaas ' . $operation . ' preserves native behavior without remote calls', static function () use ($operation): void {
        $ci = lifecycleReset(true, false);
        $ci->db->tables['tblinvoices'][42]['allowed_payment_modes'] = serialize(['bank_transfer']);
        $result = match ($operation) { 'delete' => lifecycleDelete(), 'cancel' => lifecycleCancel(), default => lifecyclePayment('100.00') };
        lifecycleCheck($result !== false, 'Unrelated payment method remains usable.');
        lifecycleSame([], $ci->asaas_gateway->calls, 'No remote side effects for unrelated invoices.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Admin guard still closes correctly.');
    });
}

foreach (['cancel', 'delete'] as $operation) {
    lifecycleTest('MySQL admin ' . $operation . ' holds named lock through remote action and local commit', static function () use ($operation): void {
        $ci = lifecycleReset(); $ci->db->dbdriver = 'mysqli';
        lifecycleSame(true, $operation === 'cancel' ? lifecycleCancel() : lifecycleDelete(), 'Admin action completes successfully.');
        $advisory = lifecycleQueryPosition('GET_LOCK('); $begin = lifecycleTracePosition('db.begin');
        $rowLock = lifecycleQueryPosition('FOR UPDATE'); $remote = lifecycleTracePosition('remote.delete');
        $commit = lifecycleTracePosition('db.commit'); $release = lifecycleQueryPosition('RELEASE_LOCK(');
        lifecycleCheck($advisory >= 0 && $advisory < $begin, 'Admin acquires the named lock before opening the transaction.');
        lifecycleCheck($begin < $rowLock && $rowLock >= 0, 'Invoice row lock is taken inside the owned transaction.');
        lifecycleCheck($rowLock < $remote && $remote >= 0, 'Remote removal starts only after both locks are held.');
        lifecycleCheck($remote < $commit && $commit >= 0, 'Native commit follows remote acceptance.');
        lifecycleCheck($commit < $release, 'Named lock remains held until local commit is confirmed.');
        $queries = array_values(array_filter($GLOBALS['lifecycle_trace'], static fn (array $row): bool => $row['operation'] === 'db.query'));
        lifecycleSame(1, count(array_filter($queries, static fn (array $row): bool => str_contains($row['details']['sql'], 'GET_LOCK('))), 'Admin and action hooks share one acquisition without reference leaks.');
        lifecycleSame(1, count(array_filter($queries, static fn (array $row): bool => str_contains($row['details']['sql'], 'RELEASE_LOCK('))), 'Successful action releases its owned lock exactly once.');
        lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Admin request leaves no held named lock.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Admin request leaves no open transaction.');
    });
}

lifecycleTest('MySQL contract locks invoice before manual remote settlement', static function (): void {
    $ci = lifecycleReset(); $ci->db->dbdriver = 'mysqli';
    lifecycleCheck(is_int(lifecyclePayment('100.00')), 'Settlement succeeds with MySQL transaction preflight.');
    $advisory = lifecycleQueryPosition('GET_LOCK('); $lock = lifecycleQueryPosition('FOR UPDATE');
    lifecycleCheck($advisory >= 0 && $advisory < lifecycleTracePosition('db.begin'), 'Named lock is acquired before the payment transaction starts.');
    lifecycleCheck($advisory < $lock, 'Named lock always precedes the invoice row lock.');
    lifecycleCheck($lock >= 0 && $lock < lifecycleTracePosition('remote.cash'), 'Acquire row lock before calculating/settling manual receipt.');
    foreach ($GLOBALS['lifecycle_trace'] as $index => $row) {
        if (in_array($row['operation'], ['native.invoice.read', 'native.balance.read'], true)
            && $row['details']['transaction_depth'] > 0) {
            lifecycleCheck($lock < $index, 'Lock precedes the first consistent invoice/balance read in the owned transaction.');
        }
    }
});

lifecycleTest('atomic manual receipt prepares remote operation and hands off at depth zero', static function (): void {
    $ci = lifecycleReset(); lifecycleEnableAtomicWriter($ci);
    lifecycleCheck(asaas_lifecycle_has_atomic_payment_writer(), 'Detect the private atomic method plus the fixture writer capability.');
    $id = lifecyclePayment('100.00');
    lifecycleCheck(is_int($id), 'The core-owned receipt transaction succeeds.');
    $cash = lifecycleTracePosition('remote.cash'); $entry = lifecycleTracePosition('native.atomic.enter');
    lifecycleSame(['prepared'], $GLOBALS['lifecycle_trace'][$cash]['details']['handoffs'], 'Capture the pre-insert state before accepting remote settlement.');
    lifecycleSame(['armed'], $GLOBALS['lifecycle_trace'][$entry]['details']['handoffs'], 'Transfer compensation ownership before entering the native writer.');
    lifecycleSame(0, $GLOBALS['lifecycle_trace'][$entry]['details']['transaction_depth'], 'The native writer receives no wrapping transaction.');
    lifecycleSame(1, $GLOBALS['lifecycle_trace'][$entry]['details']['owned_locks'], 'Named lock remains held across the handoff.');
    lifecycleCheck($cash < lifecycleTracePosition('db.commit') && lifecycleTracePosition('db.commit') < $entry, 'Remote acceptance and map commit precede native persistence.');
    lifecycleCheck(lifecycleQueryPosition('GET_LOCK(') < lifecycleTracePosition('db.begin'), 'Named lock precedes all owned transactions.');
    lifecycleCheck(lifecycleTracePosition('native.atomic.committed') < lifecycleQueryPosition('RELEASE_LOCK('), 'Release only after the core confirms its own commit.');
    lifecycleSame(['completed'], array_column(asaas_atomic_payment_state(), 'state'), 'The real after-hook confirms the matching durable receipt.');
    $marker = asaas_atomic_payment_map_marker(asaas_lifecycle_map(42));
    lifecycleSame('confirmed', $marker['state'] ?? null, 'Durable operation marker is confirmed.');
    lifecycleSame($id, $marker['payment_id'] ?? null, 'Marker identifies the exact native receipt.');
    lifecycleCheck(str_starts_with($ci->db->tables['tblinvoicepaymentrecords'][$id]['transactionid'], 'asaas-manual-'), 'Empty native reference receives a correlation id.');
    lifecycleSame(2, $ci->db->tables['tblinvoices'][42]['status'], 'Confirmed native receipt settles the invoice.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'undo_cash'), 'Confirmed native payment is never undone.');
    lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'No named lock survives the request.');
});

foreach (['cash', 'delete'] as $mode) {
    lifecycleTest('atomic ' . $mode . ' compensates after core receipt insertion fails', static function () use ($mode): void {
        $ci = lifecycleReset(); lifecycleEnableAtomicWriter($ci); $before = lifecycleBusinessSnapshot($ci);
        if ($mode === 'delete') { $GLOBALS['lifecycle_options']['paymentmethod_asaas_manual_payment_receive_in_cash'] = '0'; }
        $ci->db->faults['insert:tblinvoicepaymentrecords'] = true;
        lifecycleSame(false, lifecyclePayment('100.00'), 'Native writer reports storage refusal.');
        if ($mode === 'cash') { lifecycleAssertRevertedCash($ci, $before); }
        else { lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'Failed receipt restores the original invoice and map.'); }
        $compensation = $mode === 'cash' ? 'undo_cash' : 'restore';
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, $compensation)), 'Accepted remote operation is compensated exactly once.');
        lifecycleSame(['compensated'], array_column(asaas_atomic_payment_state(), 'state'), 'Handoff resolves the confirmed absence of a native receipt.');
        lifecycleCheck(lifecycleTracePosition('db.rollback') < lifecycleTracePosition('remote.' . $compensation), 'Core rollback precedes remote compensation.');
        lifecycleCheck(lifecycleTracePosition('remote.' . $compensation) < lifecycleQueryPosition('RELEASE_LOCK('), 'Named lock protects the entire rollback and compensation interval.');
        lifecycleSame(0, $ci->db->_trans_depth, 'Reconciliation closes its transaction.');
        lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Failed request releases its named lock.');
        asaas_receipt_lock_shutdown();
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, $compensation)), 'Repeated shutdown cannot duplicate compensation.');
    });
}

lifecycleTest('atomic durable receipt survives notification failure before after-hook', static function (): void {
    $ci = lifecycleReset(); lifecycleEnableAtomicWriter($ci); $ci->payments_model->throwAfterCommit = true;
    try {
        lifecyclePayment('100.00');
        throw new LogicException('Expected controlled notification failure.');
    } catch (RuntimeException $error) {
        lifecycleSame('Controlled notification failure after durable receipt.', $error->getMessage(), 'Preserve the original core notification exception.');
    }
    lifecycleSame(1, count($ci->db->tables['tblinvoicepaymentrecords']), 'The committed native receipt remains durable.');
    lifecycleSame(2, $ci->db->tables['tblinvoices'][42]['status'], 'Invoice stays paid after confirmed core commit.');
    lifecycleSame(['completed'], array_column(asaas_atomic_payment_state(), 'state'), 'Shutdown verifies the receipt even when the after-hook did not run.');
    lifecycleSame('confirmed', asaas_atomic_payment_map_marker(asaas_lifecycle_map(42))['state'] ?? null, 'Reconciliation confirms the durable handoff.');
    lifecycleSame([], lifecycleRemoteCalls($ci, 'undo_cash'), 'Notification failure cannot reverse a committed receipt.');
    lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Lock is released after shutdown reconciliation.');
});

lifecycleTest('atomic partial receipt keeps native writer unwrapped without remote settlement', static function (): void {
    $ci = lifecycleReset(); lifecycleEnableAtomicWriter($ci);
    $id = lifecyclePayment('20.00');
    lifecycleCheck(is_int($id), 'Partial receipt remains available with custom core.');
    $entry = lifecycleTracePosition('native.atomic.enter');
    lifecycleSame(0, $GLOBALS['lifecycle_trace'][$entry]['details']['transaction_depth'], 'Partial payment also enters core at depth zero.');
    lifecycleSame([], asaas_atomic_payment_state(), 'Partial receipt needs no remote compensation handoff.');
    lifecycleSame('', $ci->db->tables['tblinvoicepaymentrecords'][$id]['transactionid'], 'Partial receipt keeps its original reference.');
    lifecycleSame(80.0, get_invoice_total_left_to_pay(42), 'Partial payment leaves the expected remaining balance.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'No remote cash, deletion or lookup for partial receipt.');
    lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Partial completion releases the named lock.');
});

foreach ([false, true] as $atomic) {
    lifecycleTest(($atomic ? 'atomic' : 'native') . ' batch refuses a second pending receipt of the same invoice', static function () use ($atomic): void {
        $ci = lifecycleReset(); $ci->db->dbdriver = 'mysqli';
        if ($atomic) { lifecycleEnableAtomicWriter($ci); }
        $before = lifecycleBusinessSnapshot($ci);
        $data = ['invoiceid' => 42, 'amount' => '100.00', 'date' => '2026-11-05', 'paymentmode' => '2', 'transactionid' => ''];
        $error = lifecycleReject(static fn () => lifecycleRequest('record_payment', $data, 42, static function () use ($data): void {
            hooks()->apply_filters('before_payment_recorded', $data);
            hooks()->apply_filters('before_payment_recorded', $data);
        }));
        lifecycleCheck(str_contains($error->getMessage(), 'individualmente'), 'Explain that a pending receipt must finish before another one starts.');
        lifecycleAssertRevertedCash($ci, $before);
        lifecycleSame(-1, lifecycleTracePosition('db.insert', 'tblinvoicepaymentrecords'), 'Batch is stopped before inserting either native receipt.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'cash')), 'Only the first preflight reaches remote settlement.');
        lifecycleSame(1, count(lifecycleRemoteCalls($ci, 'undo_cash')), 'The first pending settlement is compensated once.');
        lifecycleCheck(lifecycleTracePosition('remote.undo_cash') < lifecycleQueryPosition('RELEASE_LOCK('), 'Batch rejection compensates while holding the named lock.');
        lifecycleSame([], asaas_receipt_lock_state()['locks'] ?? [], 'Rejected batch leaves no held lock.');
    });
}

lifecycleTest('non-transactional native table blocks persistence before remote charge', static function (): void {
    $ci = lifecycleReset(false); $ci->db->dbdriver = 'mysqli'; $ci->db->engines['tblitem_tax'] = 'MyISAM';
    $before = lifecycleBusinessSnapshot($ci);
    $error = lifecycleReject(static fn () => lifecycleCreate());
    lifecycleCheck(str_contains($error->getMessage(), 'InnoDB'), 'Explain missing rollback support.');
    lifecycleSame($before, lifecycleBusinessSnapshot($ci), 'No business writes on unsupported engine.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'No remote side effects without local rollback support.');
});

lifecycleTest('unrelated invoice does not require Asaas transactional table preflight', static function (): void {
    $ci = lifecycleReset(false); $ci->db->dbdriver = 'mysqli'; $ci->db->engines['tblitem_tax'] = 'MyISAM';
    $id = lifecycleCreate(['total' => '1.00', 'allowed_payment_modes' => serialize(['bank_transfer'])]);
    lifecycleCheck(isset($ci->db->tables['tblinvoices'][$id]), 'Non-Asaas native save is not blocked by module storage constraints.');
    lifecycleSame(-1, lifecycleTracePosition('db.begin'), 'Do not open an Asaas transaction for unrelated invoices.');
    lifecycleSame([], $ci->asaas_gateway->calls, 'Unrelated invoice has no Asaas side effects.');
});

foreach (['create', 'update'] as $operation) {
    lifecycleTest('non-Asaas ' . $operation . ' works when payment SDK cannot load', static function () use ($operation): void {
        $ci = lifecycleReset($operation === 'update', false); $ci->load->gatewayUnavailable = true;
        if ($operation === 'update') { $ci->db->tables['tblinvoices'][42]['allowed_payment_modes'] = serialize(['bank_transfer']); }
        $changes = ['total' => '1.00', 'allowed_payment_modes' => serialize(['bank_transfer'])];
        $result = $operation === 'create' ? lifecycleCreate($changes) : lifecycleUpdate($changes);
        lifecycleCheck($result !== false, 'Unrelated invoice must not depend on the Asaas SDK.');
        lifecycleSame([], $ci->asaas_gateway->calls, 'Do not synchronize unrelated invoices.');
    });
}

lifecycleTest('mapping another active charge does not retire an active payment id', static function (): void {
    $ci = lifecycleReset();
    $second = array_replace($ci->asaas_gateway->remote['pay_original'], ['id' => 'pay_second']);
    $ci->asaas_gateway->remote['pay_second'] = $second;
    asaas_lifecycle_store_payment(42, $second);
    lifecycleSame(false, asaas_lifecycle_should_ignore_payment(42, 'pay_original'), 'An active charge must remain eligible for its incoming receipt.');
    lifecycleSame(false, asaas_lifecycle_should_ignore_payment(42, 'pay_second'), 'Latest active mapping is also processable.');
});

lifecycleTest('replacement of an explicitly cancelled charge keeps retired id ignored', static function (): void {
    $ci = lifecycleReset(); lifecycleCancel();
    $ci->db->tables['tblinvoices'][42]['status'] = 1;
    $replacement = array_replace($ci->asaas_gateway->remote['pay_original'], ['id' => 'pay_replacement', 'deleted' => false, 'status' => 'PENDING']);
    $ci->asaas_gateway->remote['pay_replacement'] = $replacement;
    asaas_lifecycle_store_payment(42, $replacement);
    lifecycleCheck(asaas_lifecycle_should_ignore_payment(42, 'pay_original'), 'A retired cancelled charge cannot generate another receipt.');
    lifecycleSame(false, asaas_lifecycle_should_ignore_payment(42, 'pay_replacement'), 'Replacement is processable.');
});

$GLOBALS['lifecycle_assertions'] = 0;
$failed = []; $skipped = 0; $executed = 0;
try {
    foreach ($tests as $test) {
        if ($test['mbstring'] && !extension_loaded('mbstring')) {
            ++$skipped;
            echo 'SKIP ', $test['name'], ' (requires real ext-mbstring; no polyfill)', PHP_EOL;
            continue;
        }
        ++$executed;
        try { $test['callback'](); echo 'PASS ', $test['name'], PHP_EOL; }
        catch (Throwable $error) {
            $failed[] = $test['name'] . ': ' . get_class($error) . ': ' . $error->getMessage();
            fwrite(STDERR, 'FAIL ' . end($failed) . PHP_EOL);
            if (in_array('--debug', $argv, true)) { fwrite(STDERR, json_encode($GLOBALS['lifecycle_trace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL); }
        }
    }
} finally {
    asaas_receipt_lock_shutdown();
    ini_set('error_log', (string) $previousErrorLog);
    restore_error_handler();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(LIFECYCLE_TEMP_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir(LIFECYCLE_TEMP_ROOT);
}
echo 'Invoice lifecycle: ', count($tests), ' scenarios, ', $executed, ' executed, ', $GLOBALS['lifecycle_assertions'], ' assertions, ', count($failed), ' failures, ', $skipped, ' skipped.', PHP_EOL;
exit($failed === [] ? 0 : 1);
