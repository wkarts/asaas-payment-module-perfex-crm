<?php

declare(strict_types=1);

$manifestPath = __DIR__ . '/../.release-please-manifest.json';
$moduleFile = __DIR__ . '/../asaas.php';

if (!is_file($manifestPath) || !is_file($moduleFile)) {
    fwrite(STDERR, "Arquivos obrigatórios ausentes (.release-please-manifest.json/asaas.php).\n");
    exit(1);
}

function parseSemver(string $version): array
{
    if (!preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)$/', trim($version), $m)) {
        throw new RuntimeException("Versão inválida: {$version}");
    }

    return [(int) $m[1], (int) $m[2], (int) $m[3]];
}

function readManifestVersion(string $path): string
{
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json) || !isset($json['.']) || !is_string($json['.'])) {
        throw new RuntimeException('Manifest inválido: chave "." ausente.');
    }

    return trim($json['.']);
}

function writeManifestVersion(string $path, string $version): void
{
    file_put_contents($path, json_encode(['.' => $version], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function writeModuleVersion(string $moduleFile, string $version): void
{
    $contents = (string) file_get_contents($moduleFile);
    $updated = preg_replace('/^Version:\s*[0-9]+\.[0-9]+\.[0-9]+/m', 'Version: ' . $version, $contents, 1, $count);

    if ($count === 0 || $updated === null) {
        throw new RuntimeException('Não foi possível atualizar a linha Version: do asaas.php');
    }

    file_put_contents($moduleFile, $updated);
}

$baseVersionArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base-version=')) {
        $baseVersionArg = substr($arg, strlen('--base-version='));
    }
}

try {
    if ($baseVersionArg !== null && $baseVersionArg !== '') {
        $baseVersion = $baseVersionArg;
    } else {
        $baseRef = getenv('GITHUB_BASE_REF') ?: 'main';
        $baseRefSafe = preg_replace('/[^A-Za-z0-9_\/.\-]/', '', $baseRef);
        $baseManifestRaw = shell_exec('git show origin/' . $baseRefSafe . ':.release-please-manifest.json 2>/dev/null');

        if (!is_string($baseManifestRaw) || trim($baseManifestRaw) === '') {
            throw new RuntimeException('Não foi possível ler manifest da branch base. Use --base-version=... para fallback local.');
        }

        $baseManifest = json_decode($baseManifestRaw, true);
        if (!is_array($baseManifest) || !isset($baseManifest['.']) || !is_string($baseManifest['.'])) {
            throw new RuntimeException('Manifest da branch base inválido.');
        }

        $baseVersion = trim($baseManifest['.']);
    }

    [$major, $minor, $patch] = parseSemver($baseVersion);
    $expectedVersion = sprintf('%d.%d.%d', $major, $minor, $patch + 1);

    $currentVersion = readManifestVersion($manifestPath);
    parseSemver($currentVersion);

    if ($currentVersion !== $expectedVersion) {
        writeManifestVersion($manifestPath, $expectedVersion);
        writeModuleVersion($moduleFile, $expectedVersion);
        fwrite(STDOUT, "Versão forçada para próximo patch: {$expectedVersion} (base {$baseVersion}).\n");
        exit(0);
    }

    writeModuleVersion($moduleFile, $expectedVersion);
    fwrite(STDOUT, "Versão já está no próximo patch esperado: {$expectedVersion}.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
