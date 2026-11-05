# Consistência entre faturas do Perfex e cobranças Asaas

Esta atualização mantém o gateway, os payloads de clientes, os critérios de envio de telefone/email e o POST de atualização já utilizado pelo módulo. Acrescenta controle das operações locais que dependem da confirmação da API, sincronização do ciclo da fatura e opções de quitação manual.

## Comportamento

| Ação no CRM | Resultado no Asaas | Tratamento de falha |
| --- | --- | --- |
| Criar fatura elegível ao boleto automático | Cria a cobrança e grava seu vínculo | Rejeição da API interrompe a operação e reverte a gravação local transacional. |
| Editar fatura com Asaas habilitado | Usa a atualização existente; cria se não houver cobrança | Valida e aguarda a API antes de concluir a alteração local. A opção existente de atualizar cobranças continua sendo respeitada. |
| Excluir fatura vinculada | Exclui a cobrança antes da exclusão local | A recusa da API impede a exclusão local. |
| Marcar como cancelada | Exclui a cobrança vinculada | A recusa da API impede a confirmação do cancelamento local. |
| Reativar fatura cancelada | Cria uma nova cobrança; se já existir uma ativa, preserva-a | A falha mantém a fatura cancelada. |
| Registrar recebimento manual parcial | Mantém a cobrança aberta | Não confirma quitação nem exclui toda a cobrança por um pagamento parcial. |
| Completar a quitação manual | Confirma recebimento em dinheiro ou exclui a cobrança, conforme configuração | A recusa da API impede o registro do recebimento local. |
| Receber um webhook de pagamento | Registra somente o saldo pendente uma vez | Falha local retorna HTTP 500, permitindo nova tentativa do evento. |

As operações de exclusão/cancelamento/recebimento reconhecem tanto a forma de pagamento Asaas quanto um vínculo existente no mapa do módulo. Faturas de outros meios, sem esse vínculo, continuam no fluxo nativo. Rascunhos, faturas canceladas/quitadas, envio automático desabilitado e ausência de vencimento mantêm os critérios de elegibilidade existentes.

Excluir cobrança **não solicita estorno nem devolução financeira**. Reativar cria outro identificador de pagamento e preserva a referência da fatura. Eventos posteriores de cobranças explicitamente encerradas ou substituídas não devem gerar outra baixa local.

## Configurações

Em **Configurações → Meios de pagamento → Asaas**:

| Campo | Padrão | Efeito |
| --- | --- | --- |
| Sincronizar exclusão, cancelamento e reativação das faturas | Sim | Habilita essas três ações. |
| Sincronizar a quitação manual integral da fatura | Sim | Sincroniza o recebimento final registrado por qualquer meio local. |
| Confirmar a quitação manual como recebida em dinheiro no Asaas | Sim | Sim chama `receiveInCash`; Não exclui a cobrança ao quitar no CRM. |
| Deletar cobrança da fatura no Asaas | Valor existente, padrão Não | Opção legada de exclusão, usada quando a nova sincronização do ciclo estiver desabilitada. |

Não é necessário gerar outra API Key ou alterar o token do webhook. A configuração do token e a URL continuam disponíveis no mesmo gateway; veja [WEBHOOK.md](WEBHOOK.md).

O recebimento em dinheiro informa ao Asaas que o valor foi recebido fora da plataforma. Ele não credita o saldo da conta Asaas. O módulo envia a data do recebimento e a soma dos pagamentos efetivamente registrados, incluindo o recebimento final; créditos aplicados à fatura não são somados como dinheiro recebido. `notifyCustomer` permanece falso nessa confirmação.

Uma quitação com várias cobranças ativas para a mesma fatura é interrompida para conciliação, evitando confirmar o valor integral em mais de uma cobrança. Recebimentos parciais sucessivos da mesma fatura devem ser registrados individualmente; um lote que prepare a mesma fatura duas vezes antes de inserir os recibos é bloqueado. Lotes de faturas distintas permanecem permitidos.

## Validação de valores

O adapter valida o payload final antes do HTTP, sem alterar os valores enviados:

- Cobrança avulsa: mínimo líquido de **R$ 5,00**, considerando o desconto.
- Parcelamento de cartão: mínimo líquido de **R$ 5,00 por parcela**.
- Parcelamento de boleto/Pix com mais de uma parcela: mínimo líquido de **R$ 10,00 por parcela**.
- Desconto fixo e percentual são calculados com aritmética decimal exata, sem dependência de BCMath.
- Atualizações parciais que não enviam valor continuam sujeitas à validação da própria API.

