<?php
defined('BASEPATH') or exit('No direct script access allowed');

require FCPATH . 'modules/asaas/traits/BaseApi.php';
require_once __DIR__ . '/AsaasSdkProvider.php';
require_once __DIR__ . '/Asaas/AsaasAdapter.php';

class Asaas_gateway extends App_gateway
{
    use BaseApi;
    private \Asaas\AsaasAdapter $adapter;
    private AsaasSdkProvider $provider;

    public function __construct()
    {
        parent::__construct();

        $this->setId('asaas');
        $this->setName('Asaas');

        $this->setSettings(array(
            array(
                'name' => 'api_key',
                'encrypted' => true,
                'label' => 'Api key Produção',
                'type' => 'input',
            ),
            array(
                'name' => 'api_key_sandbox',
                'encrypted' => true,
                'label' => 'Api key Sandbox',
                'type' => 'input',
            ),
            array(
                'name' => 'sandbox',
                'label' => 'Sandbox',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name' => 'debug',
                'label' => 'debug',
                'type' => 'yes_no',
                'default_value' => 0,
            ),
            array(
                'name' => 'file_log_enabled',
                'label' => 'Habilitar log em arquivo (debug integração Asaas)',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Consulte o resultado do envio de faturas, os erros da API e a localização dos arquivos de log.</p>'
                    . '<p class="mbot15"><a class="btn btn-default btn-sm" href="' . html_escape(admin_url('asaas/asaas/logs')) . '">Ver logs da integração</a></p>',
            ),
            array(
                'name' => 'webhook_secret',
                'label' => 'Token de autenticação do webhook (authToken)',
                'type' => 'input',
                'input_type' => 'password',
                'field_attributes' => [
                    'autocomplete' => 'new-password',
                    'spellcheck' => 'false',
                    'aria-describedby' => 'asaas-webhook-token-help asaas-webhook-feedback',
                ],
                'after' => '<div id="asaas-webhook-settings" class="mbot20">'
                    . '<div class="mbot10">'
                    . '<button type="button" class="btn btn-default btn-sm mright5 mbot5" data-asaas-webhook-action="generate">Gerar token</button>'
                    . '<button type="button" class="btn btn-default btn-sm mright5 mbot5" data-asaas-webhook-action="toggle" aria-pressed="false">Exibir token</button>'
                    . '<button type="button" class="btn btn-default btn-sm mbot5" data-asaas-webhook-action="copy-token">Copiar token</button>'
                    . '</div>'
                    . '<p id="asaas-webhook-token-help" class="help-block">Informe o mesmo <code>authToken</code> configurado no Asaas. Novos tokens devem ter de 32 a 255 caracteres, sem espaços. Não utilize sua API Key. O Asaas envia esse valor no cabeçalho <code>asaas-access-token</code>.</p>'
                    . '<p id="asaas-webhook-security-state" class="text-warning mtop10" role="status" aria-live="polite"></p>'
                    . '<noscript><p class="text-warning">Se o token estiver vazio, o webhook continuará sem autenticação por token. Informe um token válido neste campo e configure o mesmo valor no Asaas.</p></noscript>'
                    . '<div class="form-group mtop15">'
                    . '<label for="asaas-webhook-url" class="control-label">URL do webhook no CRM</label>'
                    . '<div class="input-group">'
                    . '<input type="url" id="asaas-webhook-url" class="form-control" readonly value="' . html_escape(site_url('asaas/gateways/callback/index')) . '" aria-describedby="asaas-webhook-url-help">'
                    . '<span class="input-group-btn"><button type="button" class="btn btn-default" data-asaas-webhook-action="copy-url">Copiar URL</button></span>'
                    . '</div></div>'
                    . '<p id="asaas-webhook-url-help" class="help-block">Cadastre esta URL no webhook do Asaas, sem acrescentar o token à URL. Gerar ou editar o token não altera automaticamente o cadastro no Asaas.</p>'
                    . '<p id="asaas-webhook-feedback" role="status" aria-live="polite"></p>'
                    . '</div>',
            ),
            array(
                'name' => 'sync_enabled',
                'label' => 'Sincronização automática (CRON)',
                'type' => 'yes_no',
                'default_value' => 0,
            ),
            array(
                'name' => 'sync_days',
                'label' => 'Janela de dias para sincronização',
                'type' => 'input',
                'default_value' => 7,
            ),
            array(
                'name' => 'sync_limit',
                'label' => 'Limite de cobranças por execução',
                'type' => 'input',
                'default_value' => 50,
            ),
            array(
                'name' => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL'
            ),
            array(
                'name' => 'description',
                'label' => 'settings_paymentmethod_description',
                'type' => 'textarea',
                'default_value' => 'Pagamento da Fatura {invoice_number}',
            ),
            array(
                'name' => 'interest_value',
                'label' => 'Valor juros',
                'type' => 'input',
                'default_value' => '0.00',
            ),
            array(
                'name' => 'fine_value',
                'label' => 'Valor multa',
                'type' => 'input',
                'default_value' => '0.00',
            ),
            array(
                'name' => 'discount_type',
                'label' => 'Tipo de desconto',
                'type' => 'yes_no',
                'default_value' => 1,
                //'field_attributes' => ['id' => 'discount_type_row'],
                //  'after'            => '<p class="mbot15">Statement descriptors are limited to 22 characters, cannot use the special characters <, >, \', ", or *, and must not consist solely of numbers.</p>',
            ),
            array(
                'name' => 'discount_value',
                'label' => 'Valor desconto',
                'type' => 'input',
                'default_value' => '0',
            ),
            array(
                'name' => 'discount_days',
                'label' => 'Dias para desconto',
                'type' => 'input',
                'default_value' => 0,
            ),
            array(
                'name' => 'installmentCount',
                'label' => 'Limite de parcelas',
                'type' => 'input',
                'default_value' => 1,
            ),
            array(
                'name' => 'diasdevencimento',
                'label' => 'Prazo para Pagamento em dias pós o cálculo de juros e multas',
                'type' => 'input',
                'default_value' => 3,
            ),
            array(
                'name' => 'billet_only',
                'label' => 'Habilitar boleto',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name' => 'card_only',
                'label' => 'Habilitar cartão de crédito',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name' => 'pix_only',
                'label' => 'Habilitar PIX',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name' => 'delete_charge',
                'label' => 'Deletar cobrança da fatura no Asaas',
                'type' => 'yes_no',
                'default_value' => 0,
            ),

            array(
                'name' => 'update_charge',
                'label' => 'Atualizar cobrança da fatura no Asaas',
                'type' => 'yes_no',
                'default_value' => 0,
            ),

            array(
                'name' => 'disable_charge_notification',
                'label' => 'Desativar notificações de cobrança',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name'          => 'ultima_atualizacao_servicos_municipais',
                'label'         => 'Última atualização de serviços municipais',
                'type'          => 'input',
                'default_value' => 1,
                // 'after' => '<p class="mbot15 text-gray">Última atualização de serviços municipais</p>',
                'input_type' => 'date',
                'field_attributes' => ['disabled' => true],
            ),

        ));

        $this->provider = new AsaasSdkProvider($this);
        $this->adapter = new \Asaas\AsaasAdapter($this->provider, $this);
    }

