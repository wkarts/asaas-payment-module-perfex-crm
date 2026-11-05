<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Run optional diagnostic storage without activating CodeIgniter's error page. */
function asaas_diagnostic_quiet_db($db, callable $operation)
{
    $hasDebug = is_object($db) && property_exists($db, 'db_debug');
    $debug = $hasDebug ? $db->db_debug : null;
    try {
        if ($hasDebug) {
            $db->db_debug = false;
        }
        return $operation();
    } finally {
        if ($hasDebug) {
            $db->db_debug = $debug;
        }
    }
}

/** Mask stored secrets even when an upstream exception embeds them in free text. */
function asaas_diagnostic_secrets(): array
{
    $secrets = [];
    try {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $db = is_object($ci) && isset($ci->db) ? $ci->db : null;
        asaas_diagnostic_quiet_db($db, static function () use ($ci, &$secrets): void {
            if (!function_exists('get_option')) {
                return;
            }
            foreach (['webhook_secret', 'api_key', 'api_key_sandbox'] as $setting) {
                $value = get_option('paymentmethod_asaas_' . $setting);
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $secrets[] = $value;
                if ($setting !== 'webhook_secret' && isset($ci->encryption)
                    && method_exists($ci->encryption, 'decrypt')) {
                    $decrypted = $ci->encryption->decrypt($value);
                    if (is_string($decrypted) && $decrypted !== '') {
                        $secrets[] = $decrypted;
                    }
                }
            }
        });
    } catch (Throwable $exception) {
        // Redaction by key and recognizable patterns still applies without settings.
    }
    usort($secrets, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    return array_values(array_unique($secrets));
}

/** Public redactor shared by persisted records and the administrator-only viewer. */
function asaas_diagnostic_sanitize($value)
{
    try {
        return asaas_diagnostic_clean($value, asaas_diagnostic_secrets(), 0);
    } catch (Throwable $exception) {
        return '[REDACTED]';
    }
}

function asaas_diagnostic_clean($value, array $secrets, int $depth)
{
    if ($depth > 8) {
        return '[LIMIT]';
    }
    if (is_array($value)) {
        $clean = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 100) {
                $clean['_truncated'] = true;
                break;
            }
            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            $sensitive = preg_match('/token|secret|password|passwd|authorization|credential|apikey/', $normalized)
                || in_array($normalized, [
                    'key', 'cpf', 'cnpj', 'cpfcnpj', 'document', 'email', 'mail',
                    'phone', 'mobilephone', 'cellphone', 'telephone', 'name', 'fullname',
                    'firstname', 'lastname', 'customername', 'customeremail', 'billingname', 'contactname',
                    'company', 'contact', 'address', 'addressnumber',
                    'complement', 'province', 'postalcode', 'ip', 'remoteip', 'customerip',
                    'creditcard', 'creditcardholderinfo', 'cardnumber', 'holdername', 'cvv', 'ccv',
                    'payload', 'rawbody', 'body', 'headers',
                ], true);
            // Field names can themselves contain externally supplied personal data.
            $safeKey = is_int($key) ? $key : asaas_diagnostic_clean((string) $key, $secrets, $depth + 1);
            $clean[$safeKey] = $sensitive ? '[REDACTED]' : asaas_diagnostic_clean($item, $secrets, $depth + 1);
        }
        return $clean;
    }
    if (is_object($value) || is_resource($value)) {
        return '[UNSUPPORTED]';
    }
    if (!is_string($value)) {
        return $value;
    }
    if ($secrets !== []) {
        $value = str_replace($secrets, '[REDACTED]', $value);
    }
    $patterns = [
        '/\$aact_[A-Za-z0-9_+\/=.-]+/i',
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9_+\/=.-]+/i',
        '/\b(?:whsec|sk|token|secret)_[A-Za-z0-9_+\/=.-]+/i',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
        '/(?<![A-Za-z0-9_])(?:\d{3}\.\d{3}\.\d{3}-\d{2}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{11}|\d{14})(?![A-Za-z0-9_])/',
    ];
    $value = (string) preg_replace($patterns, '[REDACTED]', $value);
    $value = (string) preg_replace(
        '/((?:auth[_-]?token|webhook[_-]?secret|access[_-]?token|api[_-]?key|authorization|password|senha|cpfcnpj|cpf|cnpj|email)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;\}\]]+)/i',
        '$1[REDACTED]',
        $value
    );
    return strlen($value) > 4096 ? substr($value, 0, 4096) . ' [TRUNCATED]' : $value;
}

function asaas_diagnostic_file_enabled(): bool
{
    try {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $db = is_object($ci) && isset($ci->db) ? $ci->db : null;
        return (bool) asaas_diagnostic_quiet_db($db, static function (): bool {
            $value = function_exists('get_option') ? get_option('paymentmethod_asaas_file_log_enabled') : false;
            return in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true);
        });
    } catch (Throwable $exception) {
        return false;
    }
}

