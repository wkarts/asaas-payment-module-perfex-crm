<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/AsaasReportCatalog.php';
require_once __DIR__ . '/AsaasReportException.php';
require_once __DIR__ . '/AsaasReportExport.php';

/** Read-only financial reporting; no receipt, mandate or account mutation is performed here. */
final class AsaasReports
{
    public const MAX_EXPORT_ROWS = 100000;
    public const MAX_EXPORT_BYTES = 67108864;
    public const MAX_EXPORT_SECONDS = 45;
    private ?object $sdk;
    private \Closure $clock;
    private ?string $environment;

    public function __construct(private object $ci, ?object $sdk = null, ?callable $clock = null, ?string $environment = null)
    {
        $this->sdk = $sdk;
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
        $this->environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : null;
    }

    public function page(string $report, array $filters = []): array
    {
        $definition = $this->definition($report);
        [$query, $path, $normalized, $offset, $limit] = $this->query($definition, $filters);
        $startedAt = date('c');
        $response = $this->request($definition, $path, $query);
        if ($definition['kind'] === 'summary') {
            $rows = [$this->project($response, $definition, true)];
            $total = 1;
            $more = false;
            $offset = 0;
        } elseif ($definition['kind'] === 'collection') {
            $all = $this->collection($response);
            $total = count($all);
            $rows = array_map(fn (array $row): array => $this->project($row, $definition), array_slice($all, $offset, $limit));
            $more = $offset + count($rows) < $total;
        } else {
            [$raw, $total, $more] = $this->listPage($response, $offset, $limit);
            $rows = array_map(fn (array $row): array => $this->project($row, $definition), $raw);
        }
        $this->audit('info', 'report_query', $report, count($rows));
        return ['ok' => true, 'report' => [
            'id' => $report, 'title' => $definition['title'], 'description' => $definition['description'],
            'kind' => $definition['kind'], 'columns' => $definition['columns'], 'rows' => $rows,
            'offset' => $offset, 'per_page' => $limit, 'returned' => count($rows), 'total' => $total,
            'has_more' => $more, 'next_offset' => $more ? $offset + count($rows) : null,
            'environment' => $this->environmentName(), 'environment_label' => $this->environmentLabel(),
            'started_at' => $startedAt, 'generated_at' => date('c'), 'filters' => $normalized,
            'filter_description' => $this->filterDescription($definition, $normalized),
        ]];
    }

