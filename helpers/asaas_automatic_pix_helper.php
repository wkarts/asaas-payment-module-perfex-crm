<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_lifecycle_helper.php';
require_once __DIR__ . '/../libraries/Asaas/AsaasAutomaticPix.php';

function asaas_automatic_pix_service(): \Asaas\AsaasAutomaticPix { return new \Asaas\AsaasAutomaticPix(asaas_lifecycle_gateway()); }

/** Same scope as the card mandate, so enrollment and workers share its existing lock. */
function asaas_automatic_pix_scope(object $invoice, object $gateway): array
{
    if ((int) ($invoice->clientid ?? 0) <= 0) { throw new RuntimeException('scope_changed'); }
    $provider = $gateway->getProvider(); $environment = $provider->env(); $apiKey = $provider->apiKey();
    if (!in_array($environment, ['sandbox', 'production'], true) || !is_string($apiKey) || $apiKey === '') { throw new RuntimeException('scope_changed'); }
    foreach (['issuer_id', 'emitter_id', 'issuer_company_id'] as $name) {
        if (isset($invoice->$name) && !in_array($invoice->$name, ['', 0, '0', null], true)) { throw new RuntimeException('scope_changed'); }
    }
    $company = 0;
    foreach (['company_id', 'companyid'] as $name) {
        if (!isset($invoice->$name) || in_array($invoice->$name, ['', 0, '0'], true)) { continue; }
        $id = (string) $invoice->$name;
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 2147483647 || ($company !== 0 && $company !== (int) $id)) { throw new RuntimeException('scope_changed'); }
        $company = (int) $id;
    }
    if ($company !== 0 && ($provider->env($company) !== $environment || !hash_equals($apiKey, (string) $provider->apiKey($company)))) { throw new RuntimeException('scope_changed'); }
    return ['key' => hash('sha256', json_encode([$environment, hash('sha256', $apiKey), $company], JSON_THROW_ON_ERROR)), 'environment' => $environment, 'company_id' => $company];
}

function asaas_automatic_pix_invoice_blocks(int $invoiceId): bool
{
    $store = new \Asaas\AsaasAutomaticPixStore();
    if (!$store->ready()) { return false; }
    return in_array($store->job($invoiceId)['state'] ?? '', ['preparing', 'creating', 'waiting', 'uncertain'], true);
}

function asaas_automatic_pix_client_blocks(object $invoice): bool
{
    $store = new \Asaas\AsaasAutomaticPixStore();
    if (!$store->ready()) { return false; }
    $scope = asaas_automatic_pix_scope($invoice, asaas_lifecycle_gateway());
    return $store->clientBlocks((int) $invoice->clientid, $scope['key']);
}

/** Only after the normal webhook token authentication; does no receipt write. */
function asaas_automatic_pix_resolve_webhook_invoice(array $payment): ?object
{
    $store = new \Asaas\AsaasAutomaticPixStore();
    if (!$store->ready() || ($payment['billingType'] ?? '') !== 'PIX') { return null; }
    return asaas_automatic_pix_service()->resolvePayment($payment);
}

/** An initial QR has no externalReference. Only the independently verified immutable link can match it. */
function asaas_automatic_pix_matches_payment(object $invoice, array $payment): bool
{
    $store = new \Asaas\AsaasAutomaticPixStore();
    if (!$store->ready()) { return false; }
    return asaas_automatic_pix_service()->matchesPayment($invoice, $payment);
}

function asaas_automatic_pix_observe_webhook(array $payload): void
{
    try { asaas_automatic_pix_service()->observeWebhook($payload); }
    catch (Throwable) { asaas_automatic_pix_diagnostic('review_pending'); }
}