/** Respect CodeIgniter's local log directory; do not accept stream destinations. */
function asaas_diagnostic_file_path(): ?string
{
    try {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $directory = isset($ci->config) && method_exists($ci->config, 'item') ? $ci->config->item('log_path') : '';
        if (!is_string($directory) || $directory === '') {
            $directory = defined('APPPATH') ? APPPATH . 'logs' : '';
        }
        if ($directory === '' || strpos($directory, "\0") !== false || strpos($directory, '://') !== false
            || substr($directory, 0, 2) === '//' || substr($directory, 0, 2) === '\\\\') {
            return null;
        }
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'asaas-' . date('Y-m-d') . '.php';
    } catch (Throwable $exception) {
        return null;
    }
}

/** Best-effort file append, independent of the global CodeIgniter log threshold. */
function asaas_diagnostic_write_file(string $line): bool
{
    $handle = null;
    try {
        $path = asaas_diagnostic_file_path();
        if ($path === null || !is_dir(dirname($path)) || !is_writable(dirname($path)) || is_link($path)) {
            return false;
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            return false;
        }
        $stat = fstat($handle);
        $header = "<?php exit; ?>\n";
        if (!$stat || ($stat['size'] === 0 && fwrite($handle, $header) !== strlen($header))) {
            return false;
        }
        return fwrite($handle, $line . PHP_EOL) === strlen($line . PHP_EOL);
    } catch (Throwable $exception) {
        return false;
    } finally {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}

function asaas_diagnostic_columns($db, string $table): array
{
    if (method_exists($db, 'list_fields')) {
        $columns = $db->list_fields($table);
        return is_array($columns) ? $columns : [];
    }
    $columns = [];
    if (method_exists($db, 'field_exists')) {
        foreach (['id', 'level', 'message', 'created_at', 'context_json', 'context', 'correlation_id'] as $column) {
            if ($db->field_exists($column, $table)) {
                $columns[] = $column;
            }
        }
    }
    return $columns;
}

/** Logging must never turn a successfully created payment into an application failure. */
function asaas_diagnostic_log(string $level, string $message, array $context = [], ?bool $fileLogEnabled = null): void
{
    static $writing = false;
    static $storageWarningReported = false;
    if ($writing) {
        return;
    }
    $writing = true;
    try {
        $level = in_array(strtolower($level), ['debug', 'info', 'warning', 'error'], true) ? strtolower($level) : 'error';
        $sanitized = asaas_diagnostic_sanitize(['message' => $message, 'context' => $context]);
        if (!is_array($sanitized)) {
            $sanitized = ['message' => 'Diagnóstico Asaas indisponível.', 'context' => []];
        }
        $message = (string) $sanitized['message'];
        $context = is_array($sanitized['context']) ? $sanitized['context'] : [];
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $contextJson = json_encode($context, $jsonFlags);
        $line = date('Y-m-d H:i:s') . ' [Asaas][' . strtoupper($level) . '] '
            . json_encode(['message' => $message, 'context' => $context], $jsonFlags);
        try {
            if (function_exists('log_message')) {
                log_message($level === 'warning' ? 'error' : $level, $line);
            }
        } catch (Throwable $exception) {
            // Continue with independent destinations.
        }
        $written = false;
        try {
            $ci = function_exists('get_instance') ? get_instance() : null;
            if (isset($ci->db) && is_object($ci->db)) {
                $db = $ci->db;
                $written = (bool) asaas_diagnostic_quiet_db($db, static function () use ($db, $level, $message, $context, $contextJson): bool {
                    $table = db_prefix() . 'asaas_logs';
                    if (!$db->table_exists($table)) {
                        return false;
                    }
                    $columns = asaas_diagnostic_columns($db, $table);
                    if (array_diff(['level', 'message', 'created_at'], $columns) !== []) {
                        return false;
                    }
                    $payload = ['level' => $level, 'message' => $message, 'created_at' => date('Y-m-d H:i:s')];
                    if (in_array('context_json', $columns, true)) {
                        $payload['context_json'] = $contextJson;
                    } elseif (in_array('context', $columns, true)) {
                        $payload['context'] = $contextJson;
                    }
                    if (in_array('correlation_id', $columns, true)) {
                        $payload['correlation_id'] = isset($context['correlation_id']) && is_scalar($context['correlation_id'])
                            ? substr((string) $context['correlation_id'], 0, 64) : null;
                    }
                    return $db->insert($table, $payload) !== false;
                });
            }
        } catch (Throwable $exception) {
            // Never expose SQL, credentials or the failing diagnostic exception.
        }
        $fileEnabled = $fileLogEnabled ?? asaas_diagnostic_file_enabled();
        $fileWritten = $fileEnabled && asaas_diagnostic_write_file($line);
        if (!$written || ($fileEnabled && !$fileWritten)) {
            try {
                if (in_array($level, ['warning', 'error'], true)) {
                    error_log('[Asaas] Armazenamento de diagnóstico indisponível; ' . $line);
                } elseif (!$storageWarningReported) {
                    error_log('[Asaas] Armazenamento de diagnóstico indisponível; verifique a tabela asaas_logs e as permissões do diretório de logs.');
                }
                $storageWarningReported = true;
            } catch (Throwable $exception) {
                // The payment flow always takes precedence over diagnostic storage.
            }
        }
    } catch (Throwable $exception) {
        try {
            error_log('[Asaas] Falha ao registrar diagnóstico; nenhum dado da requisição foi incluído.');
        } catch (Throwable $ignored) {
        }
    } finally {
        $writing = false;
    }
}

/** Read only the bounded tail of today's module-owned local log. */
function asaas_diagnostic_file_rows(): array
{
    $handle = null;
    try {
        $path = asaas_diagnostic_file_path();
        if ($path === null || !is_file($path) || !is_readable($path) || is_link($path)) {
            return [];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            return [];
        }
        $stat = fstat($handle);
        if (!$stat) {
            return [];
        }
        $offset = max(0, (int) $stat['size'] - 262144);
        if (fseek($handle, $offset) !== 0) {
            return [];
        }
        $tail = stream_get_contents($handle, 262144);
        if (!is_string($tail)) {
            return [];
        }
        $lines = explode("\n", $tail);
        if ($offset > 0) {
            array_shift($lines); // The first record may start before the bounded tail.
        }
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[Asaas\]\[(DEBUG|INFO|WARNING|ERROR)\] (.+)$/', trim($line), $match)) {
                continue;
            }
            $record = json_decode($match[3], true);
            if (!is_array($record) || !isset($record['message']) || !is_string($record['message'])) {
                continue;
            }
            $context = isset($record['context']) && is_array($record['context']) ? $record['context'] : [];
            $row = ['created_at' => $match[1], 'level' => strtolower($match[2]), 'message' => $record['message'],
                'context' => $context, 'correlation_id' => isset($context['correlation_id']) && is_scalar($context['correlation_id'])
                    ? (string) $context['correlation_id'] : ''];
            $rows[] = asaas_diagnostic_sanitize($row);
            if (count($rows) === 100) {
                break;
            }
        }
        return $rows;
    } catch (Throwable $exception) {
        return [];
    } finally {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}

