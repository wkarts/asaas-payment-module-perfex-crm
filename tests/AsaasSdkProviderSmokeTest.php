<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AsaasSdkProviderSmokeTest extends TestCase
{
    public function testProviderAndSdkAutoload(): void
    {
        $provider = new AsaasSdkProvider(new App_gateway());
        $provider->ensureComposerAutoload();

        $this->assertTrue(class_exists('AsaasSdkProvider'));
        $this->assertTrue(class_exists('\\Asaas\\Sdk\\AsaasSdk'));
    }

    public function testProviderBaseUrlsMatchSdkEnvironments(): void
    {
        $provider = new AsaasSdkProvider(new App_gateway());
        $provider->ensureComposerAutoload();

        $this->assertSame(\Asaas\Sdk\Http\Environment::Production->value, $provider->baseUrl());
        $this->assertSame('https://api.asaas.com', $provider->baseHost());
    }

    public function testProviderSandboxBaseUrlMatchesSdk(): void
    {
        $provider = new AsaasSdkProvider(new class extends App_gateway {
            public function getSetting(string $name): mixed
            {
                if ($name === 'sandbox') {
                    return '1';
                }

                return null;
            }
        });
        $provider->ensureComposerAutoload();

        $this->assertSame(\Asaas\Sdk\Http\Environment::Sandbox->value, $provider->baseUrl());
        $this->assertSame('https://api-sandbox.asaas.com', $provider->baseHost());
    }
}
