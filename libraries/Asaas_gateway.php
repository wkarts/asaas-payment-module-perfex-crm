<?php
defined('BASEPATH') or exit('No direct script access allowed');

require FCPATH . 'modules/asaas/traits/BaseApi.php';
require_once __DIR__ . '/AsaasSdkProvider.php';
require_once __DIR__ . '/Asaas/AsaasAdapter.php';
require_once __DIR__ . '/../helpers/asaas_lifecycle_helper.php';
require_once __DIR__ . '/../helpers/asaas_card_helper.php';
require_once __DIR__ . '/Asaas/AsaasCardCheckout.php';

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
                'label' => 'Chave da API de Produção',
                'type' => 'input',
                'input_type' => 'password',
                'field_attributes' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'autocapitalize' => 'none'],
                'after' => '<p class="mbot15"><button type="button" class="btn btn-default btn-sm" data-asaas-secret-toggle="api_key" aria-pressed="false">Exibir chave</button></p>',
            ),
            array(
                'name' => 'api_key_sandbox',
                'encrypted' => true,
                'label' => 'Chave da API de Homologação',
                'type' => 'input',
                'input_type' => 'password',
                'field_attributes' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'autocapitalize' => 'none'],
                'after' => '<p class="mbot15"><button type="button" class="btn btn-default btn-sm" data-asaas-secret-toggle="api_key_sandbox" aria-pressed="false">Exibir chave</button></p>',
            ),
            array(
                'name' => 'sandbox',
                'label' => 'Ambiente de operação',
                'type' => 'yes_no',
                'default_value' => 1,
                'after' => '<p class="help-block">Homologação utiliza a conta de testes. Produção realiza operações reais. Selecione somente um ambiente.</p>'
                    . '<noscript><p class="help-block">Neste controle, Sim corresponde a Homologação e Não corresponde a Produção.</p></noscript>',
            ),
            array(
                'name' => 'show_environment_in_production',
                'label' => 'Exibir o ambiente em produção na área de pagamentos',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Controla a faixa de ambiente exibida ao pagador. Em Homologação, o aviso de teste permanece visível.</p>',
            ),
            array(
                'name' => 'debug',
                'label' => 'Registrar diagnóstico detalhado',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Registra o histórico detalhado da integração. Esta opção não altera o ambiente de operação.</p>',
            ),
            array(
                'name' => 'file_log_enabled',
                'label' => 'Habilitar log em arquivo',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Consulte o resultado do envio de faturas, os erros da API e a localização dos arquivos de log.</p>'
                    . '<p class="mbot15"><a class="btn btn-default btn-sm" data-asaas-logs-open href="' . html_escape(admin_url('asaas/asaas/logs')) . '">Ver logs da integração</a></p>',
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
                'label' => 'Conciliar recebimentos automaticamente pelo CRON',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Consulta o Asaas e registra os recebimentos confirmados que faltam no CRM, inclusive de faturas antigas sem webhook. Recupera os vínculos quando a identidade da cobrança pode ser comprovada. Acompanhe os resultados na guia Monitoramento.</p>',
            ),
            array(
                'name' => 'sync_days',
                'label' => 'Janela de revisão das faturas quitadas (dias)',
                'type' => 'input',
                'input_type' => 'number',
                'field_attributes' => ['min' => '1', 'max' => '365', 'step' => '1'],
                'default_value' => 7,
                'after' => '<p class="help-block">Revisa faturas com recebimentos locais recentes nesta janela. Faturas Asaas em aberto são conferidas independentemente da data de emissão ou vencimento.</p>',
            ),
            array(
                'name' => 'sync_limit',
                'label' => 'Limite de faturas por execução do CRON',
                'type' => 'input',
                'input_type' => 'number',
                'field_attributes' => ['min' => '1', 'max' => '100', 'step' => '1'],
                'default_value' => 50,
                'after' => '<p class="help-block">Limita o trabalho de cada execução. O cursor continua nas próximas faturas nas execuções seguintes; o limite não reduz o histórico disponível.</p>',
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
                'label' => 'Limite de parcelas no cartão (1 a 21)',
                'type' => 'input',
                'input_type' => 'number',
                'field_attributes' => ['min' => '1', 'max' => '21', 'step' => '1', 'required' => 'required'],
                'default_value' => 1,
                'after' => '<p class="help-block">Até 12 parcelas nas demais bandeiras e até 21 em Visa/Mastercard, conforme disponibilidade da conta Asaas. As opções também respeitam o saldo e o valor mínimo da parcela.</p>',
            ),
            array(
                'name' => 'card_min_installment',
                'label' => 'Valor mínimo da parcela (R$)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'minimum'],
                'default_value' => '5.00',
                'after' => '<p class="help-block">Mínimo de R$ 5,00 por parcela, após descontos. Este limite administrativo vale para pagamentos parcelados.</p>',
            ),
            array(
                'name' => 'card_free_installments',
                'label' => 'Absorver as taxas até esta quantidade de parcelas',
                'type' => 'input',
                'input_type' => 'number',
                'field_attributes' => ['min' => '0', 'max' => '21', 'step' => '1', 'required' => 'required'],
                'default_value' => 0,
                'after' => '<p class="help-block">Usado quando o repasse está habilitado. 0: sem faixa de isenção; 1: absorve no pagamento à vista. Não pode ultrapassar o limite de parcelas.</p>',
            ),
            array(
                'name' => 'card_fee_mode',
                'label' => 'Tratamento das taxas do cartão',
                'type' => 'input',
                'field_attributes' => ['pattern' => 'absorb|customer', 'required' => 'required'],
                'default_value' => 'absorb',
                'after' => '<p class="help-block">Absorver mantém o valor da fatura. Repassar inclui as taxas configuradas no total do cartão, apresentado antes da confirmação. Os valores iniciais das taxas são referências: ajuste conforme o contrato da sua conta Asaas.'
                    . ' <span data-asaas-card-enum-fallback="card_fee_mode">Valores: <code>absorb</code> para absorver ou <code>customer</code> para repassar ao cliente.</span></p>',
            ),
            array(
                'name' => 'card_fee_fixed_mode',
                'label' => 'Incidência da taxa fixa',
                'type' => 'input',
                'field_attributes' => ['pattern' => 'charge|installment', 'required' => 'required'],
                'default_value' => 'charge',
                'after' => '<p class="help-block">A taxa fixa é somada à taxa percentual da faixa selecionada. Configure a incidência conforme o contrato da sua conta.'
                    . ' <span data-asaas-card-enum-fallback="card_fee_fixed_mode">Valores: <code>charge</code> por cobrança ou <code>installment</code> por parcela.</span></p>',
            ),
            array(
                'name' => 'card_fee_fixed',
                'label' => 'Taxa fixa do cartão (R$)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'fixed'],
                'default_value' => '0.49',
            ),
            array(
                'name' => 'card_fee_percent_1',
                'label' => 'Taxa em 1 parcela (%)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '2.99',
            ),
            array(
                'name' => 'card_fee_percent_2_6',
                'label' => 'Taxa de 2 a 6 parcelas (%)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '3.49',
            ),
            array(
                'name' => 'card_fee_percent_7_12',
                'label' => 'Taxa de 7 a 12 parcelas (%)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '3.99',
            ),
            array(
                'name' => 'card_fee_percent_13_21',
                'label' => 'Taxa de 13 a 21 parcelas (%)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '4.29',
                'after' => '<p class="help-block">Faixa disponível para Visa/Mastercard. Percentuais de 0% a 99%, com até duas casas decimais.</p>',
            ),
            array(
                'name' => 'card_anticipation_mode',
                'label' => 'Antecipação considerada nos encargos',
                'type' => 'input',
                'field_attributes' => ['pattern' => 'none|manual|automatic', 'required' => 'required'],
                'default_value' => 'none',
                'after' => '<p class="help-block">Escolha a modalidade que pretende utilizar na sua conta Asaas. O custo estimado é incluído nos encargos do cartão quando o repasse está habilitado, respeitando as parcelas sem repasse. Esta configuração não solicita antecipações nem ativa a antecipação automática no Asaas.'
                    . ' <span data-asaas-card-enum-fallback="card_anticipation_mode">Valores: <code>none</code> para não incluir, <code>manual</code> para avulsa ou <code>automatic</code> para automática.</span></p>',
            ),
            array(
                'name' => 'card_anticipation_first_days',
                'label' => 'Dias antecipados da primeira parcela',
                'type' => 'input',
                'input_type' => 'number',
                'field_attributes' => ['min' => '0', 'max' => '365', 'step' => '1', 'required' => 'required'],
                'default_value' => 30,
                'after' => '<p class="help-block">Prazo estimado entre o recebimento antecipado e o recebimento normal da primeira parcela. As parcelas seguintes acrescentam 30 dias cada, usando mês comercial de 30 dias. Ajuste conforme a operação prevista; a taxa efetiva depende das condições do Asaas.</p>',
            ),
            array(
                'name' => 'card_anticipation_manual_rate_1',
                'label' => 'Avulsa à vista (% ao mês)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '1.25',
            ),
            array(
                'name' => 'card_anticipation_manual_rate_installments',
                'label' => 'Avulsa parcelada (% ao mês)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '1.70',
            ),
            array(
                'name' => 'card_anticipation_automatic_rate_1',
                'label' => 'Automática à vista (% ao mês)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '1.15',
            ),
            array(
                'name' => 'card_anticipation_automatic_rate_installments',
                'label' => 'Automática parcelada (% ao mês)',
                'type' => 'input',
                'field_attributes' => ['inputmode' => 'decimal', 'pattern' => '([0-9]+([.,][0-9]{1,2})?|[.,][0-9]{1,2})', 'maxlength' => '128', 'required' => 'required', 'data-asaas-card-decimal' => 'percentage'],
                'default_value' => '1.60',
                'after' => '<p class="help-block">Taxas mensais de referência, editáveis conforme seu contrato. O cálculo utiliza somente a modalidade escolhida e a taxa de à vista ou parcelado correspondente. O cliente vê um único valor de encargos do cartão.</p>',
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
                'name' => 'sync_invoice_lifecycle',
                'label' => 'Sincronizar exclusão, cancelamento e reativação das faturas',
                'type' => 'yes_no',
                'default_value' => 1,
                'after' => '<p class="help-block">Sincroniza essas ações com a cobrança vinculada no Asaas. Falhas impedem a alteração local para evitar divergências.</p>',
            ),
            array(
                'name' => 'manual_payment_sync',
                'label' => 'Sincronizar a quitação manual integral da fatura',
                'type' => 'yes_no',
                'default_value' => 1,
                'after' => '<p class="help-block">A ação abaixo é executada quando os pagamentos registrados manualmente quitam toda a fatura. Pagamentos parciais não encerram a cobrança no Asaas.</p>',
            ),
            array(
                'name' => 'manual_payment_receive_in_cash',
                'label' => 'Confirmar a quitação manual como recebida em dinheiro no Asaas',
                'type' => 'yes_no',
                'default_value' => 1,
                'after' => '<p class="help-block">Sim: confirma o recebimento em dinheiro, sem movimentar o saldo Asaas. Não: exclui a cobrança em aberto no Asaas após a quitação integral no CRM.</p>',
            ),
            array(
                'name' => 'delete_charge',
                'label' => 'Deletar cobrança da fatura no Asaas',
                'type' => 'yes_no',
                'default_value' => 0,
                'after' => '<p class="help-block">Opção legada, utilizada somente quando a sincronização de exclusão, cancelamento e reativação estiver desabilitada.</p>',
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

    /** Administrative recovery reads do not repeat requests on rate limits or server errors. */
    public function reconciliation_request(string $path, array $query = []): array
    {
        return $this->adapter->request('GET', $path, $query, [], null, false, null, true);
    }

    public function process_payment($data)
    {
        if (empty($data)) {
            return;
        }

        if (asaas_card_attempt_blocks(asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) ($data['invoice']->id ?? 0))))) {
            redirect(site_url('asaas/checkout/cartao/' . $data['invoice']->hash));
            return;
        }

        $asaasInvoiceId = (int) ($data['invoice']->id ?? 0);
        try {
            asaas_lifecycle_checkout_begin($asaasInvoiceId);
        $invoice = $data['invoice']->id;
        $ci = &get_instance();
        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . 'invoices')->row_array();

        $ci->db->select('c.*, cc.email');
        $ci->db->from(db_prefix() . 'clients c');
        $ci->db->join(db_prefix() . 'contacts cc', 'cc.userid = c.userid AND cc.is_primary = 1', 'LEFT');
        $ci->db->where('c.userid', $row['clientid']);
        $client = $ci->db->get()->row_array();

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

        // Opening an existing charge must not renew its due date or recalculate
        // interest locally. Boleto validity and late fees remain under Asaas,
        // including an OVERDUE charge with canBePaidAfterDueDate enabled.
        if (!$search_charge) {
            if ($row['status'] == '4') {
                $row['total'] = $this->calculate_invoice($invoice, $row, $fine, $interest);
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
                    "externalReference" => (string) $row['clientid'],
                    "notificationDisabled" => $notificationDisabled,
                ]);

                $cliente_create = $this->create_customer($api_url, $api_key, $post_data);
                $cliente_id = $cliente_create['id'];

                $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
            } else {
                // se existir recupera os dados para cobranca
                $cliente_id = $customer['data'][0]['id'];
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

            $post_data = json_encode($post_data);

            $charge = $this->create_charge($api_url, $api_key, $post_data);

            $this->logAsaasActivity('Cobrança Boleto/Pix Asaas [Fatura ID: ' . $invoice . ']');
        }
        } catch (Throwable $error) {
            asaas_lifecycle_reject($error, $asaasInvoiceId);
        }
        asaas_lifecycle_checkout_finish($asaasInvoiceId);

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
                    asaas_lifecycle_checkout_remember($charge);
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
            'limit' => 100,
        ]);

        if (!empty($payments['hasMore'])) {
            throw new RuntimeException('Há cobranças demais para esta referência. Concilie os vínculos antes de continuar.');
        }
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
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
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

        return $charge;
    }

    public function card_checkout(): \Asaas\AsaasCardCheckout
    {
        return new \Asaas\AsaasCardCheckout($this);
    }

    public function charge_credit_card($data)
    {
        // Keep the legacy JSON return contract for callers of this gateway.
        return json_encode($this->card_checkout()->pay((array) $data), JSON_THROW_ON_ERROR);
    }

    public function charge_pix($data)
    {
        if (empty($data)) {
            return;
        }
        $asaasInvoiceId = (int) ($data['invoice']->id ?? 0);
        try {
            asaas_lifecycle_checkout_begin($asaasInvoiceId);
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
        $postalCode = str_replace('-', '', str_replace('.', '', $client['zip']));
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
                "externalReference" => (string) $row['clientid'],
                "notificationDisabled" => $notificationDisabled,
            ];
            $post_data = json_encode($post_data);
            $cliente_create = $this->create_customer($api_url, $api_key, $post_data);
            $cliente_id = $cliente_create['id'];
            $this->logAsaasActivity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
        } else {
            // se existir recupera os dados para cobranca
            $cliente_id = $customer['data'][0]['id'];
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
                'duedate' => date('Y-m-d', strtotime("+" . $datevence . " day"))
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
        } catch (Throwable $error) {
            asaas_lifecycle_reject($error, $asaasInvoiceId);
        } finally {
            asaas_lifecycle_checkout_finish($asaasInvoiceId);
        }
    }

    public function create_charge($api_url, $api_key, $post_data)
    {
        $payload = $this->safe_json_decode_array($post_data);
        $response = $this->adapter->createPayment(is_array($payload) ? $payload : []);
        $this->require_charge_response($response);
        asaas_lifecycle_checkout_record($response);
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
        $previous = asaas_lifecycle_checkout_previous((string) $charge_id);
        $response = $this->adapter->updatePayment($charge_id, is_array($payload) ? $payload : []);
        $this->require_charge_response($response, (string) $charge_id);
        if ($previous !== null) {
            asaas_lifecycle_checkout_record($response, $previous);
        }
        return $response;
    }

    private function require_charge_response(array $response, ?string $expectedId = null): void
    {
        $id = $response['id'] ?? null;
        if (!is_string($id) || $id === '' || trim($id) !== $id
            || isset($response['errors']) || isset($response['error'])
            || ($expectedId !== null && $id !== $expectedId)) {
            throw new RuntimeException('O Asaas não confirmou a cobrança solicitada. A gravação local foi interrompida.');
        }
    }

    // https://api.asaas.com/v3/payments/id
    public function delete_charge($charge_id)
    {
        return $this->adapter->deletePayment((string) $charge_id);
    }

    public function restore_charge($charge_id): array
    {
        return $this->adapter->restorePayment((string) $charge_id);
    }

    public function receive_charge_in_cash($charge_id, string $payment_date, $value, bool $notify = false): array
    {
        return $this->adapter->receivePaymentInCash((string) $charge_id, $payment_date, $value, $notify);
    }

    public function undo_receive_in_cash($charge_id): array
    {
        return $this->adapter->undoReceivePaymentInCash((string) $charge_id);
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

    public function addPayment($data)
    {
        $state = &asaas_lifecycle_state();
        if (!empty($state['remote_payment_depth'])) {
            return asaas_lifecycle_remote_payment(fn () => parent::addPayment($data));
        }

        $invoiceId = is_array($data) ? ($data['invoiceid'] ?? null) : null;
        $paymentId = is_array($data) ? ($data['transactionid'] ?? null) : null;
        if ((!is_int($invoiceId) && !is_string($invoiceId))
            || (string) (int) $invoiceId !== (string) $invoiceId || (int) $invoiceId <= 0
            || !is_string($paymentId) || $paymentId === '') {
            asaas_diagnostic_log('error', 'Recebimento do checkout não registrado: fatura ou identificador da cobrança inválido.');
            return false;
        }
        $invoiceId = (int) $invoiceId;
        $ci = &get_instance();
        $transaction = null;
        $atomic = false;
        $locked = false;
        $success = false;
        $recorded = null;
        try {
            asaas_receipt_lock($invoiceId);
            $locked = true;
            $atomic = asaas_lifecycle_has_atomic_payment_writer();
            if ($atomic) {
                if (asaas_atomic_payment_depth($ci->db) !== 0) {
                    throw new RuntimeException('O núcleo precisa iniciar sua própria transação para registrar o recebimento.');
                }
            } else {
                $transaction = new \Asaas\InvoiceTransaction($ci->db);
                $transaction->assertTransactionalTables([db_prefix() . 'invoices', db_prefix() . 'invoicepaymentrecords']);
                $transaction->begin();
                asaas_diagnostic_quiet_db($ci->db, static fn () => asaas_lifecycle_lock_invoice($invoiceId));
            }
            $invoice = asaas_lifecycle_invoice($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('Fatura não encontrada para registrar o recebimento do checkout.');
            }
            if (asaas_lifecycle_should_ignore_payment($invoiceId, $paymentId)) {
                throw new RuntimeException('A cobrança foi encerrada ou substituída; o recebimento do checkout precisa de conciliação.');
            }
            $pricing = asaas_card_receipt_allocations(asaas_lifecycle_cached_payment(asaas_lifecycle_map($invoiceId)));
            $expectedPrincipal = null;
            if ($pricing !== null) {
                $expectedPrincipal = $pricing['allocations'][$paymentId]['principal_cents'] ?? null;
                if ($expectedPrincipal === null || asaas_payment_amount_to_cents($data['amount'] ?? null) !== $expectedPrincipal) {
                    throw new AsaasCardReceiptException('payment_mismatch');
                }
            }
            $recorded = static function () use ($ci, $invoiceId, $paymentId, $expectedPrincipal): bool {
                if ($expectedPrincipal !== null) {
                    $query = asaas_diagnostic_quiet_db($ci->db, static fn () => $ci->db->get_where(
                        db_prefix() . 'invoicepaymentrecords', ['transactionid' => $paymentId]
                    ));
                    if (!is_object($query) || !method_exists($query, 'result_array') || !is_array($rows = $query->result_array())) {
                        throw new RuntimeException('Não foi possível conferir o recibo existente da cobrança.');
                    }
                    if ($rows === []) { return false; }
                    if (count($rows) !== 1 || (int) ($rows[0]['invoiceid'] ?? 0) !== $invoiceId
                        || asaas_payment_amount_to_cents($rows[0]['amount'] ?? null) !== $expectedPrincipal) {
                        throw new AsaasCardReceiptException('allocation_invalid');
                    }
                    return true;
                }
                $query = asaas_diagnostic_quiet_db($ci->db, static fn () => $ci->db->get_where(
                    db_prefix() . 'invoicepaymentrecords', ['invoiceid' => $invoiceId, 'transactionid' => $paymentId], 1
                ));
                if (!is_object($query) || !method_exists($query, 'row_array') || !is_array($row = $query->row_array())) {
                    throw new RuntimeException('Não foi possível conferir o recibo existente da cobrança.');
                }
                return $row !== [];
            };
            if ($recorded()) {
                $success = true;
            } elseif (in_array((int) $invoice->status, [5, 6], true)) {
                throw new RuntimeException('A fatura está cancelada ou em rascunho; o recebimento precisa de conciliação.');
            } else {
                $balance = asaas_payment_amount_to_cents(get_invoice_total_left_to_pay($invoiceId, $invoice->total));
                if ($expectedPrincipal !== null && ($expectedPrincipal > $balance || (int) $invoice->status === 2)) {
                    throw new AsaasCardReceiptException('balance_mismatch');
                }
                if ((int) $invoice->status === 2 || $balance === 0) {
                    $success = true;
                } else {
                    $amount = $expectedPrincipal ?? min(asaas_payment_amount_to_cents($data['amount'] ?? null), $balance);
                    if ($amount <= 0) {
                        throw new RuntimeException('O recebimento do checkout precisa informar um valor positivo.');
                    }
                    $data['invoiceid'] = $invoiceId;
                    $data['amount'] = intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
                    $success = asaas_lifecycle_remote_payment(fn () => parent::addPayment($data)) === true;
                    if (!$success) {
                        throw new RuntimeException('O núcleo não confirmou o recebimento do checkout.');
                    }
                }
            }
            if ($transaction !== null) {
                $transaction->commit();
            }
        } catch (Throwable $error) {
            $success = false;
            if ($transaction !== null && $transaction->isActive()) {
                try {
                    $transaction->rollback();
                } catch (Throwable $rollbackError) {
                    asaas_diagnostic_log('error', 'Rollback do recibo do checkout não confirmado; conciliação necessária.', [
                        'invoice_id' => $invoiceId, 'payment_id' => $paymentId,
                    ]);
                }
            } elseif ($atomic && is_callable($recorded)) {
                try {
                    // The custom writer commits before notifications and the after-hook.
                    $success = asaas_atomic_payment_depth($ci->db) === 0 && $recorded();
                } catch (Throwable $readError) {
                    // An unavailable receipt query cannot prove either persistence or absence.
                }
            }
            asaas_diagnostic_log($success ? 'warning' : 'error', $success
                ? 'Recibo do checkout já gravado; houve falha em uma etapa posterior do núcleo.'
                : 'Recebimento do checkout não confirmado; conferir o recibo local e a cobrança Asaas.', [
                    'invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'exception' => get_class($error),
                    'reason' => $error instanceof AsaasCardReceiptException ? $error->reason() : 'receipt_failed',
                ]);
        } finally {
            if ($locked) {
                asaas_receipt_unlock($invoiceId);
            }
        }
        return $success;
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

        $chargeData = asaas_card_safe_payload($chargeData);
        $now = date('Y-m-d H:i:s');
        $existing = $ci->db->get_where($table, ['invoice_id' => $invoiceId])->row_array();

        // Refreshes of the same charge must not erase local lifecycle decisions.
        if ($existing && (string) ($existing['asaas_payment_id'] ?? '') === (string) ($chargeData['id'] ?? '')
            && !array_key_exists('_asaas_lifecycle', $chargeData)) {
            $cached = is_string($existing['payload_cache'] ?? null) ? json_decode($existing['payload_cache'], true) : null;
            if (is_array($cached['_asaas_lifecycle'] ?? null)) {
                $chargeData['_asaas_lifecycle'] = $cached['_asaas_lifecycle'];
            }
        }

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