    /**
     * Fetch all pages before exposing a download. Memory holds only one API page plus row IDs.
     * On a timeout, rate limit, changed list or malformed page the private file is discarded.
     */
    public function export(string $report, array $filters = []): AsaasReportExport
    {
        $definition = $this->definition($report);
        [$query, $path, $normalized] = $this->query($definition, $filters, true);
        $deadline = ($this->clock)() + self::MAX_EXPORT_SECONDS;
        $startedAt = date('c');
        $stream = tmpfile();
        if ($stream === false) { throw new AsaasReportException('export_storage', 'Não foi possível preparar o arquivo. Verifique o diretório temporário do servidor.'); }
        $metadata = stream_get_meta_data($stream);
        if (!isset($metadata['uri']) || !chmod($metadata['uri'], 0600)) {
            fclose($stream);
            throw new AsaasReportException('export_storage', 'Não foi possível preparar um arquivo temporário privado.');
        }
        $rows = 0;
        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) { throw new AsaasReportException('export_storage', 'Não foi possível gravar o arquivo temporário.'); }
            $headers = ['Ambiente', 'Relatório', 'Consulta iniciada em', 'Filtros'];
            foreach ($definition['columns'] as $column) { $headers[] = $column['label'] . ($column['type'] === 'money' ? ' (R$)' : ''); }
            $this->csv($stream, $headers);
            $prefix = null;
            $seen = [];
            $expected = null;
            $offset = 0;
            do {
                $this->checkBudget($deadline, $stream, $rows);
                if ($definition['kind'] === 'list') { $query['offset'] = $offset; }
                $response = $this->request($definition, $path, $query);
                $this->checkBudget($deadline, $stream, $rows);
                if ($prefix === null) {
                    $prefix = array_map([self::class, 'csvText'], [$this->environmentLabel(), $definition['title'], $startedAt, $this->filterDescription($definition, $normalized)]);
                }
                if ($definition['kind'] === 'summary') {
                    $raw = [$response]; $more = false; $total = 1;
                } elseif ($definition['kind'] === 'collection') {
                    $raw = $this->collection($response); $more = false; $total = count($raw);
                } else {
                    [$raw, $total, $more] = $this->listPage($response, $offset, 100);
                    if ($expected !== null && $total !== null && $total !== $expected) {
                        throw new AsaasReportException('report_changed', 'A lista mudou durante a exportação. Nenhum arquivo parcial foi disponibilizado; consulte novamente ou reduza o período.');
                    }
                    $expected = $expected ?? $total;
                }
                foreach ($raw as $record) {
                    if ($definition['kind'] === 'list') {
                        $identifier = $record['id'];
                        if (isset($seen[$identifier])) {
                            throw new AsaasReportException('repeated_page', 'A API repetiu registros durante a paginação. A exportação foi interrompida para evitar um relatório incompleto ou duplicado.');
                        }
                        $seen[$identifier] = true;
                    }
                    $row = $this->project($record, $definition, $definition['kind'] === 'summary');
                    $values = $prefix;
                    foreach ($definition['columns'] as $column) {
                        $value = $row[$column['key']];
                        if ($value === null) { $values[] = ''; }
                        elseif ($column['type'] === 'boolean') { $values[] = $value ? 'Sim' : 'Não'; }
                        elseif (in_array($column['type'], ['money', 'number'], true)) { $values[] = str_replace('.', ',', (string) $value); }
                        elseif ($column['type'] === 'integer') { $values[] = (string) $value; }
                        else { $values[] = self::csvText((string) $value); }
                    }
                    $this->csv($stream, $values);
                    $rows++;
                    $this->checkBudget($deadline, $stream, $rows);
                }
                $offset += count($raw);
            } while ($more);
            if ($expected !== null && $rows !== $expected) {
                throw new AsaasReportException('incomplete_report', 'A quantidade recebida não corresponde ao total informado pela API. Gere o relatório novamente.');
            }
            if (!fflush($stream)) { throw new AsaasReportException('export_storage', 'Não foi possível concluir a gravação do arquivo.'); }
            $bytes = ftell($stream);
            if ($bytes === false || !rewind($stream)) { throw new AsaasReportException('export_storage', 'Não foi possível concluir o arquivo.'); }
            $export = new AsaasReportExport($stream, 'asaas-' . $report . '-' . $this->environmentName() . '-' . date('Ymd-His') . '.csv',
                $rows, $bytes, $this->environmentName(), $startedAt);
            $this->audit('info', 'report_export', $report, $rows);
            return $export;
        } catch (\Throwable $error) {
            fclose($stream);
            $this->audit('error', 'report_export', $report, null,
                $error instanceof AsaasReportException ? $error->reason : 'export_failed');
            throw $error;
        }
    }

    private function definition(string $report): array
    {
        $definitions = AsaasReportCatalog::definitions();
        if (!isset($definitions[$report])) { throw new \InvalidArgumentException('Escolha um relatório disponível.'); }
        return $definitions[$report];
    }

    private function query(array $definition, array $filters, bool $export = false): array
    {
        $allowed = array_column($definition['filters'], 'key');
        if ($definition['dates'] !== []) { array_push($allowed, 'date_field', 'date_from', 'date_to'); }
        array_push($allowed, 'offset', 'per_page');
        foreach ($filters as $key => $value) {
            if (!in_array($key, $allowed, true) || ($value !== null && !is_scalar($value))) {
                throw new \InvalidArgumentException('Há filtros incompatíveis com o relatório selecionado.');
            }
        }
        $offset = $this->integer($filters['offset'] ?? 0, 0, 10000000, 'A posição da página é inválida.');
        $limit = $this->integer($filters['per_page'] ?? 25, 1, 100, 'Escolha de 1 a 100 registros por página.');
        if ($export) { $offset = 0; $limit = 100; }
        $query = [];
        $normalized = [];
        $path = [];
        foreach ($definition['filters'] as $field) {
            $key = $field['key'];
            $value = trim((string) ($filters[$key] ?? $definition['defaults'][$key] ?? ''));
            if ($value === '') {
                if ($field['required'] ?? false) { throw new \InvalidArgumentException('Preencha: ' . $field['label'] . '.'); }
                continue;
            }
            if (strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException('O filtro ' . $field['label'] . ' é inválido.'); }
            if ($field['type'] === 'identifier' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D', $value)) {
                throw new \InvalidArgumentException('Informe um identificador válido em: ' . $field['label'] . '.');
            }
            if ($field['type'] === 'select' && !array_key_exists($value, $field['options'])) {
                throw new \InvalidArgumentException('Escolha uma opção válida em: ' . $field['label'] . '.');
            }
            $normalized[$key] = $value;
            if ($key === ($definition['path_filter'] ?? null)) { $path['id'] = $value; }
            else { $query[$key] = in_array($key, $definition['booleans'], true) ? $value === 'true' : $value; }
        }
        if ($definition['dates'] !== []) {
            $field = (string) ($filters['date_field'] ?? array_key_first($definition['dates']));
            if (!isset($definition['dates'][$field])) { throw new \InvalidArgumentException('Escolha o campo de data do relatório.'); }
            $from = $this->dateFilter($filters['date_from'] ?? '');
            $to = $this->dateFilter($filters['date_to'] ?? '');
            if ($from !== '' && $to !== '' && $from > $to) { throw new \InvalidArgumentException('A data inicial não pode ser posterior à data final.'); }
            if ($from !== '' || $to !== '') {
                $normalized['date_field'] = $field;
                $range = $definition['range'] ?? [$field . '[ge]', $field . '[le]'];
                if ($from !== '') { $query[$range[0]] = $from; $normalized['date_from'] = $from; }
                if ($to !== '') { $query[$range[1]] = $to; $normalized['date_to'] = $to; }
            }
        }
        if ($definition['kind'] === 'list') { $query['offset'] = $offset; $query['limit'] = $limit; }
        return [$query, $path, $normalized, $offset, $limit];
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $message): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,8}$/D', (string) $value)
            || (int) $value < $minimum || (int) $value > $maximum) { throw new \InvalidArgumentException($message); }
        return (int) $value;
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \InvalidArgumentException('Informe datas válidas no formato AAAA-MM-DD.');
        }
        return $value;
    }

    private function request(array $definition, array $path, array $query): array
    {
        try {
            if ($this->sdk === null) {
                $this->ci->load->library('asaas/asaas_gateway');
                $provider = $this->ci->asaas_gateway->getProvider();
                // One SDK instance binds every page to the same environment and credential snapshot.
                $this->sdk = $provider->sdk();
                $this->environment = $provider->env();
            }
            $service = $definition['service'];
            $method = $definition['method'];
            if (!isset($this->sdk->$service) || !is_object($this->sdk->$service) || !method_exists($this->sdk->$service, $method)) {
                throw new AsaasReportException('sdk_outdated', 'A SDK carregada não oferece este relatório. Atualize o vendor do módulo para a versão distribuída e confira outros autoloaders do CRM.');
            }
            // SDK wrappers take pathParams first. Named query is deliberate, including false booleans.
            $response = $this->sdk->$service->$method(pathParams: $path, query: $query, payload: null);
            if (!is_array($response)) { throw $this->malformed(); }
            return $response;
        } catch (AsaasReportException $error) {
            $this->audit('error', 'report_query', $definition['id'], null, $error->reason);
            throw $error;
        } catch (\Throwable $error) {
            $status = null;
            if ($error instanceof \Asaas\Sdk\Exception\ApiException) { $status = $error->getStatusCode(); }
            $reason = 'api_unavailable';
            $message = 'Não foi possível consultar o relatório no Asaas. Confira a conexão e os registros da integração.';
            if ($status === 401 || $status === 403) { $reason = 'api_access_denied'; $message = 'A conta Asaas não autorizou esta consulta. Confira a chave do ambiente ativo e a disponibilidade do recurso na conta.'; }
            elseif ($status === 429) { $reason = 'api_rate_limit'; $message = 'O Asaas limitou as consultas neste momento. Aguarde e gere o relatório novamente.'; }
            elseif ($status === 404) { $reason = 'resource_not_found'; $message = 'O recurso informado não foi encontrado na conta Asaas do ambiente ativo.'; }
            elseif ($status === 400) { $reason = 'api_filter_rejected'; $message = 'O Asaas não aceitou os filtros desta consulta. Revise os identificadores, as datas e a situação selecionada.'; }
            $this->audit('error', 'report_query', $definition['id'], null, $reason, $status);
            // Remote exceptions can contain credentials, personal data or raw request/response bodies.
            throw new AsaasReportException($reason, $message, $status === 429 ? 429 : 503);
        }
    }

    private function listPage(array $response, int $offset, int $limit): array
    {
        if (!is_array($response['data'] ?? null) || !array_is_list($response['data']) || !is_bool($response['hasMore'] ?? null)
            || count($response['data']) > $limit || ($response['hasMore'] && $response['data'] === [])) { throw $this->malformed(); }
        if (isset($response['offset']) && (!is_int($response['offset']) || $response['offset'] !== $offset)) { throw $this->malformed(); }
        $total = $response['totalCount'] ?? null;
        $count = count($response['data']);
        if ($total !== null && (!is_int($total) || $total < 0 || ($count > 0 && $total < $offset + $count)
            || ($response['hasMore'] && $total <= $offset + $count)
            || (!$response['hasMore'] && $total > $offset + $count))) { throw $this->malformed(); }
        $seen = [];
        foreach ($response['data'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D', $row['id'])
                || isset($seen[$row['id']])) { throw $this->malformed(); }
            $seen[$row['id']] = true;
        }
        return [$response['data'], $total, $response['hasMore']];
    }

    /** Refunds are scoped to one payment; their endpoint has no documented pagination filters. */
    private function collection(array $response): array
    {
        if (!is_array($response['data'] ?? null) || !array_is_list($response['data']) || ($response['hasMore'] ?? null) !== false
            || count($response['data']) > self::MAX_EXPORT_ROWS) { throw $this->malformed(); }
        if (isset($response['totalCount']) && $response['totalCount'] !== count($response['data'])) { throw $this->malformed(); }
        foreach ($response['data'] as $row) { if (!is_array($row)) { throw $this->malformed(); } }
        return $response['data'];
    }

    /** Only declared scalar columns leave the service. Card objects, tokens and QR payloads never do. */
    private function project(array $row, array $definition, bool $required = false): array
    {
        $result = [];
        foreach ($definition['columns'] as $column) {
            $value = $row;
            foreach (explode('.', $column['key']) as $segment) { $value = is_array($value) ? ($value[$segment] ?? null) : null; }
            if ($value === null) {
                if ($required) { throw $this->malformed(); }
                $result[$column['key']] = null;
                continue;
            }
            if (!is_scalar($value)) { throw $this->malformed(); }
            switch ($column['type']) {
                case 'money':
                case 'number':
                    if ((!is_string($value) && !is_int($value) && !is_float($value)) || strlen((string) $value) > 40
                        || !is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 999999999999.99) { throw $this->malformed(); }
                    $result[$column['key']] = $column['type'] === 'money' ? number_format((float) $value, 2, '.', '') : (string) $value;
                    break;
                case 'integer':
                    if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?[0-9]{1,15}$/D', (string) $value)) { throw $this->malformed(); }
                    $result[$column['key']] = (int) $value;
                    break;
                case 'boolean':
                    if (!is_bool($value)) { throw $this->malformed(); }
                    $result[$column['key']] = $value;
                    break;
                case 'date':
                    $result[$column['key']] = is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[ T][0-9:.+Z-]+)?$/D', $value)
                        ? substr($value, 0, 35) : null;
                    break;
                default:
                    $result[$column['key']] = self::safeText((string) $value);
            }
        }
        return $result;
    }

    private function filterDescription(array $definition, array $filters): string
    {
        $parts = [];
        foreach ($definition['filters'] as $field) {
            if (isset($filters[$field['key']])) {
                $value = $filters[$field['key']];
                $parts[] = $field['label'] . ': ' . ($field['options'][$value] ?? $value);
            }
        }
        if (isset($filters['date_field'])) {
            $parts[] = 'Data de ' . $definition['dates'][$filters['date_field']] . ': '
                . ($filters['date_from'] ?? 'início do histórico') . ' até ' . ($filters['date_to'] ?? 'fim do histórico');
        }
        return $parts === [] ? 'Sem filtros adicionais' : self::safeText(implode(' | ', $parts));
    }

    private function environmentName(): string
    {
        if ($this->environment === null) {
            $this->environment = function_exists('get_option') && (string) \get_option('paymentmethod_asaas_sandbox') === '1' ? 'sandbox' : 'production';
        }
        return $this->environment;
    }

    private function environmentLabel(): string { return $this->environmentName() === 'sandbox' ? 'Homologação' : 'Produção'; }

    public static function safeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = preg_replace('/\$aact_[A-Za-z0-9_+=.\/-]+/', '[credencial oculta]', $value) ?? '';
        // Bound the display/export cell without cutting a UTF-8 code point.
        if (preg_match('/^.{0,1500}/us', $value, $match)) { return $match[0]; }
        return ''; // Invalid UTF-8 is never copied verbatim into a financial file.
    }

    public static function csvText(string $value): string
    {
        $value = self::safeText($value);
        // A quoted CSV field can still be interpreted as a spreadsheet formula.
        return preg_match('/^[\s\p{Z}\p{C}]*[=+@-]/u', $value) ? "'" . $value : $value;
    }

    /** @param resource $stream */
    private function csv($stream, array $values): void
    {
        if (fputcsv($stream, $values, ';', '"', '') === false) { throw new AsaasReportException('export_storage', 'Não foi possível gravar o relatório.'); }
    }

    /** @param resource $stream */
    private function checkBudget(float $deadline, $stream, int $rows): void
    {
        $bytes = ftell($stream);
        if (($this->clock)() > $deadline || $rows > self::MAX_EXPORT_ROWS || $bytes === false || $bytes > self::MAX_EXPORT_BYTES) {
            throw new AsaasReportException('export_limit', 'O relatório excedeu o tempo ou o volume seguro desta execução. Nenhum arquivo parcial foi disponibilizado. Reduza o período ou use filtros mais específicos.');
        }
    }

    private function malformed(): AsaasReportException
    {
        return new AsaasReportException('incomplete_response', 'O Asaas retornou dados ou paginação inconsistentes. A consulta não foi apresentada como completa; tente novamente.');
    }

    private function audit(string $level, string $operation, string $report, ?int $rows, ?string $reason = null, ?int $httpStatus = null): void
    {
        if (!function_exists('asaas_diagnostic_log')) { return; }
        try {
            \asaas_diagnostic_log($level, $level === 'error' ? 'Falha na consulta de relatório Asaas.' : 'Consulta de relatório Asaas concluída.', [
                'source' => 'reports', 'operation' => $operation, 'report' => $report, 'rows' => $rows,
                'environment' => $this->environmentName(), 'error_code' => $reason, 'http_status' => $httpStatus,
            ]);
        } catch (\Throwable) { /* Diagnostics must not break a read-only report. */ }
    }
}
