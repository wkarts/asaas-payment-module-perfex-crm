<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Asaas/AsaasReconciliation.php';
require_once __DIR__ . '/../helpers/asaas_staff_helper.php';

/** Native staff session + CSRF remain enforced by Perfex/CodeIgniter. */
class Monitor extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        // Do not bootstrap the SDK here: administrators still need diagnostics when credentials fail.
    }

    public function index(): void
    {
        if (!asaas_staff_can('view', 'asaas_monitor')) { access_denied('Asaas — Monitoramento'); return; }
        if (strtoupper((string) $this->input->method(true)) !== 'GET') { show_404(); return; }
        $this->output->set_header('Cache-Control: no-store, private, max-age=0');
        $this->load->view('asaas/monitor', ['title' => 'Asaas — Monitoramento']);
    }

    public function overview(): void { $this->read('overview'); }
    public function invoices(): void { $this->read('invoices'); }
    public function payments(): void { $this->read('payments'); }
    public function targets(): void { $this->read('targets'); }
    public function events(): void { $this->read('events'); }
    public function logs(): void { $this->read('logs'); }
    public function compare(): void { $this->write(false); }
    public function reconcile(): void { $this->write(true); }

    private function authorize(string $method, bool $apply = false): bool
    {
        if (!asaas_staff_can($apply ? 'reconcile' : 'view', 'asaas_monitor')) {
            $this->respond(['ok' => false, 'message' => 'Seu perfil não tem permissão para esta operação no Asaas.'], 403);
            return false;
        }
        if (strtoupper((string) $this->input->method(true)) !== $method) {
            $this->output->set_header('Allow: ' . $method);
            $this->respond(['ok' => false, 'message' => 'Método HTTP não permitido para esta operação.'], 405);
            return false;
        }
        return true;
    }

    private function read(string $method): void
    {
        if (!$this->authorize('GET')) { return; }
        try {
            $filters = [];
            foreach (['page', 'per_page', 'q', 'status', 'level', 'source', 'date_field', 'date_from', 'date_to', 'search_kind', 'target_id'] as $field) {
                $value = $this->input->get($field);
                if ($value !== null && !is_scalar($value)) { throw new InvalidArgumentException('Os filtros informados são inválidos.'); }
                if ($value !== null) { $filters[$field] = $value; }
            }
            $service = new \Asaas\AsaasReconciliation(get_instance());
            $payload = $method === 'overview' ? $service->overview() : $service->$method($filters);
            $this->respond($payload, ($payload['ok'] ?? true) === false ? 503 : 200);
        } catch (InvalidArgumentException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            if ($method === 'payments') {
                $message = 'Não foi possível consultar as cobranças no Asaas. Confira o ambiente, as credenciais e o diagnóstico da integração.';
                asaas_diagnostic_log('error', $message, [
                    'source' => 'reconciliation', 'operation' => 'list_payments', 'result' => 'error',
                    'error_code' => 'remote_query_failed',
                ]);
                $this->respond(['ok' => false, 'message' => $message,
                    'error' => ['code' => 'remote_query_failed', 'http_status' => null, 'message' => $message]], 503);
                return;
            }
            $this->respond(['ok' => false, 'message' => 'Não foi possível consultar o histórico. Verifique se o módulo e suas tabelas estão atualizados.'], 503);
        }
    }

    private function write(bool $apply): void
    {
        if (!$this->authorize('POST', $apply)) { return; }
        try {
            $ids = \Asaas\AsaasReconciliation::invoiceIds($this->input->post('invoice_ids'));
            $trigger = $this->input->post('trigger');
            $trigger = $trigger === 'batch' || count($ids) > 1 ? 'batch' : 'manual';
            $service = new \Asaas\AsaasReconciliation(get_instance());
            $this->respond($service->process($ids, $apply, $trigger));
        } catch (InvalidArgumentException $error) {
            $this->respond(['ok' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível iniciar a conferência. Consulte os registros da integração.'], 503);
        }
    }

    private function respond(array $payload, int $status = 200): void
    {
        if (isset($this->security) && method_exists($this->security, 'get_csrf_token_name') && method_exists($this->security, 'get_csrf_hash')) {
            $payload['csrf'] = ['name' => $this->security->get_csrf_token_name(), 'hash' => $this->security->get_csrf_hash()];
        }
        if (method_exists($this->output, 'enable_profiler')) { $this->output->enable_profiler(false); }
        $this->output->set_header('Cache-Control: no-store, private, max-age=0')
            ->set_header('X-Content-Type-Options: nosniff')->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