Exemplo: uma edição de R$ 100,00 para R$ 1,00 é recusada antes de concluir a gravação local. Se uma regra adicional da conta fizer a API retornar HTTP 400, o erro também interrompe a operação; o código não trata a fatura divergente como salva com sucesso.

No checkout de boleto/Pix/cartão, os payloads existentes continuam determinando descontos, juros e parcelas. A validação considera o payload efetivo de cada caminho, e alterações locais de vencimento/valor são protegidas durante a chamada.

As correções pontuais do checkout preservam telefone/email: a referência de cliente passa a ser seu ID em texto; Pix usa corretamente o CEP do array e o cálculo de data do PHP 8; cartão vencido mantém o hash da fatura e o retorno JSON. As parcelas do cartão são calculadas sobre o saldo a pagar, com quantidade positiva e sem separador de milhar.

## Transações, concorrência e compensação

O módulo utiliza os hooks públicos do Perfex. O filtro anterior à criação abre a transação antes do INSERT; o filtro anterior à edição envia a alteração remota antes dos itens e totais. Nas ações administrativas, `admin_init` protege também campos personalizados, tags e os status cujo hook nativo ocorre depois da escrita.

As tabelas envolvidas precisam ser transacionais. Em MySQL/MariaDB, o módulo verifica InnoDB nas tabelas existentes antes de iniciar uma operação protegida; não converte engines nem altera o schema automaticamente. Uma instalação com tabela não transacional recebe erro explícito, em vez de uma falsa garantia de rollback.

O bloqueio da fatura usa `FOR UPDATE`. Recebimentos manuais, checkout, webhooks e alterações administrativas da fatura compartilham um bloqueio consultivo por fatura com espera de até cinco segundos, adquirido antes da transação. Ele permanece durante rollback e compensação para impedir que um callback do próprio recebimento seja tratado como um novo pagamento. A baixa originada no checkout também confere o recibo e o saldo depois de obter o bloqueio.

Se a API aceitar a operação e a gravação local falhar, o módulo tenta a compensação correspondente **somente quando o rollback local estiver confirmado**:

- Criação: confere o estado atual e exclui a nova cobrança ainda pendente/vencida.
- Atualização: reaplica os campos anteriores da cobrança.
- Exclusão: restaura a cobrança e reaplica seus campos anteriores.
- Recebimento em dinheiro: desfaz `receiveInCash`; eventos atrasados conferem o estado atual antes de registrar pagamento.

Cobranças já recebidas não são automaticamente estornadas. Falha de transporte, commit inconclusivo, rollback não confirmado ou recusa da compensação geram diagnóstico de conciliação. Não existe transação distribuída entre MySQL e Asaas; o módulo não repete POST de cobrança automaticamente.

### Núcleos que possuem writer atômico

O Perfex nativo e a variante com `Payments_model::insert_payment_atomically` privado e `app\services\InvoicePaymentWriter` seguem os respectivos contratos. Na variante detectada, o módulo libera sua transação antes de entregar o recebimento ao writer nativo, preservando o bloqueio consultivo. O mapa registra a operação pendente. Se a gravação falhar, a compensação exige confirmação de que o recibo não existe e de que o mapa/estado financeiro não mudou. Um recibo já persistido prevalece mesmo que o envio posterior de email falhe.

Quando a referência de um recebimento dessa variante estiver vazia, o módulo preenche uma referência interna única. Referências informadas pelo usuário são preservadas. O módulo não substitui o writer nem altera o núcleo do CRM.

### Limites dos hooks nativos

- Transações externas pertencem ao chamador. O módulo usa savepoint e não confirma a transação de outro componente; não consegue observar um rollback externo ocorrido depois de seu hook final.
- Código externo que cancela/reativa diretamente pelo model só oferece o hook posterior. O módulo restaura o estado financeiro em caso de erro, mas não pode recuperar com certeza a numeração anterior de um rascunho nesse caminho. A ação administrativa é protegida antes da escrita.
- Na edição direta pelo model fora do controller administrativo, o núcleo grava campos personalizados e tags antes do filtro de atualização. O filtro impede a alteração de totais/itens rejeitada, mas esses metadados anteriores ao hook exigem uma transação do chamador para rollback completo. A tela administrativa é protegida desde `admin_init`.
- O rollback SQL não restaura arquivos físicos que o núcleo já tenha removido. A exclusão remota ocorre antes da exclusão local para que uma recusa da API não chegue à remoção dos anexos.
- Emails/SMS de terceiros não participam da transação SQL. A correção protege os registros; não promete desfazer notificações já enviadas.

