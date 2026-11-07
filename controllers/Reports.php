<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Asaas/AsaasReports.php';

/** Native staff authentication remains owned by Perfex. Every endpoint also checks its capability. */
class Reports extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        // Keep the screen available even when the remote SDK or credentials need maintenance.
    }

    public function index(): void
    {
        if (!$this->authorize('view', true)) { return; }
        $this->protect();
        $this->load->view('asaas/reports', ['title' => 'Relatórios financeiros Asaas']);
    }

    public function catalog(): void
    {
        if (!$this->authorize('view')) { return; }
        $this->respond(['ok' => true, 'reports' => \Asaas\AsaasReportCatalog::publicDefinitions(),
            'can_export' => asaas_staff_can('export', 'asaas_reports')]);
    }

    public function data(): void
    {
        if (!$this->authorize('view')) { return; }
        try {
            [$report, $filters] = $this->inputFilters();
            $this->respond((new \Asaas\AsaasReports(get_instance()))->page($report, $filters));
        } catch (InvalidArgumentException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage(), 'code' => 'invalid_filters'], 422);
        } catch (\Asaas\AsaasReportException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage(), 'code' => $error->reason], $error->httpStatus);
        } catch (Throwable) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível consultar o relatório. Confira o diagnóstico da integração.', 'code' => 'report_unavailable'], 503);
        }
    }

    public function export(): void
    {
        if (!$this->authorize('export')) { return; }
        try {
            [$report, $filters] = $this->inputFilters();
            $export = (new \Asaas\AsaasReports(get_instance()))->export($report, $filters);
            // No download headers or rows are sent until every API page has been validated.
            $stream = $export->stream();
        } catch (InvalidArgumentException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage(), 'code' => 'invalid_filters'], 422);
            return;
        } catch (\Asaas\AsaasReportException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage(), 'code' => $error->reason], $error->httpStatus);
            return;
        } catch (Throwable) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível concluir a exportação. Nenhum arquivo parcial foi disponibilizado.', 'code' => 'export_failed'], 503);
            return;
        }

        if (method_exists($this->output, 'enable_profiler')) { $this->output->enable_profiler(false); }
        // A file response bypasses CI's HTML buffer and profiler to avoid retaining the whole CSV.
        while (ob_get_level() > 0) {
            if (!ob_end_clean()) { break; }
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $export->filename . '"');
        header('Content-Length: ' . $export->bytes);
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Asaas-Report-Rows: ' . $export->rows);
        header('X-Asaas-Report-Environment: ' . $export->environment);
        try { fpassthru($stream); }
        finally { $export->close(); }
        exit;
    }

    private function authorize(string $capability, bool $page = false): bool
    {
        $canRead = function_exists('asaas_staff_can') && asaas_staff_can('view', 'asaas_reports');
        if (!$canRead || ($capability !== 'view' && !asaas_staff_can($capability, 'asaas_reports'))) {
            if ($page) { access_denied('asaas_reports'); }
            else { $this->respond(['ok' => false, 'message' => 'Seu perfil não tem permissão para esta operação nos relatórios Asaas.', 'code' => 'access_denied'], 403); }
            return false;
        }
        if (strtoupper((string) $this->input->method(true)) !== 'GET') {
            $this->output->set_header('Allow: GET');
            $this->respond(['ok' => false, 'message' => 'Método HTTP não permitido para consultar relatórios.', 'code' => 'method_not_allowed'], 405);
            return false;
        }
        return true;
    }

    private function inputFilters(): array
    {
        $input = $this->input->get();
        if ($input === null || $input === false) { $input = []; }
        if (!is_array($input) || !is_string($input['report'] ?? null)) { throw new InvalidArgumentException('Escolha um relatório disponível.'); }
        $report = $input['report'];
        unset($input['report']);
        if (strlen($report) > 40 || count($input) > 20) { throw new InvalidArgumentException('Os filtros informados são inválidos.'); }
        return [$report, $input];
    }

    private function protect(): void
    {
        if (method_exists($this->output, 'enable_profiler')) { $this->output->enable_profiler(false); }
        $this->output->set_header('Cache-Control: no-store, private, max-age=0')->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff');
    }

    private function respond(array $payload, int $status = 200): void
    {
        $this->protect();
        $this->output->set_status_header($status)->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
