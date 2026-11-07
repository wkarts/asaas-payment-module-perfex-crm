<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

/** Messages are module-owned. Never retain SDK exceptions or remote response bodies. */
final class AsaasReportException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 503)
    {
        parent::__construct($message);
    }
}
