<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** A native payment option beside recurring card, outside the one-time Pix/card/boleto chooser. */
class Asaas_automatic_pix_gateway extends App_gateway
{
    public function __construct()
    {
        parent::__construct(); $this->setId('asaas_automatic_pix'); $this->setName('Pix Automático – Asaas');
        $this->setSettings([
            ['name' => 'currencies', 'label' => 'settings_paymentmethod_currencies', 'default_value' => 'BRL',
                'after' => '<p class="help-block">Exige conta Asaas elegível, token de webhook configurado e cron do CRM. O cliente paga o primeiro QR Code e confirma a autorização no aplicativo do banco. A criação do QR Code não comprova pagamento nem ativa a autorização.</p>'
                    . '<p class="help-block">As próximas faturas elegíveis são agendadas pelo módulo entre 2 e 10 dias úteis antes do vencimento, respeitando a frequência autorizada. Faturas antigas e ciclos fora do prazo continuam com pagamento pontual.</p>'
                    . '<p class="help-block">O primeiro QR Code cobra o saldo exibido da fatura. A cobrança aberta é substituída somente após confirmação de sua exclusão. Cartão recorrente e Pix Automático não são autorizados simultaneamente para o mesmo cliente.</p>'],
            ['name' => 'additional_holidays', 'label' => 'Feriados bancários adicionais (AAAA-MM-DD, separados por vírgula)',
                'type' => 'input', 'default_value' => '', 'field_attributes' => ['maxlength' => '4000', 'autocomplete' => 'off'],
                'after' => '<p class="help-block">O calendário já considera fins de semana e feriados nacionais, incluindo Carnaval, Sexta-feira Santa e Corpus Christi. Cadastre datas adicionais aplicáveis à operação. O Asaas valida o prazo final do agendamento.</p>'
                    . '<p><a class="btn btn-default btn-sm" href="' . html_escape(admin_url('settings?group=payment_gateways&tab=online_payments_asaas_tab')) . '">Configurações do Asaas</a></p>'],
        ]);
        foreach ($this->settings as &$setting) {
            if (($setting['name'] ?? '') === 'default_selected') { $setting['default_value'] = 0; $setting['label'] = 'Disponibilizar este meio nas novas faturas'; }
        }
        unset($setting);
    }
    public function process_payment($data)
    {
        if (!is_array($data) || !is_object($data['invoice'] ?? null)) { show_404(); return; }
        $id = filter_var($data['invoice']->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hash = $data['invoice']->hash ?? null;
        if ($id === false || !is_string($hash) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $hash)) { show_404(); return; }
        $ci = get_instance(); $ci->load->model('invoices_model'); $invoice = $ci->invoices_model->get($id);
        if (!$invoice || (int) ($invoice->id ?? 0) !== $id || !is_string($invoice->hash ?? null) || !hash_equals($invoice->hash, $hash)) { show_404(); return; }
        require_once __DIR__ . '/../helpers/asaas_automatic_pix_helper.php';
        if ((string) $this->getSetting('active') !== '1' || !in_array('asaas_automatic_pix', asaas_invoice_payment_modes($invoice->allowed_payment_modes ?? []), true)) {
            set_alert('warning', 'O Pix Automático não está disponível nesta fatura.'); redirect(site_url('invoice/' . $id . '/' . $hash)); return;
        }
        redirect(site_url('asaas/automatic_pix/index/' . $hash));
    }
}
