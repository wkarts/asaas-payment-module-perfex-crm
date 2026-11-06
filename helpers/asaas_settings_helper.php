<?php

defined('BASEPATH') or exit('No direct script access allowed');

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