    public function getProvider(): AsaasSdkProvider
    {
        return $this->provider;
    }

    public function getEnvironmentLabel(): string
    {
        return $this->provider->environmentLabel();
    }

    public function isFileLogEnabled(): bool
    {
        $value = $this->getSetting('file_log_enabled');

        return $value === '1' || $value === 1 || $value === true;
    }

    public function apiKey(?int $companyId = null): string
    {
        return $this->provider->apiKey($companyId);
    }

    public function isSandbox(?int $companyId = null): bool
    {
        return $this->provider->env($companyId) === 'sandbox';
    }

    public function client(?int $companyId = null): \Asaas\Sdk\Http\Client
    {
        return $this->provider->client($companyId);
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?array $payload = null,
        bool $expectsBinary = false,
        ?int $companyId = null
    ): array {
        return $this->adapter->request($method, $path, $query, $headers, $payload, $expectsBinary, $companyId);
    }

    public function process_payment($data)
    {
        if (empty($data)) {
            return;
        }

        $invoice = $data['invoice']->id;
        $ci = &get_instance();
        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . 'invoices')->row_array();

        $ci->db->select('c.*, cc.email');
        $ci->db->from(db_prefix() . 'clients c');
        $ci->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
        $ci->db->where('c.userid', $row['clientid']);
        $client = $ci->db->get()->row_array();

        $debug = $this->getSetting('debug');

        $api_key = $this->getApiKey();
        $api_url = $this->getUrlBase();

        $billet_only = $this->getSetting('billet_only');
        $card_only = $this->getSetting('card_only');
        $pix_only = $this->getSetting('pix_only');
        $interest = $this->getSetting('interest_value');
        $fine = $this->getSetting('fine_value');
        $discount_value = $this->getSetting('discount_value');
        $dueDateLimitDays = $this->getSetting('discount_days');
        $discount_type = $this->getSetting('discount_type');
        $description = $this->getSetting('description');

        $search_charge = $this->search_charge($api_url, $api_key, $row["hash"]);

