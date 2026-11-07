<?php

declare(strict_types=1);

namespace Asaas;

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';

/** Fixed endpoints only. No financial mutation is retried by this transport. */
final class AsaasAutomaticPixApi
{
    public function __construct(private \AsaasSdkProvider $provider) {}

    public function createAuthorization(array $payload): array { return $this->request('POST', '/v3/pix/automatic/authorizations', [], $payload); }
    public function authorization(string $id): array { return $this->request('GET', '/v3/pix/automatic/authorizations/' . $this->id($id)); }
    public function authorizations(array $query): array { return $this->request('GET', '/v3/pix/automatic/authorizations', $query); }
    public function cancelAuthorization(string $id): array { return $this->request('DELETE', '/v3/pix/automatic/authorizations/' . $this->id($id)); }
    public function createPayment(array $payload): array { return $this->request('POST', '/v3/payments', [], $payload); }
    public function payment(string $id): array { return $this->request('GET', '/v3/payments/' . $this->id($id, 'pay_')); }
    public function payments(array $query): array { return $this->request('GET', '/v3/payments', $query); }
    public function deletePayment(string $id): array { return $this->request('DELETE', '/v3/payments/' . $this->id($id, 'pay_')); }
    public function transaction(string $id): array { return $this->request('GET', '/v3/pix/transactions/' . $this->id($id)); }
    public function instructions(string $paymentId): array
    {
        $this->id($paymentId, 'pay_');
        return $this->request('GET', '/v3/pix/automatic/paymentInstructions', ['paymentId' => $paymentId]);
    }

    private function id(string $id, string $prefix = ''): string
    {
        if (!preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{1,120}$/D', $id)) {
            throw new AsaasAutomaticPixRequestException('invalid_identifier', 400, false);
        }
        return rawurlencode($id);
    }

    private function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $sent = false;
        $status = 0;
        try {
            $sdk = $this->provider->sdk();
            if (!isset($sdk->automaticPix) || !method_exists($sdk->automaticPix, 'createAnAutomaticPixAuthorization')) {
                throw new AsaasAutomaticPixRequestException('sdk_unavailable', 0, false);
            }
            // The existing dedicated transport verifies that retry middleware is absent.
            $client = $this->provider->automaticPixClient();
            $headers = $this->provider->headers();
            $sent = true;
            $response = $client->request($method, $path, $query, $headers, $payload);
            if (!is_array($response) || $response === [] || isset($response['errors']) || isset($response['error'])) {
                throw new \RuntimeException('invalid_response');
            }
            $status = 200;
            return $response;
        } catch (AsaasAutomaticPixRequestException $error) {
            throw $error;
        } catch (\Throwable $error) {
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \Asaas\Sdk\Exception\ApiException) { $status = (int) $cause->getStatusCode(); break; }
                if ($cause instanceof \GuzzleHttp\Exception\RequestException && $cause->getResponse() !== null) {
                    $status = (int) $cause->getResponse()->getStatusCode(); break;
                }
            }
            // Neither upstream bodies nor exception chains (QR, payer data, credentials) are retained.
            throw new AsaasAutomaticPixRequestException($status >= 400 && $status < 500 ? 'request_rejected' : 'uncertain', $status, $sent);
        } finally {
            try {
                \asaas_diagnostic_log($status === 200 ? 'info' : 'warning', 'Consulta ou operação do Pix Automático.', [
                    'source' => 'automatic_pix', 'operation' => strtolower($method), 'endpoint' => preg_replace('~/(?:pay_[^/]+|[a-f0-9-]{32,})~i', '/{id}', $path),
                    'http_status' => $status ?: null,
                ]);
            } catch (\Throwable) {}
        }
    }
}

/** Deliberately excludes the original exception and request/response bodies. */
final class AsaasAutomaticPixRequestException extends \RuntimeException
{
    public function __construct(string $reason, int $status, public bool $requestSent) { parent::__construct($reason, $status); }
    public function rejected(): bool { return !$this->requestSent || ($this->getCode() >= 400 && $this->getCode() < 500 && !in_array($this->getCode(), [408, 409, 429], true)); }
}
