<?php

declare(strict_types=1);

/** Renders the actual card/payment views without Perfex, the SDK or external HTTP. */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/helpers/asaas_card_pricing_helper.php';

$GLOBALS['card_view_options'] = [];
$GLOBALS['card_view_scenarios'] = 0;
$GLOBALS['card_view_assertions'] = 0;
$GLOBALS['card_view_failures'] = 0;

function get_option(string $name) { return $GLOBALS['card_view_options'][$name] ?? false; }
function validation_errors(string $before = '', string $after = ''): string { return ''; }
function is_invoice_overdue($invoice): bool { return false; }
function is_client_logged_in(): bool { return false; }
function has_contact_permission(string $permission): bool { return false; }
function format_invoice_number($id): string { return 'FAT-' . (string) $id; }
function format_invoice_status($status, string $classes = '', bool $html = true): string { return '<span>Não pago</span>'; }
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function _l(string $key, $value = null): string { return $key; }
function _d(string $date): string { return $date; }
function format_organization_info(): string { return 'Empresa de teste'; }
function format_customer_info($invoice, string $type, string $address): string { return 'Cliente de teste'; }
function get_custom_fields(string $type, array $filters): array { return []; }
function app_format_money($value, string $currency): string { return number_format((float) $value, 2, ',', '.') . ' ' . $currency; }
function app_format_number($value, bool $decimals = false): string { return (string) $value; }
function is_sale_discount_applied($invoice): bool { return false; }
function is_sale_discount($invoice, $type): bool { return false; }
function db_prefix(): string { return 'tbl'; }
function total_credits_applied_to_invoice($invoiceId): int { return 0; }
function sum_from_table(string $table, array $options): int { return 0; }

class Invoices_model
{
    public const STATUS_CANCELLED = 5;
}

final class CardViewSecurityFixture
{
    public function get_csrf_token_name(): string { return 'csrf_token'; }
    public function get_csrf_hash(): string { return 'csrf-"<&token'; }
}

final class CardViewRendererFixture
{
    public CardViewSecurityFixture $security;

    public function __construct()
    {
        $this->security = new CardViewSecurityFixture();
    }

