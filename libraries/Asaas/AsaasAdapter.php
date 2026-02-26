<?php

declare(strict_types=1);

namespace Asaas;

use App_gateway;
use Asaas\Sdk\Exception\ApiException;
use Asaas\Sdk\Exception\TransportException;
use AsaasSdkProvider;

final class AsaasAdapter
{
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
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            return [];
        } catch (TransportException $exception) {
            $this->log('error', 'Erro de transporte na API Asaas.', [
                'method' => $method,
                'path' => $path,
                'message' => $exception->getMessage(),
                'correlation_id' => $headers['X-Correlation-Id'] ?? null,
            ]);

            return [];
        }
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
        $sanitizedContext = json_encode($this->sanitizeContext($context), JSON_UNESCAPED_UNICODE);

        if (in_array('context_json', $columns, true)) {
            $payload['context_json'] = $sanitizedContext;
        } elseif (in_array('context', $columns, true)) {
            $payload['context'] = $sanitizedContext;
        }

        if (in_array('correlation_id', $columns, true)) {
            $payload['correlation_id'] = $context['correlation_id'] ?? null;
        }

        $this->ci->db->insert($table, $payload);
    }

    private function sanitizeContext(array $context): array
    {
        unset($context['apiKey'], $context['access_token']);

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
