<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Asaas/AsaasReconciliation.php';

/** Native staff session + CSRF remain enforced by Perfex/CodeIgniter. */
class Monitor extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        // Do not bootstrap the SDK here: administrators still need diagnostics when credentials fail.
    }

    public function overview(): void { $this->read('overview'); }
    public function invoices(): void { $this->read('invoices'); }
    public function events(): void { $this->read('events'); }
    public function logs(): void { $this->read('logs'); }
    public function compare(): void { $this->write(false); }
    public function reconcile(): void { $this->write(true); }

    private function authorize(string $method): bool
    {
        if (!is_admin()) {
            $this->respond(['ok' => false, 'message' => 'Somente administradores podem acessar o monitoramento Asaas.'], 403);
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
            foreach (['page', 'per_page', 'q', 'status', 'level', 'source'] as $field) {
                $value = $this->input->get($field);
                if ($value !== null && !is_scalar($value)) { throw new InvalidArgumentException('Os filtros informados são inválidos.'); }
                if ($value !== null) { $filters[$field] = $value; }
            }
            $service = new \Asaas\AsaasReconciliation(get_instance());
            $this->respond($method === 'overview' ? $service->overview() : $service->$method($filters));
        } catch (InvalidArgumentException $error) {
            $this->respond(['ok' => false, 'message' => 'Os filtros informados são inválidos.'], 422);
        } catch (Throwable $error) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível consultar o histórico. Verifique se o módulo e suas tabelas estão atualizados.'], 503);
        }
    }

    private function write(bool $apply): void
    {
        if (!$this->authorize('POST')) { return; }
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
