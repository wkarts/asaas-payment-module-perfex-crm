<?php

trait BaseApi
{
    protected $apiKey;
    protected $apiUrl;
    protected $ci;
    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->library('asaas/asaas_gateway');
    }

    public function getUrlBase()
    {
        return $this->ci->asaas_gateway->getProvider()->baseHost();
    }

    public function getApiKey()
    {
        return $this->ci->asaas_gateway->getProvider()->apiKey();
    }
}
