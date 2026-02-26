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

        $data = [
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
        $status = 'NAOPAGO';
        $mensagem_erro = '';
        if ($this->input->post()) {
            $this->db->where('hash', $hash);
            $invoice1 = $this->db->get(db_prefix() . 'invoices')->row();
            $data = [];
            $data['invoice'] = $invoice1;
            $post_data = $this->input->post(NULL, TRUE);
            $data['card'] = [
                'holderName' => $this->input->post('holderName', TRUE),
                'number' => $this->input->post('cardNumber', TRUE),
                'expiryMonth' => $this->input->post('expirationMonth', TRUE),
                'expiryYear' => $this->input->post('expirationYear', TRUE),
                'cvv' => $this->input->post('securityCode', TRUE),
                'installmentCount' => $this->input->post('installmentCount', TRUE),
            ];
            $charge = $this->asaas_gateway->charge_credit_card($data);
            $content = $charge;


            $charge = json_decode($charge, TRUE);

            if (isset($charge["errors"])) {
                $mensagem_erro = $charge["errors"][0]["description"];
                //set_alert('warning', $charge["errors"][0]["description"]);

                //redirect(site_url('asaas/checkout/cartao/' . $invoice->hash), 'refresh');
                //die();
            } else {

                $content = json_decode($content);

                if ($content->status == "RECEIVED" || $content->status == "CONFIRMED" || $content->status == "RECEIVED_IN_CASH") {
                    $externalReference = $content->externalReference;
                    $status = $content->status;
                    $billingType = $content->billingType;

                    $this->db->where('hash', $externalReference);
                    $invoice = $this->db->get(db_prefix() . 'invoices')->row();

                    // check_invoice_restrictions($invoice->id, $invoiceid->hash);
                    if ($invoice) {
                        //echo "LINHA 103";
                        //echo "<hr>";
                        if ($invoice->status !== "2") {
                            //echo "LINHA 106";
                            //echo "<hr>";
                            if ($status == "RECEIVED" || $status == "CONFIRMED" || $status == "RECEIVED_IN_CASH") {
                                //echo "LINHA 109";
                                //echo "<hr>";
                                $this->asaas_gateway->addPayment([
                                    'amount' => $invoice->total,
                                    'invoiceid' => $invoice->id,
                                    'paymentmode' => 'Asaas',
                                    'paymentmethod' => $content->billingType,
                                    'transactionid' => $content->id,
                                ]);

                                log_activity('Asaas: Confirmação de pagamento para a fatura ' . $invoice->id . ', com o ID: ' . $externalReference);
                                //echo 'Asaas: Confirmação de pagamento para a fatura ' . $invoice->id . ', com o ID: ' . $externalReference;

                                redirect(site_url('invoice/' . $invoice1->id . '/' . $invoice1->hash), 'refresh');
                            } else {
                                log_activity('Asaas: Estado do pagamento da fatura ' . $invoice->id . ', com o ID: ' . $externalReference . ', Status: ' . $status);
                                //echo 'Asaas: Estado do pagamento da fatura ' . $invoice->id . ', com o ID: ' . $externalReference . ', Status: ' . $status;
                            }
                        } else {
                            log_activity('Asaas: Falha ao receber callback para a fatura ' . $invoice->id . ', com o ID: ' . $externalReference . ' ');
                            //echo 'Asaas: Falha ao receber callback para a fatura ' . $invoice->id . ', com o ID: ' . $externalReference;
                        }
                    }
                }
            }
        }

        $installmentCount = $this->asaas_gateway->getSetting('installmentCount');

        $this->db->where('hash', $hash);
        $invoice1 = $this->db->get(db_prefix() . 'invoices')->row();
        $invoice1->total_left_to_pay = get_invoice_total_left_to_pay($invoice1->id, $invoice1->total);

        $data = [
            'hash' => $hash,
            'invoice' => $invoice1,
            'installmentCount' => $installmentCount,
            'mensagem_erro' => $mensagem_erro,
            'title' => 'Asaas'
        ];
        //$this->disableNavigation();
        //$this->disableSubMenu();
        $this->data($data);
        $this->view('asaas/cartao');
        $this->layout();
    }

    public function qrcode($hash)
    {
        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            set_alert('warning', 'Fatura não encontrada.');
            redirect(site_url('invoices'));
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
