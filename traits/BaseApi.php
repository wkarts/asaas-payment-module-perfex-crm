<?php

trait BaseApi
{
    protected $apiKey;
    protected $apiUrl;
    protected $ci;

    protected function ciInstance()
    {
        if (!isset($this->ci) || $this->ci === null) {
            $this->ci = &get_instance();
        }

        return $this->ci;
    }

    public function getUrlBase()
    {
        if (method_exists($this, 'getProvider')) {
            return $this->getProvider()->baseHost();
        }

        $ci = $this->ciInstance();

        return $ci->asaas_gateway->getProvider()->baseHost();
    }

    public function getApiKey()
    {
        if (method_exists($this, 'getProvider')) {
            return $this->getProvider()->apiKey();
        }

        $ci = $this->ciInstance();

        return $ci->asaas_gateway->getProvider()->apiKey();
    }
}
