<?php

declare(strict_types=1);

/** The module uses one digit for minor/patch, matching Perfex's migration number. */
function asaas_module_version_number(string $version): int
{
    if (!preg_match('/^([0-9]{1,7})\.([0-9]{1,7})\.([0-9]{1,7})$/D', $version, $parts)) {
        throw new InvalidArgumentException('Versão inválida do módulo Asaas.');
    }
    return ((int) $parts[1] * 100) + ((int) $parts[2] * 10) + (int) $parts[3];
}

function asaas_module_version_from_number(int $number): string
{
    if ($number < 0) { throw new InvalidArgumentException('Versão inválida do módulo Asaas.'); }
    return intdiv($number, 100) . '.' . (intdiv($number, 10) % 10) . '.' . ($number % 10);
}

/** Normalize historical 1.4.10 to 1.5.0 without changing its migration 150. */
function asaas_module_next_version(string $current): string
{
    $number = asaas_module_version_number($current);
    $normalized = asaas_module_version_from_number($number);
    return $normalized !== $current ? $normalized : asaas_module_version_from_number($number + 1);
}
