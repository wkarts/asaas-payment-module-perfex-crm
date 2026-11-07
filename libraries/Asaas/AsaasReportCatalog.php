<?php

declare(strict_types=1);

namespace Asaas;

defined('BASEPATH') or exit('No direct script access allowed');

/** Explicit read-only resources. Request input can never select a URL or an SDK method. */
final class AsaasReportCatalog
{
    public static function definitions(): array
    {
        $id = static fn (string $key, string $label, bool $required = false): array =>
            ['key' => $key, 'label' => $label, 'type' => 'identifier', 'required' => $required];
        $text = static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'type' => 'text'];
        $select = static fn (string $key, string $label, array $values): array =>
            ['key' => $key, 'label' => $label, 'type' => 'select', 'options' => $values];
        $c = static fn (string $key, string $label, string $type = 'text', bool $visible = true): array =>
            ['key' => $key, 'label' => $label, 'type' => $type, 'visible' => $visible];
        $billing = ['BOLETO' => 'Boleto', 'PIX' => 'Pix', 'CREDIT_CARD' => 'Cartão de crédito', 'UNDEFINED' => 'Forma a definir'];
        $paidStatus = ['PENDING' => 'Pendente', 'CONFIRMED' => 'Confirmado', 'RECEIVED' => 'Recebido', 'OVERDUE' => 'Vencido'];
        $paymentStatus = $paidStatus + [
            'RECEIVED_IN_CASH' => 'Recebido em dinheiro', 'REFUNDED' => 'Estornado', 'REFUND_REQUESTED' => 'Estorno solicitado',
            'REFUND_IN_PROGRESS' => 'Estorno em andamento', 'CHARGEBACK_REQUESTED' => 'Chargeback solicitado',
            'CHARGEBACK_DISPUTE' => 'Chargeback em disputa', 'AWAITING_CHARGEBACK_REVERSAL' => 'Aguardando reversão de chargeback',
            'DUNNING_REQUESTED' => 'Negativação solicitada', 'DUNNING_RECEIVED' => 'Recebido após negativação',
            'AWAITING_RISK_ANALYSIS' => 'Em análise de risco',
        ];
        $splitStatus = ['PENDING' => 'Pendente', 'PROCESSING' => 'Em processamento', 'PROCESSING_REFUND' => 'Estorno em processamento',
            'AWAITING_CREDIT' => 'Aguardando crédito', 'CANCELLED' => 'Cancelado', 'DONE' => 'Liquidado',
            'REFUNDED' => 'Estornado', 'BLOCKED_BY_VALUE_DIVERGENCE' => 'Bloqueado por divergência de valor'];
        $splitColumns = [$c('id', 'Split'), $c('status', 'Situação', 'status'), $c('totalValue', 'Valor total', 'money'),
            $c('fixedValue', 'Valor fixo', 'money'), $c('percentualValue', 'Percentual', 'number'),
            $c('walletId', 'Carteira de destino'), $c('description', 'Descrição'),
            $c('externalReference', 'Referência externa', 'text', false), $c('cancellationReason', 'Motivo do cancelamento', 'text', false)];
        $splitFilters = [$id('paymentId', 'ID da cobrança no Asaas'), $select('status', 'Situação', $splitStatus)];
        $definitions = [
            'statement' => [
                'title' => 'Extrato da conta Asaas', 'group' => 'Conta e resultados',
                'description' => 'Movimentações que alteram o saldo da conta Asaas. Recebimentos, tarifas, transferências e estornos aparecem em lançamentos próprios; os identificadores permitem conferir a operação de origem.',
                'service' => 'financialTransaction', 'method' => 'retrieveExtract', 'endpoint' => '/v3/financialTransactions',
                'docs' => 'recuperar-extrato', 'kind' => 'list',
                'dates' => ['date' => 'Movimentação'], 'range' => ['startDate', 'finishDate'],
                'filters' => [$select('order', 'Ordenação', ['asc' => 'Mais antigos primeiro', 'desc' => 'Mais recentes primeiro'])],
                'defaults' => ['order' => 'asc'],
                'columns' => [$c('date', 'Data', 'date'), $c('type', 'Natureza'), $c('description', 'Descrição'),
                    $c('value', 'Valor', 'money'), $c('balance', 'Saldo após lançamento', 'money'), $c('id', 'Lançamento'),
                    $c('paymentId', 'Cobrança', 'text', false), $c('transferId', 'Transferência', 'text', false),
                    $c('anticipationId', 'Antecipação', 'text', false), $c('billId', 'Conta paga', 'text', false), $c('splitId', 'Split', 'text', false)],
            ],
            'payments' => [
                'title' => 'Cobranças e recebimentos', 'group' => 'Cobranças',
                'description' => 'Consulte recebimentos, pendências, vencimentos e valores líquidos. Confirmado indica pagamento confirmado; Recebido indica crédito recebido. Em parcelamentos, cada linha pode ser uma parcela. Esta consulta não registra baixa no CRM.',
                'service' => 'payment', 'method' => 'listPayments', 'endpoint' => '/v3/payments', 'docs' => 'listar-cobrancas', 'kind' => 'list',
                'dates' => ['paymentDate' => 'Pagamento', 'dueDate' => 'Vencimento', 'dateCreated' => 'Criação', 'estimatedCreditDate' => 'Crédito previsto'],
                'filters' => [$select('status', 'Situação', $paymentStatus), $select('billingType', 'Forma de pagamento', $billing),
                    $id('customer', 'ID do cliente no Asaas'), $text('externalReference', 'Referência externa'),
                    $id('installment', 'ID do parcelamento'), $id('subscription', 'ID da assinatura'),
                    $select('anticipated', 'Antecipação', ['true' => 'Antecipadas', 'false' => 'Não antecipadas'])],
                'booleans' => ['anticipated'],
                'columns' => [$c('id', 'Cobrança'), $c('customer', 'Cliente no Asaas'), $c('status', 'Situação', 'status'),
                    $c('billingType', 'Forma de pagamento', 'status'), $c('value', 'Valor', 'money'), $c('netValue', 'Valor líquido', 'money'),
                    $c('dueDate', 'Vencimento', 'date'), $c('paymentDate', 'Pagamento', 'date'),
                    $c('dateCreated', 'Criação', 'date', false), $c('creditDate', 'Crédito', 'date', false),
                    $c('estimatedCreditDate', 'Crédito previsto', 'date', false), $c('installment', 'Parcelamento', 'text', false),
                    $c('installmentNumber', 'Número da parcela', 'integer', false), $c('subscription', 'Assinatura', 'text', false),
                    $c('externalReference', 'Referência externa', 'text', false), $c('description', 'Descrição', 'text', false)],
            ],
            'balance' => [
                'title' => 'Saldo atual', 'group' => 'Conta e resultados',
                'description' => 'Saldo da conta Asaas no momento da consulta. Para investigar uma data passada, use o extrato e o saldo informado em cada lançamento.',
                'service' => 'finance', 'method' => 'retrieveAccountBalance', 'endpoint' => '/v3/finance/balance',
                'docs' => 'recuperar-saldo-da-conta', 'kind' => 'summary', 'filters' => [],
                'columns' => [$c('balance', 'Saldo atual', 'money')],
            ],
            'statistics' => [
                'title' => 'Estatísticas de cobranças', 'group' => 'Conta e resultados',
                'description' => 'Quantidade, valor bruto e valor líquido consolidados pela API para os filtros escolhidos. O período se refere ao campo de data selecionado; o total de cobranças não é o saldo disponível.',
                'service' => 'finance', 'method' => 'billingStatistics', 'endpoint' => '/v3/finance/payment/statistics',
                'docs' => 'estatisticas-de-cobrancas', 'kind' => 'summary',
                'dates' => ['dueDate' => 'Vencimento', 'dateCreated' => 'Criação', 'estimatedCreditDate' => 'Crédito previsto'],
                'filters' => [$select('status', 'Situação', $paidStatus),
                    $select('billingType', 'Forma de pagamento', ['BOLETO' => 'Boleto', 'CREDIT_CARD' => 'Cartão de crédito', 'PIX' => 'Pix',
                        'DEBIT_CARD' => 'Cartão de débito', 'TRANSFER' => 'Transferência', 'DEPOSIT' => 'Depósito']),
                    $id('customer', 'ID do cliente no Asaas'), $text('externalReference', 'Referência externa'),
                    $select('anticipated', 'Antecipação', ['true' => 'Antecipadas', 'false' => 'Não antecipadas'])],
                'booleans' => ['anticipated'],
                'columns' => [$c('quantity', 'Quantidade de cobranças', 'integer'), $c('value', 'Valor bruto', 'money'), $c('netValue', 'Valor líquido', 'money')],
            ],
            'split_summary' => [
                'title' => 'Resumo de splits', 'group' => 'Splits',
                'description' => 'Valores de splits a receber e a enviar informados pela conta no momento da consulta. Para conferir as operações, utilize as listas de splits recebidos ou enviados.',
                'service' => 'finance', 'method' => 'retrieveSplitValues', 'endpoint' => '/v3/finance/split/statistics',
                'docs' => 'recuperar-valores-de-split', 'kind' => 'summary', 'filters' => [],
                'columns' => [$c('income', 'A receber', 'money'), $c('value', 'A enviar', 'money')],
            ],
            'splits_received' => [
                'title' => 'Splits recebidos', 'group' => 'Splits',
                'description' => 'Valores destinados à conta, a receber ou já recebidos. Confira a situação de cada split para distinguir pendência de liquidação.',
                'service' => 'paymentSplit', 'method' => 'listReceivedSplits', 'endpoint' => '/v3/payments/splits/received',
                'docs' => 'listar-splits-recebidos', 'kind' => 'list', 'filters' => $splitFilters,
                'dates' => ['creditDate' => 'Crédito do split', 'paymentConfirmedDate' => 'Confirmação da cobrança'], 'columns' => $splitColumns,
            ],
            'splits_paid' => [
                'title' => 'Splits enviados', 'group' => 'Splits',
                'description' => 'Valores destinados a outras contas Asaas. A lista inclui operações em processamento; apenas a situação liquidada confirma o repasse.',
                'service' => 'paymentSplit', 'method' => 'listPaidSplits', 'endpoint' => '/v3/payments/splits/paid',
                'docs' => 'listar-splits-pagos', 'kind' => 'list', 'filters' => $splitFilters,
                'dates' => ['creditDate' => 'Crédito do split', 'paymentConfirmedDate' => 'Confirmação da cobrança'], 'columns' => $splitColumns,
            ],
            'transfers' => [
                'title' => 'Transferências', 'group' => 'Saídas e antecipações',
                'description' => 'Histórico de transferências da conta Asaas, incluindo tarifas, valor líquido e situação. Dados completos de contas bancárias e chaves Pix não fazem parte deste relatório.',
                'service' => 'transfer', 'method' => 'listTransfers', 'endpoint' => '/v3/transfers', 'docs' => 'listar-transferencias', 'kind' => 'list',
                'dates' => ['transferDate' => 'Efetivação', 'dateCreated' => 'Criação'],
                'filters' => [$select('type', 'Destino', ['ASAAS_ACCOUNT' => 'Conta Asaas', 'BANK_ACCOUNT' => 'Conta bancária'])],
                'columns' => [$c('id', 'Transferência'), $c('status', 'Situação', 'status'), $c('operationType', 'Modalidade', 'status'),
                    $c('value', 'Valor', 'money'), $c('transferFee', 'Tarifa', 'money'), $c('netValue', 'Valor líquido', 'money'),
                    $c('effectiveDate', 'Efetivação', 'date'), $c('scheduleDate', 'Agendamento', 'date'),
                    $c('dateCreated', 'Criação', 'date', false), $c('description', 'Descrição', 'text', false),
                    $c('externalReference', 'Referência externa', 'text', false), $c('endToEndIdentifier', 'Identificador Pix', 'text', false),
                    $c('failReason', 'Motivo da falha', 'text', false)],
            ],
            'anticipations' => [
                'title' => 'Antecipações', 'group' => 'Saídas e antecipações',
                'description' => 'Consulte antecipações, tarifas, valores líquidos e situação. A API filtra por cobrança, parcelamento e situação; não oferece filtro de período nesta listagem.',
                'service' => 'anticipation', 'method' => 'listAnticipations', 'endpoint' => '/v3/anticipations', 'docs' => 'listar-antecipacoes', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['PENDING' => 'Pendente', 'DENIED' => 'Negada', 'CREDITED' => 'Creditada',
                    'DEBITED' => 'Debitada', 'CANCELLED' => 'Cancelada', 'OVERDUE' => 'Vencida', 'SCHEDULED' => 'Agendada']),
                    $id('payment', 'ID da cobrança'), $id('installment', 'ID do parcelamento')],
                'columns' => [$c('id', 'Antecipação'), $c('status', 'Situação', 'status'), $c('requestDate', 'Solicitação', 'date'),
                    $c('anticipationDate', 'Antecipação', 'date'), $c('value', 'Valor', 'money'), $c('fee', 'Tarifa', 'money'),
                    $c('netValue', 'Valor líquido', 'money'), $c('payment', 'Cobrança'), $c('installment', 'Parcelamento', 'text', false),
                    $c('dueDate', 'Vencimento', 'date', false), $c('totalValue', 'Total da cobrança', 'money', false),
                    $c('anticipationDays', 'Dias antecipados', 'integer', false), $c('denialObservation', 'Motivo da recusa', 'text', false)],
            ],
            'bills' => [
                'title' => 'Contas pagas e agendadas', 'group' => 'Saídas e antecipações',
                'description' => 'Pagamentos de contas efetuados pela conta Asaas. Inclui agendamentos, tarifas, descontos, juros e multas. A API permite filtrar por situação; não oferece filtro de período nesta listagem.',
                'service' => 'bill', 'method' => 'listBillPayments', 'endpoint' => '/v3/bill', 'docs' => 'listar-pagamento-de-contas', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['PENDING' => 'Pendente', 'BANK_PROCESSING' => 'Processamento bancário', 'PAID' => 'Paga',
                    'FAILED' => 'Falha', 'CANCELLED' => 'Cancelada', 'REFUNDED' => 'Estornada', 'AWAITING_CHECKOUT_RISK_ANALYSIS_REQUEST' => 'Em análise'])],
                'columns' => [$c('id', 'Conta'), $c('description', 'Descrição'), $c('status', 'Situação', 'status'), $c('value', 'Valor', 'money'),
                    $c('fee', 'Tarifa', 'money'), $c('dueDate', 'Vencimento', 'date'), $c('paymentDate', 'Pagamento', 'date'),
                    $c('scheduleDate', 'Agendamento', 'date', false), $c('discount', 'Desconto', 'money', false), $c('interest', 'Juros', 'money', false),
                    $c('fine', 'Multa', 'money', false), $c('companyName', 'Emissor', 'text', false), $c('externalReference', 'Referência externa', 'text', false)],
            ],
            'installments' => [
                'title' => 'Parcelamentos', 'group' => 'Cobranças',
                'description' => 'Parcelamentos cadastrados na conta, com quantidade de parcelas e valores. Para conferir cada parcela, use Cobranças e recebimentos e informe o ID do parcelamento. Esta listagem não oferece filtros adicionais na API.',
                'service' => 'installment', 'method' => 'listInstallments', 'endpoint' => '/v3/installments', 'docs' => 'listar-parcelamentos', 'kind' => 'list', 'filters' => [],
                'columns' => [$c('id', 'Parcelamento'), $c('customer', 'Cliente no Asaas'), $c('billingType', 'Forma de pagamento', 'status'),
                    $c('installmentCount', 'Parcelas', 'integer'), $c('value', 'Valor total', 'money'), $c('netValue', 'Valor líquido', 'money'),
                    $c('paymentValue', 'Valor da parcela', 'money'), $c('dateCreated', 'Criação', 'date'),
                    $c('paymentDate', 'Pagamento', 'date', false), $c('deleted', 'Excluído', 'boolean', false), $c('description', 'Descrição', 'text', false)],
            ],
            'subscriptions' => [
                'title' => 'Assinaturas', 'group' => 'Cobranças',
                'description' => 'Assinaturas criadas no Asaas e seus próximos vencimentos. Autorizações do cartão recorrente do CRM e do Pix Automático em modo manual são recursos distintos e não aparecem como assinaturas Asaas.',
                'service' => 'subscription', 'method' => 'listSubscriptions', 'endpoint' => '/v3/subscriptions', 'docs' => 'listar-assinaturas', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['ACTIVE' => 'Ativa', 'EXPIRED' => 'Expirada', 'INACTIVE' => 'Inativa']),
                    $select('billingType', 'Forma de pagamento', $billing), $id('customer', 'ID do cliente no Asaas'),
                    $text('externalReference', 'Referência externa'), $select('includeDeleted', 'Incluir excluídas', ['true' => 'Sim', 'false' => 'Não'])],
                'booleans' => ['includeDeleted'],
                'columns' => [$c('id', 'Assinatura'), $c('customer', 'Cliente no Asaas'), $c('status', 'Situação', 'status'),
                    $c('billingType', 'Forma de pagamento', 'status'), $c('cycle', 'Periodicidade', 'status'), $c('value', 'Valor por ciclo', 'money'),
                    $c('nextDueDate', 'Próximo vencimento', 'date'), $c('endDate', 'Fim da assinatura', 'date', false),
                    $c('dateCreated', 'Criação', 'date', false), $c('deleted', 'Excluída', 'boolean', false),
                    $c('externalReference', 'Referência externa', 'text', false), $c('description', 'Descrição', 'text', false)],
            ],
            'pix_transactions' => [
                'title' => 'Transações Pix', 'group' => 'Pix',
                'description' => 'Entradas, saídas e devoluções Pix da conta, conforme a natureza e a situação. Os dados bancários e as chaves Pix dos participantes são omitidos. A API não oferece filtro de período nesta listagem.',
                'service' => 'pixTransaction', 'method' => 'listTransactions', 'endpoint' => '/v3/pix/transactions', 'docs' => 'listar-transacoes', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['SCHEDULED' => 'Agendada', 'AWAITING_REQUEST' => 'Aguardando envio', 'REQUESTED' => 'Solicitada',
                    'DONE' => 'Concluída', 'REFUSED' => 'Recusada', 'CANCELLED' => 'Cancelada', 'AWAITING_BALANCE_VALIDATION' => 'Validando saldo',
                    'AWAITING_INSTANT_PAYMENT_ACCOUNT_BALANCE' => 'Aguardando saldo', 'AWAITING_CRITICAL_ACTION_AUTHORIZATION' => 'Aguardando autorização',
                    'AWAITING_CHECKOUT_RISK_ANALYSIS_REQUEST' => 'Em análise de risco (saída)', 'AWAITING_CASH_IN_RISK_ANALYSIS_REQUEST' => 'Em análise de risco (entrada)']),
                    $select('type', 'Natureza', ['CREDIT' => 'Crédito', 'DEBIT' => 'Débito', 'CREDIT_REFUND' => 'Devolução de crédito',
                        'DEBIT_REFUND' => 'Devolução de débito', 'DEBIT_REFUND_CANCELLATION' => 'Cancelamento de devolução']),
                    $id('endToEndIdentifier', 'Identificador Pix (EndToEnd)')],
                'columns' => [$c('id', 'Transação'), $c('type', 'Natureza', 'status'), $c('status', 'Situação', 'status'),
                    $c('value', 'Valor', 'money'), $c('refundedValue', 'Valor devolvido', 'money'), $c('effectiveDate', 'Efetivação', 'date'),
                    $c('scheduledDate', 'Agendamento', 'date'), $c('description', 'Descrição', 'text', false),
                    $c('endToEndIdentifier', 'Identificador Pix', 'text', false), $c('originType', 'Origem', 'text', false),
                    $c('refusalReason', 'Motivo da recusa', 'text', false)],
            ],
            'pix_authorizations' => [
                'title' => 'Autorizações de Pix Automático', 'group' => 'Pix',
                'description' => 'Autorizações recorrentes registradas no banco. Criada não significa ativa nem comprova pagamento. A ativação depende da autorização do pagador no aplicativo do banco.',
                'service' => 'automaticPix', 'method' => 'listAutomaticPixAuthorizations', 'endpoint' => '/v3/pix/automatic/authorizations',
                'docs' => 'listar-autorizacoes-pix-automatico', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['CREATED' => 'Criada', 'ACTIVE' => 'Ativa', 'CANCELLED' => 'Cancelada', 'REFUSED' => 'Recusada', 'EXPIRED' => 'Expirada']),
                    $id('customerId', 'ID do cliente no Asaas'), $id('subscriptionId', 'ID da assinatura (quando existente)')],
                'columns' => [$c('id', 'Autorização'), $c('customerId', 'Cliente no Asaas'), $c('status', 'Situação', 'status'),
                    $c('frequency', 'Periodicidade', 'status'), $c('value', 'Valor fixo, se houver', 'money'),
                    $c('startDate', 'Início', 'date'), $c('finishDate', 'Fim', 'date'), $c('contractId', 'Contrato'),
                    $c('minLimitValue', 'Piso do limite', 'money', false), $c('paymentCreationMode', 'Modo de criação', 'status', false),
                    $c('subscriptionId', 'Assinatura', 'text', false), $c('cancellationDate', 'Cancelamento', 'date', false),
                    $c('cancellationReason', 'Motivo do cancelamento', 'text', false)],
            ],
            'pix_instructions' => [
                'title' => 'Instruções de Pix Automático', 'group' => 'Pix',
                'description' => 'Agendamentos e instruções vinculados às cobranças. Uma instrução concluída não substitui a confirmação do recebimento; confira a cobrança correspondente em Cobranças e recebimentos.',
                'service' => 'automaticPix', 'method' => 'listAutomaticPixPaymentInstructions', 'endpoint' => '/v3/pix/automatic/paymentInstructions',
                'docs' => 'listar-instrucoes-de-pagamento-pix-automatico', 'kind' => 'list',
                'filters' => [$select('status', 'Situação', ['AWAITING_REQUEST' => 'Aguardando envio', 'SCHEDULED' => 'Agendada', 'DONE' => 'Concluída',
                    'CANCELLED' => 'Cancelada', 'REFUSED' => 'Recusada']), $id('authorizationId', 'ID da autorização'),
                    $id('customerId', 'ID do cliente no Asaas'), $id('paymentId', 'ID da cobrança')],
                'columns' => [$c('id', 'Instrução'), $c('authorization.id', 'Autorização'), $c('paymentId', 'Cobrança'),
                    $c('status', 'Situação', 'status'), $c('dueDate', 'Vencimento', 'date'), $c('endToEndIdentifier', 'Identificador Pix', 'text', false)],
            ],
            'chargebacks' => [
                'title' => 'Chargebacks', 'group' => 'Estornos e disputas',
                'description' => 'Contestações de pagamentos, valor, situação e prazo para documentos. A consulta não abre nem altera disputas. Dados do cartão não são incluídos.',
                'service' => 'chargeback', 'method' => 'listChargebacks', 'endpoint' => '/v3/chargebacks/', 'docs' => 'listar-chargebacks', 'kind' => 'list',
                'dates' => ['originDisputeDate' => 'Abertura da disputa', 'originTransactionDate' => 'Transação'],
                'filters' => [$select('status', 'Situação', ['REQUESTED' => 'Solicitado', 'IN_DISPUTE' => 'Em disputa', 'DISPUTE_LOST' => 'Disputa perdida',
                    'REVERSED' => 'Revertido', 'DONE' => 'Concluído'])],
                'columns' => [$c('id', 'Chargeback'), $c('payment', 'Cobrança'), $c('status', 'Situação', 'status'),
                    $c('value', 'Valor', 'money'), $c('reason', 'Motivo'), $c('disputeStartDate', 'Abertura', 'date'),
                    $c('deadlineToSendDisputeDocuments', 'Prazo para documentos', 'date'), $c('installment', 'Parcelamento', 'text', false),
                    $c('paymentDate', 'Pagamento', 'date', false), $c('disputeStatus', 'Situação da disputa', 'status', false)],
            ],
            'refunds' => [
                'title' => 'Estornos de uma cobrança', 'group' => 'Estornos e disputas',
                'description' => 'Histórico de estornos totais ou parciais da cobrança informada. A devolução só está concluída na situação Concluído. Para consultar movimentações de estorno de toda a conta, use o extrato.',
                'service' => 'paymentRefund', 'method' => 'retrieveRefundsOfASinglePayment', 'endpoint' => '/v3/payments/{id}/refunds',
                'docs' => 'listar-estornos-de-uma-cobranca', 'kind' => 'collection',
                'filters' => [$id('payment_id', 'ID da cobrança no Asaas', true)], 'path_filter' => 'payment_id',
                'columns' => [$c('dateCreated', 'Solicitação', 'date'), $c('effectiveDate', 'Efetivação', 'date'),
                    $c('status', 'Situação', 'status'), $c('value', 'Valor', 'money'), $c('description', 'Descrição'),
                    $c('endToEndIdentifier', 'Identificador Pix', 'text', false)],
            ],
            'service_invoices' => [
                'title' => 'Notas fiscais de serviço', 'group' => 'Outros registros financeiros',
                'description' => 'Notas fiscais emitidas pelo Asaas, com valores, situação e vínculo à cobrança. São notas de serviço do Asaas, distintas das faturas comerciais do CRM.',
                'service' => 'invoice', 'method' => 'listInvoices', 'endpoint' => '/v3/invoices', 'docs' => 'listar-notas-fiscais', 'kind' => 'list',
                'dates' => ['effectiveDate' => 'Emissão'], 'range' => ['effectiveDate[Ge]', 'effectiveDate[Le]'],
                'filters' => [$select('status', 'Situação', ['SCHEDULED' => 'Agendada', 'AUTHORIZED' => 'Autorizada',
                    'PROCESSING_CANCELLATION' => 'Cancelamento em processamento', 'CANCELED' => 'Cancelada',
                    'CANCELLATION_DENIED' => 'Cancelamento negado', 'ERROR' => 'Erro']), $id('customer', 'ID do cliente no Asaas'),
                    $id('payment', 'ID da cobrança'), $id('installment', 'ID do parcelamento'), $text('externalReference', 'Referência externa')],
                'columns' => [$c('id', 'Nota fiscal'), $c('number', 'Número'), $c('status', 'Situação', 'status'),
                    $c('customer', 'Cliente no Asaas'), $c('effectiveDate', 'Emissão', 'date'), $c('value', 'Valor', 'money'),
                    $c('deductions', 'Deduções', 'money'), $c('payment', 'Cobrança'),
                    $c('installment', 'Parcelamento', 'text', false), $c('serviceDescription', 'Serviço', 'text', false),
                    $c('municipalServiceCode', 'Código municipal do serviço', 'text', false),
                    $c('externalReference', 'Referência externa', 'text', false), $c('statusDescription', 'Descrição da situação', 'text', false)],
            ],
            'dunnings' => [
                'title' => 'Negativações e recuperação de crédito', 'group' => 'Outros registros financeiros',
                'description' => 'Solicitações de negativação ou recuperação de crédito, valores, tarifas e situação. O relatório consulta o histórico; não solicita nem cancela negativações.',
                'service' => 'paymentDunning', 'method' => 'listPaymentDunnings', 'endpoint' => '/v3/paymentDunnings',
                'docs' => 'listar-negativacoes', 'kind' => 'list', 'dates' => ['requestDate' => 'Solicitação'], 'range' => ['requestStartDate', 'requestEndDate'],
                'filters' => [$select('status', 'Situação', ['PENDING' => 'Pendente', 'AWAITING_APPROVAL' => 'Aguardando aprovação',
                    'AWAITING_CANCELLATION' => 'Aguardando cancelamento', 'PROCESSED' => 'Processada', 'PAID' => 'Paga',
                    'PARTIALLY_PAID' => 'Paga parcialmente', 'DENIED' => 'Negada', 'CANCELLED' => 'Cancelada']),
                    $select('type', 'Modalidade', ['CREDIT_BUREAU' => 'Negativação', 'DEBT_RECOVERY_ASSISTANCE' => 'Recuperação de crédito']),
                    $id('payment', 'ID da cobrança')],
                'columns' => [$c('id', 'Solicitação'), $c('payment', 'Cobrança'), $c('status', 'Situação', 'status'),
                    $c('type', 'Modalidade', 'status'), $c('requestDate', 'Solicitação', 'date'), $c('value', 'Valor', 'money'),
                    $c('feeValue', 'Tarifa', 'money'), $c('netValue', 'Valor líquido', 'money'),
                    $c('receivedInCashFeeValue', 'Tarifa de recebimento em dinheiro', 'money', false),
                    $c('cancellationFeeValue', 'Tarifa de cancelamento', 'money', false), $c('description', 'Descrição', 'text', false),
                    $c('denialReason', 'Motivo da recusa', 'text', false)],
            ],
            'mobile_recharges' => [
                'title' => 'Recargas de celular', 'group' => 'Outros registros financeiros',
                'description' => 'Recargas de celular pagas pela conta Asaas, com valor, operadora e situação. O número do telefone não é incluído. Esta listagem não oferece filtros adicionais na API.',
                'service' => 'mobilePhoneRecharge', 'method' => 'listMobileRecharges', 'endpoint' => '/v3/mobilePhoneRecharges',
                'docs' => 'listar-recargas-de-celular', 'kind' => 'list', 'filters' => [],
                'columns' => [$c('id', 'Recarga'), $c('operatorName', 'Operadora'), $c('status', 'Situação', 'status'), $c('value', 'Valor', 'money')],
            ],
        ];

        foreach ($definitions as $key => &$definition) {
            $definition['id'] = $key;
            $definition += ['dates' => [], 'defaults' => [], 'booleans' => []];
        }
        unset($definition);
        return $definitions;
    }

    /** Metadata for the interface; transport selection remains server-owned. */
    public static function publicDefinitions(): array
    {
        $rows = [];
        foreach (self::definitions() as $definition) {
            $rows[] = array_intersect_key($definition, array_flip(['id', 'title', 'group', 'description', 'kind', 'filters', 'columns', 'dates', 'defaults']));
        }
        return $rows;
    }
}
