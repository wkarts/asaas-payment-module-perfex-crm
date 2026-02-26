<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

class Callback extends APP_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
    }

    public function index()
    {
        if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') !== 0) {
            return;
        }

        $rawBody = trim(file_get_contents("php://input"));
        $headers = array_change_key_case($this->input->request_headers(), CASE_LOWER);

        $result = $this->asaas_gateway->handle_webhook($headers, $rawBody);

        if ($result['processed'] === true) {
            echo $result['message'];
            return;
        }

        log_activity('Asaas: Falha ao processar webhook. ' . $result['message']);
        echo $result['message'];
    }


}
