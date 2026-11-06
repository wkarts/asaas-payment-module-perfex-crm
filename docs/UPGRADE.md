# Plano de migração — Asaas (SDK argws)

## Plano (8–12 bullets)
1. Manter `argws/asaas-sdk-php` como SDK da integração e preservar as demais dependências declaradas, incluindo `endroid/qr-code`.
2. Criar `AsaasSdkProvider` para centralizar configuração (sandbox/produção, apiKey, user-agent, timeouts, headers e healthcheck).
3. Criar `AsaasAdapter` como camada única de integração, encapsulando chamadas à SDK e chamadas HTTP diretas quando não houver métodos expostos.
4. Refatorar `Asaas_gateway` para usar o Adapter nas operações de cobrança, cliente, webhook e consulta.
5. Implementar idempotência de webhooks com tabela dedicada e validação por token interno.
6. Criar tabelas de mapeamento (clientes, pagamentos, eventos) e logs com migrações idempotentes.
7. Atualizar telas de pagamento (client area) para exibir ambiente ativo e instruções de pagamento.
8. Adicionar endpoint de healthcheck (`/asaas/health`) para diagnóstico rápido da API.
9. Garantir logs com correlation-id e sanitização de segredos.
10. Documentar instalação, configuração e checklist de testes manuais.

## Notas de compatibilidade
- Métodos ausentes na SDK argws são chamados via cliente HTTP do próprio SDK, sem inventar endpoints.
- Estornos ficam sinalizados como não suportados até que o SDK exponha métodos oficiais.

## Instalação e Composer
1. Dentro do módulo, execute:
   `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
2. Use o `composer.lock` versionado e preserve todas as dependências de `composer.json`. Não substitua a lista de dependências nem execute atualização global para instalar esta correção.

## Configuração (Sandbox/Produção)
- Configure as chaves de API para cada ambiente no gateway Asaas.
- O User-Agent é definido automaticamente pelo SDK.
- Os timeouts seguem os padrões do SDK, sem necessidade de ajuste manual.
- Informe ou gere o token de autenticação do webhook nas configurações do gateway e configure o mesmo `authToken` no Asaas.

## Multi-tenant (sem .env)
- Opcionalmente, salve overrides em `paymentmethod_asaas_{setting}_company_{companyId}`.
  Exemplo: `paymentmethod_asaas_api_key_company_2`.

## Webhook
- A URL completa aparece nas configurações do meio de pagamento Asaas. Endpoint: `/asaas/gateways/callback/index`; o alias `/asaas/gateways/callback` continua válido.
- O Asaas envia o token no header oficial `asaas-access-token`. Novos cadastros devem usar esse header, sem segredos na URL.
- As formas legadas são mantidas somente por compatibilidade quando o header oficial está ausente. Campo vazio mantém a instalação sem autenticação por token, com aviso explícito.
- Eventos já concluídos são ignorados por `event_id`; falhas de processamento podem ser tentadas novamente. O recibo também é conferido por fatura e identificador do pagamento.
- Consulte [WEBHOOK.md](WEBHOOK.md) para geração, configuração, respostas HTTP, compatibilidade, testes e rollback.

## CRON / Sincronização
- A opção de conciliação automática usa o CRON nativo do Perfex por meio do hook `after_cron_run`.
- Configure a janela de dias e o limite de faturas na subguia **Sincronização**. A opção permanece desabilitada por padrão.
- A subguia **Monitoramento** reúne histórico e comparação/conciliação individual ou em lote, além do resultado da última execução automática.
- Consulte [CONFIGURACOES-MONITORAMENTO.md](CONFIGURACOES-MONITORAMENTO.md) para filtros, limites, segurança, índices e atualização a partir da versão histórica `1.4.10`.

## Checklist de testes manuais
- Criar cobrança (boleto/PIX) a partir de fatura.
- Pagar cobrança e verificar baixa da fatura.
- Atualizar status manualmente e via webhook (idempotência).
- Testar healthcheck com chave inválida (deve retornar AUTH_ERROR).
- Verificar logs sem exposição da API Key.

## Diagnóstico do envio de faturas

- Abra **Configurações → Meios de pagamento → Asaas → Ver logs da integração** como administrador.
- O envio registra a fatura, as condições de bloqueio, o resultado e os erros HTTP/SDK. A opção de log em arquivo grava um arquivo próprio sem depender do nível global de log do Perfex.
- Consulte [DIAGNOSTICO.md](DIAGNOSTICO.md) para os caminhos, interpretação dos erros, testes e procedimento de atualização.

## Ciclo de faturas e quitação manual

Consulte [CICLO-FATURAS.md](CICLO-FATURAS.md) para exclusão, cancelamento, reativação, valor mínimo, bloqueio de salvamento após rejeição e escolha entre receber em dinheiro ou excluir a cobrança ao quitar manualmente. Essa atualização não exige uma nova versão da SDK.

## GitHub Actions (Release)
- Em **Settings → Actions → General → Workflow permissions**, selecione **Read and write permissions**.
