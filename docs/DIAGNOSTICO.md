# Diagnóstico de envio de faturas ao Asaas

## Onde consultar

Abra **Configurações → Meios de pagamento → Asaas → Logs** com um usuário administrador. O visualizador aparece na própria subguia, abaixo das opções de diagnóstico, com os últimos 100 registros, o estado do armazenamento e a localização do arquivo de diagnóstico. Use **Atualizar registros de log** dentro do painel para consultar novamente, sem sair das configurações.

O painel é carregado somente ao abrir Logs, por um `GET` administrativo a `asaas/asaas/logs_data`, com resposta sem cache. Ele usa o relatório sanitizado já existente; não aceita um caminho de arquivo informado pelo navegador. Quando o banco está indisponível, preserva a leitura dos últimos 256 KiB do arquivo Asaas do dia, limitada a 100 registros. Os valores e o botão Salvar do formulário nativo permanecem independentes da consulta.

A rota anterior `asaas/asaas/logs` continua disponível por compatibilidade, inclusive pelo link de fallback quando o JavaScript ou a organização por subguias não estão disponíveis. Os endereços são gerados pelo `admin_url()` do Perfex.

Essa consulta não envia faturas, não faz tentativas automáticas e não altera configurações. A tela pode abrir mesmo quando o carregamento da SDK falha. Não é necessário expor mensagens PHP no navegador.

Os destinos dos registros são:

- **Banco:** tabela `<prefixo>asaas_logs`, com suporte às colunas de contexto atuais e legadas.
- **Arquivo próprio do módulo:** habilite **Habilitar log em arquivo** e salve. O padrão é `application/logs/asaas-AAAA-MM-DD.php`. Se o Perfex configurar outro `log_path`, o módulo acompanha esse diretório. A tela informa o caminho efetivo e a permissão de escrita.
- **Log padrão do Perfex/CodeIgniter:** normalmente `application/logs/log-AAAA-MM-DD.php`, conforme `log_path`, `log_file_extension` e `log_threshold` da instalação.
- **Contingência do PHP:** se o armazenamento do diagnóstico falhar, erros são encaminhados ao `error_log` do servidor. A localização depende do PHP-FPM/Apache/hospedagem.

O arquivo próprio funciona independentemente do `log_threshold` global. Não altera essa configuração, não cria diretórios com permissão aberta e usa proteção contra acesso PHP direto. Chaves, tokens e dados pessoais são ocultados; não envie dumps completos de configuração para investigar uma falha.

## Como investigar uma fatura

1. Anote o ID da fatura no Perfex.
2. Ative o log em arquivo se precisar de uma segunda fonte de diagnóstico.
3. Confira no Asaas se a cobrança já existe antes de repetir uma operação com resposta inconclusiva.
4. Execute a operação necessária na fatura e abra a tela de logs.
5. Procure `invoice_id`, `payment_id`, método/caminho HTTP, status, mensagem e `correlation_id`.

Os hooks registram a entrada no envio, o sucesso confirmado e as condições que impedem uma chamada: boleto automático desabilitado, ausência de vencimento, forma de pagamento Asaas não permitida, cliente não encontrado ou falha na inicialização da integração. Falhas reais interrompem o salvamento e mostram o motivo. Nas operações protegidas, o banco local é revertido; se a API já tiver aceitado uma alteração, a compensação depende da confirmação desse rollback. Consulte [CICLO-FATURAS.md](CICLO-FATURAS.md) para garantias, limites e opções de recebimento manual.

| Registro | Interpretação |
| --- | --- |
| Cobrança criada/atualizada com `payment_id` | O Asaas devolveu um identificador de cobrança. |
| HTTP 400 | A API recusou os dados; consulte a descrição retornada. |
| HTTP 401/403 | Confira a API Key e o ambiente selecionado. O token do webhook é uma credencial diferente. |
| HTTP 404 | Confira o recurso/identificador. As chamadas do módulo devem preservar `/v3`. |
| HTTP 429 | Limite de requisições atingido. |
| HTTP 5xx | Falha informada pela API; confira o estado remoto antes de repetir uma criação. |
| Erro de transporte sem status | Verifique DNS, conexão, TLS e timeout do servidor. |
| Falha ao carregar SDK | Confira o `vendor` instalado; um autoloader da raiz sem a SDK deve permitir o fallback para o módulo. |
| Operação da fatura bloqueada | A operação não terminou como sucesso; confira a causa e os registros de rollback/compensação. |
| Conciliação manual necessária | A resposta ou compensação ficou inconclusiva; confira CRM e Asaas antes de repetir uma cobrança. |
| Bloqueio de recebimento não obtido | Outra operação está trabalhando na mesma fatura; aguarde sua conclusão antes de tentar novamente. |

O diagnóstico recupera o status HTTP mesmo quando uma versão da SDK o encapsula como erro genérico de transporte. Ele não adiciona repetição automática de cobranças. Falhas no próprio logger não devem substituir a resposta de um pagamento aceito.

## Correção de compatibilidade

Os métodos públicos do gateway mantêm as assinaturas. O adapter completa `/v3` nos caminhos relativos da API e preserva caminhos que já incluem a versão. Valor, vencimento, juros, multa, desconto, modalidade e hash de referência da cobrança continuam sob as regras existentes.

No cadastro de um cliente pelo fluxo de boleto, `externalReference` passa a receber o ID do cliente como string, em vez de serializar o objeto completo da fatura. A referência da cobrança continua sendo o hash da fatura.

O provider preserva uma SDK compatível já carregada ou disponibilizada pelo autoloader principal. Quando esse autoloader não possui as classes necessárias, tenta o `vendor` do módulo antes de reportar a falha. A atualização não modifica `composer.json`, `composer.lock` ou o código das dependências.

## Validação sem acessar uma conta Asaas

```bash
php tests/provider-autoload-smoke.php
php tests/payment-api-smoke.php
php tests/invoice-dispatch-smoke.php
php tests/diagnostics-smoke.php
node tests/logs-ui-smoke.js
php tests/webhook-token-smoke.php
php tests/webhook-api-smoke.php
```

O teste do fluxo de faturas usa os hooks, gateway, adapter e cliente da SDK reais com transporte Guzzle controlado; somente o núcleo do Perfex e o banco são simulados. Requer `mbstring`, como o fluxo existente de descrição das faturas. Não emite cobranças externas.

## Atualização e rollback

Aplique a atualização dos arquivos do módulo preservando as dependências instaladas e as configurações. Não há mudança de esquema, limpeza de históricos ou troca de API Key/token neste ajuste de apresentação. Reabra as configurações para carregar os assets atualizados e consultar o painel na subguia Logs.

O rollback consiste em restaurar os arquivos anteriores do módulo. Ele também restaura os defeitos de carregamento/caminho e as limitações anteriores de diagnóstico. Antes de repetir uma cobrança após qualquer incidente, confira sua existência no Asaas para evitar duplicidade.