/** Bounded read for the authenticated administrator, including legacy log schemas. */
function asaas_diagnostic_report(): array
{
    $report = ['rows' => [], 'database_state' => 'unavailable', 'source' => 'none',
        'file_enabled' => asaas_diagnostic_file_enabled(), 'file_path' => asaas_diagnostic_file_path(),
        'directory_writable' => false, 'file_exists' => false];
    try {
        if ($report['file_path'] !== null) {
            $report['directory_writable'] = is_dir(dirname($report['file_path'])) && is_writable(dirname($report['file_path']));
            $report['file_exists'] = is_file($report['file_path']);
        }
        $ci = function_exists('get_instance') ? get_instance() : null;
        if (isset($ci->db) && is_object($ci->db)) {
            $db = $ci->db;
            asaas_diagnostic_quiet_db($db, static function () use ($db, &$report): void {
                $table = db_prefix() . 'asaas_logs';
                if (!$db->table_exists($table)) {
                    $report['database_state'] = 'missing';
                    return;
                }
                $columns = asaas_diagnostic_columns($db, $table);
                if (array_diff(['id', 'level', 'message', 'created_at'], $columns) !== []) {
                    return;
                }
                $selected = array_intersect(['id', 'level', 'message', 'created_at', 'context_json', 'context', 'correlation_id'], $columns);
                $query = $db->select(implode(',', $selected))->order_by('id', 'DESC')->limit(100)->get($table);
                if (!$query) {
                    return;
                }
                $records = $query->result_array();
                if (!is_array($records)) {
                    return;
                }
                $report['database_state'] = 'ok';
                $report['source'] = 'database';
                foreach (array_slice($records, 0, 100) as $record) {
                    $context = json_decode((string) ($record['context_json'] ?? $record['context'] ?? ''), true);
                    unset($record['context_json']);
                    $record['context'] = is_array($context) ? $context : [];
                    $report['rows'][] = asaas_diagnostic_sanitize($record);
                }
            });
        }
    } catch (Throwable $exception) {
        $report['database_state'] = 'unavailable';
        $report['source'] = 'none';
        $report['rows'] = [];
    }
    if ($report['database_state'] !== 'ok') {
        $report['rows'] = asaas_diagnostic_file_rows();
        if ($report['rows'] !== []) {
            $report['source'] = 'file';
        }
    }
    return $report;
}
