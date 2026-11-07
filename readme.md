## Webhook com token de autenticação

As configurações do meio de pagamento Asaas exibem a URL do webhook e permitem informar, gerar, exibir e copiar o token. O recebedor reconhece o header oficial `asaas-access-token`, preservando a configuração e os contratos legados. Consulte o [guia de configuração e atualização](docs/WEBHOOK.md).

## Diagnóstico de envio de faturas

Em **Configurações → Meios de pagamento → Asaas**, o botão **Ver logs da integração** permite ao administrador consultar os resultados e localizar os arquivos de log. O módulo preserva `/v3` nas chamadas de clientes/cobranças e usa as dependências locais como fallback quando a raiz do Perfex não fornece a SDK. Consulte o [guia de diagnóstico](docs/DIAGNOSTICO.md).

## Ciclo de faturas e recebimentos

Exclusão, cancelamento e reativação podem acompanhar a cobrança no Asaas. A quitação manual integral permite confirmar recebimento em dinheiro ou excluir a cobrança. Valores inválidos e rejeições da API interrompem a operação para evitar salvar uma fatura divergente. Consulte o [guia de comportamento, configuração, transações e atualização](docs/CICLO-FATURAS.md).

## Cartão de crédito e ambiente do checkout

O módulo distribui a SDK `argws/asaas-sdk-php` **0.3.0**, com checkout de cartão à vista ou em até 21 parcelas, conforme limite configurado, bandeira e saldo da fatura. O processamento confere a resposta Asaas, acompanha parcelas individualmente e mantém proteção contra envio repetido. A opção **Exibir o ambiente em produção na área de pagamentos** permite ocultar a faixa no checkout; o aviso de homologação permanece visível. Consulte [configuração, instalação e conciliação do cartão](docs/CARTAO-CREDITO.md).

## Cartão de crédito recorrente

O meio **Cartão de crédito recorrente – Asaas** é independente das opções do checkout pontual. O cliente entra na própria conta, autoriza esta fatura e as próximas faturas Asaas elegíveis, e pode revogar a autorização. A fila usa o cron do Perfex, conserva somente um token criptografado e mantém a cobrança em aberto quando há recusa. Um resultado incerto é consultado sem repetir a captura. O recurso inicia desativado e mantém seus contratos com a SDK **0.3.0** distribuída. Consulte o [guia de recorrência, consentimento, instalação e operação](docs/CARTAO-RECORRENTE.md).

## Pix Automático

O meio **Pix Automático – Asaas** aparece separadamente nas formas de pagamento da fatura. O cliente autenticado aceita o valor e a frequência, paga o primeiro QR Code e autoriza a recorrência no aplicativo do banco. Pagamento inicial e autorização são confirmações distintas. As próximas faturas elegíveis são agendadas pelo cron na janela de 2 a 10 dias úteis antes do vencimento, sem criar uma assinatura paralela no Asaas. A autorização exige token de webhook, HTTPS, conta elegível e SDK com suporte ao recurso. O recurso inicia desativado. Consulte [configuração, consentimento e recuperação](docs/PIX-AUTOMATICO.md).

## Menu Asaas, monitoramento e relatórios

O menu principal **Asaas** oferece páginas completas de **Monitoramento** e **Relatórios**, mantendo os componentes nas configurações do meio de pagamento. Os perfis de colaboradores possuem permissões separadas para visualizar, conciliar e exportar. Credenciais e configurações permanecem administrativas.

O monitoramento aceita o número completo da fatura, como `FAT-000148/06/2026`, e nome do cliente. Na consulta ao Asaas, a seleção local é convertida em vínculo exato de fatura ou cliente antes da chamada. A conciliação continua complementando os webhooks, com conferência do recebimento e da duplicidade.

Os relatórios reúnem **20 consultas financeiras e operacionais**, incluindo extrato, cobranças, saldo, estatísticas, antecipações, transferências, parcelamentos e Pix Automático. O CSV consulta todas as páginas dos filtros; erros de completude interrompem a exportação. A impressão/PDF do navegador identifica a página exibida. Consulte [recursos, filtros, permissões e limites](docs/relatorios-financeiros.md).

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
