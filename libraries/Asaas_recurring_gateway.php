<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** A separate native gateway; credentials and pricing remain owned by Asaas. */
class Asaas_recurring_gateway extends App_gateway
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('asaas_recurring');
        $this->setName('Cartão de crédito recorrente – Asaas');
        // App_gateway supplies active (default 0), label and default_selected.
        $this->setSettings([
            [
                'name' => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL',
                'after' => '<p class="help-block">Meio de pagamento separado para o cliente autorizar a cobrança desta fatura e das próximas faturas Asaas elegíveis, em uma parcela por fatura. Exige acesso à conta do cliente e consentimento explícito.</p>'
                    . '<p class="help-block">Utiliza o ambiente, as credenciais e os encargos à vista configurados no meio de pagamento Asaas principal, que deve permanecer ativo. Uma recusa suspende a autorização até o cliente cadastrar o cartão novamente; o pagamento pontual continua disponível quando não há confirmação pendente.</p>'
                    . '<p class="help-block">O módulo guarda somente a autorização e o token criptografado fornecido pelo Asaas. Número do cartão, validade e CVV não são persistidos.</p>'
                    . '<p><a class="btn btn-default btn-sm" href="' . html_escape(admin_url('settings?group=payment_gateways&tab=online_payments_asaas_tab')) . '">Configurações do Asaas</a></p>',
            ],
        ]);
        // Override only the native default; adding a second setting duplicates the form field.
        foreach ($this->settings as &$setting) {
            if (($setting['name'] ?? '') === 'default_selected') {
                $setting['default_value'] = 0;
                $setting['label'] = 'Pré-selecionar este meio nas novas faturas';
                $setting['after'] = '<p class="help-block">A seleção do meio não substitui a autorização explícita do cliente para cobranças recorrentes.</p>';
            }
        }
        unset($setting);
    }

    /** Selecting this method only opens enrollment/status; it never charges. */
    public function process_payment($data)
    {
        if (!is_array($data) || !is_object($data['invoice'] ?? null)) {
            show_404();
            return;
        }
        $supplied = $data['invoice'];
        $id = filter_var($supplied->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hash = $supplied->hash ?? null;
        if ($id === false || !is_string($hash) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $hash)) {
            show_404();
            return;
        }
        $ci = &get_instance();
        $ci->load->model('invoices_model');
        $invoice = $ci->invoices_model->get($id);
        if (!is_object($invoice) || (int) ($invoice->id ?? 0) !== $id
            || !is_string($invoice->hash ?? null) || !hash_equals($invoice->hash, $hash)) {
            show_404();
            return;
        }
        $ci->load->library('asaas_gateway');
        $currency = strtoupper((string) ($invoice->currency_name ?? ''));
        if ($currency === '' && function_exists('get_currency') && isset($invoice->currency)) {
            $currencyRecord = get_currency($invoice->currency);
            $currency = is_object($currencyRecord) ? strtoupper((string) ($currencyRecord->name ?? '')) : '';
        }
        $modes = function_exists('asaas_invoice_payment_modes')
            ? asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []) : [];
        if ((string) $this->getSetting('active') !== '1'
            || (string) $ci->asaas_gateway->getSetting('active') !== '1'
            || !in_array('asaas_recurring', $modes, true) || $currency !== 'BRL'
            || in_array((int) ($invoice->status ?? 0), [2, 5, 6], true)) {
            set_alert('warning', 'O cartão recorrente não está disponível para uma nova adesão nesta fatura.');
            redirect(site_url('invoice/' . $id . '/' . $hash));
            return;
        }
        redirect(site_url('asaas/recurring/index/' . $hash));
    }
}
