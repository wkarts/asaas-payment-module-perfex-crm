# Relatórios financeiros Asaas

A área **Asaas → Relatórios** consulta a conta Asaas do ambiente ativo. A mesma área está disponível na subguia **Relatórios** das configurações do meio de pagamento, com um link para abrir a tela completa.

As consultas são somente de leitura. Consultar, imprimir ou exportar não cria cobranças, não solicita antecipações, não faz transferências e não registra recebimentos no CRM. O webhook e a conciliação continuam responsáveis pela baixa das faturas.

## Acesso dos colaboradores

No perfil de acesso do colaborador, o grupo **Asaas — Relatórios** oferece:

- **Visualizar relatórios financeiros da conta Asaas**: permite abrir a tela, consultar e paginar os relatórios.
- **Exportar relatórios financeiros**: permite gerar CSV e usar a impressão pela interface. A permissão de visualizar também é necessária.

As verificações são aplicadas nas rotas do servidor. Conceder acesso aos relatórios não concede acesso às configurações, às chaves de API nem à conciliação. Administradores mantêm acesso completo.

## Catálogo implementado

Os filtros exibidos são os aceitos pelo recurso correspondente. Um filtro disponível em cobranças não é enviado a outra API que não o documenta.

| Relatório | Consulta da API | Filtros de negócio disponíveis |
| --- | --- | --- |
| Extrato da conta Asaas | `GET /v3/financialTransactions` | Início e fim da movimentação; ordenação |
| Cobranças e recebimentos | `GET /v3/payments` | Situação, forma de pagamento, cliente, referência externa, parcelamento, assinatura, antecipação; período por pagamento, vencimento, criação ou crédito previsto |
| Saldo atual | `GET /v3/finance/balance` | Saldo do momento; sem filtro histórico |
| Estatísticas de cobranças | `GET /v3/finance/payment/statistics` | Situação, forma de pagamento, cliente, referência externa, antecipação; período por vencimento, criação ou crédito previsto |
| Resumo de splits | `GET /v3/finance/split/statistics` | Valores atuais a receber e a enviar |
| Splits recebidos | `GET /v3/payments/splits/received` | Cobrança, situação; período por crédito ou confirmação da cobrança |
| Splits enviados | `GET /v3/payments/splits/paid` | Cobrança, situação; período por crédito ou confirmação da cobrança |
| Transferências | `GET /v3/transfers` | Destino; período por efetivação ou criação |
| Antecipações | `GET /v3/anticipations` | Cobrança, parcelamento e situação |
| Contas pagas e agendadas | `GET /v3/bill` | Situação |
| Parcelamentos | `GET /v3/installments` | Paginação; para as parcelas, filtrar o parcelamento em Cobranças e recebimentos |
| Assinaturas | `GET /v3/subscriptions` | Situação, forma de pagamento, cliente, referência externa e inclusão de excluídas |
| Transações Pix | `GET /v3/pix/transactions` | Situação, natureza e identificador EndToEnd |
| Autorizações de Pix Automático | `GET /v3/pix/automatic/authorizations` | Situação, cliente e assinatura, quando existente |
| Instruções de Pix Automático | `GET /v3/pix/automatic/paymentInstructions` | Situação, autorização, cliente e cobrança |
| Chargebacks | `GET /v3/chargebacks/` | Situação; período por abertura da disputa ou transação |
| Estornos de uma cobrança | `GET /v3/payments/{id}/refunds` | ID obrigatório da cobrança |
| Notas fiscais de serviço | `GET /v3/invoices` | Situação, cliente, cobrança, parcelamento, referência externa e período de emissão |
| Negativações e recuperação de crédito | `GET /v3/paymentDunnings` | Situação, modalidade, cobrança e período de solicitação |
| Recargas de celular | `GET /v3/mobilePhoneRecharges` | Paginação |

O extrato pertence à conta Asaas. Ele não é um acesso a extratos de outras instituições bancárias. Notas fiscais de serviço são as emitidas pelo Asaas, e não a lista de faturas comerciais do Perfex. A disponibilidade de produtos depende da conta e das permissões concedidas à API.

## Consultar e interpretar

1. Selecione o relatório.
2. Preencha os filtros desejados. Os campos identificados como “ID no Asaas” recebem o identificador do recurso, como `cus_...` ou `pay_...`.
3. Clique em **Consultar**. A tela informa ambiente, filtros efetivamente usados, data da consulta e intervalo dos registros exibidos.
4. Use **Anterior** e **Próxima** para navegar. **Ver detalhes** apresenta os campos adicionais sem aumentar a altura de todas as linhas da tabela.

Os registros são consultados diretamente na API. Não dependem da existência de webhooks locais. A lista de cobranças pode mostrar parcelas e operações que ainda não possuem vínculo confirmado com uma fatura do CRM; criar esse vínculo ou dar baixa pertence ao monitoramento e à conciliação.

Em cobranças, **Confirmado** e **Recebido** representam estados distintos: confirmação do pagamento e recebimento do crédito na conta. Em splits, instruções de Pix Automático e autorizações, o estado de um recurso não deve ser confundido com o recebimento da cobrança. Uma autorização criada não está necessariamente ativa; uma instrução concluída não substitui a verificação da cobrança.