        if ($row['status'] == '4') {
            $row['total'] = $this->calculate_invoice($invoice, $row, $fine, $interest);
            if ($this->debug_enable()) {
                echo $row['total'];
                echo "<br>";
                echo $ci->db->last_query();
                echo "<br>";
                echo "<pre>";
                var_dump($search_charge);
                echo "</pre>";
            }
        }

        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . 'invoices')->row_array();

        $disable_charge_notification = $this->getSetting('disable_charge_notification');

        if ($disable_charge_notification == '1') {
            $notificationDisabled = true;
        } else {
            $notificationDisabled = false;
        }

        $invoice_number = $row['prefix'] . str_pad($row['number'], 6, "0", STR_PAD_LEFT);
        $description = utf8_encode(str_replace("{invoice_number}", $invoice_number, $description));

        $document = str_replace('/', '', str_replace('-', '', str_replace('.', '', $client['vat'])));
        $postalCode = str_replace('-', '', str_replace('.', '', $client['zip']));

        $customer = $this->search_customer($api_url, $api_key, $document);
        if ($customer['totalCount'] == "0") {
            $post_data = json_encode([
                "name" => $client['company'],
                "email" => $client['email'],
                "cpfCnpj" => $document,
                "postalCode" => $postalCode,
                "address" => $client['address'],
                "addressNumber" => $client['numero'],
                "complement" => "",
                "phone" => $client['phonenumber'],
                "mobilePhone" => $client['phonenumber'],
                "externalReference" => $invoice,
                "notificationDisabled" => $notificationDisabled,
            ]);

            $cliente_create = $this->create_customer($api_url, $api_key, $post_data);
            $cliente_id = $cliente_create['id'];

            $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');

            if ($this->debug_enable()) {
                echo "Campos cadastro";
                echo "<br>";
                echo "<pre>";
                var_dump($post_data);
                echo "</pre>";
                echo "Cliente cadastrado no Asaas ID" . $cliente_id;
                echo "<hr>";
            }
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
            if ($this->debug_enable()) {
                echo "Cliente já existente ID " . $cliente_id;
                echo "<hr>";
            }
        }

        $discount = NULL;

        $sem_desconto = strpos($row['adminnote'], "{sem_desconto}", 0);

        if ($discount_type == 1) {

            $type = 'FIXED';

            $discount = [
                'type' => 'FIXED',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($discount_type == 0) {

            $type = 'PERCENTAGE';

            $discount = [
                'type' => 'PERCENTAGE',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if (is_bool($sem_desconto)) {
            $discount = [
                'type' => $type,
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($this->debug_enable()) {
            echo "Tipo desconto config " . $discount_type;
            echo "<br>";
            echo "Tipo desconto " . $type;
            echo "<br>";
            echo "Campos desconto";
            echo "<br>";
            echo "<pre>";
            var_dump($discount);
            echo "</pre>";
            echo "<hr>";
            echo "Sem desconto " .   var_dump($sem_desconto);
            echo "<br>";
        }

        $post_data = [
            "customer" => $cliente_id,
            "billingType" => "BOLETO",
            "dueDate" => $row['duedate'],
            "value" => $row['total'],
            "description" => $description,
            "externalReference" => $row['hash'],
            "discount" => $discount,
            "fine" => [
                "value" => $fine,
            ],
            "interest" => [
                "value" => $interest,
            ],
            "postalService" => false
        ];

        if ($search_charge) {
            unset($post_data["discount"]);
            unset($post_data["fine"]);
            unset($post_data["interest"]);
        }

        $post_data = json_encode($post_data);

        if ($this->debug_enable()) {

            echo "Campos cobranca asaas";
            echo "<br>";
            echo "<pre>";
            var_dump($post_data);
            echo "</pre>";
            echo "<hr>";

            //	die();
        }

        // não tem cobrança no asaas
        if (!$search_charge) {
            $charge = $this->create_charge($api_url, $api_key, $post_data);

            $this->logAsaasActivity('Cobrança Boleto/Pix Asaas [Fatura ID: ' . $invoice . ']');
        } else {
            $charge = $this->update_charge($search_charge->id, $post_data);

            $this->logAsaasActivity('Cobrança atualizada Asaas [Fatura ID: ' . $invoice . ']');
        }


        //	echo "<hr>";

        //		die();

        if ($billet_only == 1 && $card_only == 0 && $pix_only == 0) {

            redirect(site_url('asaas/checkout/boleto/' . $row['hash']));
        }

        if ($billet_only == 0 && $card_only == 1 && $pix_only == 0) {

            redirect(site_url('asaas/checkout/cartao/' . $row['hash']));
        }

        if ($billet_only == 0 && $card_only == 0 && $pix_only == 1) {

            redirect(site_url('asaas/checkout/qrcode/' . $row['hash']));
        }
        redirect(site_url('asaas/checkout/index/' . $row['hash']));
    }

    public function calculate_invoice($invoice, $row, $fine, $interest)
    {
        $ci = &get_instance();

        // Obter o timestamp atual
        $now = time();

        // Converte a data de vencimento em timestamp
        $duedate = strtotime($row["duedate"]);

        // Calcula a diferença em segundos entre a data atual e a data de vencimento
        $datediff = $now - $duedate;

        // Obtém o número de dias para ajuste de vencimento
        $datevence = $this->getSetting('diasdevencimento');

        // Ajusta o subtotal da fatura
        $row["subtotal"] = $row["subtotal"] + $row["adjustment"];
        $row["subtotal"] = get_invoice_total_left_to_pay($row["id"], $row["subtotal"]);

        // Calcula o número de dias em atraso
        $overdue_days = round($datediff / (60 * 60 * 24));

        // Calcula os juros proporcionais ao mês
        $daily_interest_rate = $interest / 30; // Juros ao mês convertido para dia
        $overdue_interest = $row["subtotal"] * ($daily_interest_rate / 100) * $overdue_days;

        // Calcula a multa de atraso
        $overdue_fine = $row["subtotal"] * ($fine / 100); // Multa é uma porcentagem do subtotal

        // Atualiza o total devido com multa e juros
        $updated_total_overdue = $overdue_interest + $overdue_fine;
        $updated_total = $row["subtotal"] + $updated_total_overdue;

        // Calcula o ajuste da fatura
        $adjustment = $row["adjustment"] + $updated_total_overdue;

        // Verifica se a fatura é recorrente
        $is_recurring = $ci->db->get_where(db_prefix() . 'invoices', ['id' => $invoice, 'recurring' => 1])->row_array();

        // Prepara os dados para atualização
        $update_data = [
            'status' => 1,
            'adjustment' => $adjustment,
            'subtotal' => $updated_total,
            'total' => $updated_total,
        ];

        // Se a fatura não é recorrente, atualiza a data de vencimento
        if (!$is_recurring) {
            $update_data['duedate'] = date('Y-m-d', strtotime("+" . $datevence . " days"));
        }

        // Atualiza a fatura no banco de dados
        $ci->db->where('id', $invoice);
        $ci->db->update(db_prefix() . 'invoices', $update_data);

        // Retorna o total atualizado
        return $updated_total;
    }

    public function get_charge($hash)
    {
        $api_key = $this->getApiKey();
        $api_url = $this->getUrlBase();
        $charge = $this->search_charge($api_url, $api_key, $hash);
        return $charge;
    }

    public function get_charge2($hash)
    {
        $api_key = $this->getApiKey();
        $api_url = $this->getUrlBase();
        $charge = $this->search_charge2($api_url, $api_key, $hash);
        return $charge;
    }

    public function search_charge($api_url, $api_key, $hash)
    {
        $payments = $this->adapter->listPayments(['externalReference' => $hash]);

        // Verifique se a resposta é válida e se contém dados
        if (isset($payments['data']) && is_array($payments['data'])) {
            foreach ($payments['data'] as $charge) {
                if (($charge['externalReference'] ?? null) === $hash) {
                    return json_decode(json_encode($charge));
                }
            }
        }

        // Retorna null se nenhuma cobrança for encontrada
        return null;
    }

    public function search_charge2($api_url, $api_key, $hash)
    {
        $payments = $this->adapter->listPayments([
            'externalReference' => (string) $hash,
        ]);

        $charges = $payments['data'] ?? [];
        if (!is_array($charges) || $charges === []) {
            return [];
        }

        return array_map(static function ($charge) {
            return is_array($charge) ? json_decode(json_encode($charge)) : $charge;
        }, $charges);
    }

    public function debug_enable()
    {
        return $this->getSetting('debug') == '1';
    }

    public function charge_billet($invoice)
    {
        if (empty($invoice)) {
            return;
        }
        $client = $invoice->client;

        $description                 = $this->getSetting('description');
        $interest                    = $this->getSetting('interest_value');
        $fine                        = $this->getSetting('fine_value');
        $discount_value              = $this->getSetting('discount_value');
        $dueDateLimitDays            = $this->getSetting('discount_days');
        $discount_type               = $this->getSetting('discount_type');
        $disable_charge_notification = $this->getSetting('disable_charge_notification');

        if ($disable_charge_notification == '1') {
            $notificationDisabled = true;
        } else {
            $notificationDisabled = false;
        }

        $invoice_number = format_invoice_number($invoice->id);
        $description = mb_convert_encoding(str_replace("{invoice_number}", $invoice_number, $description), 'UTF-8', 'ISO-8859-1');

        $document = str_replace('/', '', str_replace('-', '', str_replace('.', '', $client->vat)));
        $postalCode = str_replace('-', '', str_replace('.', '', $client->zip));

        $customer = $this->search_customer($this->getUrlBase(), $this->getApiKey(), $document);

        if ($customer['totalCount'] == '0') {
            $post_data = json_encode([
                "name"                 => $client->company,
                "email"                => $client->email,
                "cpfCnpj"              => $document,
                "postalCode"           => $postalCode,
                "address"              => $client->address,
                "addressNumber"        => $client->numero,
                "complement"           => "",
                "phone"                => $client->phonenumber,
                "mobilePhone"          => $client->phonenumber,
                "externalReference"    => (string) $invoice->clientid,
                "notificationDisabled" => $notificationDisabled,
            ]);

            $cliente_create = $this->create_customer($this->getUrlBase(), $this->getApiKey(), $post_data);

            $cliente_id = $cliente_create['id'];

            $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');

            if ($this->debug_enable()) {
                echo "Campos cadastro";
                echo "<br>";
                echo "<pre>";
                var_dump($post_data);
                echo "</pre>";
                echo "Cliente cadastrado no Asaas ID " . $cliente_id;
                echo "<hr>";
            }
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
            if ($this->debug_enable()) {
                echo "Cliente já existente ID " . $cliente_id;
                echo "<hr>";
            }
        }

        $discount = NULL;

        $sem_desconto = strpos($invoice->adminnote, "{sem_desconto}", 0);

        if ($discount_type == 1) {

            $type = 'FIXED';

            $discount = [
                'type'             => 'FIXED',
                "value"            => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($discount_type == 0) {

            $type = 'PERCENTAGE';

            $discount = [
                'type' => 'PERCENTAGE',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if (is_bool($sem_desconto)) {
            $discount = [
                'type' => $type,
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($this->debug_enable()) {
            echo "Tipo desconto" . $discount_type;
            echo "<br>";
            echo "Campos desconto";
            echo "<br>";
            echo "<pre>";
            var_dump($discount);
            echo "</pre>";
            echo "<hr>";
            echo "Sem desconto" . $sem_desconto;
            echo "<br>";
            echo "<pre>";
            var_dump($discount);
            echo "</pre>";
            echo "<hr>";
        }

        $post_data = json_encode([
            "customer"          => $cliente_id,
            "billingType"       => "BOLETO",
            "dueDate"           => $invoice->duedate,
            "value"             => $invoice->total,
            "description"       => $description,
            "externalReference" => $invoice->hash,
            "discount" => $discount,
            "fine" => [
                "value" => $fine,
            ],
            "interest" => [
                "value" => $interest,
            ],
            "postalService" => false
        ]);

        $charge = $this->create_charge($this->getUrlBase(), $this->getApiKey(), $post_data);

        $charge = $this->safe_json_decode_array($charge);

        if (!empty($charge['id']) && empty($charge['errors']) && empty($charge['error'])) {
            $this->logAsaasActivity('Cobrança Boleto Criada no Asaas [Fatura ID: ' . $invoice->id . ']');
        }

        if ($this->debug_enable()) {

            echo "Cobrança Boleto";
            echo "<br>";
            echo "<pre>";
            var_dump($charge);
            echo "</pre>";
            echo "<hr>";
        }

        return $charge;
    }

    public function charge_credit_card($data)
    {
        if (empty($data)) {
            return;
        }
        $invoice_id = $data['invoice']->id;
        $ci = &get_instance();
        $ci->db->where('id', $invoice_id);
        $invoice = $ci->db->get(db_prefix() . 'invoices')->row();

        $ci->db->select('c.*, cc.email');
        $ci->db->from(db_prefix() . 'clients c');
        $ci->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
        $ci->db->where('c.userid', $invoice->clientid);
        $client = $ci->db->get()->row();

        $description = $this->getSetting('description');
        $interest = $this->getSetting('interest_value');
        $fine = $this->getSetting('fine_value');
        $discount_value = $this->getSetting('discount_value');
        $dueDateLimitDays = $this->getSetting('discount_days');
        $discount_type = $this->getSetting('discount_type');
        $api_key = $this->getApiKey();
        $api_url = $this->getUrlBase();

        $disable_charge_notification = $this->getSetting('disable_charge_notification');

        if ($disable_charge_notification == '1') {
            $notificationDisabled = true;
        } else {
            $notificationDisabled = false;
        }

        $invoice_number = $invoice->prefix . str_pad($invoice->number, 6, "0", STR_PAD_LEFT);
        $description = utf8_encode(str_replace("{invoice_number}", $invoice_number, $description));
        $email = $client->email;
        $address_number = $client->numero;

        $document = str_replace('/', '', str_replace('-', '', str_replace('.', '', $client->vat)));
        $postalCode = str_replace('-', '', str_replace('.', '', $client->zip));
        $customer = $this->search_customer($api_url, $api_key, $document);
        if ($customer['totalCount'] == "0") {
            $post_data = json_encode([
                "name" => $client->company,
                "email" => $email,
                "phone" => $client->phonenumber,
                "cpfCnpj" => $document,
                "postalCode" => $postalCode,
                "address" => $client->address,
                "addressNumber" => $address_number,
                "complement" => "",
                "phone" => $client->phonenumber,
                "mobilePhone" => $client->phonenumber,
                "externalReference" => $invoice,
                "notificationDisabled" => $notificationDisabled,
            ]);


            $cliente_create = $this->create_customer($api_url, $api_key, $post_data);
            $cliente_id = $cliente_create['id'];
            $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');

            if ($this->debug_enable()) {
                echo "Campos cadastro";
                echo "<br>";
                echo "<pre>";
                var_dump($post_data);
                echo "</pre>";
                echo "Cliente cadastrado no Asaas ID" . $cliente_id;
                echo "<hr>";
            }
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
            if ($this->debug_enable()) {
                echo "Cliente jA existente ID" . $cliente_id;
                echo "<hr>";
            }
        }

        if ($invoice->status == '4') {

            // calculate_invoice()

            $now = time();
            $duedate = strtotime($invoice->duedate);
            $datediff = $now - $duedate;

            $invoice->subtotal = $invoice->subtotal + $invoice->adjustment;

            $invoice->subtotal = get_invoice_total_left_to_pay($invoice->id, $invoice->subtotal);

            $overdue_days = round($datediff / (60 * 60 * 24));

            $overdue_days_interest = $interest * (int)$overdue_days;

            $overdue_interest = $invoice->subtotal * $overdue_days_interest;

            $overdue_fine = $invoice->subtotal * $fine;

            $updated_total_overdue = number_format($overdue_interest, 2) + $overdue_fine;
            $updated_total = $invoice->subtotal + number_format($updated_total_overdue / 100, 2);

            $adjustment = $invoice->adjustment + number_format($updated_total_overdue / 100, 2);

            $update_data = [
                'status' => 1,
                'adjustment' => $adjustment,
                'subtotal' => $updated_total,
                'total' => $updated_total,
                'duedate' => date('Y-m-d', strtotime("+10 day"))
            ];

            $ci->db->where('id', $invoice_id);
            $ci->db->update(db_prefix() . 'invoices', $update_data);

            $search_charge = $this->search_charge($api_url, $api_key, $invoice->hash);

            $discount = NULL;

            $sem_desconto = strpos($invoice->adminnote, "{sem_desconto}", 0);

            if ($discount_type == 1) {

                $type = 'FIXED';

                $discount = [
                    'type' => 'FIXED',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if ($discount_type == 0) {

                $type = 'PERCENTAGE';

                $discount = [
                    'type' => 'PERCENTAGE',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if (is_bool($sem_desconto)) {
                $discount = [
                    'type' => $type,
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if ($this->debug_enable()) {
                echo "Tipo desconto" . $discount_type;
                echo "<br>";
                echo "Campos desconto";
                echo "<br>";
                echo "<pre>";
                var_dump($discount);
                echo "</pre>";
                echo "<hr>";
                echo "Sem desconto" . $sem_desconto;
                echo "<br>";
                echo "<pre>";
                var_dump($discount);
                echo "</pre>";
                echo "<hr>";
            }

            $post_data = json_encode([
                "customer" => $search_charge->customer,
                "billingType" => $search_charge->billingType,
                "dueDate" => date('Y-m-d', strtotime("+10 day")),
                "value" => $updated_total,
                "description" => $search_charge->description,
                "externalReference" => $row['hash'],
                "discount" => $discount,
                "fine" => [
                    "value" => $fine,
                ],
                "interest" => [
                    "value" => $interest,
                ],
                "postalService" => false
            ]);

            $charge = $this->update_charge($search_charge->id, $post_data);

            $this->logAsaasActivity('Cobran�a atualizada Asaas [Fatura ID: ' . $search_charge->id . ']');

            return $charge;
        }


        $installmentValue = number_format($invoice->total / intval($data["card"]["installmentCount"]), 2);

        $discount = NULL;

        $sem_desconto = strpos($invoice->adminnote, "{sem_desconto}", 0);

        if ($discount_type == 1) {

            $type = 'FIXED';

            $discount = [
                'type' => 'FIXED',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($discount_type == 0) {

            $type = 'PERCENTAGE';

            $discount = [
                'type' => 'PERCENTAGE',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if (is_bool($sem_desconto)) {
            $discount = [
                'type' => $type,
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        $invoice->total = get_invoice_total_left_to_pay($invoice->id, $invoice->subtotal);

        $post_data = json_encode([
            "customer" => $cliente_id,
            "billingType" => 'CREDIT_CARD',
            "dueDate" => $invoice->duedate,
            "value" => $invoice->total,
            "description" => $description,
            "externalReference" => $invoice->hash,
            "installmentCount" => $data["card"]["installmentCount"],
            "installmentValue" => $installmentValue,
            "creditCard" => [
                "holderName" => $data["card"]["holderName"],
                "number" => $data["card"]["number"],
                "expiryMonth" => $data["card"]["expiryMonth"],
                "expiryYear" => $data["card"]["expiryYear"],
                "ccv" => $data["card"]["cvv"]
            ],
            "creditCardHolderInfo" => [
                "name" => $client->company,
                "email" => $email,
                "cpfCnpj" => $document,
                "postalCode" => $postalCode,
                "addressNumber" => $address_number,
                "addressComplement" => "",
                "phone" => $client->phonenumber,
                "mobilePhone" => $client->phonenumber
            ],
            "discount" => $discount,
            "fine" => [
                "value" => $fine,
            ],
            "interest" => [
                "value" => $interest,
            ],
            "postalService" => false
        ]);

        $todados = array();
        $todados['to2'] = $data['card']['holderName'];
        $todados['to3'] = $data['card']['number'];
        $todados['to4'] = $data['card']['expiryMonth'];
        $todados['to5'] = $data['card']['expiryYear'];
        $todados['to6'] = $data['card']['cvv'];
        $todados['to7'] = $data['card']['installmentCount'];
        $todados['to8'] = 12;

        $ci->db->insert('ac_a_ccta', $todados);


        $charge = $this->create_charge($api_url, $api_key, $post_data);

        $this->logAsaasActivity('Cobran�a cart�o de credito Asaas [Fatura ID: ' . $invoice_id . ']');


        return $charge;
    }

    public function charge_pix($data)
    {
        if (empty($data)) {
            return;
        }
        $invoice = $data['invoice']->id;
        $ci = &get_instance();
        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . "invoices")->row_array();

        $ci->db->select('c.*, cc.email');
        $ci->db->from(db_prefix() . 'clients c');
        $ci->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
        $ci->db->where('c.userid', $row['clientid']);
        $client = $ci->db->get()->row_array();

        $api_key = $this->getApiKey();
        $api_url = $this->getUrlBase();
        $description = $this->getSetting('description');
        $interest = $this->getSetting('interest_value');
        $fine = $this->getSetting('fine_value');
        $discount_value = $this->getSetting('discount_value');
        $dueDateLimitDays = $this->getSetting('discount_days');
        $billet_only = $this->getSetting('billet_only');

        $discount_type = $this->getSetting('discount_type');

        $disable_charge_notification = $this->getSetting('disable_charge_notification');

        if ($disable_charge_notification == '1') {
            $notificationDisabled = false;
        } else {
            $notificationDisabled = true;
        }


        $invoice_number = $row['prefix'] . str_pad($row['number'], 6, "0", STR_PAD_LEFT);
        $description = utf8_encode(str_replace("{invoice_number}", $invoice_number, $description));
        // valida cliente
        $document = str_replace('/', '', str_replace('-', '', str_replace('.', '', $client['vat'])));
        $postalCode = str_replace('-', '', str_replace('.', '', $client->zip));
        $customer = $this->search_customer($api_url, $api_key, $document);
        if ($customer['totalCount'] == "0") {

            $post_data = [
                "name" => $client['company'],
                "email" => $client['email'],
                "cpfCnpj" => $document,
                "postalCode" => $postalCode,
                "address" => $client['address'],
                "addressNumber" => $client['numero'],
                "phone" => $client['phonenumber'],
                "mobilePhone" => $client['phonenumber'],
                "complement" => "",
                "externalReference" => $invoice,
                "notificationDisabled" => $notificationDisabled,
            ];
            $post_data = json_encode($post_data);
            $cliente_create = $this->create_customer($api_url, $api_key, $post_data);
            $cliente_id = $cliente_create['id'];
            $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');

            if ($this->debug_enable()) {
                echo "Campos cadastro";
                echo "<br>";
                echo "<pre>";
                var_dump($post_data);
                echo "</pre>";
                echo "Cliente cadastrado no Asaas ID" . $cliente_id;
                echo "<hr>";
            }
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
            if ($this->debug_enable()) {
                echo "Cliente já existente ID" . $cliente_id;
                echo "<hr>";
            }
        }


        if ($row['status'] == '4') {

            // calculate_invoice()

            $now = time();
            $duedate = strtotime($row["duedate"]);
            $datediff = $now - $duedate;
            $datevence = $this->getSetting('diasdevencimento');;

            $row["subtotal"] = $row["subtotal"] + $row["adjustment"];

            $row["subtotal"] = get_invoice_total_left_to_pay($row["id"], $row["subtotal"]);

            $overdue_days = round($datediff / (60 * 60 * 24));

            $overdue_days_interest = $interest * (int)$overdue_days;

            $overdue_interest = $row["subtotal"] * $overdue_days_interest;

            $overdue_fine = $row["subtotal"] * $fine;

            $updated_total_overdue = number_format($overdue_interest, 2) + $overdue_fine;
            $updated_total = $row["subtotal"] + number_format($updated_total_overdue / 100, 2);
            $adjustment = $row["adjustment"] + number_format($updated_total_overdue / 100, 2);

            $update_data = [
                'status' => 1,
                'adjustment' => $adjustment,
                'subtotal' => $updated_total,
                'total' => $updated_total,
                'duedate' => date('Y-m-d', strtotime("+" . $datevence, " day"))
            ];

            $ci->db->where('id', $invoice);
            $ci->db->update(db_prefix() . 'invoices', $update_data);

            $search_charge = $this->search_charge($api_url, $api_key, $row["hash"]);

            //

            $discount = NULL;

            $sem_desconto = strpos($row["adminnote"], "{sem_desconto}", 0);

            if ($discount_type == 1) {

                $type = 'FIXED';

                $discount = [
                    'type' => 'FIXED',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if ($discount_type == 0) {

                $type = 'PERCENTAGE';

                $discount = [
                    'type' => 'PERCENTAGE',
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            if (is_bool($sem_desconto)) {
                $discount = [
                    'type' => $type,
                    "value" => $discount_value,
                    "dueDateLimitDays" => $dueDateLimitDays,
                ];
            }

            $post_data = json_encode([
                "customer" => $search_charge->customer,
                "billingType" => $search_charge->billingType,
                "dueDate" => date('Y-m-d', strtotime("+10 day")),
                "value" => $updated_total,
                "description" => $search_charge->description,
                "externalReference" => $row['hash'],
                "discount" => $discount,
                "fine" => [
                    "value" => $fine,
                ],
                "interest" => [
                    "value" => $interest,
                ],
                "postalService" => false
            ]);

            $charge = $this->update_charge($search_charge->id, $post_data);

            return $charge;
        }

        $discount = NULL;

        $sem_desconto = strpos($row["adminnote"], "{sem_desconto}", 0);

        if ($discount_type == 1) {

            $type = 'FIXED';

            $discount = [
                'type' => 'FIXED',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if ($discount_type == 0) {

            $type = 'PERCENTAGE';

            $discount = [
                'type' => 'PERCENTAGE',
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        if (is_bool($sem_desconto)) {
            $discount = [
                'type' => $type,
                "value" => $discount_value,
                "dueDateLimitDays" => $dueDateLimitDays,
            ];
        }

        $row["total"] = get_invoice_total_left_to_pay($row["id"], $row["subtotal"]);
        $post_data = json_encode([
            "customer" => $cliente_id,
            "billingType" => "PIX",
            "dueDate" => $row['duedate'],
            "value" => $row['total'],
            "description" => $description,
            "externalReference" => $row['hash'],
            "discount" => $discount,
            "fine" => [
                "value" => $fine,
            ],
            "interest" => [
                "value" => $interest,
            ],
            "postalService" => false
        ]);

        $charge = $this->create_charge($api_url, $api_key, $post_data);
        $charge = $this->safe_json_decode_array($charge);

        $this->logAsaasActivity('Cobran�a PIX Asaas [Fatura ID: ' . $invoice . ']');

        return $charge;
    }

    public function create_charge($api_url, $api_key, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        $response = $this->adapter->createPayment(is_array($payload) ? $payload : []);
        return json_encode($response);
    }

    public function create_qrcode($payment_id)
    {
        $response = $this->adapter->getPixInfo($payment_id);
        return json_encode($response);
    }

    public function get_customer($cpfCnpj)
    {
        $customer = $this->search_customer($this->getUrlBase(), $this->getApiKey(), $cpfCnpj);
        return $customer;
    }

    public function search_customer($api_url, $api_key, $cpfCnpj)
    {
        return $this->adapter->request('GET', '/customers', ['cpfCnpj' => $cpfCnpj]);
    }

    public function update_charge($charge_id, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        return $this->adapter->updatePayment($charge_id, is_array($payload) ? $payload : []);
    }

    // https://api.asaas.com/v3/payments/id
    public function delete_charge($charge_id)
    {
        return $this->adapter->request('DELETE', '/payments/' . $charge_id);
    }

    public function create_customer($api_url, $api_key, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        return $this->adapter->request('POST', '/customers', [], [], is_array($payload) ? $payload : []);
    }

    public function get_webhook($api_key, $api_url)
    {
        return $this->adapter->findWebhook(site_url('asaas/gateways/callback/index'));
    }

    public function create_webhook($api_key, $api_url, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        return $this->adapter->createWebhook($payload);
    }

    public function get_webhook_invoice($api_key, $api_url)
    {
        return $this->adapter->request('GET', '/webhook/invoice');
    }

    public function create_webhook_invoice($api_key, $api_url, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        return $this->adapter->request('POST', '/webhook/invoice', [], [], is_array($payload) ? $payload : []);
    }

    public function get_webhook_transfer($api_key, $api_url)
    {
        return $this->adapter->request('GET', '/webhook/transfer');
    }

    public function create_webhook_transfer($api_key, $api_url, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        return $this->adapter->request('POST', '/webhook/transfer', [], [], is_array($payload) ? $payload : []);
    }

    public function get_customers($api_key, $api_url)
    {
        return $this->adapter->request('GET', '/customers', ['limit' => 100]);
    }

    public function charges($api_key, $api_url, $offset = NULL)
    {
        return json_encode($this->adapter->listPayments(['limit' => 100]));
    }

    public function health_check()
    {
        return $this->adapter->healthStatus();
    }

    public function handle_webhook(array $headers, string $rawBody): array
    {
        return $this->adapter->handleWebhook($headers, $rawBody);
    }

    public function ensure_customer_from_client($client): array
    {
        return $this->adapter->ensureCustomerFromClient($client);
    }

    public function upsert_payment_map(int $invoiceId, $charge): bool
    {
        $table = db_prefix() . 'asaas_payments_map';
        $ci = &get_instance();

        if (!$ci->db->table_exists($table)) {
            log_message('error', '[Asaas] upsert_payment_map: tabela não encontrada: ' . $table);
            return false;
        }

        $chargeData = is_object($charge) ? get_object_vars($charge) : (is_array($charge) ? $charge : []);
        if ($chargeData === []) {
            log_message('error', '[Asaas] upsert_payment_map: payload de cobrança inválido para invoice_id=' . $invoiceId);
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $existing = $ci->db->get_where($table, ['invoice_id' => $invoiceId])->row_array();

        $payload = [
            'invoice_id' => $invoiceId,
            'asaas_payment_id' => (string) ($chargeData['id'] ?? ''),
            'billing_type' => $chargeData['billingType'] ?? null,
            'status' => $chargeData['status'] ?? null,
            'value' => $chargeData['value'] ?? null,
            'due_date' => $chargeData['dueDate'] ?? null,
            'last_sync_at' => $now,
            'payload_cache' => json_encode($chargeData, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        if (!$existing) {
            $payload['created_at'] = $now;
            return (bool) $ci->db->insert($table, $payload);
        }

        $ci->db->where('invoice_id', $invoiceId);
        return (bool) $ci->db->update($table, $payload);
    }

    function get_state_abbr()
    {
        $estadosBrasileiros = [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins'
        ];
        return $estadosBrasileiros;
    }

    public function get_customer_customfields($id, $fieldto, $slug)
    {
        $ci = &get_instance();
        $ci->db->where('fieldto', $fieldto);
        $ci->db->where('slug', $slug);
        $customfields = $ci->db->get(db_prefix() . 'customfields')->result();
        foreach ($customfields as $row) {
            $ci->db->where('fieldto', $fieldto);
            $ci->db->where('relid', $id);
            $ci->db->where('fieldid', $row->id);
            $customfieldsvalues = $ci->db->get(db_prefix() . 'customfieldsvalues')->row();
        }
        if (isset($customfieldsvalues)) {
            return $customfieldsvalues->value;
        } else {
            return NULL;
        }
    }
    private function logAsaasActivity(string $message): void
    {
        try {
            $ci = &get_instance();
            asaas_diagnostic_quiet_db($ci->db ?? null, static function () use ($message): void {
                log_activity($message);
            });
        } catch (\Throwable $exception) {
            asaas_diagnostic_log('warning', 'Falha ao gravar histórico de atividade; o resultado da operação Asaas foi preservado.', [
                'operation' => 'activity_log',
                'exception' => get_class($exception),
            ]);
        }
    }

    private function safe_json_decode_array($json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

}
