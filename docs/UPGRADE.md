# Plano de migração — Asaas (SDK argws)

## Atualização com Pix Automático, menu e relatórios

A atualização é aditiva e preserva os meios existentes. A dependência distribuída passa a `argws/asaas-sdk-php` **0.3.0**, que contém a PR #22 da SDK. A PR #23 acrescenta paginação opcional e documentação/contratos de consultas; esta entrega do módulo não exige uma versão ainda não publicada da SDK. Use o `composer.lock` e o `vendor` do pacote juntos.

1. Faça backup do módulo, banco e configuração de criptografia do Perfex. Instale primeiro em homologação.
2. Instale o ZIP da versão publicada ou execute `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` no módulo a partir deste lock. Não substitua somente arquivos da SDK.
3. Execute a atualização do módulo pelo Perfex. A migration `158_version_158.php` cria as tabelas de autorizações e trabalhos Pix, sem alterar ou apagar faturas e recebimentos existentes. O workflow atual avança a versão de 1.5.7 para 1.5.8 no merge. Um pacote candidato da PR ainda identificado como 1.5.7 requer reativação do módulo em homologação para executar o instalador aditivo.
4. Em perfis de colaboradores, conceda **Asaas — Monitoramento: visualizar/conciliar** e **Asaas — Relatórios: visualizar/exportar**, conforme o trabalho de cada pessoa. Essas permissões concedem acesso aos dados da integração/conta, e não somente às faturas atribuídas ao colaborador. Sem permissão, o menu e as rotas são bloqueados.
5. Confira **Asaas → Monitoramento** e **Asaas → Relatórios**. As mesmas ferramentas permanecem nas configurações do meio de pagamento. Relatórios usam somente consultas; abrir essas telas não registra recebimentos.
6. Para habilitar Pix Automático, siga [PIX-AUTOMATICO.md](PIX-AUTOMATICO.md). Mantenha o recurso desativado até validar elegibilidade da conta, banco pagador, webhook autenticado e cron. Autorização e pagamento são estados diferentes; não faça baixa somente porque uma instrução foi concluída.

As bibliotecas HTTP compatíveis também foram atualizadas no lock para corrigir avisos de segurança das versões anteriores. A atualização não altera os parâmetros de e-mail/telefone dos clientes nem introduz armazenamento de dados de cartão.

### Retorno à versão anterior

Preserve o backup das novas tabelas. Desativar o gateway impede novos agendamentos locais, mas não cancela automaticamente autorizações ou pagamentos já enviados ao banco. Antes de retirar o código novo, cancele as autorizações pela interface e confira pagamentos pendentes no Asaas/monitoramento; resolva estados incertos por consulta. Depois restaure o módulo e suas dependências do mesmo backup. Não exclua tabelas de eventos, recibos ou mandatos como parte do retorno.

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
