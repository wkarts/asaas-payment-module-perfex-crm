<?php

declare(strict_types=1);
use Asaas\Sdk\AsaasSdk;
use Asaas\Sdk\Config\AsaasConfig;
use Asaas\Sdk\Exception\ApiException;
use Asaas\Sdk\Exception\TransportException;
use Asaas\Sdk\Http\Client;
use Asaas\Sdk\Http\Environment;

class AsaasSdkProvider
{
    private App_gateway $gateway;
    private static bool $autoloaded = false;

    public function __construct(App_gateway $gateway)
    {
        $this->gateway = $gateway;
        try {
            $this->ensureComposerAutoload();
        } catch (\RuntimeException) {
            // Bootstrap failure is already logged. Keep settings accessible;
            // SDK operations will retry loading and fail explicitly if necessary.
        }
    }

    public function ensureComposerAutoload(): void
    {
        if (self::$autoloaded) {
            return;
        }

        $bootstrapError = null;

        // Perfex or another module may have registered a compatible SDK already.
        try {
            if ($this->hasRequiredSdkClasses()) {
                self::$autoloaded = true;
                return;
            }
        } catch (\Throwable $exception) {
            $bootstrapError = $exception;
        }

        $paths = [];

        if (defined('FCPATH')) {
            $paths[] = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        }

        $paths[] = __DIR__ . '/../vendor/autoload.php';

        foreach (array_unique($paths) as $path) {
            if (!is_file($path)) {
                continue;
            }

            try {
                require_once $path;

                // A root Composer autoloader does not necessarily contain this module's SDK.
                if ($this->hasRequiredSdkClasses()) {
                    self::$autoloaded = true;
                    return;
                }
            } catch (\Throwable $exception) {
                $bootstrapError = $exception;
            }
        }

        $message = 'Não foi possível carregar o SDK Asaas. Verifique o vendor do módulo e execute composer install nas dependências do módulo.';
        $context = ['operation' => 'sdk_bootstrap'];
        if ($bootstrapError !== null) {
            $context['exception_class'] = get_class($bootstrapError);
        }
        $this->logDiagnostic($message, $context);

        throw new \RuntimeException($message, 0, $bootstrapError);
    }