O extrato pode trazer mais de um lançamento para uma operação, por exemplo recebimento e tarifa. O valor pode ser negativo, indicando saída, e o saldo informado é o saldo após aquele lançamento. Tipos novos de lançamento são preservados como retornados pela API.

## CSV e impressão

**Exportar CSV completo** consulta todas as páginas dos filtros escolhidos, começando no primeiro registro, independentemente da página aberta na tela. O arquivo usa UTF-8 com BOM, ponto e vírgula como delimitador e vírgula decimal. As colunas incluem ambiente, nome do relatório, início da consulta e filtros, além dos campos financeiros.

A exportação só começa a ser enviada após a validação de todas as páginas. Se houver erro de API, paginação inconsistente, registros repetidos ou mudança no total durante a leitura, o arquivo temporário é descartado e a interface apresenta o erro. A aplicação não entrega uma parte dos registros com indicação de exportação concluída.

Para manter a requisição limitada, uma exportação admite até 100 mil linhas e 64 MiB de arquivo, com orçamento de 45 segundos entre as consultas. Os limites do PHP, do servidor web e da conexão com a API também se aplicam. Quando o limite é atingido, use períodos menores ou filtros mais específicos. Nos recursos em que a API não oferece filtro de período, a tela não inventa esse filtro.

A API não fornece um snapshot transacional único para estas listas. Uma exportação consulta o estado disponível durante sua geração. Mudanças detectáveis no total ou duplicidade interrompem o arquivo; alterações de campos entre páginas, sem mudança do total, não são uma garantia de snapshot histórico.

**Imprimir página / PDF** usa a impressão do navegador, com opção de salvar em PDF quando oferecida por ele. Imprime a página atualmente exibida, identificando o intervalo e o total; não é uma exportação PDF integral da conta. Para todas as páginas, use o CSV completo.

## Proteções e compatibilidade

- As fachadas da SDK recebem filtros no argumento nomeado `query:`; os IDs de rota são enviados em `pathParams:`.
- Os recursos, métodos e campos são definidos em uma lista fixa do servidor. O cliente não pode indicar um endpoint arbitrário ou executar uma operação financeira de escrita.
- Número do cartão, CVV, tokens de cartão, chaves de API, QR Codes de autorização, chaves Pix e dados completos de contas bancárias não são projetados para estes relatórios.
- Texto exportado é protegido contra interpretação como fórmula por planilhas; valores financeiros negativos continuam numéricos.
- A preparação do CSV usa arquivo temporário privado, com permissões `0600`, removido ao concluir ou interromper a geração. O arquivo não é salvo no diretório público do módulo.
- As respostas usam `Cache-Control: no-store` e desabilitam o profiler. O diagnóstico registra somente o recurso consultado, contagem, ambiente e código de falha controlado; não registra o corpo bruto da resposta financeira.
- A mesma instância da SDK é usada em todas as páginas de uma exportação, preservando o ambiente e as credenciais dessa consulta.
- O filtro de datas das notas fiscais usa `effectiveDate[Ge]` e `effectiveDate[Le]`, respeitando a grafia documentada da API.
- O endpoint de estornos de uma cobrança não documenta filtros de paginação. A coleção só é tratada como completa quando o retorno confirma que não há páginas restantes.

## Validação automatizada

- `php tests/reports-smoke.php`: fachadas reais da SDK com Guzzle MockHandler; contratos GET, filtros, exportação multipágina, falhas, sigilo, paginação e proteção de CSV.
- `php tests/reports-controller-smoke.php`: controller e helper real de permissões; leitura, exportação, negação por perfil, métodos HTTP e download.
- `node tests/reports-browser-smoke.cjs`: view PHP real em Chromium; formulário de configurações, filtros, paginação, responsividade, XSS, exportação, impressão e perfil de leitura.

Os testes são isolados e não acessam uma conta real. A validação em homologação deve usar as permissões e os produtos disponíveis na conta do cliente.

## Documentação oficial consultada

- [Extrato](https://docs.asaas.com/reference/recuperar-extrato)
- [Cobranças](https://docs.asaas.com/reference/listar-cobrancas)
- [Estatísticas de cobranças](https://docs.asaas.com/reference/estatisticas-de-cobrancas)
- [Splits recebidos](https://docs.asaas.com/reference/listar-splits-recebidos)
- [Transferências](https://docs.asaas.com/reference/listar-transferencias)
- [Antecipações](https://docs.asaas.com/reference/listar-antecipacoes)
- [Pagamentos de contas](https://docs.asaas.com/reference/listar-pagamento-de-contas)
- [Parcelamentos](https://docs.asaas.com/reference/listar-parcelamentos)
- [Chargebacks](https://docs.asaas.com/reference/listar-chargebacks)
- [Estornos de uma cobrança](https://docs.asaas.com/reference/listar-estornos-de-uma-cobranca)
- [Notas fiscais](https://docs.asaas.com/reference/listar-notas-fiscais)
- [Negativações](https://docs.asaas.com/reference/listar-negativacoes)
- [Recargas](https://docs.asaas.com/reference/listar-recargas-de-celular)
- [Autorizações do Pix Automático](https://docs.asaas.com/reference/listar-autorizacoes-pix-automatico)
- [Instruções do Pix Automático](https://docs.asaas.com/reference/listar-instrucoes-de-pagamento-pix-automatico)
