<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/scripts/module-version.php';
$assertions = 0;
$scenarios = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
};
$cases = [
    '1.4.8' => '1.4.9', '1.4.9' => '1.5.0', '1.4.10' => '1.5.0',
    '1.4.11' => '1.5.1', '1.5.0' => '1.5.1', '1.5.9' => '1.6.0',
    '1.9.9' => '2.0.0', '0.9.9' => '1.0.0', '9.9.9' => '10.0.0',
];
foreach ($cases as $current => $expected) {
    $assert(asaas_module_next_version($current) === $expected, 'Sequência incorreta para ' . $current);
    $assert(preg_match('/^[0-9]+\.[0-9]\.[0-9]$/D', $expected) === 1, 'A saída deve manter minor/patch de um dígito.');
    ++$scenarios;
}
$assert(asaas_module_version_number('1.4.10') === asaas_module_version_number('1.5.0'), 'A correção histórica deve preservar a migration 150.');
$assert(asaas_module_version_number('1.5.1') === 151, 'A nova migração deve continuar sequencial.');
++ $scenarios;

$temporary = sys_get_temp_dir() . '/asaas-version-' . bin2hex(random_bytes(8));
mkdir($temporary . '/scripts', 0700, true);
mkdir($temporary . '/migrations', 0700);
foreach (['module-version.php', 'bump-patch.php', 'ensure-version-migration.php'] as $file) {
    copy(dirname(__DIR__) . '/scripts/' . $file, $temporary . '/scripts/' . $file);
}
$run = static function (string $script, array $arguments = []) use ($temporary): array {
    $process = proc_open(array_merge([PHP_BINARY, $temporary . '/scripts/' . $script], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $temporary);
    if (!is_resource($process)) { throw new RuntimeException('Não foi possível executar o script de versão.'); }
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
try {
    $existingMigration = "<?php\n// historical migration must remain unchanged\n";
    file_put_contents($temporary . '/migrations/150_version_150.php', $existingMigration);
    file_put_contents($temporary . '/asaas.php', "<?php\n/*\nModule Name: Asaas fixture\nVersion: 1.4.10\n*/\n");
    file_put_contents($temporary . '/.release-please-manifest.json', json_encode(['.' => '1.4.10']));
    [$code, $out, $err] = $run('bump-patch.php');
    $assert($code === 0 && str_contains($out, 'new_version=1.5.0') && $err === '', 'O script real deve corrigir 1.4.10 para 1.5.0.');
    $assert(str_contains((string) file_get_contents($temporary . '/asaas.php'), 'Version: 1.5.0'), 'O cabeçalho deve receber a versão corrigida.');
    $assert(json_decode((string) file_get_contents($temporary . '/.release-please-manifest.json'), true)['.'] === '1.5.0', 'O manifesto deve ficar sincronizado.');
    $assert(file_get_contents($temporary . '/migrations/150_version_150.php') === $existingMigration, 'A normalização não pode substituir uma migração já aplicada.');
    $assert(!is_file($temporary . '/migrations/151_version_151.php'), 'A correção histórica não deve inventar outra migração.');
    [$code] = $run('ensure-version-migration.php');
    $assert($code === 0, 'A versão normalizada deve passar no validador.');
    ++$scenarios;

    [$code, $out] = $run('bump-patch.php');
    $assert($code === 0 && str_contains($out, 'new_version=1.5.1'), 'A próxima publicação deve seguir 1.5.1.');
    $assert(str_contains((string) file_get_contents($temporary . '/migrations/151_version_151.php'), 'Migration_Version_151'), 'A nova publicação deve criar a migração seguinte.');
    ++$scenarios;

    file_put_contents($temporary . '/asaas.php', "<?php\n/*\nVersion: 1.5.9\n*/\n");
    [$code, $out] = $run('bump-patch.php');
    $assert($code === 0 && str_contains($out, 'new_version=1.6.0'), 'A virada seguinte também deve zerar o patch.');
    $assert(is_file($temporary . '/migrations/160_version_160.php'), 'A migração 1.6.0 deve ser a 160.');
    ++$scenarios;

    file_put_contents($temporary . '/asaas.php', "<?php\n/*\nVersion: 1.4.10\n*/\n");
    [$code, , $err] = $run('ensure-version-migration.php');
    $assert($code !== 0 && str_contains($err, 'Use 1.5.0'), 'O CI deve impedir uma nova versão com patch acima de9.');
    ++$scenarios;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
    rmdir($temporary);
}
echo "Versionamento: {$scenarios} cenários, {$assertions} asserções, 0 falhas.\n";
