<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

/** A completed private temporary file. Partial exports are never handed to the controller. */
final class AsaasReportExport
{
    /** @param resource $stream */
    public function __construct(private $stream, public readonly string $filename, public readonly int $rows,
        public readonly int $bytes, public readonly string $environment, public readonly string $startedAt)
    {
    }

    /** @return resource */
    public function stream()
    {
        if (!is_resource($this->stream) || !rewind($this->stream)) {
            throw new AsaasReportException('export_unavailable', 'O arquivo não está mais disponível. Gere o relatório novamente.');
        }
        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) { fclose($this->stream); }
    }

    public function __destruct() { $this->close(); }
}
