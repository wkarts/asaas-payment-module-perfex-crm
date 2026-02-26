<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Main extends ClientsController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
        $this->load->helper('general');
    }

    public function index()
    {
        // Mantém comportamento simples de teste, mas sem CURL e sem URL base manual.
        $post_data = json_encode(["type" => "EVP"]);
        $minhas_chaves = $this->create_key(null, null, $post_data);

        // saída compatível com o que já existia
        var_dump(json_decode($minhas_chaves, true));
    }

    public function create_key($api_url, $api_key, $post_data)
    {
        $payload = json_decode((string) $post_data, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('POST', '/pix/addressKeys', [], [], $payload);

        return json_encode(is_array($response) ? $response : []);
    }

    public function list_keys($api_url, $api_key)
    {
        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('GET', '/pix/addressKeys', [], [], null);

        return json_decode(json_encode(is_array($response) ? $response : []));
    }

    public function get_key($api_url, $api_key, $id)
    {
        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('GET', '/pix/addressKeys/' . $id, [], [], null);

        return json_decode(json_encode(is_array($response) ? $response : []));
    }

    public function delete_key($api_url, $api_key, $id)
    {
        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('DELETE', '/pix/addressKeys/' . $id, [], [], null);

        return json_decode(json_encode(is_array($response) ? $response : []));
    }

    public function retorna_cobranca($id = 178458832)
    {
        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('GET', '/payments/' . $id, [], [], null);

        echo json_encode(is_array($response) ? $response : []);
    }

    public function retorna_cobrancas($hash = '')
    {
        $client = $this->asaas_gateway->getProvider()->client();
        $response = $client->request('GET', '/payments', ['externalReference' => $hash], [], null);

        echo json_encode(is_array($response) ? $response : []);
    }
}