## Logs e tratamento de divergências antigas

Abra **Asaas → Ver logs da integração** nas configurações do meio de pagamento. Consulte `invoice_id`, `payment_id`, operação, status HTTP e mensagem. O arquivo próprio, quando habilitado, costuma ficar em `application/logs/asaas-AAAA-MM-DD.php`; o caminho efetivo aparece na tela. Veja [DIAGNOSTICO.md](DIAGNOSTICO.md).

Erros novos interrompem o salvamento; AJAX recebe HTTP 422 com mensagem e ID da fatura, e formulários comuns mostram o motivo. O comportamento evita o aviso enganoso de sucesso após rejeição remota.

Faturas divergentes criadas antes desta atualização não são reenviadas nem excluídas em lote. Compare o ID/valor/vencimento no CRM e no Asaas antes de corrigir cada uma. Isso evita duplicar uma cobrança cuja resposta anterior tenha sido perdida.

## Atualização e rollback

1. Faça backup do banco e de `modules/asaas`, incluindo as dependências instaladas.
2. Aplique os arquivos completos da atualização ou o ZIP da release aprovada. Não instale somente os arquivos PHP principais: os novos helpers e a classe de transação são necessários.
3. Em instalação via Composer, execute `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` usando o `composer.lock` do módulo. Preserve **todas** as dependências declaradas, incluindo `endroid/qr-code`.
4. Abra as configurações Asaas e escolha o comportamento da quitação manual. Confirme os logs e o webhook com token já configurado.
5. Valide uma fatura de homologação: criação, edição válida, edição de R$ 1,00 recusada, cancelamento, reativação, recebimento parcial/final e exclusão.

Esta mudança não exige atualização da SDK, alteração do schema ou migração de dados. Usa o cliente HTTP já disponível e mantém `composer.json`/`composer.lock` intactos.

Para rollback, restaure o conjunto anterior completo do módulo e suas configurações. O retorno de código não recria cobranças excluídas nem desfaz recebimentos já confirmados; concilie operações em andamento antes de reabrir o fluxo. Os metadados novos ficam no JSON já existente do mapa e não exigem remoção de tabelas.

O checkout deixa de gravar número completo, validade e código de segurança do cartão em uma tabela fixa sem contrato com o módulo. Os dados continuam no payload necessário ao Asaas; nenhuma limpeza automática de registros históricos é feita por esta atualização.

## Testes automatizados

```bash
php tests/payment-validation-smoke.php
php tests/payment-lifecycle-api-smoke.php
php tests/invoice-transaction-smoke.php
php tests/invoice-lifecycle-smoke.php
php tests/atomic-payment-smoke.php
php tests/invoice-receipt-lock-smoke.php
php tests/checkout-receipt-smoke.php
php tests/webhook-token-smoke.php
php tests/invoice-dispatch-smoke.php
```

Os testes de transação e handoff usam SQLite real; os fluxos de hooks usam fixtures do contrato Perfex, e os contratos HTTP usam transporte controlado. Não enviam cobranças reais. A CI executa PHP 8.1/8.2 com `mbstring` e `pdo_sqlite`; o teste de lifecycle falha se `mbstring` estiver ausente na CI. Homologação com a conta Asaas e a instalação efetiva continua necessária para regras particulares do ambiente.

## Referências oficiais

- [Excluir cobrança](https://docs.asaas.com/reference/excluir-cobranca).
- [Confirmar recebimento em dinheiro](https://docs.asaas.com/reference/confirmar-recebimento-em-dinheiro).
- [Restaurar cobrança removida](https://docs.asaas.com/reference/restore-removed-payment).
- [Limites para criação de cobranças](https://central.ajuda.asaas.com/hc/pt-br/articles/31972480144795-Quais-s%C3%A3o-os-limites-para-cria%C3%A7%C3%A3o-de-cobran%C3%A7as).
