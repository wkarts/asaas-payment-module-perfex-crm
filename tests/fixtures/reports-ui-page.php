<?php

declare(strict_types=1);

/** Isolated rendering of the actual reusable report view; every value is a public fixture. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admin_url(string $path = ''): string { return 'https://crm.example.test/admin/' . ltrim($path, '/'); }
function is_admin(): bool { return false; }
function has_permission(string $feature, string $staff, string $capability): bool
{
    return $feature === 'asaas_reports' && ($capability === 'view' || ($capability === 'export' && !in_array('--view-only', $GLOBALS['argv'], true)));
}
require_once dirname(__DIR__, 2) . '/helpers/asaas_staff_helper.php';
require_once dirname(__DIR__, 2) . '/libraries/Asaas/AsaasReportCatalog.php';
if (in_array('--manifest', $argv, true)) { echo json_encode(\Asaas\AsaasReportCatalog::publicDefinitions(), JSON_THROW_ON_ERROR); exit; }
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatórios financeiros Asaas — fixture</title>
    <link rel="stylesheet" href="/_fixture/bootstrap.css">
    <link rel="stylesheet" href="/modules/asaas/assets/css/asaas-reports.css">
    <style>body{background:#f8fafc;color:#334155;padding:16px}main{max-width:1240px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:20px}.mtop5{margin-top:5px}.mtop20{margin-top:20px}.mbot0{margin-bottom:0}.no-margin{margin:0}.text-muted,.help-block{color:#64748b}button,a,input,select{font-size:13px!important}label{font-weight:600}form>footer{padding-top:20px;border-top:1px solid #e2e8f0;margin-top:20px}@media(max-width:600px){body{padding:8px}main{padding:12px}}</style>
</head>
<body>
    <main>
        <form id="fixture-settings" action="/should-not-submit" method="post">
            <input type="hidden" name="settings[paymentmethod_asaas_active]" value="1">
            <?php require dirname(__DIR__, 2) . '/views/settings/reports.php'; ?>
            <footer><button id="fixture-save" type="submit" class="btn btn-default">Salvar configurações (fixture)</button></footer>
        </form>
    </main>
    <script>window.fixtureSubmits=0;document.getElementById('fixture-settings').addEventListener('submit',function(event){event.preventDefault();window.fixtureSubmits++;});</script>
    <script src="/modules/asaas/assets/js/asaas-reports.js"></script>
</body>
</html>
