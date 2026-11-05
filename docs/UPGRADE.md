# Plano de migração — Asaas (SDK argws)

## Plano (8–12 bullets)
1. Manter apenas `argws/asaas-sdk-php` como dependência oficial do módulo.
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
   `composer install`
2. Confirme que apenas `argws/asaas-sdk-php` está presente em `composer.json`.

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
- Eventos duplicados são ignorados por `event_id`.
- Consulte [WEBHOOK.md](WEBHOOK.md) para geração, configuração, respostas HTTP, compatibilidade, testes e rollback.

## CRON / Sincronização
- Parâmetros de CRON foram preparados nos settings (janela de dias e limite).
- Caso queira sincronização automática, configure uma rotina externa que chame o módulo.
  (Implementação de cron dedicada pode ser adicionada conforme necessidade.)

## Checklist de testes manuais
- Criar cobrança (boleto/PIX) a partir de fatura.
- Pagar cobrança e verificar baixa da fatura.
- Atualizar status manualmente e via webhook (idempotência).
- Testar healthcheck com chave inválida (deve retornar AUTH_ERROR).
- Verificar logs sem exposição da API Key.

## GitHub Actions (Release)
- Em **Settings → Actions → General → Workflow permissions**, selecione **Read and write permissions**.
