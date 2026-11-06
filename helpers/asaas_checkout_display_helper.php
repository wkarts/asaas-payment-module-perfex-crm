<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Translate presentation only; SDK environment identifiers stay unchanged. */
function asaas_checkout_environment_label(string $environment): string
{
    return match (strtoupper(trim($environment))) {
        'SANDBOX' => 'Homologação',
        'PRODUCTION' => 'Produção',
        default => trim($environment),
    };
}

/** Controls only the environment notice shown to the payer, never the API environment. */
function asaas_checkout_show_environment(string $environment, $showInProduction = null): bool
{
    $environment = strtoupper(trim($environment));
    if ($environment === '') {
        return false;
    }
    if ($environment !== 'PRODUCTION') {
        return true;
    }
    if ($showInProduction === null) {
        $showInProduction = function_exists('get_option')
            ? get_option('paymentmethod_asaas_show_environment_in_production')
            : false;
    }

    return in_array($showInProduction, [true, 1, '1'], true);
}
