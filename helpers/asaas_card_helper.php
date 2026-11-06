<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_payment_validation_helper.php';

function asaas_card_money(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Use the common limit of all supported brands; never trust the posted select. */
function asaas_card_max_installments($configured): int
{
    $count = filter_var($configured, FILTER_VALIDATE_INT);
    return $count === false ? 1 : max(1, min(12, $count));
}

function asaas_card_installment_options($balance, $configured, array $discount = []): array
{
    // Credits/overpayments can leave a non-positive balance on an already settled invoice.
    if (is_numeric($balance) && is_finite((float) $balance) && (float) $balance <= 0) {
        return [];
    }
    $cents = asaas_payment_amount_to_cents($balance);
    $options = [];
    for ($count = 1; $count <= asaas_card_max_installments($configured); ++$count) {
        $amount = asaas_card_money($cents);
        $payload = ['billingType' => 'CREDIT_CARD', 'discount' => $discount];
        $payload += $count === 1 ? ['value' => $amount] : ['installmentCount' => $count, 'totalValue' => $amount];
        if (asaas_payment_validation_error($payload) !== null) {
            break;
        }
        $parcel = number_format(intdiv($cents, $count) / 100, 2, ',', '.');
        $options[] = ['count' => $count, 'label' => $count === 1 ? 'À vista — R$ ' . $parcel : $count . 'x de aproximadamente R$ ' . $parcel];
    }
    return $options;
}

/** Cardholder fields are payment data, never updates to the customer's contact preferences. */
function asaas_card_validate_input(array $input, int $maximum): array
{
    foreach (['holderName', 'number', 'expiryMonth', 'expiryYear', 'cvv', 'installmentCount',
        'holderEmail', 'holderDocument', 'holderPostalCode', 'holderAddressNumber', 'holderPhone'] as $field) {
        if (!isset($input[$field]) || !is_scalar($input[$field]) || strlen((string) $input[$field]) > 254) {
            throw new InvalidArgumentException('Preencha os dados do cartão e do titular.');
        }
        $input[$field] = trim((string) $input[$field]);
    }
    $count = filter_var($input['installmentCount'], FILTER_VALIDATE_INT);
    if ($count === false || $count < 1 || $count > $maximum) {
        throw new InvalidArgumentException('A quantidade de parcelas não está disponível para esta fatura.');
    }
    $number = preg_replace('/[ -]/', '', $input['number']);
    if (!preg_match('/^\d{13,19}$/D', $number)) {
        throw new InvalidArgumentException('Informe um número de cartão válido.');
    }
    $month = filter_var(ltrim($input['expiryMonth'], '0') ?: '0', FILTER_VALIDATE_INT);
    $year = $input['expiryYear'];
    if ($month === false || $month < 1 || $month > 12 || !preg_match('/^\d{4}$/D', $year)
        || (int) $year > (int) date('Y') + 25 || sprintf('%04d%02d', (int) $year, $month) < date('Ym')) {
        throw new InvalidArgumentException('Confira o mês e o ano de validade do cartão.');
    }
    if (strlen($input['holderName']) < 2 || !preg_match('/^\d{3,4}$/D', $input['cvv'])) {
        throw new InvalidArgumentException('Confira o nome do titular e o código de segurança.');
    }
    $document = preg_replace('/[.\/\s-]/', '', $input['holderDocument']);
    $postalCode = preg_replace('/[.\s-]/', '', $input['holderPostalCode']);
    $phone = preg_replace('/[()\s+.-]/', '', $input['holderPhone']);
    if (!filter_var($input['holderEmail'], FILTER_VALIDATE_EMAIL)
        || !preg_match('/^(?:\d{11}|\d{14})$/D', $document)
        || !preg_match('/^\d{8}$/D', $postalCode)
        || !preg_match('/^\d{10,13}$/D', $phone) || $input['holderAddressNumber'] === '') {
        throw new InvalidArgumentException('Confira o e-mail, CPF/CNPJ, CEP, número do endereço e telefone do titular.');
    }
    return [
        'installmentCount' => $count,
        'creditCard' => ['holderName' => $input['holderName'], 'number' => $number,
            'expiryMonth' => str_pad((string) $month, 2, '0', STR_PAD_LEFT), 'expiryYear' => $year, 'ccv' => $input['cvv']],
        'creditCardHolderInfo' => ['name' => $input['holderName'], 'email' => $input['holderEmail'], 'cpfCnpj' => $document,
            'postalCode' => $postalCode, 'addressNumber' => $input['holderAddressNumber'], 'phone' => $phone],
    ];
}

/** Discard all card credentials, including reusable tokens returned by Asaas. */
function asaas_card_safe_payload(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (preg_match('/^(?:creditCard|creditCardHolderInfo|creditCardToken|card|cardToken|cardNumber|ccv|cvv|securityCode)$/iD', (string) $key)) {
            unset($payload[$key]);
        } elseif (is_array($value)) {
            $payload[$key] = asaas_card_safe_payload($value);
        }
    }
    return $payload;
}