    public function render(string $view = 'cartao', array $overrides = [], array $options = []): string
    {
        $GLOBALS['card_view_options'] = array_merge(['show_amount_due_on_invoice' => '1'], $options);
        $invoice = (object) [
            'id' => 42, 'hash' => 'hash-card-fixture', 'status' => 1,
            'date' => '2030-01-10', 'duedate' => '2030-01-20', 'subtotal' => 120, 'total' => 120,
            'total_left_to_pay' => 70, 'adjustment' => 0, 'discount_total' => 0,
            'discount_percent' => 0, 'currency_name' => 'BRL', 'payments' => [],
            'include_shipping' => 0, 'show_shipping_on_invoice' => 0, 'sale_agent' => 0, 'project_id' => 0,
        ];
        $hash = $invoice->hash;
        $billet_only = $pix_only = $card_only = 1;
        $environment = 'PRODUCTION';
        $card_holder_defaults = [
            'holderName' => 'Titular de teste', 'holderEmail' => 'holder@example.test',
            'holderDocument' => '12345678909', 'holderPostalCode' => '01001-000',
            'holderAddressNumber' => '100', 'holderPhone' => '(11) 99999-0000',
        ];
        $installment_options = [
            ['count' => 1, 'label' => 'À vista — R$ 70,00'],
            ['count' => 2, 'label' => '2x de R$ 35,00'],
        ];
        extract($overrides, EXTR_OVERWRITE);
        ob_start();
        try {
            require __DIR__ . '/../views/' . $view . '.php';
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}

function cardViewAssert(bool $condition, string $message): void
{
    $GLOBALS['card_view_assertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cardViewTest(string $name, callable $callback): void
{
    $GLOBALS['card_view_scenarios']++;
    try {
        $callback();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $GLOBALS['card_view_failures']++;
        echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
    }
}

function cardViewDocument(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
        return new DOMXPath($document);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function cardViewField(DOMXPath $document, string $name): DOMElement
{
    $field = $document->query('//*[@name="' . $name . '"]')->item(0);
    if (!$field instanceof DOMElement) {
        throw new RuntimeException('Missing field: ' . $name);
    }
    return $field;
}

$renderer = new CardViewRendererFixture();

cardViewTest('card form keeps one escaped CSRF token and required accessible fields', static function () use ($renderer): void {
    $html = $renderer->render();
    $document = cardViewDocument($html);
    cardViewAssert($document->query('//form[@id="asaas-card-checkout"]')->length === 1, 'One card form is required.');
    cardViewAssert($document->query('//input[@name="csrf_token"]')->length === 1, 'Only the framework CSRF token must be rendered.');
    cardViewAssert(cardViewField($document, 'csrf_token')->getAttribute('value') === 'csrf-"<&token', 'CSRF token must be escaped without changing its value.');
    cardViewAssert($document->query('//*[@id="cardHash"]')->length === 0, 'Unused legacy cardHash must not remain.');
    foreach (['holderName', 'cardNumber', 'expirationMonth', 'expirationYear', 'securityCode', 'installmentCount', 'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'] as $name) {
        $field = cardViewField($document, $name);
        cardViewAssert($field->hasAttribute('required'), $name . ' must be required.');
        cardViewAssert($document->query('//label[@for="' . $field->getAttribute('id') . '"]')->length === 1, $name . ' must have its own label.');
    }
    cardViewAssert(cardViewField($document, 'securityCode')->getAttribute('type') === 'password', 'CVV must be masked.');
    cardViewAssert($document->query('//script[contains(@src, "asaas-card-checkout.js?v=")]')->length === 1, 'The privacy handler must load with an updated asset version.');
});

cardViewTest('card and holder fields explicitly disable browser autocomplete', static function () use ($renderer): void {
    $document = cardViewDocument($renderer->render());
    $form = $document->query('//form[@id="asaas-card-checkout"]')->item(0);
    cardViewAssert($form instanceof DOMElement && $form->getAttribute('autocomplete') === 'off', 'The complete payment form must ask the browser not to store or autocomplete values.');
    $inputs = $document->query('//form[@id="asaas-card-checkout"]//input[not(@type="hidden")]');
    cardViewAssert($inputs->length === 10, 'Every existing card and holder input must remain present.');
    foreach ($inputs as $input) {
        cardViewAssert($input->getAttribute('autocomplete') === 'off', $input->getAttribute('name') . ' must disable autocomplete.');
    }
    cardViewAssert(!cardViewField($document, 'csrf_token')->hasAttribute('autocomplete'), 'Hidden CSRF fields must not receive invalid autocomplete on/off tokens.');
});

cardViewTest('browser constraints accept valid lengths and reject out-of-range card data', static function () use ($renderer): void {
    $document = cardViewDocument($renderer->render());
    $examples = [
        'cardNumber' => [
            ['1234567890123', '4111 1111 1111 1111', '1234567890123456789', ' 4111 1111 1111 1111 '],
            ['123456789012', '12345678901234567890', '4111 1111 1111 A111'],
        ],
        'expirationMonth' => [['1', '01', '09', '10', '12'], ['0', '00', '13', '123']],
        'expirationYear' => [['2030'], ['30', '20300', 'abcd']],
        'securityCode' => [['123', '0123'], ['12', '12345', '1a3']],
    ];
    foreach ($examples as $name => [$accepted, $rejected]) {
        $field = cardViewField($document, $name);
        $pattern = '~\A(?:' . $field->getAttribute('pattern') . ')\z~';
        cardViewAssert($field->getAttribute('inputmode') === 'numeric', $name . ' must request the numeric keyboard.');
        foreach ($accepted as $value) {
            cardViewAssert(preg_match($pattern, $value) === 1, $name . ' should accept ' . $value);
        }
        foreach ($rejected as $value) {
            cardViewAssert(preg_match($pattern, $value) === 0, $name . ' should reject ' . $value);
        }
    }
});

cardViewTest('only backend installment choices are shown for the outstanding balance', static function () use ($renderer): void {
    $document = cardViewDocument($renderer->render());
    $choices = $document->query('//select[@name="installmentCount"]/option');
    cardViewAssert($choices->length === 2, 'The view must preserve backend installment choices.');
    cardViewAssert($choices->item(0)->textContent === 'À vista — R$ 70,00', 'Single payment must use the outstanding amount supplied by the controller.');
    cardViewAssert($choices->item(1)->textContent === '2x de R$ 35,00', 'Installments must not be calculated from the invoice total.');
    cardViewAssert($choices->item(1)->getAttribute('value') === '2', 'Installment count must retain the existing POST field format.');
});

cardViewTest('messages, safe holder defaults and installment labels are escaped', static function () use ($renderer): void {
    $hostile = '<script>alert("x")</script>';
    $html = $renderer->render('cartao', [
        'mensagem_erro' => $hostile, 'mensagem_info' => $hostile,
        'card_holder_defaults' => ['holderName' => '"><img src=x onerror=alert(1)>', 'holderEmail' => 'holder@example.test'],
        'installment_options' => [['count' => 1, 'label' => $hostile]],
    ]);
    $document = cardViewDocument($html);
    cardViewAssert(!str_contains($html, $hostile), 'Untrusted message or option HTML must not be rendered.');
    cardViewAssert(substr_count($html, html_escape($hostile)) === 3, 'Both messages and the installment label must be escaped.');
    cardViewAssert($document->query('//img[@onerror]')->length === 0, 'Holder defaults must not create injected elements.');
    cardViewAssert(cardViewField($document, 'holderName')->getAttribute('value') === '"><img src=x onerror=alert(1)>', 'Safe holder defaults should remain editable text.');
});

cardViewTest('PAN, expiration and CVV are never repopulated from POST or holder defaults', static function () use ($renderer): void {
    $_POST = [
        'cardNumber' => 'do-not-render-pan', 'securityCode' => 'do-not-render-cvv',
        'expirationMonth' => 'do-not-render-month', 'expirationYear' => 'do-not-render-year',
    ];
    try {
        $html = $renderer->render('cartao', ['card_holder_defaults' => $_POST]);
        $document = cardViewDocument($html);
        cardViewAssert(!cardViewField($document, 'cardNumber')->hasAttribute('value'), 'PAN must not be populated by the server.');
        cardViewAssert(!cardViewField($document, 'securityCode')->hasAttribute('value'), 'CVV must not be populated by the server.');
        cardViewAssert(!cardViewField($document, 'expirationMonth')->hasAttribute('value'), 'Expiration month must not be populated by the server.');
        cardViewAssert(!cardViewField($document, 'expirationYear')->hasAttribute('value'), 'Expiration year must not be populated by the server.');
        cardViewAssert(!str_contains($html, 'do-not-render-'), 'Submitted card data must not appear anywhere in the output.');
    } finally {
        $_POST = [];
    }
});

foreach (['Aguardando confirmação do cartão.', 'Confirmação ainda não disponível. Aguarde.', 'Pagamento já confirmado.'] as $message) {
    cardViewTest('blocked checkout preserves invoice details: ' . $message, static function () use ($renderer, $message): void {
        $html = $renderer->render('cartao', ['card_checkout_blocked' => true, 'mensagem_info' => $message]);
        $document = cardViewDocument($html);
        cardViewAssert($document->query('//form')->length === 0, 'Blocked checkout must not expose a payment form.');
        cardViewAssert($document->query('//input')->length === 0, 'Blocked checkout must not expose card inputs.');
        cardViewAssert(str_contains($html, $message), 'The server payment state should be displayed.');
        cardViewAssert(str_contains($html, 'invoice/42/hash-card-fixture'), 'The invoice return link must remain.');
        cardViewAssert(str_contains($html, '120,00 BRL') && str_contains($html, '70,00 BRL'), 'Invoice total and outstanding balance must remain visible.');
        cardViewAssert(!str_contains($html, 'asaas-card-checkout.js'), 'Blocked checkout must not load an active form handler.');
    });
}

cardViewTest('missing backend installment data does not offer an unverified payment', static function () use ($renderer): void {
    $html = $renderer->render('cartao', ['installment_options' => null]);
    cardViewAssert(!str_contains($html, '<form'), 'No checkout form should be displayed without backend payment choices.');
    cardViewAssert(str_contains($html, 'Volte à fatura'), 'The user should be directed back to the invoice.');
});

foreach ([['PRODUCTION', '0', false], ['PRODUCTION', '1', true], ['SANDBOX', '0', true]] as [$environment, $option, $visible]) {
    cardViewTest('card environment ' . $environment . ' option=' . $option, static function () use ($renderer, $environment, $option, $visible): void {
        $html = $renderer->render('cartao', ['environment' => $environment], ['paymentmethod_asaas_show_environment_in_production' => $option]);
        cardViewAssert(str_contains($html, 'Ambiente ativo:') === $visible, 'Card environment notice must match the shared checkout rule.');
    });
}

cardViewTest('pending card confirmation also blocks every alternate payment method', static function () use ($renderer): void {
    $message = 'Aguardando confirmação <script>unsafe()</script>';
    $html = $renderer->render('payment', ['card_checkout_blocked' => true, 'mensagem_info' => $message]);
    foreach (['boleto', 'qrcode'] as $method) {
        cardViewAssert(!str_contains($html, '/asaas/checkout/' . $method . '/'), 'Pending card payment must hide the ' . $method . ' option.');
    }
    $document = cardViewDocument($html);
    cardViewAssert($document->query('//div[contains(concat(" ", normalize-space(@class), " "), " thumbnail ")]')->length === 0, 'Pending payment must not expose another payment option.');
    cardViewAssert(str_contains($html, 'asaas/checkout/cartao/hash-card-fixture') && str_contains($html, 'Acompanhar pagamento por cartão'), 'A read-only confirmation link must remain available.');
    cardViewAssert(str_contains($html, html_escape($message)), 'The payment state must be visible and escaped.');
    cardViewAssert(!str_contains($html, '<script>unsafe()'), 'The selection notice must not render supplied HTML.');
    cardViewAssert(str_contains($html, 'invoice/42/hash-card-fixture'), 'The invoice return link must remain available.');
});

cardViewTest('payment selection without a card block preserves all enabled methods', static function () use ($renderer): void {
    $html = $renderer->render('payment');
    foreach (['boleto', 'cartao', 'qrcode'] as $method) {
        cardViewAssert(str_contains($html, '/asaas/checkout/' . $method . '/'), 'Enabled payment method must remain available: ' . $method);
    }
});

cardViewTest('priced offers disclose charges and preserve acknowledgements without JavaScript', static function () use ($renderer): void {
    $offers = [
        ['count' => 1, 'label' => 'À vista — R$ 103,59', 'principal' => '100.00', 'fee' => '3.59', 'total' => '103.59', 'schedule' => 'Pagamento à vista.', 'quote' => str_repeat('a', 64)],
        ['count' => 3, 'label' => '3x de R$ 34,71 — total R$ 104,13', 'principal' => '100.00', 'fee' => '4.13', 'total' => '104.13', 'schedule' => '2 parcelas de R$ 34,71 e a última de R$ 34,71.', 'quote' => str_repeat('b', 64)],
    ];
    $html = $renderer->render('cartao', ['installment_options' => $offers]);
    $document = cardViewDocument($html);
    cardViewAssert($document->query('//input[@name="cardQuote[1]" and @value="' . str_repeat('a', 64) . '"]')->length === 1
        && $document->query('//input[@name="cardQuote[3]" and @value="' . str_repeat('b', 64) . '"]')->length === 1,
        'Every server offer must carry its own acknowledgement in the native form.');
    cardViewAssert($document->query('//select[@name="installmentCount"]/option[@data-card-total="104.13" and @data-card-fee="4.13"]')->length === 1,
        'The browser can display only the server-supplied amount for the selected offer.');
    cardViewAssert(str_contains($html, '<noscript>') && str_contains($html, 'R$ 103,59') && str_contains($html, 'R$ 3,59'),
        'The no-JavaScript form must show the total and surcharge before confirmation.');
    cardViewAssert(strpos($html, 'Como deseja pagar?') < strpos($html, 'id="cardNumber"'), 'The financial offer must appear before entering a card number.');
});

cardViewTest('anticipation cost appears only in the aggregate card charge', static function () use ($renderer): void {
    $price = asaas_card_price('100.00', 1, ['card_fee_mode' => 'customer', 'card_anticipation_mode' => 'manual']);
    $offer = ['count' => 1, 'label' => 'À vista — R$ 104,90',
        'principal' => '100.00', 'fee' => asaas_card_pricing_money($price['fee_cents']),
        'total' => asaas_card_pricing_money($price['charged_cents']), 'schedule' => 'Pagamento à vista.',
        'quote' => asaas_card_pricing_token($price, 'view-fixture')];
    $html = $renderer->render('cartao', ['installment_options' => [$offer]]);
    $document = cardViewDocument($html);
    cardViewAssert($price['charged_cents'] === 10490 && str_contains($html, 'Encargos do cartão')
        && str_contains($html, 'R$ 104,90') && str_contains($html, 'R$ 4,90'),
        'The visible amount must include the complete surcharge, before the customer enters card data.');
    cardViewAssert($document->query('//select[@name="installmentCount"]/option[@data-card-fee="4.90" and @data-card-total="104.90"]')->length === 1,
        'JavaScript receives the same aggregate total calculated by the server.');
    cardViewAssert(!preg_match('/antecipa|monthly_rate|first_days|automatic|manual_rate/i', $html),
        'No separate anticipation label, rate, mode or hidden data may be rendered to the payer.');
    cardViewAssert($document->query('//noscript')->length === 1,
        'The aggregate charge remains available without JavaScript.');
});

echo sprintf(
    'Card view: %d scenarios, %d assertions, %d failures.' . PHP_EOL,
    $GLOBALS['card_view_scenarios'],
    $GLOBALS['card_view_assertions'],
    $GLOBALS['card_view_failures']
);
exit($GLOBALS['card_view_failures'] === 0 ? 0 : 1);
