<?php

declare(strict_types=1);

/**
 * Página isolada para testar as views/scripts reais sem instalar o Perfex.
 * O renderer abaixo reproduz o contrato de campos do Perfex (form-group + after).
 * Todos os valores são fictícios. Nenhuma conta, banco ou API é acessada.
 *
 * php tests/fixtures/settings-ui-page.php [--manifest] [--wrapped] [--no-layout]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fixtureRoot = dirname(__DIR__, 2);
$fixtureTemporary = sys_get_temp_dir() . '/asaas-settings-ui-' . bin2hex(random_bytes(8));
mkdir($fixtureTemporary . '/modules', 0700, true);
symlink($fixtureRoot, $fixtureTemporary . '/modules/asaas');
register_shutdown_function(static function () use ($fixtureTemporary): void {
    unlink($fixtureTemporary . '/modules/asaas');
    rmdir($fixtureTemporary . '/modules');
    rmdir($fixtureTemporary);
});
define('BASEPATH', __DIR__ . '/');
define('FCPATH', $fixtureTemporary . '/');

$fixtureValues = [
    'api_key' => 'fixture-production-key-abcdefghijklmnopqrstuvwxyz',
    'api_key_sandbox' => 'fixture-homologation-key-abcdefghijklmnopqrstuvwxyz',
    'webhook_secret' => hash('sha256', 'public-settings-ui-fixture'),
    'sandbox' => '1',
    'debug' => '1',
    'label' => 'Asaas · Boleto, Pix e cartão',
    'active' => '1',
    'future_option' => 'Opção futura preservada',
];

function html_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function site_url(string $path = ''): string { return 'https://crm.example.test/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return site_url('admin/' . ltrim($path, '/')); }
function is_admin(): bool { return true; }
function get_option(string $name)
{
    return $GLOBALS['fixtureValues'][str_replace('paymentmethod_asaas_', '', $name)] ?? '';
}
function &get_instance(): object
{
    static $ci;
    if (!$ci) {
        $ci = (object) ['security' => new class {
            public function get_csrf_token_name(): string { return 'csrf_fixture'; }
            public function get_csrf_hash(): string { return 'fixture-csrf-token'; }
        }];
    }
    return $ci;
}

class App_gateway
{
    private array $fixtureSettings = [];
    public function __construct() {}
    public function setId($id): void {}
    public function setName($name): void {}
    public function setSettings(array $settings): void
    {
        // Estes dois campos são acrescentados pelo App_gateway do Perfex.
        $this->fixtureSettings = array_merge([
            ['name' => 'active', 'label' => 'Ativo', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'label', 'label' => 'Título', 'type' => 'input'],
        ], $settings, [
            ['name' => 'future_option', 'label' => 'Parâmetro futuro', 'type' => 'input'],
        ]);
    }
    public function getSettings(): array { return $this->fixtureSettings; }
    public function getSetting(string $name) { return get_option('paymentmethod_asaas_' . $name); }
    public function decryptSetting(string $name): string { return (string) $this->getSetting($name); }
}

require $fixtureRoot . '/libraries/Asaas_gateway.php';
$fixtureGateway = new Asaas_gateway();
$fixtureSettings = $fixtureGateway->getSettings();
foreach ($fixtureSettings as $option) {
    $fixtureValues[$option['name']] ??= (string) ($option['default_value'] ?? '');
}

function fixtureRenderSetting(array $option): void
{
    $key = $option['name'];
    $name = 'settings[paymentmethod_asaas_' . $key . ']';
    $label = [
        'settings_paymentmethod_currencies' => 'Moedas',
        'settings_paymentmethod_description' => 'Descrição',
    ][$option['label']] ?? $option['label'];
    $value = get_option('paymentmethod_asaas_' . $key);
    echo '<div class="form-group" data-fixture-option="' . html_escape($key) . '">';
    if (($option['type'] ?? 'input') === 'yes_no') {
        echo '<label class="control-label clearfix" for="paymentmethod_asaas_' . html_escape($key) . '">' . html_escape($label) . '</label>';
        foreach (['1' => 'Sim', '0' => 'Não'] as $choice => $caption) {
            // IDs do renderer nativo incluem o label, inclusive espaços e acentos.
            $id = 'y_opt_' . ($choice === 1 ? '1_' : '2_') . $label;
            echo '<div class="radio radio-primary radio-inline"><input type="radio" id="' . html_escape($id)
                . '" name="' . html_escape($name) . '" value="' . $choice . '"'
                . ((string) $value === (string) $choice ? ' checked' : '') . '>';
            echo '<label for="' . html_escape($id) . '">' . $caption . '</label></div>';
        }
    } else {
        echo '<label class="control-label" for="' . html_escape($name) . '">' . html_escape($label) . '</label>';
        $attributes = '';
        foreach (($option['field_attributes'] ?? []) as $attribute => $attributeValue) {
            $attributes .= ' ' . html_escape($attribute) . '="' . html_escape($attributeValue) . '"';
        }
        if (($option['type'] ?? 'input') === 'textarea') {
            echo '<textarea class="form-control" id="' . html_escape($name) . '" name="' . html_escape($name) . '"'
                . $attributes . '>' . html_escape($value) . '</textarea>';
        } else {
            echo '<input class="form-control" id="' . html_escape($name) . '" name="' . html_escape($name)
                . '" type="' . html_escape($option['input_type'] ?? 'text') . '" value="' . html_escape($value) . '"' . $attributes . '>';
        }
    }
    echo '</div>' . ($option['after'] ?? '');
    echo '<span data-fixture-after="' . html_escape($key) . '" hidden></span>';
}

$fixtureWrapped = in_array('--wrapped', $argv, true);
$fixtureLayout = !in_array('--no-layout', $argv, true);
ob_start();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asaas — fixture isolada de configurações</title>
    <link rel="stylesheet" href="/_fixture/bootstrap.css">
    <link rel="stylesheet" href="/_fixture/fixture.css">
    <link rel="stylesheet" href="/_fixture/asaas-settings.css">
    <link rel="stylesheet" href="/_fixture/asaas-monitor.css">
</head>
<body>
<main class="fixture-page">
    <h1>Meios de Pagamento</h1>
    <div class="panel panel-default"><div class="panel-body">
        <form id="settings-form" action="/admin/settings" method="post">
            <input type="hidden" name="csrf_fixture" value="fixture-csrf-token">
            <div class="horizontal-scrollable-tabs panel-full-width-tabs">
                <div class="horizontal-tabs">
                    <ul class="nav nav-tabs nav-tabs-horizontal" role="tablist" aria-label="Meios de pagamento">
                        <li class="active"><a href="#online_payments_asaas_tab" data-toggle="tab" role="tab">Asaas</a></li>
                        <li><a href="#online_payments_other_tab" data-toggle="tab" role="tab">Outro meio de pagamento</a></li>
                    </ul>
                </div>
            </div>
            <div class="tab-content mtop30">
                <div id="online_payments_asaas_tab" class="tab-pane active" role="tabpanel">
                    <h4>Asaas</h4>
                    <?php if ($fixtureLayout) { require $fixtureRoot . '/views/settings/tabs.php'; } ?>
                    <hr>
                    <?php if ($fixtureWrapped) { echo '<div id="fixture-incompatible-wrapper">'; } ?>
                    <?php foreach ($fixtureSettings as $option) { fixtureRenderSetting($option); } ?>
                    <?php if ($fixtureWrapped) { echo '</div>'; } ?>
                </div>
                <div id="online_payments_other_tab" class="tab-pane" role="tabpanel">
                    <h4>Outro meio de pagamento</h4>
                    <div class="form-group"><label for="other-label">Título</label><input id="other-label" class="form-control" name="settings[paymentmethod_other_label]" value="Outro gateway preservado"></div>
                </div>
            </div>
            <div class="fixture-save"><button type="submit" class="btn btn-info" id="settings-save">Salvar configurações</button></div>
        </form>
    </div></div>
</main>
<script src="/_fixture/jquery.js"></script>
<script src="/_fixture/bootstrap.js"></script>
<script>
(function () {
    var form = document.getElementById('settings-form');
    var originalNodes = Array.from(form.querySelectorAll('input[name], textarea[name], select[name]'));
    window.__asaasFixture = {
        nodes: originalNodes,
        values: originalNodes.map(function (input) { return {name: input.name, value: input.value, checked: input.checked, disabled: input.disabled}; }),
        payload: Array.from(new FormData(form)).sort(function (a, b) { return a[0].localeCompare(b[0]); }),
        other: document.getElementById('online_payments_other_tab').innerHTML,
        shown: [],
        originalListenerCalls: 0
    };
    originalNodes.find(function (input) { return input.name === 'settings[paymentmethod_asaas_api_key]'; })
        .addEventListener('fixture-original-event', function () { window.__asaasFixture.originalListenerCalls++; });
    document.addEventListener('asaas:settings:shown', function (event) { window.__asaasFixture.shown.push(event.detail.group); });
}());
</script>
<script src="/_fixture/asaas-settings.js"></script>
<script src="/_fixture/asaas-webhook-settings.js"></script>
<script src="/_fixture/asaas-monitor.js"></script>
</body>
</html>
<?php
$fixtureHtml = ob_get_clean();
if (!in_array('--manifest', $argv, true)) {
    echo $fixtureHtml;
    exit;
}

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($fixtureHtml, LIBXML_NONET);
$xpath = new DOMXPath($dom);
$controls = [];
foreach ($xpath->query('//*[@name and (self::input or self::textarea or self::select)]') as $input) {
    $controls[] = [
        'name' => $input->getAttribute('name'), 'type' => $input->getAttribute('type'),
        'value' => $input->tagName === 'textarea' ? $input->textContent : $input->getAttribute('value'),
        'checked' => $input->hasAttribute('checked'), 'disabled' => $input->hasAttribute('disabled'),
    ];
}
echo json_encode([
    'settings' => array_column($fixtureSettings, 'name'),
    'controls' => $controls,
    'groups' => array_map(static fn (DOMElement $element): string => $element->getAttribute('data-asaas-settings-panel'), iterator_to_array($xpath->query('//*[@data-asaas-settings-panel]'))),
    'forms' => $xpath->query('//form')->length,
    'nested_forms' => $xpath->query('//form//form')->length,
    'html' => $fixtureHtml,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
