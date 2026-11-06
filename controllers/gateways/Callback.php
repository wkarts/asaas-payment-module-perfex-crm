<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

require_once __DIR__ . '/../../helpers/asaas_diagnostics_helper.php';

class Callback extends APP_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
    }

    public function index()
    {
        if ($this->input->method(true) !== 'POST') {
            return $this->output
                ->set_header('Allow: POST')
                ->set_status_header(405)
                ->set_content_type('text/plain', 'utf-8')
                ->set_output('Método não permitido.');
        }

        $rawBody = trim($this->input->raw_input_stream);
        $headers = $this->input->request_headers();

        try {
            $result = $this->asaas_gateway->handle_webhook($headers, $rawBody);
        } catch (\Throwable $exception) {
            // Não registrar payload, headers ou mensagens de terceiros que possam conter segredos.
            log_activity('Asaas: Falha interna ao processar webhook.');
            asaas_diagnostic_log('error', 'Webhook Asaas não concluído.', [
                'source' => 'webhook', 'operation' => 'webhook_callback',
                'http_status' => 500, 'processed' => false, 'duplicated' => false,
            ]);
            return $this->output
                ->set_status_header(500)
                ->set_content_type('text/plain', 'utf-8')
                ->set_output('Falha interna ao processar webhook.');
        }

        if ($result['processed'] !== true) {
            log_activity('Asaas: Falha ao processar webhook. ' . $result['message']);
        }

        // Keep delivery outcome visible even if its business transaction rolled back.
        // Never pass headers, body, token, card data or the original exception here.
        asaas_diagnostic_log($result['processed'] === true ? 'info' : 'warning',
            $result['processed'] === true ? 'Webhook Asaas processado.' : 'Webhook Asaas recusado ou não concluído.', [
                'source' => 'webhook', 'operation' => 'webhook_callback',
                'http_status' => (int) ($result['http_status'] ?? ($result['processed'] ? 200 : 400)),
                'processed' => $result['processed'] === true,
                'duplicated' => !empty($result['duplicated']),
            ]);

        return $this->output
            ->set_status_header($result['http_status'] ?? ($result['processed'] ? 200 : 400))
            ->set_content_type('text/plain', 'utf-8')
            ->set_output($result['message']);
    }


}
