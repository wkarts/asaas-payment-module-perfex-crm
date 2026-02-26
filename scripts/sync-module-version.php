<?php

declare(strict_types=1);

$manifestPath = __DIR__ . '/../.release-please-manifest.json';
$moduleFile = __DIR__ . '/../asaas.php';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Manifest não encontrado: .release-please-manifest.json\n");
    exit(1);
}

if (!is_file($moduleFile)) {
    fwrite(STDERR, "Arquivo do módulo não encontrado: asaas.php\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || empty($manifest['.']) || !is_string($manifest['.'])) {
    fwrite(STDERR, "Manifest inválido: chave '.' ausente.\n");
    exit(1);
}

$version = trim($manifest['.']);
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
    fwrite(STDERR, "Versão inválida no manifest: {$version}\n");
    exit(1);
}

$contents = (string) file_get_contents($moduleFile);
$updated = preg_replace('/^Version:\s*[0-9]+\.[0-9]+\.[0-9]+/m', 'Version: ' . $version, $contents, 1, $count);

if ($count === 0 || $updated === null) {
    fwrite(STDERR, "Não foi possível atualizar a linha Version: no asaas.php\n");
    exit(1);
}

if ($updated !== $contents) {
    file_put_contents($moduleFile, $updated);
    fwrite(STDOUT, "Versão sincronizada no módulo: {$version}\n");
    exit(0);
}

fwrite(STDOUT, "Versão do módulo já está sincronizada: {$version}\n");
