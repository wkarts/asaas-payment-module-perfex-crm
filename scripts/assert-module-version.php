<?php

declare(strict_types=1);

$moduleFile = __DIR__ . '/../asaas.php';

if (!is_file($moduleFile)) {
    fwrite(STDERR, "Arquivo asaas.php não encontrado.\n");
    exit(1);
}

$contents = (string) file_get_contents($moduleFile);
if (!preg_match('/^Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $contents, $matches)) {
    fwrite(STDERR, "Não foi possível extrair Version: do asaas.php.\n");
    exit(1);
}

$moduleVersion = $matches[1];
$tag = trim((string) ($argv[1] ?? ''));
if ($tag === '') {
    fwrite(STDERR, "Uso: php scripts/assert-module-version.php <tag>\n");
    exit(1);
}

$normalizedTag = $tag;
if (preg_match('/v([0-9]+\.[0-9]+\.[0-9]+)$/', $tag, $tagMatches)) {
    $normalizedTag = $tagMatches[1];
} else {
    $normalizedTag = ltrim($tag, 'v');
}

if ($normalizedTag !== $moduleVersion) {
    fwrite(STDERR, sprintf(
        "Versão divergente: tag '%s' != versão do módulo '%s'.\n",
        $tag,
        $moduleVersion
    ));
    exit(1);
}

fwrite(STDOUT, sprintf("Versão validada com sucesso: %s (tag %s).\n", $moduleVersion, $tag));
