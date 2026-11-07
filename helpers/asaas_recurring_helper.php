<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_lifecycle_helper.php';
require_once __DIR__ . '/../libraries/Asaas/AsaasRecurring.php';

function asaas_recurring_service(): \Asaas\AsaasRecurring
{
    return new \Asaas\AsaasRecurring(asaas_lifecycle_gateway());
}

/** Read-only hook predicate. The native invoice writer owns its transaction. */
function asaas_recurring_authorizes_new_invoice(array $data): bool
{
    try { return asaas_recurring_service()->authorizesNewInvoice($data); }
    catch (Throwable $error) { return false; }
}

/** Protect ordinary checkout/lifecycle while a recurring operation is inconclusive. */
function asaas_recurring_invoice_blocks(int $invoiceId): bool
{
    $ci = function_exists('get_instance') ? get_instance() : null;
    if (!is_object($ci) || !isset($ci->db) || !is_object($ci->db)) { return false; }
    $store = new \Asaas\AsaasRecurringStore();
    if (!$store->ready()) { return false; }
    $job = $store->job($invoiceId);
    return in_array($job['state'] ?? '', ['processing', 'uncertain'], true);
}

/** All public/diagnostic messages are fixed local text; never an API exception or payload. */
function asaas_recurring_message(string $reason): string
{
    $messages = [
        'inactive' => 'Cadastre o cartão e autorize o pagamento desta fatura e das próximas faturas Asaas deste cliente.',
        'active' => 'Cartão recorrente autorizado para as novas faturas Asaas deste cliente.',
        'other_mandate' => 'Este cliente possui uma autorização Pix Automático ou uma operação pendente. Cancele-a e aguarde a confirmação antes de autorizar o cartão recorrente.',
        'confirmed' => 'Pagamento confirmado e registrado no CRM.',
        'declined' => 'O pagamento não foi aprovado. A recorrência foi pausada. Cadastre o cartão novamente ou use o pagamento pontual.',
        'revoked' => 'A autorização foi revogada. Nenhuma nova cobrança automática será iniciada. Uma operação já enviada continua sujeita à confirmação.',
        'queued' => 'Esta fatura aguarda o processamento do cartão recorrente pelo cron do CRM.',
        'uncertain' => 'A operação aguarda conferência no Asaas. Não repita o pagamento. A conciliação consulta o resultado sem cobrar novamente.',
        'receipt_pending' => 'O Asaas confirmou o recebimento, mas o registro no CRM ainda precisa de conciliação. Não repita o pagamento.',
        'suspended' => 'O cartão recorrente está pausado. Cadastre o cartão novamente em uma fatura aberta ou use o pagamento pontual.',
        'storage_unavailable' => 'O armazenamento do cartão recorrente não está disponível. Atualize ou reative o módulo para aplicar a migração e confira o banco de dados.',
        'vault_unavailable' => 'Não foi possível acessar o cofre criptografado. A recorrência foi interrompida; consulte o administrador.',
        'busy' => 'Outra operação desta autorização está em andamento. Aguarde a conclusão antes de tentar novamente.',
        'unavailable' => 'O cartão recorrente não está disponível para esta fatura.',
        'invalid_consent' => 'Confirme a autorização e as condições atuais antes de cadastrar o cartão.',
        'contact_mismatch' => 'Entre com um contato autorizado do cliente desta fatura para gerenciar a recorrência.',
        'quote_changed' => 'O valor ou as condições do cartão mudaram. Atualize a página e confira o total antes de autorizar.',
        'policy_changed' => 'As condições de cobrança mudaram. A recorrência foi pausada e exige uma nova autorização.',
        'scope_changed' => 'A conta, a empresa ou o ambiente Asaas mudou. A operação foi interrompida para conferência.',
        'authorization_changed' => 'A autorização foi alterada ou revogada. Esta tentativa não será repetida automaticamente.',
        'invoice_changed' => 'A fatura foi alterada, paga ou cancelada. Confira seu estado antes de outra operação.',
        'invoice_ineligible' => 'A fatura não está incluída na autorização recorrente atual.',
        'customer_mismatch' => 'O cliente da cobrança Asaas não corresponde à autorização. Consulte o financeiro.',
        'charge_mismatch' => 'O vínculo da cobrança precisa de conferência no financeiro antes de cobrar.',
        'preparation_failed' => 'Não foi possível preparar a cobrança. A fatura continua aberta e a recorrência foi pausada.',
        'preparation_recovered' => 'A cobrança foi localizada sem confirmação de pagamento. Escolha novamente como pagar; não houve repetição automática.',
        'tokenization_failed' => 'Não foi possível confirmar o cadastro seguro do cartão. Revise os dados ou consulte o financeiro.',
        'awaiting_previous' => 'Outra fatura desta autorização aguarda confirmação. A próxima cobrança será avaliada após a conferência.',
        'transaction_active' => 'Conclua a alteração da fatura antes de iniciar o pagamento por cartão.',
        'https_required' => 'Acesse esta página por HTTPS para cadastrar o cartão.',
        'invoice_scope_unavailable' => 'Não foi possível determinar a autorização recorrente no contexto desta fatura.',
        'enqueue_failed' => 'Não foi possível enfileirar a fatura para o cartão recorrente. O cron tentará recuperar as novas faturas elegíveis.',
        'cron_failed' => 'O processamento do cartão recorrente não pôde ser concluído. Confira o banco de dados e a configuração do cron.',
        'webhook_review_pending' => 'O webhook foi tratado, mas a conferência da tentativa recorrente continua pendente.',
    ];
    return $messages[$reason] ?? $messages['unavailable'];
}

function asaas_recurring_diagnostic(string $reason, int $invoiceId = 0): void
{
    try {
        $known = preg_match('/^[a-z_]{1,48}$/D', $reason) ? $reason : 'unavailable';
        asaas_diagnostic_log(in_array($known, ['confirmed', 'queued', 'revoked', 'active'], true) ? 'info' : 'warning',
            asaas_recurring_message($known), ['source' => 'recurring', 'operation' => 'recurring_card', 'invoice_id' => $invoiceId, 'reason_code' => $known]);
    } catch (Throwable $ignored) {
        // Optional diagnostics must not change the financial result.
    }
}
