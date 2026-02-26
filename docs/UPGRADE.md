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
- Defina o token interno de webhook para validação.

## Multi-tenant (sem .env)
- Opcionalmente, salve overrides em `paymentmethod_asaas_{setting}_company_{companyId}`.
  Exemplo: `paymentmethod_asaas_api_key_company_2`.

## Webhook
- Endpoint padrão: `/asaas/gateways/callback`.
- Envie o token via header `X-Asaas-Webhook-Token` ou query `?token=`.
- Eventos duplicados são ignorados por `event_id`.

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
