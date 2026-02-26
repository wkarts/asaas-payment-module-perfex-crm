<?php

declare(strict_types=1);

namespace Asaas\Sdk\Service\Generated;

use Asaas\Sdk\Service\AbstractService;

class CustomerService extends AbstractService
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function listCustomers(array $query = [], array $headers = []): mixed
    {
        return $this->request('GET', '/customers', [], $query, $headers);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function createCustomer(array $payload, array $headers = []): mixed
    {
        return $this->request('POST', '/customers', [], [], $headers, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function updateCustomer(string $customerId, array $payload, array $headers = []): mixed
    {
        return $this->request('POST', '/customers/{id}', ['id' => $customerId], [], $headers, $payload);
    }

    /**
     * @param array<string, string> $headers
     */
    public function getCustomer(string $customerId, array $headers = []): mixed
    {
        return $this->request('GET', '/customers/{id}', ['id' => $customerId], [], $headers);
    }
}
