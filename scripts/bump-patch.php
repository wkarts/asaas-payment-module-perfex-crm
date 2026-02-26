<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$moduleFileCandidates = [
    $root . '/modules/asaas/asaas.php',
    $root . '/asaas.php',
];

$moduleFile = null;
foreach ($moduleFileCandidates as $candidate) {
    if (is_file($candidate)) {
        $moduleFile = $candidate;
        break;
    }
}

if ($moduleFile === null) {
    fwrite(STDERR, "Arquivo asaas.php não encontrado.\n");
    exit(1);
}

$migrationsDir = dirname($moduleFile) . '/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Diretório migrations não encontrado: {$migrationsDir}\n");
    exit(1);
}

$moduleContents = (string) file_get_contents($moduleFile);
if (!preg_match('/^Version:\s*([0-9]+)\.([0-9]+)\.([0-9]+)/m', $moduleContents, $matches)) {
    fwrite(STDERR, "Não foi possível extrair Version: do asaas.php.\n");
    exit(1);
}

$major = (int) $matches[1];
$minor = (int) $matches[2];
$patch = (int) $matches[3];
$newPatch = $patch + 1;
$newVersion = sprintf('%d.%d.%d', $major, $minor, $newPatch);

$updatedContents = preg_replace(
    '/^Version:\s*[0-9]+\.[0-9]+\.[0-9]+/m',
    'Version: ' . $newVersion,
    $moduleContents,
    1,
    $replaceCount
);

if ($updatedContents === null || $replaceCount === 0) {
    fwrite(STDERR, "Falha ao atualizar Version no arquivo do módulo.\n");
    exit(1);
}

if (file_put_contents($moduleFile, $updatedContents) === false) {
    fwrite(STDERR, "Falha ao salvar arquivo do módulo.\n");
    exit(1);
}

$versionInt = ($major * 100) + ($minor * 10) + $newPatch;
$migrationFileName = sprintf('%d_version_%d.php', $versionInt, $versionInt);
$migrationPath = $migrationsDir . '/' . $migrationFileName;

if (!is_file($migrationPath)) {
    $migrationTemplate = <<<PHPFILE
<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_{$versionInt} extends App_module_migration
{
    public function up()
    {
        // No database updates
    }
}
PHPFILE;

    if (file_put_contents($migrationPath, $migrationTemplate . PHP_EOL) === false) {
        fwrite(STDERR, "Falha ao criar migration {$migrationFileName}.\n");
        exit(1);
    }
}

$manifestPath = $root . '/.release-please-manifest.json';
if (is_file($manifestPath)) {
    $manifestData = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($manifestData)) {
        $manifestData['.'] = $newVersion;
        file_put_contents(
            $manifestPath,
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }
}

fwrite(STDOUT, sprintf("new_version=%s\nmigration=%s\n", $newVersion, $migrationFileName));
