<?php

declare(strict_types=1);

namespace Asaas;

use App_gateway;
use Asaas\Sdk\Exception\ApiException;
use Asaas\Sdk\Exception\TransportException;
use AsaasSdkProvider;
use RuntimeException;

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
        ?int $companyId = null
    ): array {
        $client = $this->provider->client($companyId);
        $correlationId = $headers['X-Correlation-Id'] ?? null;
        $headers = $this->provider->headers($headers, $correlationId);

        try {
            $response = $client->request($method, $path, $query, $headers, $payload, false, $expectsBinary);
            $this->log('info', 'Asaas request concluída.', [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            return is_array($response) ? $response : [];
        } catch (ApiException $exception) {
            $this->log('error', 'Erro na API Asaas.', [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            throw new RuntimeException(
                sprintf('Erro HTTP Asaas [%s %s]: %s', $method, $path, $exception->getMessage()),
                (int) $exception->getStatusCode(),
                $exception
            );
        } catch (TransportException $exception) {
            $this->log('error', 'Erro de transporte na API Asaas.', [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'message' => $exception->getMessage(),
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            throw new RuntimeException(
                sprintf('Erro de transporte Asaas [%s %s]: %s', $method, $path, $exception->getMessage()),
                0,
                $exception
            );
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
        $client = $this->provider->client($companyId);
        $correlationId = $headers['X-Correlation-Id'] ?? null;
        $headers = $this->provider->headers($headers, $correlationId);

        try {
            $response = $client->request($method, $path, $query, $headers, $payload, false, $expectsBinary);

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
            return [
                'success' => false,
                'data' => null,
                'error' => [
                    'type' => 'api_error',
                    'status' => $exception->getStatusCode(),
                    'message' => $exception->getMessage(),
                ],
                'meta' => [
                    'method' => $method,
                    'path' => $path,
                    'correlation_id' => $headers['X-Correlation-Id'] ?? null,
                ],
            ];
        } catch (TransportException $exception) {
            return [
                'success' => false,
                'data' => null,
                'error' => [
                    'type' => 'transport_error',
                    'status' => null,
                    'message' => $exception->getMessage(),
                ],
                'meta' => [
                    'method' => $method,
                    'path' => $path,
                    'correlation_id' => $headers['X-Correlation-Id'] ?? null,
                ],
            ];
        }
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
        return $this->request('POST', '/payments/' . $paymentId, [], [], $payload);
    }

    public function cancelPayment(string $paymentId): array
    {
        return $this->request('POST', '/payments/' . $paymentId . '/cancel');
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

        $eventId = $payload['id'] ?? $payload['eventId'] ?? md5($rawBody);
        $eventTable = db_prefix() . 'asaas_webhook_events';

        if ($this->ci->db->table_exists($eventTable)) {
            $existing = $this->ci->db->get_where($eventTable, ['event_id' => $eventId])->row_array();
            if ($existing) {
                return [
                    'processed' => true,
                    'duplicated' => true,
                    'message' => 'Evento já processado.',
                    'http_status' => 200,
                ];
            }
        }

        $payment = $payload['payment'] ?? [];
        $externalReference = $payment['externalReference'] ?? null;
        $paymentId = $payment['id'] ?? null;
        $status = $payment['status'] ?? null;

        $invoice = null;
        if ($externalReference) {
            $this->ci->db->where('hash', $externalReference);
            $invoice = $this->ci->db->get(db_prefix() . 'invoices')->row();
        }

        $receivedAt = date('Y-m-d H:i:s');
        if ($this->ci->db->table_exists($eventTable)) {
            $this->ci->db->insert($eventTable, [
                'event_id' => $eventId,
                'event_type' => $payload['event'] ?? null,
                'asaas_payment_id' => $paymentId,
                'invoice_id' => $invoice?->id,
                'received_at' => $receivedAt,
                'payload' => $rawBody,
                'process_status' => 'received',
            ]);
        }

        if ($invoice && $this->gateway && $invoice->status !== '2' && in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
            $this->gateway->addPayment([
                'amount' => $invoice->total,
                'invoiceid' => $invoice->id,
                'paymentmode' => 'Asaas',
                'paymentmethod' => $payment['billingType'] ?? 'Asaas',
                'transactionid' => $paymentId,
            ]);
        }

        if ($this->ci->db->table_exists($eventTable)) {
            $this->ci->db->where('event_id', $eventId);
            $this->ci->db->update($eventTable, [
                'processed_at' => date('Y-m-d H:i:s'),
                'process_status' => 'processed',
                'error_message' => null,
            ]);
        }

        return [
            'processed' => true,
            'duplicated' => false,
            'message' => 'Evento processado.',
            'http_status' => 200,
        ];
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
        $sanitized = $this->sanitizeContext($context);
        $sanitizedContext = json_encode($sanitized, JSON_UNESCAPED_UNICODE);

        log_message('error', '[Asaas][' . strtoupper($level) . '] ' . $message . ' | context=' . $sanitizedContext);

        $table = db_prefix() . 'asaas_logs';
        if (!$this->ci->db->table_exists($table)) {
            return;
        }

        $payload = [
            'level' => $level,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $columns = $this->getLogColumns($table);

        if (in_array('context_json', $columns, true)) {
            $payload['context_json'] = $sanitizedContext;
        } elseif (in_array('context', $columns, true)) {
            $payload['context'] = $sanitizedContext;
        }

        if (in_array('correlation_id', $columns, true)) {
            $payload['correlation_id'] = $sanitized['correlation_id'] ?? null;
        }

        $inserted = $this->ci->db->insert($table, $payload);
        if ($inserted === false) {
            $error = $this->ci->db->error();
            log_message('error', '[Asaas] Falha ao persistir log em banco: ' . json_encode($error, JSON_UNESCAPED_UNICODE));
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
