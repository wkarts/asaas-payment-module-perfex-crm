<?php

declare(strict_types=1);

namespace Asaas;

/** Never retains the original exception, which can contain card credentials. */
class AsaasCardRequestException extends \RuntimeException
{
    public function __construct(string $message, int $status, public bool $requestSent)
    {
        parent::__construct($message, $status);
    }
}
