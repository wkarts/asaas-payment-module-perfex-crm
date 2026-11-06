<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

class Asaas extends AdminController
{
    protected $apiKey;

    public function __construct()
    {
        parent::__construct();
        // Diagnostics must remain accessible when the payment SDK cannot bootstrap.
        if (isset($this->router) && in_array($this->router->fetch_method(), ['logs', 'logs_data'], true)) {
            return;
        }
        $this->load->library('asaas/asaas_gateway');
        $this->apiKey  = $this->asaas_gateway->getApiKey();
    }

    public function index()
    {

        $this->db->where('active', 1);

        $clients = $this->db->get(db_prefix() . 'clients')->result();

        $i = 1;

        foreach ($clients as $client) {

            $get_customer = $this->asaas_gateway->get_customer($client->vat);

            echo $i;
            echo "<hr>";
            var_dump($client->userid);
            echo "<hr>";
            var_dump($client->company);
            echo "<hr>";
            var_dump($client->vat);
            echo "<hr>";
            var_dump(str_replace('/', '', str_replace('-', '', str_replace('.', '', $client->vat))));

            echo "<hr>";
            var_dump($get_customer);
            echo "<hr>";

            $i++;
        }
    }

    public function get_invoice_data($invoice_hash)
    {
        $this->db->where('hash', $invoice_hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if ($invoice->status == 2) {
            echo 1;
        } else {
            echo 0;
        }
    }

    public function charges()
    {
        $response = $this->asaas_gateway->charges($this->apiKey, null);

        $response = json_decode($response, TRUE);

        natsort($response["data"]);

        $data = [
            "response" => $response ? $response["data"] : NULL,
        ];

        $this->load->view('asaas/charges', $data);
    }

    public function customers()
    {
        $response = $this->asaas_gateway->get_customers($this->apiKey, null);

        natsort($response["data"]);

        $data = [
            "response" => $response ? $response["data"] : NULL,
        ];

        $this->load->view('asaas/customers', $data);
    }

    /** Administrator-only diagnostics; linked from payment settings, without a new menu. */
    public function logs()
    {
        if (!is_admin()) {
            access_denied('Asaas');
            return;
        }

        require_once __DIR__ . '/../helpers/asaas_diagnostics_helper.php';
        $data = asaas_diagnostic_report();
        $data['title'] = 'Diagnóstico da integração Asaas';
        $this->load->view('asaas/logs', $data);
    }

    /** Read-only diagnostics for the Logs subtab; no SDK or remote API is needed. */
    public function logs_data(): void
    {
        if (!is_admin()) {
            $this->logs_json(['ok' => false, 'message' => 'Somente administradores podem consultar os logs da integração Asaas.'], 403);
            return;
        }
        if (strtoupper((string) $this->input->method(true)) !== 'GET') {
            $this->output->set_header('Allow: GET');
            $this->logs_json(['ok' => false, 'message' => 'Método HTTP não permitido para consultar os logs.'], 405);
            return;
        }

        try {
            require_once __DIR__ . '/../helpers/asaas_diagnostics_helper.php';
            // The existing helper owns bounded reads, redaction and the local-file fallback.
            // No file name or path supplied by the request is accepted.
            $this->logs_json(['ok' => true, 'report' => asaas_diagnostic_report()]);
        } catch (Throwable $exception) {
            $this->logs_json(['ok' => false, 'message' => 'Não foi possível consultar o diagnóstico. Atualize os registros para tentar novamente.'], 503);
        }
    }

    private function logs_json(array $payload, int $status = 200): void
    {
        if (isset($this->security) && method_exists($this->security, 'get_csrf_token_name') && method_exists($this->security, 'get_csrf_hash')) {
            $payload['csrf'] = ['name' => $this->security->get_csrf_token_name(), 'hash' => $this->security->get_csrf_hash()];
        }
        if (method_exists($this->output, 'enable_profiler')) {
            $this->output->enable_profiler(false);
        }
        $this->output->set_header('Cache-Control: no-store, private, max-age=0')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function health()
    {
        $status = $this->asaas_gateway->health_check();
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($status));
    }

    public function merge()
    {

        $charges = $this->asaas_gateway->charges($this->apiKey, null);

        $charges = json_decode($charges, TRUE);

        natsort($charges["data"]);

        $response = $this->asaas_gateway->get_customers($this->apiKey, null);

        natsort($response["data"]);

        $i = 0;

        $new_array = [];

        foreach ($charges["data"] as $row) {
            foreach ($response["data"] as $customer) {
                if ($row["customer"] = $customer["id"]) {
                    $new_array[$i]["name"] = $customer["name"];
                    $new_array[$i]["cpfCnpj"] = $customer["cpfCnpj"];
                    $new_array[$i]["id"] = $row["id"];
                    $new_array[$i]["dateCreated"] = $row["dateCreated"];
                    $new_array[$i]["customer"] = $row["customer"];
                    $new_array[$i]["value"] = $row["value"];
                    $new_array[$i]["description"] = $row["description"];
                    $new_array[$i]["billingType"] = $row["billingType"];
                    $new_array[$i]["status"] = $row["status"];
                    $new_array[$i]["dueDate"] = $row["dueDate"];
                    $new_array[$i]["paymentDate"] = $row["paymentDate"];
                    $new_array[$i]["installmentNumber"] = $row["installmentNumber"];
                    $new_array[$i]["invoiceUrl"] = $row["invoiceUrl"];
                    $new_array[$i]["invoiceNumber"] = $row["invoiceNumber"];
                    $new_array[$i]["externalReference"] = $row["externalReference"];
                }
                $i++;
            }

            var_dump($new_array);

            die();
        }
        $data = [
            "new_array" => $new_array ? $new_array : NULL,
        ];


        $this->load->view('asaas/customers', $data);
    }

    public function services()
    {
        $response = $this->invoice->services($this->apiKey, null);
    }

    public function setup_webhook()
    {

        $email = "suporte@wwsoftwares.com.br";

        $webhook = $this->asaas_gateway->get_webhook($this->apiKey, null);

        var_dump($webhook);
        echo "<hr>";
        $set_webhook = $this->set_webhook($this->apiKey, null, $email);

        var_dump($set_webhook);
        echo "<hr>";

        $set_webhook_invoice = $this->set_webhook_invoice($this->apiKey, null, $email);

        var_dump($set_webhook_invoice);

        echo "<hr>";
        // $set_webhook_transfer = $this->set_webhook_transfer($this->apiKey, $this->apiUrl, $email);
        // var_dump($set_webhook_transfer);
    }

    public function set_webhook($api_key, $api_url, $email)
    {
        $webhook = $this->asaas_gateway->get_webhook($api_key, $api_url);
        if (empty($webhook['url'])) {
            $post_data = json_encode([
                "url" => site_url('asaas/gateways/callback/index'),
                "email" => $email,
                "interrupted" => false,
                "enabled" => true,
                "apiVersion" => 3
            ]);
            $create_webhook = $this->asaas_gateway->create_webhook($api_key, $api_url, $post_data);
            return $create_webhook;
        }

        return $webhook;
    }

    public function set_webhook_invoice($api_key, $api_url, $email)
    {
        $webhook = $this->asaas_gateway->get_webhook_invoice($api_key, $api_url);

        if ($webhook["url"] !== site_url('asaas_invoice/gateways/callback/index')) {
            $post_data = json_encode([
                "url" => site_url('asaas_invoice/gateways/callback'),
                "email" => $email,
                "interrupted" => false,
                "enabled" => true,
                "apiVersion" => 3

            ]);

            $create_webhook = $this->asaas_gateway->create_webhook_invoice($api_key, $api_url, $post_data);

            return $create_webhook;
        }
    }

    public function set_webhook_transfer($email)
    {
        $webhook = $this->asaas_gateway->get_webhook_transfer($this->apiKey, null);
        if ($webhook["url"] !== site_url('asaas/gateways/callback/invoices')) {
            $post_data = json_encode([
                "url" => site_url('asaas/gateways/callback'),
                "email" => $email,
                "interrupted" => false,
                "enabled" => true,
                "apiVersion" => 3

            ]);
            $create_webhook = $this->asaas_gateway->create_webhook_transfer($this->apiKey, null, $post_data);
            return $create_webhook;
        }
    }

    public function retorna_cobranca($id = 178458832)
    {
        $response = $this->asaas_gateway->request('GET', '/payments/' . $id);

        echo json_encode(is_array($response) ? $response : []);
    }

    public function retorna_cobrancas($hash = '')
    {
        $response = $this->asaas_gateway->request('GET', '/payments', ['externalReference' => $hash]);

        echo json_encode(is_array($response) ? $response : []);
    }
}
