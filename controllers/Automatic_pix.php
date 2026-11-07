<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../helpers/asaas_automatic_pix_helper.php';

/** Separate customer route: native session/CSRF plus a single-use invoice-bound nonce. */
class Automatic_pix extends ClientsController
{
    private const NONCE_KEY = 'asaas_automatic_pix_nonce';
    public function __construct()
    {
        parent::__construct(); $this->load->library('asaas_gateway'); $this->load->helper('general');
    }
    public function index($hash = null)
    {
        $this->protectResponse();
        $method = strtoupper((string) $this->input->method(true));
        if (!in_array($method, ['GET', 'POST'], true)) { $this->output->set_header('Allow: GET, POST'); show_error('Método HTTP não permitido.', 405); return; }
        if (!is_string($hash) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $hash)) { show_404(); return; }
        $url = site_url('asaas/automatic_pix/index/' . $hash);
        if ($method === 'GET' && (!empty($_GET) || !empty($_SERVER['QUERY_STRING']))) { redirect($url, 'location', 303); return; }
        $this->db->where('hash', $hash); $row = $this->db->get(db_prefix() . 'invoices')->row();
        if (!$row) { show_404(); return; }
        $this->load->model('invoices_model'); $invoice = $this->invoices_model->get($row->id);
        if (!$invoice || (int) ($invoice->id ?? 0) !== (int) $row->id || !is_string($invoice->hash ?? null)
            || !hash_equals($invoice->hash, $hash) || (int) ($invoice->clientid ?? 0) < 1) { show_404(); return; }
        $base = ['invoice' => $invoice, 'pix_url' => $url, 'invoice_url' => site_url('invoice/' . (int) $invoice->id . '/' . $hash),
            'login_url' => site_url('authentication/login'), 'requires_login' => !is_client_logged_in()];
        if (!is_client_logged_in()) {
            $this->session->set_userdata(['red_url' => $url]);
            if ($method === 'POST') { redirect($base['login_url'], 'location', 303); return; }
            $this->render($base + ['pix_page' => [], 'pix_nonce' => '']); return;
        }
        if ((int) get_client_user_id() !== (int) $invoice->clientid || (int) get_contact_user_id() < 1 || !has_contact_permission('invoices')) {
            show_error('O Pix Automático deve ser gerenciado por um contato deste cliente com acesso às faturas.', 403); return;
        }
        if ($method === 'POST') {
            if (!$this->consumeNonce($invoice, $this->postText('pixNonce', 64))) { show_error('O formulário expirou ou já foi utilizado. Atualize a página e tente novamente.', 403); return; }
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { show_error('Formulário inválido.', 413); return; }
            if (!function_exists('is_https') || !is_https()) { show_error('Use uma conexão HTTPS para gerenciar a autorização.', 400); return; }
            try {
                $service = $this->service(); $action = $this->postText('action', 16);
                if ($action === 'enroll') {
                    $result = $service->enroll($invoice, ['accepted' => $this->postText('pixConsent', 1) === '1',
                        'terms_version' => $this->postText('consentVersion', 32), 'quote' => $this->postText('pixQuote', 64),
                        'frequency' => $this->postText('frequency', 16), 'contact_id' => (int) get_contact_user_id()]);
                } elseif ($action === 'cancel') { $result = $service->cancel($invoice); }
                elseif ($action === 'refresh') { $result = $service->refresh($invoice); }
                else { show_error('Operação inválida.', 422); return; }
                $reason = ($result['ok'] ?? false) ? ($result['state'] ?? 'review_pending') : ($result['error_code'] ?? 'review_pending');
                $this->session->set_flashdata('asaas_pix_notice', asaas_automatic_pix_message(is_string($reason) ? $reason : 'review_pending'));
            } catch (Throwable $error) {
                asaas_automatic_pix_diagnostic('review_pending', (int) $invoice->id);
                $this->session->set_flashdata('asaas_pix_notice', asaas_automatic_pix_message('review_pending'));
            }
            redirect($url, 'location', 303); return;
        }
        try { $page = $this->safePage($this->service()->page($invoice)); }
        catch (Throwable) {
            asaas_automatic_pix_diagnostic('review_pending', (int) $invoice->id);
            $page = $this->safePage(['state' => 'unavailable', 'message' => asaas_automatic_pix_message('review_pending')]);
        }
        if (!function_exists('is_https') || !is_https()) {
            $page['can_enroll'] = $page['can_cancel'] = $page['can_refresh'] = false;
            $page['message'] = asaas_automatic_pix_message('https_required');
        }
        $this->session->unset_userdata(self::NONCE_KEY);
        $nonce = $page['can_enroll'] || $page['can_cancel'] || $page['can_refresh'] ? $this->issueNonce($invoice) : '';
        $this->render($base + ['pix_page' => $page, 'pix_nonce' => $nonce]);
    }
    protected function service(): object { return new \Asaas\AsaasAutomaticPix($this->asaas_gateway); }
    private function render(array $data): void
    {
        $this->data($data + ['title' => 'Pix Automático']); $this->view('asaas/automatic_pix'); $this->layout();
    }
    private function postText(string $name, int $max): string
    {
        $value = $this->input->post($name, false);
        return is_string($value) && strlen($value) <= $max ? $value : '';
    }
    private function issueNonce(object $invoice): string
    {
        $nonce = bin2hex(random_bytes(32));
        $this->session->set_userdata([self::NONCE_KEY => ['hash' => hash('sha256', $nonce), 'invoice_id' => (int) $invoice->id,
            'invoice_hash' => (string) $invoice->hash, 'client_id' => (int) $invoice->clientid, 'contact_id' => (int) get_contact_user_id(), 'expires' => time() + 1800]]);
        return $nonce;
    }
    private function consumeNonce(object $invoice, string $nonce): bool
    {
        $data = $this->session->userdata(self::NONCE_KEY); $this->session->unset_userdata(self::NONCE_KEY);
        return preg_match('/^[a-f0-9]{64}$/D', $nonce) && is_array($data) && is_string($data['hash'] ?? null)
            && is_int($data['expires'] ?? null) && $data['expires'] > time() && hash_equals($data['hash'], hash('sha256', $nonce))
            && (int) ($data['invoice_id'] ?? 0) === (int) $invoice->id && ($data['invoice_hash'] ?? '') === (string) $invoice->hash
            && (int) ($data['client_id'] ?? 0) === (int) $invoice->clientid && (int) ($data['contact_id'] ?? 0) === (int) get_contact_user_id();
    }
    private function protectResponse(): void
    {
        $this->output->cache(0); $this->output->enable_profiler(false);
        foreach (['Cache-Control: no-store, private, max-age=0', 'Pragma: no-cache', 'Expires: 0', 'Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff'] as $header) {
            $this->output->set_header($header); if (!headers_sent()) { header($header); }
        }
    }
    private function safePage(array $page): array
    {
        $safe = [];
        foreach (['can_enroll', 'can_cancel', 'can_refresh', 'can_pay_now'] as $key) { $safe[$key] = ($page[$key] ?? false) === true; }
        foreach (['message', 'consent_text', 'frequency', 'state', 'consent_version', 'expires_at'] as $key) {
            $safe[$key] = is_string($page[$key] ?? null) && strlen($page[$key]) <= 2048 ? $page[$key] : '';
        }
        $safe['quote'] = is_string($page['quote'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $page['quote']) ? $page['quote'] : '';
        $safe['amount'] = is_string($page['amount'] ?? null) && preg_match('/^\d{1,12}\.\d{2}$/D', $page['amount']) ? $page['amount'] : '';
        $safe['payload'] = is_string($page['payload'] ?? null) && strlen($page['payload']) <= 8192 && !preg_match('/[\x00-\x1F]/', $page['payload']) ? $page['payload'] : '';
        $safe['encoded_image'] = '';
        if (is_string($page['encoded_image'] ?? null) && strlen($page['encoded_image']) <= 262144) {
            $png = base64_decode($page['encoded_image'], true);
            if (is_string($png) && str_starts_with($png, "\x89PNG\r\n\x1a\n")) { $safe['encoded_image'] = base64_encode($png); }
        }
        if ($safe['quote'] === '' || $safe['amount'] === '' || $safe['consent_version'] === '') { $safe['can_enroll'] = false; }
        return $safe;
    }
}
