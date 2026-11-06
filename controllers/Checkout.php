<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Checkout extends ClientsController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
        $this->load->helper('general');
    }

    public function index($hash)
    {

        $this->db->where('hash', $hash);

        $invoice = $this->db->get(db_prefix() . 'invoices')->row();
        if (!$invoice) {
            show_404();
            return;
        }
        $cached = asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) $invoice->id));

        $data = [
            'card_checkout_blocked' => asaas_card_attempt_blocks($cached),
            'mensagem_info' => 'Existe um pagamento por cartão em acompanhamento. Volte à fatura ou acesse a confirmação do cartão; não pague novamente por outro meio.',
            'billet_only' => $this->asaas_gateway->getSetting('billet_only'),
            'card_only'   => $this->asaas_gateway->getSetting('card_only'),
            'pix_only'    => $this->asaas_gateway->getSetting('pix_only'),
            'environment' => $this->asaas_gateway->getEnvironmentLabel(),
            'invoice'     => $invoice,
            'hash'        => $hash,
            'title'       => 'Asaas'
        ];
        // $this->disableNavigation();
        // $this->disableSubMenu();
        $this->data($data);
        $this->view('asaas/payment');
        $this->layout();
    }

    public function boleto($hash)
    {
        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();
        if (!$invoice) {
            show_404();
            return;
        }
        if (asaas_card_attempt_blocks(asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) $invoice->id)))) {
            redirect(site_url('asaas/checkout/cartao/' . $hash));
            return;
        }

        $data = [];
        $data['invoice'] = $invoice;

        $charge = $this->asaas_gateway->get_charge($hash);

        if ($charge) {
            redirect($charge->bankSlipUrl);
        } else {
            // redirect(site_url('checkout/error/' . $invoice->hash), 'refresh');
            $this->session->set_flashdata('error', 'Não foi possível gerar a cobrança, Fale com o financeiro');

            set_alert('warning', 'Não foi possível gerar a cobrança, Fale com o financeiro');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash), 'refresh');
        }
    }

    public function cartao($hash)
    {
        $this->protectCardResponse();
        $hasCardPost = (bool) $this->input->post();
        // Keep credentials only in the local call; later hooks/profilers must not read them from globals.
        $card = $hasCardPost ? $this->takeCardInput() : [];
        $this->db->where('hash', $hash);
        $row = $this->db->get(db_prefix() . 'invoices')->row();
        if (!$row) {
            show_404();
            return;
        }
        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get($row->id);
        if (!$invoice) {
            show_404();
            return;
        }
        $checkout = $this->asaas_gateway->card_checkout();
        $message = (string) $this->session->flashdata('asaas_card_error');
        $info = (string) $this->session->flashdata('asaas_card_info');
        if ($hasCardPost) {
            try {
                if (!$checkout->available($invoice)) {
                    throw new InvalidArgumentException('O cartão de crédito não está disponível para esta fatura.');
                }
                $payment = json_decode($this->asaas_gateway->charge_credit_card(['invoice' => $invoice, 'card' => $card]), true, 512, JSON_THROW_ON_ERROR);
                $this->recordCardResult($invoice, $payment);
                $info = asaas_card_is_received($payment)
                    ? 'Pagamento confirmado no Asaas. Os recebimentos confirmados foram conciliados com a fatura.'
                    : 'Pagamento em acompanhamento no Asaas. Aguarde a confirmação antes de tentar novamente ou pagar por outro meio.';
                $this->session->set_flashdata('asaas_card_info', $info);
            } catch (InvalidArgumentException $error) {
                $this->session->set_flashdata('asaas_card_error', $error->getMessage());
            } catch (Throwable $error) {
                // The card service only exposes controlled messages, never card/API payloads.
                asaas_diagnostic_log('error', 'Checkout de cartão precisa de atenção.', ['invoice_id' => (int) $invoice->id, 'exception' => get_class($error)]);
                $this->session->set_flashdata('asaas_card_error', $error instanceof \Asaas\AsaasCardCheckoutException
                    ? $error->getMessage() : 'Não foi possível concluir o pagamento. Consulte o financeiro antes de repetir.');
            } finally {
                unset($card);
            }
            redirect(site_url('asaas/checkout/cartao/' . $hash), 'location', 303);
            return; // PRG prevents browser refresh from resubmitting card credentials.
        }

        try {
            $reconciled = $checkout->reconcile($invoice);
            if ($reconciled !== null) {
                $this->recordCardResult($invoice, $reconciled);
                $invoice = $this->invoices_model->get($row->id);
            }
        } catch (Throwable $error) {
            asaas_diagnostic_log('error', 'Confirmação do cartão pendente de conciliação.', ['invoice_id' => (int) $invoice->id]);
            $info = 'O pagamento está em acompanhamento. Não repita a tentativa; consulte o financeiro.';
        }
        $invoice->total_left_to_pay = get_invoice_total_left_to_pay($invoice->id, $invoice->total);
        $cached = asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) $invoice->id));
        $blocked = !$checkout->available($invoice) || asaas_card_attempt_blocks($cached);
        if ($blocked && $info === '') {
            $info = (int) $invoice->status === 2 ? 'Esta fatura já está paga.'
                : 'O pagamento por cartão não está disponível para uma nova tentativa. Consulte o financeiro ou acompanhe a fatura.';
        }
        $client = $invoice->client ?? (object) [];
        $options = asaas_card_installment_options($invoice->total_left_to_pay,
            $this->asaas_gateway->getSetting('installmentCount'), $checkout->discount());
        if (!empty($cached['installment']) && isset($cached['_asaas_lifecycle']['card_attempt']['installment_count'])) {
            $existingCount = (int) $cached['_asaas_lifecycle']['card_attempt']['installment_count'];
            $options = array_values(array_filter($options, static fn (array $option): bool => $option['count'] === $existingCount));
        }
        $this->data([
            'hash' => $hash, 'invoice' => $invoice, 'title' => 'Asaas',
            'environment' => $this->asaas_gateway->getEnvironmentLabel(),
            'installment_options' => $options, 'card_checkout_blocked' => $blocked,
            'mensagem_erro' => $message, 'mensagem_info' => $info,
            'card_holder_defaults' => [
                'holderName' => '', 'holderEmail' => $client->email ?? '', 'holderDocument' => $client->vat ?? '',
                'holderPostalCode' => $client->zip ?? '', 'holderAddressNumber' => $client->numero ?? '',
                'holderPhone' => $client->phonenumber ?? '',
            ],
        ]);
        $this->view('asaas/cartao');
        $this->layout();
    }

    private function protectCardResponse(): void
    {
        $this->output->cache(0);
        $this->output->enable_profiler(false);
        foreach (['Cache-Control: no-store', 'Pragma: no-cache', 'Expires: 0'] as $header) {
            $this->output->set_header($header);
            // CI redirect()/show_404() can exit before Output sends its queued headers.
            if (!headers_sent()) {
                header($header);
            }
        }
    }

    private function takeCardInput(): array
    {
        $fields = [
            'holderName' => 'holderName', 'number' => 'cardNumber', 'expiryMonth' => 'expirationMonth',
            'expiryYear' => 'expirationYear', 'cvv' => 'securityCode', 'installmentCount' => 'installmentCount',
            'holderEmail' => 'holderEmail', 'holderDocument' => 'holderDocument',
            'holderPostalCode' => 'holderPostalCode', 'holderAddressNumber' => 'holderAddressNumber',
            'holderPhone' => 'holderPhone',
        ];
        $card = [];
        foreach ($fields as $field => $posted) {
            $card[$field] = $this->input->post($posted, true);
            if ($field !== 'installmentCount') {
                unset($_POST[$posted], $_REQUEST[$posted]);
            }
        }
        return $card;
    }

    private function recordCardResult(object $invoice, array $payment): void
    {
        foreach ($payment['_asaas_card_receipts'] ?? [] as $receipt) {
            if (!asaas_card_is_received($receipt) || ($receipt['externalReference'] ?? null) !== (string) $invoice->hash) {
                continue;
            }
            $balance = get_invoice_total_left_to_pay($invoice->id, $invoice->total);
            if (asaas_payment_amount_to_cents($balance) === 0) {
                break;
            }
            if (!$this->asaas_gateway->addPayment([
                'amount' => asaas_card_receipt_amount($receipt, $balance),
                'invoiceid' => $invoice->id, 'paymentmode' => 'Asaas',
                'paymentmethod' => 'CREDIT_CARD', 'transactionid' => $receipt['id'],
            ])) {
                throw new \Asaas\AsaasCardCheckoutException('Pagamento confirmado no Asaas, mas a baixa no CRM está pendente de conciliação. Não repita o pagamento; consulte o financeiro.');
            }
        }
    }

    public function qrcode($hash)
    {
        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            set_alert('warning', 'Fatura não encontrada.');
            redirect(site_url('invoices'));
            return;
        }
        if (asaas_card_attempt_blocks(asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) $invoice->id)))) {
            redirect(site_url('asaas/checkout/cartao/' . $hash));
            return;
        }

        $invoice->total_left_to_pay = get_invoice_total_left_to_pay($invoice->id, $invoice->total);

        $data = [];
        $data['invoice'] = $invoice;

        $billet = $this->asaas_gateway->get_charge($hash);

        // Verifica se a cobrança foi retornada
        if ($billet && isset($billet->id)) {
            $qrcode = $this->asaas_gateway->create_qrcode($billet->id);

            $data = [
                'invoice' => $invoice,
                'response' => json_decode($qrcode),
                'environment' => $this->asaas_gateway->getEnvironmentLabel(),
            ];
        } else {
            set_alert('warning', 'Não foi possível gerar o QR code. Verifique a cobrança.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash), 'refresh');
        }

        //$this->disableNavigation();
        //$this->disableSubMenu();
        $this->title('Asaas');
        $this->data($data);
        $this->view('asaas/qrcode');
        $this->layout();
    }


    public function error($hash)
    {
        $this->title('Asaas');
        $this->disableNavigation();
        $this->disableSubMenu();
        $this->view('asaas/error');
        $this->layout();
    }
}
