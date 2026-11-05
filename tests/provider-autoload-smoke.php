<?php

declare(strict_types=1);

/**
 * Offline regression checks for SDK bootstrap and health diagnostics.
 * Run: php tests/provider-autoload-smoke.php
 * Optional: ASAAS_TEST_SDK_SOURCE=/absolute/sdk/src php tests/provider-autoload-smoke.php
 * Each scenario uses an isolated process and temporary Composer-loader fixtures.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PROVIDER_FIXTURE_KEY = 'fixture-private-api-key-never-log';
const PROVIDER_FIXTURE_BOOTSTRAP_ERROR = 'fixture-private-bootstrap-message-never-log';

class App_gateway
{
    public function __construct(private bool $sandbox = false)
    {
    }

    public function getSetting(string $name): mixed
    {
        return $name === 'sandbox' ? ($this->sandbox ? '1' : '0') : null;
    }

    public function decryptSetting(string $name): string
    {
        return in_array($name, ['api_key', 'api_key_sandbox'], true) ? PROVIDER_FIXTURE_KEY : '';
    }
}

function get_option(string $name): mixed
{
    return $GLOBALS['provider_fixture_options'][$name] ?? '';
}

function decrypt(string $value): string
{
    return 'decrypted:' . $value;
}

function asaas_diagnostic_log(string $level, string $message, array $context = [], ?bool $fileEnabled = null): void
{
    $GLOBALS['provider_fixture_logs'][] = compact('level', 'message', 'context', 'fileEnabled');
}

function providerAutoloadCheck(bool $condition, string $message): void
{
    ++$GLOBALS['provider_fixture_assertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function providerAutoloadRemoveFixture(string $directory): void
{
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            providerAutoloadRemoveFixture($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

function providerAutoloadLoader(string $vendor, string $sdkSource, string $marker): string
{
    return '<?php $GLOBALS[' . var_export($marker, true) . '] = ($GLOBALS[' . var_export($marker, true) . '] ?? 0) + 1; '
        . '$loader = require ' . var_export($vendor . '/autoload.php', true) . '; '
        . '$loader->setPsr4("Asaas\\\\Sdk\\\\", ' . var_export($sdkSource, true) . '); return $loader;';
}

function providerAutoloadAssertSafeLogs(): void
{
    $logs = json_encode($GLOBALS['provider_fixture_logs'], JSON_THROW_ON_ERROR);
    providerAutoloadCheck(!str_contains($logs, PROVIDER_FIXTURE_KEY), 'O diagnóstico não pode conter a API key.');
    providerAutoloadCheck(!str_contains($logs, PROVIDER_FIXTURE_BOOTSTRAP_ERROR), 'O diagnóstico não pode copiar mensagens arbitrárias de bootstrap.');
}

function providerAutoloadScenario(string $scenario): int
{
    $GLOBALS['provider_fixture_assertions'] = 0;
    $GLOBALS['provider_fixture_logs'] = [];
    $GLOBALS['provider_fixture_options'] = [];
    $module = dirname(__DIR__);
    $vendor = $module . '/vendor';
    $sdkSource = getenv('ASAAS_TEST_SDK_SOURCE') ?: $vendor . '/argws/asaas-sdk-php/src';
    $sdkSource = realpath($sdkSource);
    if ($sdkSource === false || !is_file($sdkSource . '/AsaasSdk.php')) {
        throw new RuntimeException('A origem de SDK informada para o teste não existe.');
    }

    $fixture = sys_get_temp_dir() . '/asaas-provider-smoke-' . bin2hex(random_bytes(8));
    mkdir($fixture . '/module/libraries', 0700, true);
    mkdir($fixture . '/module/vendor', 0700, true);
    mkdir($fixture . '/perfex/vendor', 0700, true);
    copy($module . '/libraries/AsaasSdkProvider.php', $fixture . '/module/libraries/AsaasSdkProvider.php');
    define('FCPATH', $fixture . '/perfex/');

    try {
        $missingSdk = in_array($scenario, ['missing_sdk', 'health_missing_sdk'], true);
        if (!$missingSdk) {
            file_put_contents($fixture . '/module/vendor/autoload.php', providerAutoloadLoader($vendor, $sdkSource, 'provider_fixture_module_loaded'));
        }

        $rootLoader = '<?php $GLOBALS["provider_fixture_root_loaded"] = ($GLOBALS["provider_fixture_root_loaded"] ?? 0) + 1;';
        if ($scenario === 'compatible_root_sdk' || (!$missingSdk && str_starts_with($scenario, 'health_'))) {
            $rootLoader = providerAutoloadLoader($vendor, $sdkSource, 'provider_fixture_root_loaded');
        } elseif ($scenario === 'broken_root_loader') {
            $rootLoader .= ' throw new RuntimeException(' . var_export(PROVIDER_FIXTURE_BOOTSTRAP_ERROR, true) . ');';
        } elseif ($scenario === 'partial_root_sdk') {
            $rootLoader .= ' require ' . var_export($sdkSource . '/AsaasSdk.php', true) . ';';
        }
        if ($scenario !== 'missing_root_loader') {
            file_put_contents($fixture . '/perfex/vendor/autoload.php', $rootLoader);
        }
        if ($scenario === 'preloaded_sdk') {
            $loader = require $vendor . '/autoload.php';
            $loader->setPsr4('Asaas\\Sdk\\', $sdkSource);
            providerAutoloadCheck(class_exists(Asaas\Sdk\AsaasSdk::class), 'O cenário deve carregar a SDK antes do provider.');
        }

        require $fixture . '/module/libraries/AsaasSdkProvider.php';
        $sandbox = $scenario === 'health_sandbox';
        $gateway = new App_gateway($sandbox);

        if ($missingSdk) {
            $provider = new AsaasSdkProvider($gateway);
            providerAutoloadCheck(($GLOBALS['provider_fixture_root_loaded'] ?? 0) === 1, 'O construtor deve tentar carregar o SDK mesmo quando ele estiver ausente.');
            providerAutoloadCheck(!class_exists(Asaas\Sdk\Http\Client::class, false), 'O cenário não pode carregar o SDK incidentalmente.');
            providerAutoloadCheck(count($GLOBALS['provider_fixture_logs']) > 0, 'A construção sem SDK precisa deixar diagnóstico.');
            providerAutoloadCheck($provider->env() === 'production' && $provider->environmentLabel() === 'PRODUCTION', 'Os dados de ambiente devem continuar disponíveis para as configurações sem o SDK.');
            providerAutoloadCheck($provider->apiKey() === PROVIDER_FIXTURE_KEY && $provider->setting('sandbox') === '0', 'As configurações devem continuar acessíveis sem o SDK.');

            if ($scenario === 'health_missing_sdk') {
                $result = $provider->healthCheck();
                providerAutoloadCheck($result['online'] === false && $result['status'] === 'SDK_ERROR', 'O health check real sem SDK deve retornar SDK_ERROR, sem sucesso falso.');
                providerAutoloadCheck($result['httpStatus'] === null, 'A ausência do SDK não deve inventar um status HTTP.');
                providerAutoloadCheck(is_int($result['latencyMs']) && $result['latencyMs'] >= 0, 'O health check sem SDK deve manter o contrato de latência.');
                providerAutoloadCheck(str_contains($result['message'], 'SDK Asaas'), 'O health check deve explicar que as dependências estão indisponíveis.');
                providerAutoloadCheck(!str_contains($result['message'], PROVIDER_FIXTURE_KEY), 'A mensagem pública do health check não pode expor a API key.');
                $lastLog = end($GLOBALS['provider_fixture_logs']);
                providerAutoloadCheck(($lastLog['context']['operation'] ?? null) === 'health_check', 'A falha no health check deve gerar seu próprio diagnóstico.');
            } else {
                foreach (['ensureComposerAutoload', 'client', 'sdk', 'baseUrl'] as $method) {
                    try {
                        $provider->{$method}();
                        throw new LogicException('A chamada real ' . $method . ' sem SDK deveria falhar explicitamente.');
                    } catch (RuntimeException $exception) {
                        providerAutoloadCheck(str_contains($exception->getMessage(), 'SDK Asaas'), $method . ' deve identificar a ausência do SDK.');
                        providerAutoloadCheck(str_contains($exception->getMessage(), 'composer install'), $method . ' deve orientar a restauração das dependências.');
                    }
                }
            }
            providerAutoloadAssertSafeLogs();
            return $GLOBALS['provider_fixture_assertions'];
        }

        $provider = new AsaasSdkProvider($gateway);
        $provider->ensureComposerAutoload();
        providerAutoloadCheck(class_exists(Asaas\Sdk\Http\Client::class), 'O cliente SDK deve estar disponível após o bootstrap.');
        providerAutoloadCheck($provider->client() instanceof Asaas\Sdk\Http\Client, 'O provider deve construir o cliente real.');
        providerAutoloadCheck($provider->sdk() instanceof Asaas\Sdk\AsaasSdk, 'O provider deve construir a fachada real do SDK.');
        providerAutoloadCheck(realpath((new ReflectionClass(Asaas\Sdk\AsaasSdk::class))->getFileName()) === realpath($sdkSource . '/AsaasSdk.php'), 'A origem da SDK selecionada deve ser preservada.');
        providerAutoloadCheck($provider->apiKey() === PROVIDER_FIXTURE_KEY, 'A seleção e a descriptografia da API key devem permanecer iguais.');
        providerAutoloadCheck($provider->env() === ($sandbox ? 'sandbox' : 'production'), 'O ambiente configurado deve ser preservado.');
        $GLOBALS['provider_fixture_options']['paymentmethod_asaas_sandbox_company_7'] = '1';
        $GLOBALS['provider_fixture_options']['paymentmethod_asaas_api_key_sandbox_company_7'] = 'company-fixture-key';
        providerAutoloadCheck($provider->apiKey(7) === 'decrypted:company-fixture-key', 'A API key por empresa deve manter a descriptografia existente.');
        providerAutoloadCheck($provider->baseUrl(7) === 'https://api-sandbox.asaas.com/v3', 'O ambiente por empresa deve permanecer independente do padrão.');

        if ($scenario === 'compatible_root_sdk' || str_starts_with($scenario, 'health_')) {
            providerAutoloadCheck(($GLOBALS['provider_fixture_root_loaded'] ?? 0) === 1, 'O autoloader compatível do Perfex deve ser usado uma vez.');
            providerAutoloadCheck(empty($GLOBALS['provider_fixture_module_loaded']), 'Uma SDK válida da raiz não deve ser substituída pelo módulo.');
        } elseif ($scenario === 'preloaded_sdk') {
            providerAutoloadCheck(empty($GLOBALS['provider_fixture_root_loaded']), 'Uma SDK já disponível deve evitar carregar outro autoloader de raiz.');
            providerAutoloadCheck(empty($GLOBALS['provider_fixture_module_loaded']), 'Uma SDK já disponível deve evitar recarregar o vendor do módulo.');
        } else {
            providerAutoloadCheck(($GLOBALS['provider_fixture_module_loaded'] ?? 0) === 1, 'O módulo deve complementar um bootstrap de raiz ausente, incompleto ou inválido.');
        }

        if (str_starts_with($scenario, 'health_')) {
            $history = [];
            $response = new GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"data":[]}');
            if ($scenario === 'health_api_error') {
                $response = new GuzzleHttp\Psr7\Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"code":"invalid_access_token","description":"Chave de acesso inválida."}]}');
            } elseif ($scenario === 'health_transport_error') {
                $response = new GuzzleHttp\Exception\ConnectException(PROVIDER_FIXTURE_KEY, new GuzzleHttp\Psr7\Request('GET', 'https://api.asaas.com/v3/payments'));
            }
            $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([$response]));
            $stack->push(GuzzleHttp\Middleware::history($history));
            $environment = $sandbox ? Asaas\Sdk\Http\Environment::Sandbox : Asaas\Sdk\Http\Environment::Production;
            $transport = new GuzzleHttp\Client(['base_uri' => $environment->value, 'handler' => $stack]);
            $client = new Asaas\Sdk\Http\Client(PROVIDER_FIXTURE_KEY, $environment, 'ProviderAutoloadSmoke/1.0', 30, 10, null, $transport);
            $provider = new class($gateway, $client, $scenario === 'health_bootstrap_error') extends AsaasSdkProvider {
                public function __construct(App_gateway $gateway, private Asaas\Sdk\Http\Client $fixtureClient, private bool $failBootstrap)
                {
                    parent::__construct($gateway);
                }

                public function client(?int $companyId = null): Asaas\Sdk\Http\Client
                {
                    if ($this->failBootstrap) {
                        throw new RuntimeException(PROVIDER_FIXTURE_BOOTSTRAP_ERROR);
                    }
                    return $this->fixtureClient;
                }

                public function sdk(?int $companyId = null): Asaas\Sdk\AsaasSdk
                {
                    if ($this->failBootstrap) {
                        throw new RuntimeException(PROVIDER_FIXTURE_BOOTSTRAP_ERROR);
                    }
                    $sdk = parent::sdk($companyId);
                    $sdk->payment = new Asaas\Sdk\Service\PaymentService($this->fixtureClient);
                    return $sdk;
                }
            };
            $result = $provider->healthCheck();
            if ($scenario === 'health_bootstrap_error') {
                providerAutoloadCheck($result['online'] === false && $result['status'] === 'SDK_ERROR', 'Falha no bootstrap deve retornar SDK_ERROR.');
                providerAutoloadCheck($result['httpStatus'] === null, 'Falha no bootstrap não deve inventar um status HTTP.');
                providerAutoloadCheck(count($history) === 0, 'Não deve haver chamada HTTP quando o bootstrap falha.');
                providerAutoloadCheck(!str_contains($result['message'], PROVIDER_FIXTURE_BOOTSTRAP_ERROR), 'A mensagem pública não deve expor o erro bruto.');
            } elseif ($scenario === 'health_api_error') {
                providerAutoloadCheck($result['online'] === false && $result['status'] === 'AUTH_ERROR', '401 deve permanecer um erro de autenticação.');
                providerAutoloadCheck($result['httpStatus'] === 401, 'O diagnóstico deve preservar o status HTTP real.');
            } elseif ($scenario === 'health_transport_error') {
                providerAutoloadCheck($result['online'] === false && $result['status'] === 'TRANSPORT_ERROR', 'A falha de transporte deve manter seu tipo.');
                providerAutoloadCheck($result['httpStatus'] === null, 'Falha de conexão não tem status HTTP.');
                providerAutoloadCheck(!str_contains($result['message'], PROVIDER_FIXTURE_KEY), 'A mensagem pública de transporte não deve expor o erro bruto.');
            } else {
                providerAutoloadCheck($result['online'] === true, 'O health check deve aceitar a resposta simulada de sucesso.');
                providerAutoloadCheck(count($history) === 1, 'O health check deve consultar a API uma única vez.');
                $request = $history[0]['request'];
                providerAutoloadCheck($request->getUri()->getPath() === '/v3/payments', 'O health check deve manter /v3 em qualquer versão do SDK.');
                providerAutoloadCheck($request->getUri()->getHost() === ($sandbox ? 'api-sandbox.asaas.com' : 'api.asaas.com'), 'O health check deve usar o ambiente selecionado.');
                parse_str($request->getUri()->getQuery(), $query);
                providerAutoloadCheck($query === ['limit' => '1'], 'Somente limit=1 deve ser enviado na query.');
                providerAutoloadCheck($request->getHeaderLine('access_token') === PROVIDER_FIXTURE_KEY, 'A autenticação da API deve permanecer no header access_token.');
                providerAutoloadCheck($request->getHeaderLine('X-Request-Source') === 'perfex-asaas', 'Os headers de diagnóstico não podem virar parâmetros de query.');
                providerAutoloadCheck($request->getHeaderLine('X-Correlation-Id') !== '', 'O health check deve manter o identificador de correlação.');
            }
            if (in_array($scenario, ['health_bootstrap_error', 'health_api_error', 'health_transport_error'], true)) {
                providerAutoloadCheck(count($GLOBALS['provider_fixture_logs']) > 0, 'Falhas do health check precisam deixar diagnóstico.');
            }
        }
        providerAutoloadAssertSafeLogs();
        return $GLOBALS['provider_fixture_assertions'];
    } finally {
        providerAutoloadRemoveFixture($fixture);
    }
}

if (($argv[1] ?? '') === '--scenario') {
    try {
        echo json_encode(['assertions' => providerAutoloadScenario($argv[2] ?? '')], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$scenarios = [
    'fallback_root_without_sdk',
    'compatible_root_sdk',
    'preloaded_sdk',
    'missing_root_loader',
    'broken_root_loader',
    'partial_root_sdk',
    'missing_sdk',
    'health_missing_sdk',
    'health_production',
    'health_sandbox',
    'health_bootstrap_error',
    'health_api_error',
    'health_transport_error',
];
$assertions = 0;
$failed = 0;
foreach ($scenarios as $scenario) {
    $command = [PHP_BINARY, '-n'];
    $extensionDirectory = ini_get('extension_dir');
    $ctypeModule = $extensionDirectory . '/ctype.' . PHP_SHLIB_SUFFIX;
    if (is_file($ctypeModule)) {
        array_push($command, '-d', 'extension_dir=' . $extensionDirectory, '-d', 'extension=ctype');
    }
    array_push($command, __FILE__, '--scenario', $scenario);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o processo isolado do teste.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $result = json_decode($output, true);
    if ($status !== 0 || !is_array($result) || !isset($result['assertions'])) {
        ++$failed;
        fwrite(STDERR, '[FAIL] ' . $scenario . ': ' . trim($error ?: $output) . PHP_EOL);
        continue;
    }
    $assertions += $result['assertions'];
    echo '[PASS] ', $scenario, PHP_EOL;
}
echo 'Provider: ', count($scenarios), ' cenários, ', $assertions, ' asserções, ', $failed, ' falhas.', PHP_EOL;
exit($failed === 0 ? 0 : 1);
