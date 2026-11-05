# Webhook Asaas com token de autenticação

O módulo recebe notificações de pagamento na rota já existente:

```text
POST /asaas/gateways/callback/index
```

O alias `/asaas/gateways/callback` continua disponível. A URL completa, incluindo domínio, subdiretório e eventual `index.php`, é calculada com `site_url()` e aparece nas configurações do meio de pagamento **Asaas**.

## Configurar no CRM e no Asaas

1. Abra **Configurações → Meios de pagamento → Asaas**.
2. Copie a **URL do webhook no CRM**. Ela é somente leitura e acompanha a URL atual da instalação.
3. No campo **Token de autenticação do webhook (authToken)**, informe o token já cadastrado no Asaas ou clique em **Gerar token**.
4. Use **Exibir token / Ocultar token** e **Copiar token** conforme necessário.
5. Salve as configurações do CRM e cadastre **exatamente o mesmo valor** no campo de token do webhook do Asaas. Faça as duas alterações em sequência para evitar entregas com credenciais diferentes.
6. No Asaas, utilize a URL exibida pelo módulo e selecione os eventos de pagamento necessários. Para as baixas já tratadas pelo módulo, os eventos padrão são `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED`.
7. Teste primeiro em Sandbox e confira a resposta HTTP nos logs de webhook do Asaas.

**Gerar, copiar ou editar não salva automaticamente e não altera o cadastro no Asaas.** O botão de geração usa `crypto.getRandomValues()` com 32 bytes aleatórios e produz 64 caracteres hexadecimais. Se o navegador não disponibilizar geração segura, o módulo informa o problema e permite informar um token manualmente.

Novos tokens devem ter **32 a 255 caracteres**, sem espaços ou caracteres de controle. A API Key não deve ser reutilizada: ela autentica as chamadas do CRM à API, enquanto o token do webhook autentica as notificações recebidas. O formulário e o servidor rejeitam tokens fora do formato ou iguais às chaves de API conhecidas.

Sandbox e produção têm cadastros independentes no Asaas. O módulo reaproveita o campo de configuração existente; ao alternar de ambiente, confira se o valor salvo corresponde ao webhook daquele ambiente.

## Autenticação e respostas

O Asaas envia o `authToken` cadastrado no cabeçalho:

```http
asaas-access-token: TOKEN_CONFIGURADO_NO_CRM_E_NO_ASAAS
```

O módulo compara esse valor com o segredo salvo usando `hash_equals()`, antes de consultar eventos, faturas ou registrar pagamentos. O nome do cabeçalho é tratado sem distinção entre maiúsculas e minúsculas. Um cabeçalho oficial inválido não pode ser compensado por um token legado correto.

| Condição | HTTP |
|---|---:|
| Evento aceito | 200 |
| Evento já registrado pelo mecanismo de duplicidade existente | 200 |
| Token configurado, mas ausente ou incorreto na requisição | 401 |
| JSON inválido após a autenticação | 400 |
| Método diferente de POST | 405, com `Allow: POST` |
| Falha interna durante o processamento | 500, com mensagem genérica |

A autenticação por token compartilhado não é uma assinatura HMAC. O tratamento existente de pagamentos e duplicidade por `event_id` foi preservado; esta atualização não introduz uma fila nem altera a regra de baixa financeira.

## Compatibilidade com instalações existentes

- A opção continua sendo `paymentmethod_asaas_webhook_secret`. O valor já salvo é reaproveitado, sem alteração de schema, criptografia retroativa ou migração destrutiva.
- Um token legado fora do novo formato pode permanecer **inalterado** enquanto outras configurações são salvas. Uma substituição deve atender às regras atuais.
- O campo vazio preserva o comportamento anterior, **sem autenticação por token**, com aviso explícito na tela. Configure um token nos dois sistemas para habilitar a validação.
- Quando o cabeçalho oficial está ausente, os cabeçalhos legados `X-Asaas-Webhook-Token`, `X-Webhook-Token` e a query legada continuam aceitos para não interromper integrações já instaladas. Para novos cadastros, utilize somente o cabeçalho oficial e não coloque segredos na URL.
- Se uma entrada inválida alcançar o servidor, apenas a alteração do token é descartada. O segredo anterior é preservado, as demais configurações seguem seu fluxo nativo e um alerta informa a rejeição.
- O módulo continua compatível com o cliente HTTP da SDK já instalada. A atualização não muda `composer.json`, `composer.lock` nem o código vendorizado.

