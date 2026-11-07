<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Native staff permissions; a missing permissions API always denies non-administrators. */
function asaas_staff_can(string $capability, string $feature): bool
{
    $allowed = [
        'asaas_monitor' => ['view', 'reconcile'],
        'asaas_reports' => ['view', 'export'],
    ];
    if (!isset($allowed[$feature]) || !in_array($capability, $allowed[$feature], true)) { return false; }
    if (function_exists('is_admin') && is_admin()) { return true; }
    $check = static function (string $action) use ($feature): bool {
        if (function_exists('staff_can')) { return (bool) staff_can($action, $feature); }
        return function_exists('has_permission') && (bool) has_permission($feature, '', $action);
    };
    // An action permission alone must never reveal the underlying financial data.
    return $check('view') && ($capability === 'view' || $check($capability));
}

function asaas_register_staff_permissions(): void
{
    if (!function_exists('register_staff_capabilities')) { return; }
    register_staff_capabilities('asaas_monitor', ['capabilities' => [
        'view' => 'Visualizar faturas, cobranças, webhooks e diagnóstico de toda a integração',
        'reconcile' => 'Conciliar recebimentos confirmados no CRM (individual e em lote)',
    ]], 'Asaas — Monitoramento');
    register_staff_capabilities('asaas_reports', ['capabilities' => [
        'view' => 'Visualizar relatórios financeiros da conta Asaas',
        'export' => 'Exportar relatórios financeiros',
    ]], 'Asaas — Relatórios');
}

function asaas_register_staff_menu(): void
{
    $monitor = asaas_staff_can('view', 'asaas_monitor');
    $reports = asaas_staff_can('view', 'asaas_reports');
    if (!$monitor && !$reports) { return; }
    $ci = &get_instance();
    if (!isset($ci->app_menu) || !is_object($ci->app_menu)
        || !is_callable([$ci->app_menu, 'add_sidebar_menu_item'])
        || !is_callable([$ci->app_menu, 'add_sidebar_children_item'])) { return; }
    $ci->app_menu->add_sidebar_menu_item('asaas-financial', [
        'name' => 'Asaas', 'collapse' => true, 'position' => 16, 'icon' => 'fa fa-university',
    ]);
    foreach ([
        [$monitor, 'monitor', 'Monitoramento', 1],
        [$reports, 'reports', 'Relatórios', 2],
    ] as [$enabled, $slug, $label, $position]) {
        if (!$enabled) { continue; }
        $ci->app_menu->add_sidebar_children_item('asaas-financial', [
            'slug' => 'asaas-' . $slug, 'name' => $label, 'position' => $position,
            'href' => admin_url('asaas/' . $slug),
        ]);
    }
    // The delegated financial roles do not grant settings, keys or webhook administration.
    if (function_exists('is_admin') && is_admin()) {
        $ci->app_menu->add_sidebar_children_item('asaas-financial', [
            'slug' => 'asaas-settings', 'name' => 'Configurações', 'position' => 3,
            'href' => admin_url('settings?group=payment_gateways&tab=online_payments_asaas_tab'),
        ]);
    }
}
