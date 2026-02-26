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
        $response = $this->sdk()->customer->listCustomers(['cpfCnpj' => $document]);
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

            $createdResponse = $this->sdk()->customer->createCustomer($payload);
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
        return $this->sdk()->customer->getCustomer($asaasCustomerId);
    }

    public function updateCustomer(string $asaasCustomerId, array $payload): array
    {
        return $this->sdk()->customer->updateCustomer($asaasCustomerId, $payload);
    }

    public function listCustomers(array $query): array
    {
        return $this->sdk()->customer->listCustomers($query);
    }

    public function createCustomer(array $payload): array
    {
        return $this->sdk()->customer->createCustomer($payload);
    }

    public function createPayment(array $payload): array
    {
        return $this->sdk()->payment->createPayment($payload);
    }

    public function getPayment(string $paymentId): array
    {
        return $this->sdk()->payment->getPayment($paymentId);
    }

    public function listPayments(array $query): array
    {
        return $this->sdk()->payment->listPayments($query);
    }

    public function updatePayment(string $paymentId, array $payload): array
    {
        return $this->sdk()->payment->updatePayment($paymentId, $payload);
    }

    public function cancelPayment(string $paymentId): array
    {
        return $this->sdk()->payment->cancelPayment($paymentId);
    }

    public function deletePayment(string $paymentId): array
    {
        return $this->sdk()->payment->deletePayment($paymentId);
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
        return $this->sdk()->payment->getPaymentPixQrCode($paymentId);
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

    /**
     * @return array{processed: bool, duplicated: bool, message: string}
     */
    public function handleWebhook(array $headers, string $rawBody): array
    {
        $secret = (string) $this->provider->setting('webhook_secret');
        if ($secret !== '') {
            $headerToken = $headers['x-asaas-webhook-token'] ?? $headers['x-webhook-token'] ?? '';
            $queryToken = $_GET['token'] ?? '';

            if ($secret !== $headerToken && $secret !== $queryToken) {
                return [
                    'processed' => false,
                    'duplicated' => false,
                    'message' => 'Token inválido.',
                ];
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [
                'processed' => false,
                'duplicated' => false,
                'message' => 'Payload inválido.',
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
        ];
    }

    public function healthStatus(): array
    {
        return $this->provider->healthCheck();
    }


    private function sdk(?int $companyId = null): \Asaas\Sdk\AsaasSdk
    {
        return $this->provider->sdk($companyId);
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
        $sensitiveKeys = ['apiKey', 'api_key', 'access_token', 'authorization', 'token'];

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