## Cadastro pela API

Os métodos públicos `get_webhook($api_key, $api_url)` e `create_webhook($api_key, $api_url, $post_data)` mantêm suas assinaturas. Para o webhook de pagamentos, passam a usar os endpoints atuais `GET /v3/webhooks` e `POST /v3/webhooks`.

A consulta seleciona somente a URL desta instalação, reconhece o alias sem `/index` e percorre a paginação. Não seleciona o primeiro webhook de outra aplicação nem modifica outros webhooks da conta.

A criação usa o token **já salvo no CRM** em `authToken` e preenche os campos padrão ausentes: nome, API v3, ativo, fila não interrompida, envio `SEQUENTIALLY` e eventos `PAYMENT_CONFIRMED` / `PAYMENT_RECEIVED`. Opções de eventos e envio fornecidas explicitamente são preservadas. O token salvo prevalece sobre um eventual `authToken` diferente no payload, garantindo que o cadastro corresponda ao recebedor.

Sem um token válido salvo, a criação é recusada antes de chamar a API. Isso evita criar um webhook com token gerado automaticamente pelo Asaas que ainda não esteja configurado no recebedor. O Asaas devolve um token gerado automaticamente somente na resposta de criação quando `authToken` não é informado; ele também pode ser copiado manualmente para o campo do CRM quando o cadastro for realizado por outra ferramenta.

Para alterar apenas o token de um webhook já cadastrado, a API do Asaas oferece `PUT /v3/webhooks/{id}` com `authToken`. Campos omitidos mantêm seus valores. Salvar o formulário do CRM não executa essa operação automaticamente.

Os helpers antigos de notas fiscais e transferências de outros módulos não foram migrados por esta alteração. O cadastro manual descrito acima é o caminho de configuração do webhook de pagamentos nesta entrega.

## Segurança dos registros

O token é mascarado por padrão na interface e pode ser exibido pelo administrador. Isso é uma proteção visual: a opção de armazenamento existente foi mantida para preservar instalações anteriores. Os novos campos de token são mascarados na sanitização de contexto dos logs. As respostas dos helpers de cadastro/consulta não propagam `authToken` para as saídas de diagnóstico antigas. Erros internos do callback não incluem o corpo, os cabeçalhos nem mensagens de exceção na resposta.

## Validação

Os testes abaixo usam o adapter e o controller reais com interfaces controladas do Perfex e o cliente real da SDK com transporte Guzzle simulado. Não enviam pagamentos nem acessam contas Asaas:

```bash
php tests/webhook-token-smoke.php
php tests/webhook-api-smoke.php
node --check assets/js/asaas-webhook-settings.js
```

O CI também mantém o PHPUnit existente e o lint PHP. A homologação final exige uma instalação Perfex e uma conta Sandbox: salve o mesmo token nos dois sistemas, gere um evento, confirme HTTP 200, reenvie o evento e confira que não ocorreu nova baixa. Repita a chamada com token incorreto e confirme HTTP 401 sem alterações financeiras.

## Atualização e retorno à configuração anterior

Faça backup dos arquivos do módulo e das configurações antes de atualizar. Substitua os arquivos em `modules/asaas`, preservando suas dependências instaladas de acordo com o lock, e recarregue a tela de configurações. Não há migration de banco nesta alteração.

Se o problema for divergência de token, prefira restaurar o valor anterior nos dois sistemas mantendo o código que reconhece o cabeçalho oficial. Voltar aos arquivos antigos também exige restaurar uma configuração de webhook compatível com aquela versão: a versão anterior não reconhece `asaas-access-token`. Após qualquer reversão, confira os logs de entrega e reconcilie os eventos afetados.

## Referências oficiais

- [Receber eventos e validar o token](https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook)
- [Criar webhook](https://docs.asaas.com/reference/create-new-webhook)
- [Listar webhooks](https://docs.asaas.com/reference/list-webhooks)
- [Atualizar webhook](https://docs.asaas.com/reference/update-existing-webhook)
- [Eventos de cobrança](https://docs.asaas.com/docs/webhook-para-cobrancas)
