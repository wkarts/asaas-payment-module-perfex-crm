<?php

declare(strict_types=1);

namespace Asaas;

use App_gateway;
use Asaas\Sdk\Exception\ApiException;
use Asaas\Sdk\Exception\TransportException;
use AsaasSdkProvider;
use RuntimeException;

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';
require_once __DIR__ . '/../../helpers/asaas_payment_validation_helper.php';
require_once __DIR__ . '/../../helpers/asaas_lifecycle_helper.php';
require_once __DIR__ . '/../../helpers/asaas_receipt_lock_helper.php';
require_once __DIR__ . '/../../helpers/asaas_card_helper.php';

final class AsaasAdapter
{
    private const PREFACES_RESOURCE_PATH = '/prefaces';

    private AsaasSdkProvider $provider;
    private ?App_gateway $gateway;
    private $ci;
    private ?array $logColumns = null;

    public function __construct(AsaasSdkProvider $provider, ?App_gateway $gateway = null)
    {
        $this->provider = $provider;
        $this->gateway = $gateway;
        $this->ci = &get_instance();
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?array $payload = null,
        bool $expectsBinary = false,
        ?int $companyId = null,
        bool $singleAttempt = false
    ): array {
        if ($singleAttempt && strtoupper($method) !== 'GET') {
            throw new \InvalidArgumentException('O transporte de conciliação permite somente consultas GET.');
        }
        $correlationId = $headers['X-Correlation-Id'] ?? null;
        $headers = $this->provider->headers($headers, $correlationId);
        $apiPath = $this->apiPath($path);

        try {
            $client = $singleAttempt ? $this->provider->reconciliationClient($companyId) : $this->provider->client($companyId);
            $response = $client->request($method, $apiPath, $query, $headers, $payload, false, $expectsBinary);
            $this->log('info', 'Asaas request concluída.', [
                'method' => $method,
                'path' => $apiPath,
                'query' => $query,
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            return is_array($response) ? $response : [];
        } catch (ApiException $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', 'Erro na API Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'query' => $query,
                'status' => $error['status'],
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            throw new RuntimeException(
                sprintf('Erro HTTP Asaas %s [%s %s]: %s', $error['status'], $method, $apiPath, $error['message']),
                (int) $error['status'],
                $exception
            );
        } catch (TransportException $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', $error['status'] ? 'Erro na API Asaas.' : 'Erro de transporte na API Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'query' => $query,
                'status' => $error['status'],
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            throw new RuntimeException(
                sprintf('Falha Asaas%s [%s %s]: %s', $error['status'] ? ' HTTP ' . $error['status'] : '', $method, $apiPath, $error['message']),
                (int) $error['status'],
                $exception
            );
        } catch (\Throwable $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', 'Falha ao preparar ou executar integração Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'exception' => get_class($exception),
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            throw new RuntimeException($error['message'], 0, $exception);
        }
    }

    /**
     * Executa requisição com envelope padronizado para novos módulos sem quebrar legado.
     *
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function requestStructured(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?array $payload = null,
        bool $expectsBinary = false,
        ?int $companyId = null
    ): array {
        $correlationId = $headers['X-Correlation-Id'] ?? null;
        $headers = $this->provider->headers($headers, $correlationId);
        $apiPath = $this->apiPath($path);

        try {
            $client = $this->provider->client($companyId);
            $response = $client->request($method, $apiPath, $query, $headers, $payload, false, $expectsBinary);

            return [
                'success' => true,
                'data' => is_array($response) ? $response : [],
                'error' => null,
                'meta' => [
                    'method' => $method,
                    'path' => $path,
                    'correlation_id' => $headers['X-Correlation-Id'] ?? null,
                ],
            ];
        } catch (ApiException $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', 'Erro na API Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'status' => $error['status'],
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);
            return [
                'success' => false,
                'data' => null,
                'error' => [
                    'type' => 'api_error',
                    'status' => $error['status'],
                    'message' => $error['message'],
                ],
                'meta' => [
                    'method' => $method,
                    'path' => $path,
                    'correlation_id' => $headers['X-Correlation-Id'] ?? null,
                ],
            ];
        } catch (TransportException $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', $error['status'] ? 'Erro na API Asaas.' : 'Erro de transporte na API Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'status' => $error['status'],
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);
            return [
                'success' => false,
                'data' => null,
                'error' => [
                    'type' => $error['status'] ? 'api_error' : 'transport_error',
                    'status' => $error['status'],
                    'message' => $error['message'],
                ],
                'meta' => [
                    'method' => $method,
                    'path' => $path,
                    'correlation_id' => $headers['X-Correlation-Id'] ?? null,
                ],
            ];
        } catch (\Throwable $exception) {
            $error = $this->describeFailure($exception);
            $this->log('error', 'Falha ao preparar ou executar integração Asaas.', [
                'method' => $method,
                'path' => $apiPath,
                'exception' => get_class($exception),
                'message' => $error['message'],
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => ['type' => 'integration_error', 'status' => null, 'message' => $error['message']],
                'meta' => ['method' => $method, 'path' => $path, 'correlation_id' => $headers['X-Correlation-Id'] ?? null],
            ];
        }
    }

    /** Guzzle resolves /payments against the host, so the API version must be explicit. */
    private function apiPath(string $path): string
    {
        if (preg_match('#^(?:https?://|//)#i', $path)) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');

        return preg_match('#^/v3(?:/|\\?|$)#', $path) ? $path : '/v3' . $path;
    }

    /** Recover HTTP status/details even when older SDKs wrap Guzzle errors as transport errors. */
    private function describeFailure(\Throwable $exception): array
    {
        $status = null;
        $message = '';

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ApiException) {
                $status = $cause->getStatusCode();
                $message = $cause->getMessage();
                break;
            }

            if ($cause instanceof \GuzzleHttp\Exception\RequestException && $cause->getResponse() !== null) {
                $response = $cause->getResponse();
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
                $decoded = strlen($body) <= 65536 ? json_decode($body, true) : null;
                $description = is_array($decoded) ? ($decoded['errors'][0]['description'] ?? $decoded['message'] ?? '') : '';
                $message = is_string($description) ? $description : '';
                break;
            }
        }

        if ($message === '' || $message === 'Erro na API Asaas.') {
            if ($status === 401 || $status === 403) {
                $message = 'A API recusou a autenticação. Confira a API Key do ambiente selecionado.';
            } elseif ($status === 404) {
                $message = 'Recurso não encontrado na API. Confira o caminho e o identificador da cobrança.';
            } elseif ($status !== null) {
                $message = 'A API Asaas retornou HTTP ' . $status . '.';
            } elseif ($exception instanceof TransportException) {
                $message = 'Não foi possível conectar à API Asaas. Confira DNS, conexão, TLS e timeout do servidor.';
            } else {
                $message = 'Falha ao carregar ou executar a integração Asaas: ' . $exception->getMessage();
            }
        }

        return ['status' => $status, 'message' => \asaas_diagnostic_sanitize(substr($message, 0, 2000))];
    }

