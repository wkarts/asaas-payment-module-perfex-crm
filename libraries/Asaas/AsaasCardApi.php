<?php

declare(strict_types=1);

namespace Asaas;

use Asaas\Sdk\Exception\ApiException;
use AsaasSdkProvider;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';
require_once __DIR__ . '/../../helpers/asaas_payment_validation_helper.php';
require_once __DIR__ . '/AsaasCardRequestException.php';

/** Dedicated card transport: no retry, card payload cache or raw API error logging. */
final class AsaasCardApi
{
    public function __construct(private AsaasSdkProvider $provider)
    {
    }

    public function createPayment(#[\SensitiveParameter] array $payload): array
    {
        // This validator also handles installmentCount + totalValue, using decimals.
        try {
            \asaas_payment_validate_payload(array_intersect_key($payload, array_flip([
                'billingType', 'value', 'installmentCount', 'installmentValue', 'totalValue', 'discount',
            ])));
        } catch (RuntimeException $exception) {
            throw new AsaasCardRequestException($exception->getMessage(), 400, false);
        }

        return $this->request('card_create_payment', 'POST', '/v3/payments', $payload);
    }

    public function payPayment(string $id, #[\SensitiveParameter] array $payload): array
    {
        $this->requireIdentifier($id, true);

        return $this->request('card_pay_payment', 'POST', '/v3/payments/' . rawurlencode($id) . '/payWithCreditCard', $payload);
    }

    public function getPayment(string $id): array
    {
        $this->requireIdentifier($id, true);

        return $this->request('card_get_payment', 'GET', '/v3/payments/' . rawurlencode($id));
    }

    /** Returns the Asaas listing envelope, including data and hasMore. */
    public function listInstallmentPayments(string $id): array
    {
        $this->requireIdentifier($id);

        return $this->request('card_list_installment_payments', 'GET', '/v3/installments/' . rawurlencode($id) . '/payments', null, ['limit' => 100]);
    }

    private function request(
        string $operation,
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $payload = null,
        array $query = []
    ): array {
        $correlationId = null;
        $dispatched = false;

        try {
            $headers = $this->provider->headers();
            $correlationId = $headers['X-Correlation-Id'] ?? null;
            $client = $method === 'POST' ? $this->provider->cardClient() : $this->provider->client();
            $dispatched = true;
            $response = $client->request($method, $path, $query, $headers, $payload);
            if (!is_array($response) || $response === []) {
                // An empty/non-JSON response does not establish whether a card was charged.
                throw new RuntimeException('Unconfirmed card response.');
            }

            $this->log('info', 'Operação de cartão Asaas concluída.', $operation, 200, $correlationId);

            return $response;
        } catch (Throwable $exception) {
            $status = $this->httpStatus($exception);
            $message = $status >= 400 && $status < 500
                ? 'O Asaas não aprovou esta solicitação de cartão. Revise os dados informados ou entre em contato com a empresa.'
                : 'Não foi possível confirmar o resultado da operação com cartão. Consulte o estado da cobrança antes de tentar novamente.';

            $this->log('error', $message, $operation, $status, $correlationId);

            // The original exception can hold PAN, CVV, token or the HTTP body.
            // Preserve only the status; code 0/5xx means an inconclusive outcome.
            throw new AsaasCardRequestException($message, $status, $dispatched);
        }
    }

    private function requireIdentifier(string $id, bool $payment = false): void
    {
        $pattern = $payment ? '/\Apay_[A-Za-z0-9_-]{1,100}\z/D' : '/\A[A-Za-z0-9_-]{1,120}\z/D';
        if (!preg_match($pattern, $id)) {
            throw new AsaasCardRequestException('Identificador da cobrança ou do parcelamento inválido.', 400, false);
        }
    }

    /** Extract only the status, including older SDKs that wrap Guzzle HTTP errors. */
    private function httpStatus(#[\SensitiveParameter] Throwable $exception): int
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ApiException) {
                $status = (int) $cause->getStatusCode();
                return $status >= 400 && $status <= 599 ? $status : 0;
            }

            if ($cause instanceof RequestException && $cause->getResponse() !== null) {
                $status = $cause->getResponse()->getStatusCode();
                return $status >= 400 && $status <= 599 ? $status : 0;
            }
        }

        return 0;
    }

    private function log(string $level, string $message, string $operation, int $status, ?string $correlationId): void
    {
        try {
            // Never pass the request, response, payload or original exception here.
            \asaas_diagnostic_log($level, $message, [
                'operation' => $operation,
                'http_status' => $status === 0 ? null : $status,
                'correlation_id' => $correlationId,
            ]);
        } catch (Throwable) {
            // Logging must not turn an accepted payment into a failed request.
        }
    }
}
