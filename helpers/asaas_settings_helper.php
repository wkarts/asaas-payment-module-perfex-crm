<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/asaas_card_pricing_helper.php';

/** Accept only the two native radio values; never coerce an array or a malformed string. */
function asaas_settings_environment_flag($value): ?string
{
    if ($value === '1' || $value === 1 || $value === true) {
        return '1';
    }
    if ($value === '0' || $value === 0 || $value === false) {
        return '0';
    }

    return null;
}

/**
 * Keep sandbox as the only persisted environment selector (1 = homologation, 0 = production).
 * Invalid selections preserve the previous setting by removing only that field from the update.
 */
function asaas_validate_environment_settings($data)
{
    if (!is_array($data) || !isset($data['settings']) || !is_array($data['settings'])) {
        return $data;
    }

    $groups = [];
    foreach ($data['settings'] as $name => $value) {
        if (preg_match('/\Apaymentmethod_asaas_(sandbox|production)(_company_[1-9][0-9]*)?\z/D', (string) $name, $match)) {
            $groups[$match[2] ?? ''][$match[1]] = $name;
        }
    }

    $rejected = false;
    foreach ($groups as $fields) {
        $hasProduction = isset($fields['production']);
        $production = null;
        if ($hasProduction) {
            $production = asaas_settings_environment_flag($data['settings'][$fields['production']]);
            // A secondary flag is never stored or allowed to become an alternative source of truth.
            unset($data['settings'][$fields['production']]);
        }

        if (!isset($fields['sandbox'])) {
            // There is no supported legacy production-only option to infer a change from.
            $rejected = true;
            continue;
        }

        $sandboxName = $fields['sandbox'];
        $sandbox = asaas_settings_environment_flag($data['settings'][$sandboxName]);
        if ($sandbox === null || ($hasProduction && ($production === null || $sandbox === $production))) {
            unset($data['settings'][$sandboxName]);
            $rejected = true;
            continue;
        }

        $data['settings'][$sandboxName] = $sandbox;
    }

    if ($rejected) {
        set_alert('danger', 'Uma seleção de ambiente Asaas inválida foi ignorada; o valor anterior desse ambiente foi preservado. Selecione Homologação ou Produção.');
    }

    return $data;
}

/**
 * Validate the card options as one group so that a rejected fee cannot be saved
 * together with a new pass-through mode. Unrelated settings and company scopes
 * retain the native settings workflow; this filter never writes options itself.
 */
function asaas_validate_card_settings($data)
{
    if (!is_array($data) || !isset($data['settings']) || !is_array($data['settings'])) {
        return $data;
    }

    $defaults = asaas_card_pricing_defaults();
    $pattern = '/\Apaymentmethod_asaas_(' . implode('|', array_keys($defaults)) . ')(_company_[1-9][0-9]*)?\z/D';
    $groups = [];
    foreach ($data['settings'] as $name => $value) {
        if (preg_match($pattern, (string) $name, $match)) {
            $groups[$match[2] ?? ''][$match[1]] = $name;
        }
    }

    $firstError = null;
    foreach ($groups as $suffix => $fields) {
        try {
            $effective = [];
            foreach ($defaults as $key => $default) {
                if (isset($fields[$key])) {
                    $value = $data['settings'][$fields[$key]];
                    // Missing stored options use defaults; blank submitted fields are errors.
                    if ($value === null || $value === '') {
                        throw new InvalidArgumentException('Preencha os campos de parcelamento e taxas do cartão.');
                    }
                } else {
                    $value = function_exists('get_option') ? get_option('paymentmethod_asaas_' . $key . $suffix) : null;
                    $value = $value === false ? null : $value;
                }
                $effective[$key] = $value;
            }
            // Share the same parser, limits and cross-field checks as the payment quote.
            $normalized = asaas_card_pricing_normalize_settings($effective);
            foreach ($fields as $key => $name) {
                $data['settings'][$name] = $normalized[$key];
            }
        } catch (InvalidArgumentException $error) {
            foreach ($fields as $name) {
                unset($data['settings'][$name]);
            }
            $firstError = $firstError ?? $error->getMessage();
        }
    }

    if ($firstError !== null) {
        set_alert('danger', 'As configurações de cartão inválidas não foram salvas; os valores anteriores desse grupo foram preservados. ' . $firstError);
    }

    return $data;
}