    /**
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function createPreface(array $payload, array $headers = [], ?int $companyId = null): array
    {
        if ($payload === []) {
            return $this->validationError('Payload do Prefaces é obrigatório.');
        }

        return $this->requestStructured('POST', self::PREFACES_RESOURCE_PATH, [], $headers, $payload, false, $companyId);
    }

    /**
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function getPreface(string $prefaceId, array $headers = [], ?int $companyId = null): array
    {
        if (trim($prefaceId) === '') {
            return $this->validationError('prefaceId é obrigatório.');
        }

        return $this->requestStructured('GET', self::PREFACES_RESOURCE_PATH . '/' . $prefaceId, [], $headers, null, false, $companyId);
    }

    /**
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function listPrefaces(array $query = [], array $headers = [], ?int $companyId = null): array
    {
        return $this->requestStructured('GET', self::PREFACES_RESOURCE_PATH, $query, $headers, null, false, $companyId);
    }

    /**
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function updatePreface(string $prefaceId, array $payload, string $method = 'PUT', array $headers = [], ?int $companyId = null): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->validationError('Método de atualização inválido. Use PUT ou PATCH.');
        }

        if (trim($prefaceId) === '') {
            return $this->validationError('prefaceId é obrigatório.');
        }

        if ($payload === []) {
            return $this->validationError('Payload do Prefaces é obrigatório.');
        }

        return $this->requestStructured($method, self::PREFACES_RESOURCE_PATH . '/' . $prefaceId, [], $headers, $payload, false, $companyId);
    }

    /**
     * @return array{success: bool, data: array|null, error: array|null, meta: array}
     */
    public function deletePreface(string $prefaceId, array $headers = [], ?int $companyId = null): array
    {
        if (trim($prefaceId) === '') {
            return $this->validationError('prefaceId é obrigatório.');
        }

        return $this->requestStructured('DELETE', self::PREFACES_RESOURCE_PATH . '/' . $prefaceId, [], $headers, null, false, $companyId);
    }

