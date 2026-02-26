<?php

declare(strict_types=1);

namespace Asaas\Sdk\Service\Generated;

use Asaas\Sdk\Service\AbstractService;

class PaymentService extends AbstractService
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function listPayments(array $query = [], array $headers = []): mixed
    {
        return $this->request('GET', '/payments', [], $query, $headers);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function createPayment(array $payload, array $headers = []): mixed
    {
        return $this->request('POST', '/payments', [], [], $headers, $payload);
    }

    /**
     * @param array<string, string> $headers
     */
    public function getPayment(string $paymentId, array $headers = []): mixed
    {
        return $this->request('GET', '/payments/{id}', ['id' => $paymentId], [], $headers);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function updatePayment(string $paymentId, array $payload, array $headers = []): mixed
    {
        return $this->request('POST', '/payments/{id}', ['id' => $paymentId], [], $headers, $payload);
    }

    /**
     * @param array<string, string> $headers
     */
    public function cancelPayment(string $paymentId, array $headers = []): mixed
    {
        return $this->request('POST', '/payments/{id}/cancel', ['id' => $paymentId], [], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function deletePayment(string $paymentId, array $headers = []): mixed
    {
        return $this->request('DELETE', '/payments/{id}', ['id' => $paymentId], [], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function getPaymentPixQrCode(string $paymentId, array $headers = []): mixed
    {
        return $this->request('GET', '/payments/{id}/pixQrCode', ['id' => $paymentId], [], $headers);
    }
}

