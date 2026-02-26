<?php

declare(strict_types=1);

use Asaas\AsaasAdapter;
use PHPUnit\Framework\TestCase;

final class AsaasAdapterPrefacesTest extends TestCase
{
    public function testCreatePrefaceValidatesPayload(): void
    {
        $adapter = new AsaasAdapter(new AsaasSdkProvider(new App_gateway()));
        $response = $adapter->createPreface([]);

        $this->assertFalse($response['success']);
        $this->assertSame('validation_error', $response['error']['type']);
    }

    public function testGetPrefaceValidatesId(): void
    {
        $adapter = new AsaasAdapter(new AsaasSdkProvider(new App_gateway()));
        $response = $adapter->getPreface('');

        $this->assertFalse($response['success']);
        $this->assertSame('prefaceId é obrigatório.', $response['error']['message']);
    }

    public function testUpdatePrefaceValidatesMethod(): void
    {
        $adapter = new AsaasAdapter(new AsaasSdkProvider(new App_gateway()));
        $response = $adapter->updatePreface('pref_1', ['name' => 'ok'], 'POST');

        $this->assertFalse($response['success']);
        $this->assertSame('validation_error', $response['error']['type']);
    }

    public function testDeletePrefaceValidatesId(): void
    {
        $adapter = new AsaasAdapter(new AsaasSdkProvider(new App_gateway()));
        $response = $adapter->deletePreface('   ');

        $this->assertFalse($response['success']);
        $this->assertSame('prefaceId é obrigatório.', $response['error']['message']);
    }
}