    /**
     * @return array{success: bool, data: array|null, error: array{type: string, status: int|null, message: string}, meta: array}
     */
    private function validationError(string $message): array
    {
        return [
            'success' => false,
            'data' => null,
            'error' => [
                'type' => 'validation_error',
                'status' => null,
                'message' => $message,
            ],
            'meta' => [],
        ];
    }

    /**
     * @param array|object $perfexClient
     * @return array{customerId: string|null, created: bool, updated: bool, raw: array}
     */
    public function ensureCustomerFromClient($perfexClient): array
    {
        $client = $this->normalizeClient($perfexClient);
        $clientId = $client['userid'] ?? $client['id'] ?? null;

        if (!$clientId) {
            return [
                'customerId' => null,
                'created' => false,
                'updated' => false,
                'raw' => ['error' => 'Cliente inválido.'],
            ];
        }

        $mapTable = db_prefix() . 'asaas_customers_map';
        if ($this->ci->db->table_exists($mapTable)) {
            $existing = $this->ci->db->get_where($mapTable, ['perfex_client_id' => $clientId])->row_array();
            if ($existing && !empty($existing['asaas_customer_id'])) {
                return [
                    'customerId' => $existing['asaas_customer_id'],
                    'created' => false,
                    'updated' => false,
                    'raw' => $existing,
                ];
            }
        }

        $document = $this->normalizeDocument($client['vat'] ?? '');
        $response = $this->request('GET', '/customers', ['cpfCnpj' => $document]);
        $customerId = null;
        $created = false;
        $updated = false;

        if (!empty($response['data'][0]['id'])) {
            $customerId = $response['data'][0]['id'];
        } else {
            $payload = [
                'name' => $client['company'] ?? $client['name'] ?? 'Cliente',
                'externalReference' => (string) $clientId,
                'notificationDisabled' => (string) $this->provider->setting('disable_charge_notification') === '1',
                'email' => $client['email'] ?? '',
                'cpfCnpj' => $document,
                'postalCode' => $this->normalizePostalCode($client['zip'] ?? ''),
                'address' => $client['address'] ?? '',
                'addressNumber' => $client['numero'] ?? '',
                'complement' => $client['complemento'] ?? '',
                'phone' => $client['phonenumber'] ?? '',
                'mobilePhone' => $client['phonenumber'] ?? '',
            ];

            $createdResponse = $this->request('POST', '/customers', [], [], $payload);
            $customerId = $createdResponse['id'] ?? null;
            $created = $customerId !== null;
        }

        if ($customerId && $this->ci->db->table_exists($mapTable)) {
            $now = date('Y-m-d H:i:s');
            $this->ci->db->replace($mapTable, [
                'perfex_client_id' => $clientId,
                'asaas_customer_id' => $customerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($customerId && $this->ci->db->field_exists('asaas_customer_id', db_prefix() . 'clients')) {
            $this->ci->db->where('userid', $clientId);
            $this->ci->db->update(db_prefix() . 'clients', ['asaas_customer_id' => $customerId]);
            $updated = true;
        }

        return [
            'customerId' => $customerId,
            'created' => $created,
            'updated' => $updated,
            'raw' => $response,
        ];
    }

    public function getCustomer(string $asaasCustomerId): array
    {
        return $this->request('GET', '/customers/' . $asaasCustomerId);
    }

    public function updateCustomer(string $asaasCustomerId, array $payload): array
    {
        return $this->request('POST', '/customers/' . $asaasCustomerId, [], [], $payload);
    }

    public function createPayment(array $payload): array
    {
        \asaas_payment_validate_payload($payload);
        return $this->request('POST', '/payments', [], [], $payload);
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . $paymentId);
    }

    public function listPayments(array $query): array
    {
        return $this->request('GET', '/payments', $query);
    }

    public function updatePayment(string $paymentId, array $payload): array
    {
        $this->requirePaymentId($paymentId);
        \asaas_payment_validate_payload($payload);
        return $this->request('POST', '/payments/' . $paymentId, [], [], $payload);
    }

    public function cancelPayment(string $paymentId): array
    {
        return $this->deletePayment($paymentId);
    }

    public function deletePayment(string $paymentId): array
    {
        $this->requirePaymentId($paymentId);
        $response = $this->request('DELETE', '/payments/' . rawurlencode($paymentId));
        $this->requirePaymentResponse($response, $paymentId, 'exclusão');
        if (($response['deleted'] ?? null) !== true) {
            throw new RuntimeException('O Asaas não confirmou a exclusão da cobrança. Consulte o estado atual antes de tentar novamente.');
        }
        return $response;
    }

    public function restorePayment(string $paymentId): array
    {
        $this->requirePaymentId($paymentId);
        $response = $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/restore');
        $this->requirePaymentResponse($response, $paymentId, 'restauração');
        if (($response['deleted'] ?? null) !== false || !in_array($response['status'] ?? null, ['PENDING', 'OVERDUE'], true)) {
            throw new RuntimeException('O Asaas não confirmou a restauração da cobrança em aberto. Consulte o estado atual antes de tentar novamente.');
        }
        return $response;
    }

    public function receivePaymentInCash(string $paymentId, string $paymentDate, $value, bool $notifyCustomer = false): array
    {
        $this->requirePaymentId($paymentId);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if ($date === false || $date->format('Y-m-d') !== $paymentDate) {
            throw new RuntimeException('Informe uma data de recebimento válida no formato AAAA-MM-DD.', 400);
        }
        try {
            $receivedCents = \asaas_payment_amount_to_cents($value, 'Valor recebido');
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), 400, $exception);
        }
        if ($receivedCents <= 0) {
            throw new RuntimeException('O valor recebido deve ser maior que zero.', 400);
        }
        $response = $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/receiveInCash', [], [], [
            'paymentDate' => $paymentDate,
            'value' => $value,
            'notifyCustomer' => $notifyCustomer,
        ]);
        $this->requirePaymentResponse($response, $paymentId, 'recebimento em dinheiro');
        $received = ($response['status'] ?? null) === 'RECEIVED_IN_CASH'
            || (($response['status'] ?? null) === 'RECEIVED' && ($response['billingType'] ?? null) === 'RECEIVED_IN_CASH');
        if (!$received || ($response['deleted'] ?? false) !== false) {
            throw new RuntimeException('O Asaas não confirmou o recebimento em dinheiro. Consulte o estado atual antes de tentar novamente.');
        }
        return $response;
    }

    public function undoReceivePaymentInCash(string $paymentId): array
    {
        $this->requirePaymentId($paymentId);
        $response = $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/undoReceivedInCash');
        $this->requirePaymentResponse($response, $paymentId, 'reversão do recebimento em dinheiro');
        if (($response['deleted'] ?? false) !== false || !in_array($response['status'] ?? null, ['PENDING', 'OVERDUE'], true)) {
            throw new RuntimeException('O Asaas não confirmou a reversão do recebimento em dinheiro para uma cobrança em aberto.');
        }
        return $response;
    }

    private function requirePaymentId(string $paymentId): void
    {
        if ($paymentId === '' || trim($paymentId) !== $paymentId || !preg_match('/^[a-zA-Z0-9_-]+$/D', $paymentId)) {
            throw new RuntimeException('Identificador da cobrança Asaas inválido.', 400);
        }
    }

    private function requirePaymentResponse(array $response, string $paymentId, string $operation): void
    {
        if (($response['id'] ?? null) !== $paymentId || isset($response['errors']) || isset($response['error'])) {
            throw new RuntimeException('O Asaas retornou uma resposta sem confirmação válida para a ' . $operation . ' da cobrança.');
        }
    }

    public function refundPayment(string $paymentId, array $payload = []): array
    {
        return [
            'error' => 'refund_not_supported',
            'message' => 'Estorno não suportado pela SDK argws neste momento.',
            'paymentId' => $paymentId,
            'payload' => $payload,
        ];
    }

    public function getPixInfo(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . $paymentId . '/pixQrCode');
    }

    public function getBoletoInfo(string $paymentId): array
    {
        $payment = $this->getPayment($paymentId);

        return [
            'linhaDigitavel' => $payment['identificationField'] ?? null,
            'invoiceUrl' => $payment['invoiceUrl'] ?? null,
            'bankSlipUrl' => $payment['bankSlipUrl'] ?? null,
            'raw' => $payment,
        ];
    }

    /** Retorna somente o webhook desta instalação, preservando o contrato legado de objeto único. */
    public function findWebhook(string $callbackUrl): array
    {
        $urls = [rtrim($callbackUrl, '/')];
        if (substr($urls[0], -6) === '/index') {
            $urls[] = substr($urls[0], 0, -6);
        }

        $offset = 0;
        do {
            // O cliente atual recebe caminhos absolutos; manter /v3 no caminho da API.
            $response = $this->request('GET', '/v3/webhooks', ['limit' => 100, 'offset' => $offset]);
            $webhooks = $response['data'] ?? [];
            foreach ($webhooks as $webhook) {
                if (in_array(rtrim((string) ($webhook['url'] ?? ''), '/'), $urls, true)) {
                    unset($webhook['authToken']);
                    return $webhook;
                }
            }
            $offset += count($webhooks);
        } while (!empty($response['hasMore']) && count($webhooks) > 0);

        return [];
    }

    /** Cria o webhook de pagamentos com o mesmo segredo já salvo no recebedor. */
    public function createWebhook(array $payload): array
    {
        require_once __DIR__ . '/../../helpers/asaas_webhook_helper.php';

        $secret = (string) $this->provider->setting('webhook_secret');
        if ($secret === '') {
            throw new RuntimeException('Informe e salve o token de autenticação do webhook nas configurações do Asaas antes de cadastrá-lo pela API.');
        }
        $error = \asaas_webhook_token_validation_error($secret, null, [$this->provider->apiKey()]);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $payload['authToken'] = $secret;
        $payload += [
            'name' => 'Perfex CRM - Asaas',
            'apiVersion' => 3,
            'enabled' => true,
            'interrupted' => false,
            'sendType' => 'SEQUENTIALLY',
            'events' => ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'],
        ];

        $response = $this->request('POST', '/v3/webhooks', [], [], $payload);
        // O token já está salvo; não propagá-lo para saídas de diagnóstico legadas.
        unset($response['authToken']);
        return $response;
    }

    /**
     * @return array{processed: bool, duplicated: bool, message: string, http_status: int}
     */
    public function handleWebhook(array $headers, string $rawBody): array
    {
        $secret = (string) $this->provider->setting('webhook_secret');
        if ($secret !== '') {
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (array_key_exists('asaas-access-token', $headers)) {
                // O header oficial tem precedência: não aceitar fallback se ele for inválido.
                $token = $headers['asaas-access-token'];
                $authenticated = is_string($token) && hash_equals($secret, $token);
            } else {
                // Compatibilidade com integrações existentes; novos webhooks usam o header oficial.
                $headerToken = $headers['x-asaas-webhook-token'] ?? $headers['x-webhook-token'] ?? null;
                $queryToken = $_GET['token'] ?? null;
                $authenticated = (is_string($headerToken) && hash_equals($secret, $headerToken))
                    || (is_string($queryToken) && hash_equals($secret, $queryToken));
            }

            if (!$authenticated) {
                return [
                    'processed' => false,
                    'duplicated' => false,
                    'message' => 'Token inválido.',
                    'http_status' => 401,
                ];
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [
                'processed' => false,
                'duplicated' => false,
                'message' => 'Payload inválido.',
                'http_status' => 400,
            ];
        }

        $candidateId = $payload['id'] ?? $payload['eventId'] ?? null;
        $eventId = is_string($candidateId) && $candidateId !== '' ? $candidateId : md5($rawBody);
        $eventTable = db_prefix() . 'asaas_webhook_events';
        $operation = 'webhook:' . $eventId;
        $started = false;
        $receiptLockInvoiceId = null;

        try {
            $hasEventTable = $this->ci->db->table_exists($eventTable);
            $existing = $hasEventTable ? $this->ci->db->get_where($eventTable, ['event_id' => $eventId])->row_array() : [];
            if (($existing['process_status'] ?? null) === 'processed') {
                return $this->webhookResult(true, true, 'Evento já processado.');
            }

            $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
            $externalReference = is_string($payment['externalReference'] ?? null) ? $payment['externalReference'] : null;
            $paymentId = is_string($payment['id'] ?? null) ? $payment['id'] : null;
            $status = $payment['status'] ?? null;
            $receiptEvent = !isset($payload['event']) || in_array($payload['event'], ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'PAYMENT_DUNNING_RECEIVED'], true);
            $invoice = null;
            if ($externalReference !== null && $externalReference !== '') {
                $this->ci->db->where('hash', $externalReference);
                $invoice = $this->ci->db->get(db_prefix() . 'invoices')->row();
            }

            if ($invoice) {
                // A timeout can leave the quote persisted before its validated allocation map.
                // Recover via GET before acquiring our own lock/transaction; the checkout owns
                // its recovery lock and still cannot repeat a capture. Already mapped events do no HTTP.
                if ($receiptEvent && empty($payment['deleted']) && in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)
                    && $paymentId !== null && ($payment['billingType'] ?? null) === 'CREDIT_CARD') {
                    $cache = \asaas_lifecycle_cached_payment(\asaas_lifecycle_map((int) $invoice->id));
                    $pricing = \asaas_card_receipt_pricing($cache);
                    if ($pricing !== null && !array_key_exists('allocations', $pricing)
                        && !\asaas_lifecycle_should_ignore_payment((int) $invoice->id, $paymentId)) {
                        if ($this->gateway === null) { throw new \AsaasCardReceiptException('allocation_missing'); }
                        $this->gateway->card_checkout()->reconcile($invoice);
                    }
                }
                \asaas_receipt_lock((int) $invoice->id);
                $receiptLockInvoiceId = (int) $invoice->id;
            }

            // Some Perfex variants already own an independent payment transaction.
            // Their writer rejects an outer transaction; its committed receipt is
            // recovered by transactionid/invoiceid if recording this event fails.
            $atomicPaymentWriter = \asaas_lifecycle_has_atomic_payment_writer();
            if (!$atomicPaymentWriter) {
                \asaas_lifecycle_begin($operation);
                $started = true;
            }
            if ($invoice) {
                if (!$atomicPaymentWriter) {
                    \asaas_lifecycle_lock_invoice((int) $invoice->id);
                }
                // A manual settlement may have committed while this callback waited for its lock.
                $this->ci->db->where('id', (int) $invoice->id);
                $invoice = $this->ci->db->get(db_prefix() . 'invoices')->row();
            }

            $existing = $hasEventTable ? $this->ci->db->get_where($eventTable, ['event_id' => $eventId])->row_array() : [];
            if (($existing['process_status'] ?? null) === 'processed') {
                if ($started) {
                    \asaas_lifecycle_finish($operation);
                }
                return $this->webhookResult(true, true, 'Evento já processado.');
            }
            if ($hasEventTable && !$existing) {
                $inserted = $this->ci->db->insert($eventTable, [
                    'event_id' => $eventId,
                    'event_type' => is_string($payload['event'] ?? null) ? $payload['event'] : null,
                    'asaas_payment_id' => $paymentId,
                    'invoice_id' => $invoice?->id,
                    'received_at' => date('Y-m-d H:i:s'),
                    'payload' => json_encode(\asaas_card_safe_payload($payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'process_status' => 'received',
                ]);
                if ($inserted === false) {
                    throw new RuntimeException('Não foi possível registrar o evento do webhook.');
                }
            }

            if ($invoice && $receiptEvent && empty($payment['deleted'])
                && in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                if ($paymentId === null || $paymentId === '') {
                    throw new RuntimeException('O evento de recebimento não informou o identificador da cobrança.');
                }
                $ignored = \asaas_lifecycle_should_ignore_payment((int) $invoice->id, $paymentId);
                $cache = $ignored ? [] : \asaas_lifecycle_cached_payment(\asaas_lifecycle_map((int) $invoice->id));
                $principal = $ignored ? null : \asaas_card_receipt_principal($payment, $cache);
                $alreadyRecorded = $this->hasRecordedPayment((int) $invoice->id, $paymentId, $principal);
                if (!$ignored && !$alreadyRecorded && $principal !== null && (int) $invoice->status === 2) {
                    throw new \AsaasCardReceiptException('balance_mismatch');
                }
                if (!$ignored && !$alreadyRecorded && !in_array((int) $invoice->status, [2, 5, 6], true)) {
                    if ($this->gateway === null) {
                        throw new RuntimeException('O gateway Asaas não está disponível para registrar o recebimento.');
                    }
                    $balance = get_invoice_total_left_to_pay((int) $invoice->id, $invoice->total);
                    if (!is_numeric($balance) || !is_finite((float) $balance)) {
                        throw new RuntimeException('Não foi possível apurar o saldo da fatura para registrar o recebimento.');
                    }
                    if ((float) $balance > 0) {
                        $receipt = [
                            'amount' => \asaas_card_receipt_amount($payment, $balance, $cache),
                            'invoiceid' => $invoice->id,
                            'paymentmode' => 'Asaas',
                            'paymentmethod' => is_string($payment['billingType'] ?? null) ? $payment['billingType'] : 'Asaas',
                            'transactionid' => $paymentId,
                        ];
                        $note = \asaas_card_receipt_note($payment, $cache);
                        if ($note !== '') { $receipt['note'] = $note; }
                        $added = \asaas_lifecycle_remote_payment(fn () => $this->gateway->addPayment($receipt));
                        if ($added !== true) {
                            throw new RuntimeException('O Perfex não confirmou o registro do recebimento da cobrança.');
                        }
                    } elseif ($principal !== null) {
                        throw new \AsaasCardReceiptException('balance_mismatch');
                    }
                }
            }

            if ($hasEventTable) {
                $this->ci->db->where('event_id', $eventId);
                $updated = $this->ci->db->update($eventTable, [
                    'processed_at' => date('Y-m-d H:i:s'),
                    'process_status' => 'processed',
                    'error_message' => null,
                ]);
                if ($updated === false) {
                    throw new RuntimeException('Não foi possível concluir o registro do evento do webhook.');
                }
            }

            if ($started) {
                \asaas_lifecycle_finish($operation);
            }
            return $this->webhookResult(true, false, 'Evento processado.');
        } catch (\Throwable $exception) {
            if ($started) {
                try {
                    \asaas_lifecycle_transaction()->rollback();
                    \asaas_lifecycle_finish($operation);
                } catch (\Throwable $rollbackError) {
                    $this->log('error', 'Falha ao reverter o processamento do webhook Asaas.', ['exception' => get_class($rollbackError)]);
                }
            }
            $this->log('error', 'Não foi possível concluir o recebimento pelo webhook Asaas.', [
                'event_id' => $eventId,
                'exception' => get_class($exception),
                'reason' => $exception instanceof \AsaasCardReceiptException ? $exception->reason() : 'receipt_failed',
                'detail' => $exception instanceof \AsaasCardReceiptException ? $exception->getMessage() : null,
            ]);
            return $this->webhookResult(false, false, 'Falha interna ao processar webhook.', 500);
        } finally {
            if ($receiptLockInvoiceId !== null) {
                try {
                    \asaas_receipt_unlock($receiptLockInvoiceId);
                } catch (\Throwable $unlockError) {
                    $this->log('error', 'Não foi possível liberar a proteção de recebimento da fatura.', [
                        'invoice_id' => $receiptLockInvoiceId,
                        'exception' => get_class($unlockError),
                    ]);
                }
            }
        }
    }

    private function webhookResult(bool $processed, bool $duplicated, string $message, int $httpStatus = 200): array
    {
        return ['processed' => $processed, 'duplicated' => $duplicated, 'message' => $message, 'http_status' => $httpStatus];
    }

    private function hasRecordedPayment(int $invoiceId, string $paymentId, ?int $expectedPrincipal = null): bool
    {
        $table = db_prefix() . 'invoicepaymentrecords';
        if (!$this->ci->db->table_exists($table)
            || !$this->ci->db->field_exists('invoiceid', $table)
            || !$this->ci->db->field_exists('transactionid', $table)) {
            return false;
        }
        if ($expectedPrincipal !== null) {
            if (!$this->ci->db->field_exists('amount', $table)) { throw new \AsaasCardReceiptException('allocation_invalid'); }
            $records = $this->ci->db->get_where($table, ['transactionid' => $paymentId])->result_array();
            if ($records === []) { return false; }
            if (count($records) !== 1 || (int) ($records[0]['invoiceid'] ?? 0) !== $invoiceId
                || \asaas_payment_amount_to_cents($records[0]['amount'] ?? null) !== $expectedPrincipal) {
                throw new \AsaasCardReceiptException('allocation_invalid');
            }
            return true;
        }
        return (bool) $this->ci->db->get_where($table, ['invoiceid' => $invoiceId, 'transactionid' => $paymentId])->row_array();
    }

    public function healthStatus(): array
    {
        return $this->provider->healthCheck();
    }

    private function normalizeClient($perfexClient): array
    {
        if (is_array($perfexClient)) {
            return $perfexClient;
        }

        if (is_object($perfexClient)) {
            return get_object_vars($perfexClient);
        }

        return [];
    }

    private function normalizeDocument(string $document): string
    {
        return preg_replace('/[^0-9]/', '', $document);
    }

    private function normalizePostalCode(string $postalCode): string
    {
        return preg_replace('/[^0-9]/', '', $postalCode);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        try {
            \asaas_diagnostic_log($level, $message, $context, $this->shouldWriteFileLog());
        } catch (\Throwable $exception) {
            // A diagnostic failure must never turn an accepted charge into a failed request.
            error_log('[Asaas] Não foi possível registrar o diagnóstico da integração.');
        }
    }


    private function shouldWriteFileLog(): bool
    {
        if ($this->gateway === null || !method_exists($this->gateway, 'isFileLogEnabled')) {
            return false;
        }

        return (bool) $this->gateway->isFileLogEnabled();
    }

    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['apiKey', 'api_key', 'access_token', 'authorization', 'token',
            'authToken', 'webhook_secret', 'asaas-access-token', 'x-asaas-webhook-token', 'x-webhook-token'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
                continue;
            }

            $normalizedKey = strtolower((string) $key);
            foreach ($sensitiveKeys as $sensitiveKey) {
                if ($normalizedKey === strtolower($sensitiveKey)) {
                    $context[$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $context;
    }

    private function getLogColumns(string $table): array
    {
        if ($this->logColumns !== null) {
            return $this->logColumns;
        }

        $columns = [];
        $query = $this->ci->db->query('SHOW COLUMNS FROM `' . $table . '`');
        if ($query) {
            foreach ($query->result_array() as $row) {
                if (!empty($row['Field'])) {
                    $columns[] = $row['Field'];
                }
            }
        }

        $this->logColumns = $columns;

        return $columns;
    }
}
