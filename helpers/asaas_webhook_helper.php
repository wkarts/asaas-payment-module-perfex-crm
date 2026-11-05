<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Validate replacements without forcing an existing installation to rotate its token.
 * An empty token preserves the explicitly documented legacy configuration.
 */
function asaas_webhook_token_validation_error($token, ?string $currentToken = null, array $apiKeys = []): ?string
{
    if (!is_string($token)) {
        return 'Informe o token de autenticação do webhook como texto.';
    }

    if ($currentToken !== null && hash_equals($currentToken, $token)) {
        return null;
    }

    if ($token === '') {
        return null;
    }

    if (preg_match('/\A[^\s\p{Z}\p{C}]{32,255}\z/u', $token) !== 1) {
        return 'O novo token do webhook deve ter de 32 a 255 caracteres, sem espaços ou caracteres de controle.';
    }

    if (stripos($token, '$aact_') === 0) {
        return 'Use um token exclusivo para o webhook, diferente da API Key do Asaas.';
    }

    foreach ($apiKeys as $apiKey) {
        if (is_string($apiKey) && $apiKey !== '' && hash_equals($apiKey, $token)) {
            return 'Use um token exclusivo para o webhook, diferente da API Key do Asaas.';
        }
    }

    return null;
}

/** Preserve the stored token on invalid input without discarding other settings. */
function asaas_validate_webhook_settings($data)
{
    $optionName = 'paymentmethod_asaas_webhook_secret';
    if (!is_array($data) || !isset($data['settings']) || !is_array($data['settings'])
        || !array_key_exists($optionName, $data['settings'])) {
        return $data;
    }

    $currentToken = get_option($optionName);
    $currentToken = is_string($currentToken) ? $currentToken : '';
    $token = $data['settings'][$optionName];
    $error = asaas_webhook_token_validation_error($token, $currentToken);

    if ($error === null && $token !== '' && $token !== $currentToken) {
        $apiKeys = [];
        $ci = function_exists('get_instance') ? get_instance() : null;

        foreach (['paymentmethod_asaas_api_key', 'paymentmethod_asaas_api_key_sandbox'] as $apiKeyOption) {
            if (isset($data['settings'][$apiKeyOption]) && is_string($data['settings'][$apiKeyOption])) {
                $apiKeys[] = $data['settings'][$apiKeyOption];
            }

            $storedKey = get_option($apiKeyOption);
            if (is_string($storedKey) && $storedKey !== '' && isset($ci->encryption)
                && method_exists($ci->encryption, 'decrypt')) {
                $apiKeys[] = $ci->encryption->decrypt($storedKey);
            }
        }

        $error = asaas_webhook_token_validation_error($token, $currentToken, $apiKeys);
    }

    if ($error !== null) {
        unset($data['settings'][$optionName]);
        set_alert('danger', 'O token do webhook não foi alterado; o valor anterior foi preservado. ' . $error);
    }

    return $data;
}