    private function hasRequiredSdkClasses(): bool
    {
        foreach ([
            AsaasSdk::class,
            AsaasConfig::class,
            Client::class,
            Environment::class,
            ApiException::class,
            TransportException::class,
            \GuzzleHttp\Client::class,
            \GuzzleHttp\HandlerStack::class,
        ] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    private function logDiagnostic(string $message, array $context = []): void
    {
        if (function_exists('asaas_diagnostic_log')) {
            asaas_diagnostic_log('error', $message, $context);
        } elseif (function_exists('log_message')) {
            log_message('error', '[Asaas] ' . $message . ' | context=' . json_encode($context));
        }
    }

    public function sdk(?int $companyId = null): AsaasSdk
    {
        $this->ensureComposerAutoload();

        $config = new AsaasConfig(
            apiKey: $this->getApiKey($companyId),
            environment: $this->environmentEnum($companyId),
            appName: $this->getUserAgent($companyId),
            timeout: $this->getTimeout($companyId),
            connectTimeout: $this->getConnectTimeout($companyId)
        );

        return new AsaasSdk($config);
    }

    public function client(?int $companyId = null): Client
    {
        $this->ensureComposerAutoload();

        return new Client(
            $this->getApiKey($companyId),
            $this->environmentEnum($companyId),
            $this->getUserAgent($companyId),
            $this->getTimeout($companyId),
            $this->getConnectTimeout($companyId)
        );
    }

    /** Recovery GETs make one attempt; the next scheduled/user action decides when to retry. */
    public function reconciliationClient(?int $companyId = null): Client
    {
        try {
            $this->ensureComposerAutoload();
            $constructor = (new \ReflectionClass(Client::class))->getConstructor();
            $parameters = $constructor === null ? [] : $constructor->getParameters();
            $injection = $parameters[6] ?? null;
            $type = $injection === null ? null : $injection->getType();
            if ($injection === null || $injection->getName() !== 'client'
                || !$type instanceof \ReflectionNamedType || $type->getName() !== \GuzzleHttp\Client::class) {
                throw new \RuntimeException('Unsupported SDK HTTP transport injection.');
            }

            $environment = $this->environmentEnum($companyId);
            $timeout = $this->getTimeout($companyId);
            $connectTimeout = $this->getConnectTimeout($companyId);
            $transport = new \GuzzleHttp\Client([
                'base_uri' => $environment->value,
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'http_errors' => false,
                'allow_redirects' => false,
                'handler' => \GuzzleHttp\HandlerStack::create(),
            ]);
            $client = new Client($this->getApiKey($companyId), $environment, $this->getUserAgent($companyId),
                $timeout, $connectTimeout, null, $transport);
            $property = new \ReflectionProperty(Client::class, 'client');
            $property->setAccessible(true);
            if ($property->getValue($client) !== $transport) {
                throw new \RuntimeException('SDK ignored the injected recovery transport.');
            }
            return $client;
        } catch (\Throwable) {
            $message = 'Não foi possível preparar a consulta segura de conciliação. Confira a SDK Asaas 0.2.64 ou superior e o vendor do módulo.';
            try { $this->logDiagnostic($message, ['operation' => 'reconciliation_client_bootstrap']); }
            catch (\Throwable) {}
            throw new \RuntimeException($message);
        }
    }

    /**
     * Card mutations must never inherit the SDK's automatic retry middleware.
     * SDK 0.2.64 supports an injected Guzzle client, not a maxRetries option.
     * Keep the existing client()/sdk() behaviour for all other operations.
     */
    public function cardClient(?int $companyId = null): Client
    {
        try {
            $this->ensureComposerAutoload();

            // A root autoloader may have loaded an older SDK before this module.
            // Refuse an unknown constructor instead of silently enabling retries.
            $constructor = (new \ReflectionClass(Client::class))->getConstructor();
            $parameters = $constructor === null ? [] : $constructor->getParameters();
            $injection = $parameters[6] ?? null;
            $injectionType = $injection === null ? null : $injection->getType();
            if ($injection === null || $injection->getName() !== 'client'
                || !$injectionType instanceof \ReflectionNamedType
                || $injectionType->getName() !== \GuzzleHttp\Client::class) {
                throw new \RuntimeException('Unsupported SDK HTTP transport injection.');
            }

            $environment = $this->environmentEnum($companyId);
            $transport = new \GuzzleHttp\Client([
                'base_uri' => $environment->value,
                'timeout' => 90.0,
                'connect_timeout' => $this->getConnectTimeout($companyId),
                'http_errors' => false,
                'allow_redirects' => false,
                // Guzzle's default stack has no retry middleware.
                'handler' => \GuzzleHttp\HandlerStack::create(),
            ]);

            $client = new Client(
                $this->getApiKey($companyId),
                $environment,
                $this->getUserAgent($companyId),
                90.0,
                $this->getConnectTimeout($companyId),
                null,
                $transport
            );

            // Verify that the loaded SDK actually retained the safe transport.
            $property = new \ReflectionProperty(Client::class, 'client');
            $property->setAccessible(true);
            if ($property->getValue($client) !== $transport) {
                throw new \RuntimeException('SDK ignored the injected HTTP transport.');
            }

            return $client;
        } catch (\Throwable) {
            $message = 'Não foi possível preparar o transporte seguro para cartão. Atualize a SDK Asaas para 0.2.64 ou superior e confira o vendor carregado pelo Perfex.';
            try {
                $this->logDiagnostic($message, ['operation' => 'card_client_bootstrap']);
            } catch (\Throwable) {
                // Preserve the safe bootstrap error even when diagnostics fail.
            }
            // Do not retain an exception chain that can contain credentials.
            throw new \RuntimeException($message);
        }
    }

    public function env(?int $companyId = null): string
    {
        return $this->getSandboxSetting($companyId) ? 'sandbox' : 'production';
    }

    public function environmentLabel(?int $companyId = null): string
    {
        return $this->getSandboxSetting($companyId) ? 'SANDBOX' : 'PRODUCTION';
    }

    public function baseUrl(?int $companyId = null): string
    {
        $this->ensureComposerAutoload();

        return $this->environmentEnum($companyId)->value;
    }

    public function baseHost(?int $companyId = null): string
    {
        $baseUrl = $this->baseUrl($companyId);

        return rtrim(preg_replace('#/v3/?$#', '', $baseUrl), '/');
    }

    public function apiKey(?int $companyId = null): string
    {
        return $this->getApiKey($companyId);
    }

    public function headers(array $extra = [], ?string $correlationId = null): array
    {
        $correlationId = $correlationId ?: bin2hex(random_bytes(16));

        return $extra + [
            'X-Correlation-Id' => $correlationId,
            'X-Request-Source' => 'perfex-asaas',
        ];
    }

    /**
     * @return array{online: bool, status: string, httpStatus: int|null, latencyMs: int, message: string}
     */
    public function healthCheck(?int $companyId = null): array
    {
        $startedAt = microtime(true);

        try {
            // The HTTP client contract is stable across SDK 0.1.x and 0.2.x.
            $this->client($companyId)->request('GET', '/v3/payments', ['limit' => 1], $this->headers());

            return [
                'online' => true,
                'status' => 'ONLINE',
                'httpStatus' => 200,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'message' => 'API respondeu com sucesso.',
            ];
        } catch (ApiException $exception) {
            $status = $exception->getStatusCode();
            $this->logDiagnostic('Falha HTTP ao verificar a conexão com o Asaas.', [
                'operation' => 'health_check',
                'http_status' => $status,
                'exception_class' => get_class($exception),
            ]);

            return [
                'online' => false,
                'status' => $this->mapApiStatus($status),
                'httpStatus' => $status,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'message' => $exception->getMessage(),
            ];
        } catch (TransportException $exception) {
            // SDK 0.1.x wraps Guzzle's HTTP errors as transport errors.
            $previous = $exception->getPrevious();
            if ($previous instanceof \GuzzleHttp\Exception\RequestException && $previous->hasResponse()) {
                $response = $previous->getResponse();
                $status = $response->getStatusCode();
                $apiError = ApiException::fromResponse($status, (string) $response->getBody());
                $this->logDiagnostic('Falha HTTP ao verificar a conexão com o Asaas.', [
                    'operation' => 'health_check',
                    'http_status' => $status,
                    'exception_class' => get_class($previous),
                ]);

                return [
                    'online' => false,
                    'status' => $this->mapApiStatus($status),
                    'httpStatus' => $status,
                    'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
                    'message' => $apiError->getMessage(),
                ];
            }

            $this->logDiagnostic('Falha de transporte ao verificar a conexão com o Asaas.', [
                'operation' => 'health_check',
                'exception_class' => get_class($exception),
            ]);

            return [
                'online' => false,
                'status' => 'TRANSPORT_ERROR',
                'httpStatus' => null,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            $this->logDiagnostic('Não foi possível inicializar o SDK Asaas para verificar a conexão.', [
                'operation' => 'health_check',
                'exception_class' => get_class($exception),
            ]);

            return [
                'online' => false,
                'status' => 'SDK_ERROR',
                'httpStatus' => null,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'message' => 'Não foi possível inicializar o SDK Asaas. Verifique as dependências e consulte o diagnóstico do módulo.',
            ];
        }
    }

    public function setting(string $name, ?int $companyId = null): mixed
    {
        return $this->getSettingValue($name, $companyId);
    }

    private function mapApiStatus(?int $status): string
    {
        if ($status === 401 || $status === 403) {
            return 'AUTH_ERROR';
        }

        if ($status === 429) {
            return 'RATE_LIMIT';
        }

        if ($status !== null && $status >= 500) {
            return 'SERVER_ERROR';
        }

        return 'API_ERROR';
    }

    private function getSandboxSetting(?int $companyId = null): bool
    {
        $value = $this->getSettingValue('sandbox', $companyId);

        return $value === '1' || $value === 1 || $value === true;
    }

    private function getApiKey(?int $companyId = null): string
    {
        $settingName = $this->getSandboxSetting($companyId) ? 'api_key_sandbox' : 'api_key';

        $override = $this->getSettingOverride($settingName, $companyId);
        if ($override !== null) {
            return $override;
        }

        return (string) $this->gateway->decryptSetting($settingName);
    }

    private function getUserAgent(?int $companyId = null): string
    {
        return 'PerfexAsaas/1.0';
    }

    private function getTimeout(?int $companyId = null): float
    {
        return 30.0;
    }

    private function getConnectTimeout(?int $companyId = null): float
    {
        return 10.0;
    }

    private function environmentEnum(?int $companyId = null): Environment
    {
        return $this->getSandboxSetting($companyId) ? Environment::Sandbox : Environment::Production;
    }

    private function getSettingValue(string $name, ?int $companyId = null): mixed
    {
        $override = $this->getSettingOverride($name, $companyId);
        if ($override !== null) {
            return $override;
        }

        return $this->gateway->getSetting($name);
    }

    private function getSettingOverride(string $name, ?int $companyId = null): mixed
    {
        if (!$companyId || !function_exists('get_option')) {
            return null;
        }

        $optionKey = 'paymentmethod_asaas_' . $name . '_company_' . $companyId;
        $value = get_option($optionKey);

        if ($value === '' || $value === null) {
            return null;
        }

        if (in_array($name, ['api_key', 'api_key_sandbox'], true) && function_exists('decrypt')) {
            return decrypt($value);
        }

        return $value;
    }
}
