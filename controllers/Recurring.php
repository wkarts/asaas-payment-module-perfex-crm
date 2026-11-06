<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Native client session and CodeIgniter CSRF protect this separate enrollment. */
class Recurring extends ClientsController
{
    private const FORM_NONCE_SESSION_KEY = 'asaas_recurring_form_nonce';
    private const FORM_NONCE_TTL = 1800;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
        $this->load->helper('general');
    }

    public function index($hash = null)
    {
        $this->protectResponse();
        $method = strtoupper((string) $this->input->method(true));
        $hadQuery = !empty($_GET) || !empty($_SERVER['QUERY_STRING']);
        $request = $this->takeRequest($method === 'POST');
        if (!in_array($method, ['GET', 'POST'], true)) {
            unset($request);
            $this->output->set_header('Allow: GET, POST');
            show_error('Método HTTP não permitido.', 405);
            return;
        }
        if (!is_string($hash) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $hash)) {
            unset($request);
            show_404();
            return;
        }
        $url = site_url('asaas/recurring/index/' . $hash);
        if ($method === 'GET' && $hadQuery) {
            // This route has no query parameters. Never carry payment data in a return URL.
            unset($request);
            redirect($url, 'location', 303);
            return;
        }
        $this->db->where('hash', $hash);
        $row = $this->db->get(db_prefix() . 'invoices')->row();
        if (!$row) {
            unset($request);
            show_404();
            return;
        }
        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get($row->id);
        if (!$invoice || !is_string($invoice->hash ?? null) || !hash_equals($invoice->hash, $hash)
            || (int) ($invoice->clientid ?? 0) < 1) {
            unset($request);
            show_404();
            return;
        }
        $invoiceUrl = site_url('invoice/' . (int) $invoice->id . '/' . $hash);
        if (!is_client_logged_in()) {
            unset($request);
            // Native Perfex authentication consumes red_url after a successful login.
            $this->session->set_userdata(['red_url' => $url]);
            if ($method === 'POST') {
                $this->session->set_flashdata('asaas_recurring_error', 'Entre na conta do cliente e preencha o formulário novamente para autorizar a recorrência.');
                redirect(site_url('authentication/login'), 'location', 303);
                return;
            }
            $this->render($invoice, [
                'requires_login' => true, 'recurring_page' => [], 'recurring_url' => $url,
                'invoice_url' => $invoiceUrl, 'login_url' => site_url('authentication/login'),
            ]);
            return;
        }
        if ((int) get_client_user_id() !== (int) $invoice->clientid || (int) get_contact_user_id() < 1
            || !has_contact_permission('invoices')) {
            unset($request);
            show_error('A recorrência deve ser gerenciada por um contato deste cliente com acesso às faturas.', 403);
            return;
        }
        if ($method === 'POST') {
            // Some Perfex installations disable global CSRF. This session-bound, single-use
            // nonce is required independently, including when revoking an existing consent.
            if (!$this->consumeFormNonce($invoice, $request['nonce'])) {
                unset($request);
                show_error('O formulário expirou ou já foi utilizado. Atualize a página da fatura e preencha os dados novamente.', 403);
                return;
            }
            try {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                    throw new InvalidArgumentException('O formulário enviado é inválido. Preencha os dados novamente.');
                }
                $service = $this->service();
                if ($request['action'] === 'revoke') {
                    $result = $service->revoke($invoice);
                } elseif ($request['action'] === 'enroll') {
                    if (!$this->secureConnection()) {
                        throw new InvalidArgumentException('Use uma conexão HTTPS para cadastrar o cartão.');
                    }
                    if ($request['consent'] !== '1') {
                        throw new InvalidArgumentException('Leia e aceite a autorização de cobrança recorrente antes de continuar.');
                    }
                    $result = $service->enroll($invoice, $request['card'], [
                        'accepted' => true, 'terms_version' => $request['version'],
                        'quote' => $request['quote'], 'contact_id' => (int) get_contact_user_id(),
                        'ip' => (string) $this->input->ip_address(), 'pay_now' => true,
                    ]);
                } else {
                    throw new InvalidArgumentException('Escolha uma operação válida para a recorrência.');
                }
                $success = is_array($result) && ($result['ok'] ?? false) === true;
                $message = $this->publicText(is_array($result) ? ($result['message'] ?? null) : null,
                    'Confira a situação da autorização e da fatura antes de iniciar outra operação.');
                $this->session->set_flashdata($success ? 'asaas_recurring_info' : 'asaas_recurring_error', $message);
            } catch (InvalidArgumentException $error) {
                $this->session->set_flashdata('asaas_recurring_error', $this->publicText($error->getMessage(),
                    'Confira os dados do cartão e a autorização antes de continuar.'));
            } catch (Throwable $error) {
                // Upstream exception chains can contain card requests; never retain them in logs/session.
                asaas_diagnostic_log('error', 'A operação de cartão recorrente precisa de conferência.', [
                    'source' => 'recurring', 'invoice_id' => (int) $invoice->id, 'exception' => get_class($error),
                ]);
                $this->session->set_flashdata('asaas_recurring_error',
                    'Não foi possível confirmar o resultado. Consulte a situação da fatura e o financeiro antes de tentar novamente.');
            } finally {
                unset($request, $result);
            }
            redirect($url, 'location', 303);
            return;
        }
        unset($request);
        $page = [];
        try {
            $page = $this->safePage($this->service()->page($invoice));
        } catch (Throwable $error) {
            asaas_diagnostic_log('error', 'Não foi possível consultar o estado do cartão recorrente.', [
                'source' => 'recurring', 'invoice_id' => (int) $invoice->id, 'exception' => get_class($error),
            ]);
            $page = $this->safePage(['state' => 'unavailable',
                'message' => 'A autorização não pôde ser consultada. Procure o financeiro antes de tentar um novo pagamento.']);
        }
        if (!$this->secureConnection()) {
            $page['can_enroll'] = false;
            $page['message'] = 'O cadastro de cartão exige uma conexão HTTPS. Acesse o endereço seguro do portal.';
        }
        $client = $invoice->client ?? (object) [];
        $this->session->unset_userdata(self::FORM_NONCE_SESSION_KEY);
        $formNonce = ($page['can_enroll'] || $page['can_revoke']) ? $this->issueFormNonce($invoice) : '';
        $this->render($invoice, [
            'requires_login' => false, 'recurring_page' => $page, 'recurring_url' => $url,
            'recurring_csrf_nonce' => $formNonce,
            'invoice_url' => $invoiceUrl, 'login_url' => site_url('authentication/login'),
            'mensagem_erro' => $this->publicText($this->session->flashdata('asaas_recurring_error'), ''),
            'mensagem_info' => $this->publicText($this->session->flashdata('asaas_recurring_info'), ''),
            'card_holder_defaults' => [
                'holderEmail' => $client->email ?? '', 'holderDocument' => $client->vat ?? '',
                'holderPostalCode' => $client->zip ?? '', 'holderAddressNumber' => $client->numero ?? '',
                'holderPhone' => $client->phonenumber ?? '',
            ],
        ]);
    }

    protected function service(): object
    {
        require_once __DIR__ . '/../libraries/Asaas/AsaasRecurring.php';
        return new \Asaas\AsaasRecurring($this->asaas_gateway);
    }

    private function render(object $invoice, array $data): void
    {
        $this->data($data + ['invoice' => $invoice, 'title' => 'Cartão de crédito recorrente',
            'environment' => $this->asaas_gateway->getEnvironmentLabel()]);
        $this->view('asaas/recorrente');
        $this->layout();
    }

    private function secureConnection(): bool
    {
        // Trust CodeIgniter's configured HTTPS/proxy handling, never a user-supplied form value.
        return function_exists('is_https') && is_https();
    }

    private function issueFormNonce(object $invoice): string
    {
        $nonce = bin2hex(random_bytes(32));
        $this->session->set_userdata([self::FORM_NONCE_SESSION_KEY => [
            'token_hash' => hash('sha256', $nonce), 'invoice_id' => (int) $invoice->id, 'client_id' => (int) $invoice->clientid,
            'invoice_hash' => (string) $invoice->hash, 'contact_id' => (int) get_contact_user_id(),
            'expires_at' => time() + self::FORM_NONCE_TTL,
        ]]);
        return $nonce;
    }

    /** Consume before checking or performing a mutation, so failures cannot replay the form. */
    private function consumeFormNonce(object $invoice, string $nonce): bool
    {
        $stored = $this->session->userdata(self::FORM_NONCE_SESSION_KEY);
        $this->session->unset_userdata(self::FORM_NONCE_SESSION_KEY);
        return preg_match('/^[a-f0-9]{64}$/D', $nonce) === 1 && is_array($stored)
            && is_string($stored['token_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $stored['token_hash']) === 1
            && is_int($stored['expires_at'] ?? null) && $stored['expires_at'] > time()
            && (int) ($stored['invoice_id'] ?? 0) === (int) $invoice->id
            && (int) ($stored['client_id'] ?? 0) === (int) $invoice->clientid
            && is_string($stored['invoice_hash'] ?? null) && hash_equals($stored['invoice_hash'], (string) $invoice->hash)
            && (int) ($stored['contact_id'] ?? 0) === (int) get_contact_user_id()
            && hash_equals($stored['token_hash'], hash('sha256', $nonce));
    }

    private function protectResponse(): void
    {
        $this->output->cache(0);
        $this->output->enable_profiler(false);
        foreach (['Cache-Control: no-store', 'Pragma: no-cache', 'Expires: 0', 'Referrer-Policy: no-referrer'] as $header) {
            $this->output->set_header($header);
            if (!headers_sent()) { header($header); }
        }
    }

    /** Keep secrets only in the local call, including on rejected methods/authentication. */
    private function takeRequest(bool $posted): array
    {
        $request = ['action' => '', 'consent' => '', 'version' => '', 'quote' => '', 'nonce' => '', 'card' => []];
        if ($posted) {
            foreach (['action' => 'action', 'consent' => 'recurringConsent', 'version' => 'consentVersion', 'quote' => 'cardQuote', 'nonce' => 'recurringNonce'] as $key => $name) {
                $value = $this->input->post($name, false);
                $request[$key] = is_string($value) && strlen($value) <= 128 ? $value : '';
            }
            if ($request['action'] === 'enroll') {
                foreach (['holderName' => 'holderName', 'number' => 'cardNumber', 'expiryMonth' => 'expirationMonth',
                    'expiryYear' => 'expirationYear', 'cvv' => 'securityCode', 'holderEmail' => 'holderEmail',
                    'holderDocument' => 'holderDocument', 'holderPostalCode' => 'holderPostalCode',
                    'holderAddressNumber' => 'holderAddressNumber', 'holderPhone' => 'holderPhone'] as $key => $name) {
                    $request['card'][$key] = $this->input->post($name, false);
                }
                $request['card']['installmentCount'] = '1';
                $request['card']['quote'] = $request['quote'];
            }
        }
        $csrfName = $this->security->get_csrf_token_name();
        $csrf = isset($_POST[$csrfName]) && is_string($_POST[$csrfName]) ? [ $csrfName => $_POST[$csrfName] ] : [];
        $_POST = $csrf;
        $_REQUEST = $csrf;
        $_GET = [];
        $_SERVER['QUERY_STRING'] = '';
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
        }
        return $request;
    }

    private function publicText($value, string $fallback): string
    {
        if (!is_string($value) || strlen($value) > 2000) { return $fallback; }
        $value = function_exists('asaas_diagnostic_sanitize') ? (string) asaas_diagnostic_sanitize($value) : $value;
        // Do not let accidentally returned payment credentials become flash data.
        if (preg_match('/(?<![A-Za-z0-9])(?:\d[ -]?){13,19}(?![A-Za-z0-9])|\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b|(?:creditCardToken|cardToken|securityCode|\bCVV\b|\bCCV\b)\s*[=:]/i', $value)) {
            return $fallback;
        }
        return $value;
    }

    /** Only display fields enter the view; authorization tokens never cross this boundary. */
    private function safePage(array $page): array
    {
        $safe = [];
        foreach (['enabled', 'can_enroll', 'can_revoke', 'can_pay_now'] as $key) { $safe[$key] = ($page[$key] ?? false) === true; }
        foreach (['state', 'authorization_state', 'attempt_state', 'error_code', 'consent_version'] as $key) {
            $value = $page[$key] ?? '';
            $safe[$key] = is_string($value) && preg_match('/^[A-Za-z0-9_-]{0,80}$/D', $value) ? $value : '';
        }
        foreach (['principal', 'fee', 'total'] as $key) {
            $value = $page[$key] ?? '';
            $safe[$key] = is_string($value) && preg_match('/^\d{1,12}\.\d{2}$/D', $value) ? $value : '';
        }
        $safe['quote'] = is_string($page['quote'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $page['quote']) ? $page['quote'] : '';
        $safe['message'] = $this->publicText($page['message'] ?? '', 'Consulte o financeiro para conferir a autorização.');
        $safe['consent_text'] = $this->publicText($page['consent_text'] ?? '', '');
        if ($safe['quote'] === '' || $safe['total'] === '' || $safe['principal'] === '' || $safe['fee'] === '' || $safe['consent_version'] === '') {
            $safe['can_enroll'] = false;
        }
        if (in_array($safe['state'], ['processing', 'uncertain'], true)
            || in_array($safe['attempt_state'], ['preparing', 'processing', 'ambiguous', 'pending', 'uncertain'], true)) {
            $safe['can_enroll'] = false;
            $safe['can_pay_now'] = false;
        }
        return $safe;
    }
}
