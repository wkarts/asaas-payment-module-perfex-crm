<?php

declare(strict_types=1);

$moduleFile = __DIR__ . '/../asaas.php';
$migrationsDir = __DIR__ . '/../migrations';
$writeMode = in_array('--write', $argv, true);

if (!is_file($moduleFile)) {
    fwrite(STDERR, "Arquivo asaas.php não encontrado.\n");
    exit(1);
}

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Diretório migrations não encontrado.\n");
    exit(1);
}

$contents = (string) file_get_contents($moduleFile);
if (!preg_match('/^Version:\s*([0-9]+)\.([0-9]+)\.([0-9]+)/m', $contents, $matches)) {
    fwrite(STDERR, "Não foi possível extrair Version: do asaas.php.\n");
    exit(1);
}

$major = (int) $matches[1];
$minor = (int) $matches[2];
$patch = (int) $matches[3];
$versionInt = ($major * 100) + ($minor * 10) + $patch;
$filename = sprintf('%d_version_%d.php', $versionInt, $versionInt);
$path = $migrationsDir . '/' . $filename;

if (is_file($path)) {
    fwrite(STDOUT, sprintf("Migration já existe: %s\n", $filename));
    exit(0);
}

if (!$writeMode) {
    fwrite(STDERR, sprintf(
        "Migration obrigatória ausente para Version %d.%d.%d: %s (execute com --write).\n",
        $major,
        $minor,
        $patch,
        $filename
    ));
    exit(1);
}

$template = <<<PHPFILE
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

if (file_put_contents($path, $template . PHP_EOL) === false) {
    fwrite(STDERR, sprintf("Falha ao criar migration: %s\n", $filename));
    exit(1);
}

fwrite(STDOUT, sprintf("Migration criada: %s\n", $filename));