function asaas_automatic_pix_message(string $reason): string
{
    $messages = [
        'inactive' => 'Pague esta fatura por Pix e autorize no aplicativo do seu banco os próximos pagamentos automáticos.',
        'created' => 'Pague o QR Code e conclua a autorização no aplicativo do banco. O primeiro pagamento e a autorização são confirmações diferentes.',
        'active' => 'Pix Automático autorizado. As novas faturas elegíveis serão agendadas conforme a frequência autorizada.',
        'queued' => 'Esta fatura aguarda a janela de agendamento do Pix Automático. Você pode pagar antes pelo meio pontual disponível.',
        'waiting' => 'O pagamento está aguardando confirmação no Asaas. Não inicie outro pagamento enquanto a conferência estiver pendente.',
        'scheduled' => 'O banco recebeu o agendamento. A fatura só será baixada quando o Asaas confirmar o pagamento.',
        'confirmed' => 'Pagamento confirmado e registrado na fatura.',
        'cancelled' => 'A autorização foi cancelada. Novos débitos não serão agendados; pagamentos já enviados continuam sujeitos à confirmação.',
        'expired' => 'A autorização expirou. Confira o primeiro pagamento antes de gerar uma nova autorização.',
        'refused' => 'O banco não ativou a autorização. Se o primeiro Pix foi pago, esse recebimento continua válido.',
        'cancelling' => 'O cancelamento aguarda confirmação do Asaas. Nenhuma nova cobrança será agendada pelo módulo.',
        'uncertain' => 'A operação aguarda conferência no Asaas. Não repita o pagamento; a consulta recupera o resultado sem emitir outra cobrança.',
        'request_rejected' => 'O Asaas recusou esta solicitação. Confira a elegibilidade da conta, o prazo e a situação da fatura no monitoramento.',
        'unavailable' => 'O Pix Automático não está disponível para esta fatura.',
        'storage_unavailable' => 'Atualize ou reative o módulo para instalar o armazenamento do Pix Automático.',
        'sdk_unavailable' => 'Atualize a SDK e o vendor do módulo para uma versão com suporte ao Pix Automático.',
        'other_mandate' => 'Há uma autorização de cartão recorrente ativa ou uma cobrança pendente. Desative-a e aguarde a conclusão antes de autorizar o Pix Automático.',
        'existing_authorization' => 'Já existe uma autorização Pix ou uma operação pendente para este cliente. Consulte sua situação antes de cadastrar outra.',
        'invalid_consent' => 'Leia e aceite as condições atuais, incluindo o pagamento inicial e a frequência escolhida.',
        'contact_mismatch' => 'Entre com um contato deste cliente com permissão para faturas.',
        'https_required' => 'Use uma conexão HTTPS para gerenciar a autorização.',
        'busy' => 'Outra operação desta autorização está em andamento. Aguarde a conclusão.',
        'scope_changed' => 'A conta, a empresa ou o ambiente Asaas mudou. A operação precisa de conferência.',
        'invoice_changed' => 'A fatura foi alterada, paga ou cancelada. Atualize a página antes de continuar.',
        'quote_changed' => 'O valor ou as condições mudaram. Atualize a página e confira o total antes de autorizar.',
        'charge_mismatch' => 'O vínculo da cobrança precisa de conferência antes de autorizar um novo pagamento.',
        'receipt_conflict' => 'O Asaas confirmou um Pix, mas o saldo ou o vínculo da fatura divergiu. O financeiro precisa conferir o recebimento.',
        'receipt_pending' => 'O Asaas confirmou o Pix; o registro na fatura ainda precisa de conciliação. Não pague novamente.',
        'outside_window' => 'A fatura está fora do prazo de agendamento do Pix Automático. Utilize o pagamento pontual para esta fatura.',
        'cycle_exists' => 'Já existe uma cobrança para este ciclo autorizado. Esta fatura continua disponível para pagamento pontual.',
        'authorization_changed' => 'A autorização não está ativa ou foi alterada. Esta fatura não será cobrada automaticamente.',
        'instruction_refused' => 'O banco recusou este pagamento automático. Use o pagamento pontual após a conferência. A autorização pode continuar válida para os próximos ciclos.',
        'instruction_cancelled' => 'O agendamento foi cancelado. O resultado da cobrança será conferido antes de liberar outra forma de pagamento.',
        'preparation_failed' => 'Não foi possível concluir a preparação do Pix. Confira a fatura antes de escolher outra forma de pagamento.',
        'review_pending' => 'A situação do Pix Automático aguarda consulta ao Asaas.',
        'transaction_active' => 'Conclua a alteração da fatura antes de gerenciar o Pix Automático.',
        'invalid_frequency' => 'Escolha uma frequência válida para os pagamentos.',
        'invalid_due_date' => 'A data da fatura não permite agendar o Pix Automático.',
        'failed' => 'A solicitação não foi concluída. Consulte a fatura para escolher outra forma de pagamento.',
        'skipped' => 'Esta fatura não foi incluída no débito automático. Use o pagamento pontual.',
    ];
    return $messages[$reason] ?? $messages['unavailable'];
}

function asaas_automatic_pix_diagnostic(string $reason, int $invoiceId = 0): void
{
    try {
        $reason = preg_match('/^[a-z_]{1,48}$/D', $reason) ? $reason : 'unavailable';
        asaas_diagnostic_log(in_array($reason, ['active', 'queued', 'confirmed', 'cancelled'], true) ? 'info' : 'warning',
            asaas_automatic_pix_message($reason), ['source' => 'automatic_pix', 'operation' => 'automatic_pix', 'invoice_id' => $invoiceId, 'reason_code' => $reason]);
    } catch (Throwable) {}
}