function asaas_card_is_received(array $payment): bool
{
    return empty($payment['deleted']) && in_array($payment['status'] ?? '', ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'], true);
}

/** Each confirmed card payment settles only its gross amount, including its own discount. */
function asaas_card_receipt_amount(array $payment, $balance): string
{
    $cents = asaas_payment_amount_to_cents($balance);
    if (($payment['billingType'] ?? '') === 'CREDIT_CARD') {
        $parcel = asaas_payment_amount_to_cents($payment['originalValue'] ?? $payment['value'] ?? null, 'Valor da parcela');
        if ($parcel <= 0) {
            throw new RuntimeException('A parcela confirmada não informou um valor válido.');
        }
        $cents = min($cents, $parcel);
    }
    return asaas_card_money($cents);
}

/** An uncertain capture must be reconciled before changing the debt behind it. */
function asaas_card_assert_invoice_edit(object $invoice, array $updates): void
{
    $cache = asaas_lifecycle_cached_payment(asaas_lifecycle_map((int) $invoice->id));
    if (!asaas_card_attempt_blocks($cache) && empty($cache['installment'])) {
        return;
    }
    foreach (['total', 'duedate', 'clientid', 'currency', 'allowed_payment_modes'] as $field) {
        if (array_key_exists($field, $updates) && serialize($updates[$field]) !== serialize($invoice->$field ?? null)) {
            // Perfex may send numerically equal amounts as strings or floats.
            if ($field === 'total' && asaas_payment_amount_to_cents($updates[$field]) === asaas_payment_amount_to_cents($invoice->total)) {
                continue;
            }
            if (in_array($field, ['clientid', 'currency', 'duedate'], true) && (string) $updates[$field] === (string) ($invoice->$field ?? '')) {
                continue;
            }
            if ($field === 'allowed_payment_modes' && asaas_invoice_payment_modes($updates[$field]) === asaas_invoice_payment_modes($invoice->$field ?? [])) {
                continue;
            }
            throw new RuntimeException('Há um pagamento ou parcelamento por cartão vinculado à fatura. Concilie-o antes de alterar valor, vencimento, cliente, moeda ou meios de pagamento.');
        }
    }
}

function asaas_card_attempt_blocks(array $cached): bool
{
    return in_array($cached['_asaas_lifecycle']['card_attempt']['state'] ?? '',
        ['preparing', 'processing', 'ambiguous', 'pending', 'confirmed'], true);
}

function asaas_card_assert_no_uncertain_payment(int $invoiceId): void
{
    $cached = asaas_lifecycle_cached_payment(asaas_lifecycle_map($invoiceId));
    if (in_array($cached['_asaas_lifecycle']['card_attempt']['state'] ?? '', ['preparing', 'processing', 'ambiguous', 'pending'], true)) {
        throw new RuntimeException('O cartão ainda está em acompanhamento no Asaas. Concilie a tentativa antes de cancelar, excluir ou receber esta fatura por outro meio.');
    }
}
