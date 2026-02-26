Refatorações:

# Arquivo: modules/asaas/asaas.php

- Removi funções do arquivo principal que não estavam sendo usadas para deixar o código mais limpo.
- Removi comentários também para deixar o código mais limpo, pois isso deixa código sujo.
- Definir uma constante para o nome do módulo, pois o nome do módulo é usado em vários lugares e se o nome mudar, teria que mudar em vários lugares.
- Movi todos os _hooks_ (ganchos) para o topo, pois isso deixa o código mais limpo e claro.
- Removi variáveis que não estavam sendo usadas.

1h30

# Arquivo: modules/asaas/controllers/Asaas.php
- Removi comentários também para deixar o código mais limpo, pois isso deixa código sujo.
- Centralizei ambiente/baseUrl no Provider (alinhado com a SDK `argws/asaas-sdk-php`).

Foram adicionados dois métodos à classe Asaas_gateway.php:
```php
public function getUrlBase()
{
    return $this->getProvider()->baseHost();
}

public function getApiKey()
{
    return $this->getProvider()->apiKey();
}
```

# No arquivo: `modules/asaas/controllers/Main.php`, criei duas variáveis e defini os valores conforme o ambiente:
```php
Criei duas variáveis:
```php
protected $apiKey;
protected $apiUrl;
public function __construct()
{
    parent::__construct();
    $this->load->library('asaas_gateway');
    $this->load->helper('general');
    $this->apiKey  = $this->asaas_gateway->getApiKey();
    $this->apiUrl  = $this->asaas_gateway->getUrlBase();
}

```

Observação: `getUrlBase()` retorna a base **sem** `/v3`, então as rotas devem incluir o prefixo manualmente (ex.: `/v3/pix/addressKeys`).

O método abaixo foi refatorado para usar as variáveis criadas:
`modules/asaas/libraries/Asaas_gateway.php`

__Depois:__
```php
public function get_customer($cpfCnpj)
{
    $customer = $this->search_customer($this->getUrlBase(), $this->getApiKey(), $cpfCnpj);
    return $customer;
}
```
