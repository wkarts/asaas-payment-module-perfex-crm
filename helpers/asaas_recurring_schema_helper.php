<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Additive installation only; never run DDL from an invoice or payment transaction. */
function asaas_recurring_schema_install(object $db): void
{
    $prefix = db_prefix();
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
        throw new RuntimeException('Prefixo de tabelas inválido para instalar o cartão recorrente.');
    }
    $authorizations = $prefix . 'asaas_recurring_authorizations';
    if (!$db->table_exists($authorizations)) {
        $result = $db->query("CREATE TABLE `{$authorizations}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `client_id` INT NOT NULL,
            `scope_key` CHAR(64) NOT NULL,
            `environment` VARCHAR(16) NOT NULL,
            `company_id` INT NOT NULL DEFAULT 0,
            `customer_id` VARCHAR(128) NOT NULL,
            `revision` INT NOT NULL DEFAULT 1,
            `state` VARCHAR(24) NOT NULL,
            `token_ciphertext` TEXT NULL,
            `contact_id` INT NOT NULL,
            `consent_version` VARCHAR(32) NOT NULL,
            `authorized_at` DATETIME NOT NULL,
            `cutoff_invoice_id` INT NOT NULL,
            `pricing_policy_hash` CHAR(64) NOT NULL,
            `reason_code` VARCHAR(48) NULL,
            `revoked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_scope` (`client_id`, `scope_key`),
            KEY `state_client_cutoff` (`state`, `client_id`, `cutoff_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        if ($result === false) { throw new RuntimeException('Não foi possível instalar as autorizações do cartão recorrente.'); }
    }
    $jobs = $prefix . 'asaas_recurring_jobs';
    if (!$db->table_exists($jobs)) {
        $result = $db->query("CREATE TABLE `{$jobs}` (
            `invoice_id` INT NOT NULL,
            `invoice_hash` VARCHAR(128) NOT NULL,
            `client_id` INT NOT NULL,
            `authorization_id` INT NOT NULL,
            `authorization_revision` INT NOT NULL,
            `scope_key` CHAR(64) NOT NULL,
            `customer_id` VARCHAR(128) NOT NULL,
            `explicit_current` TINYINT NOT NULL DEFAULT 0,
            `state` VARCHAR(24) NOT NULL,
            `phase` VARCHAR(24) NOT NULL DEFAULT 'queued',
            `payment_id` VARCHAR(128) NULL,
            `reason_code` VARCHAR(48) NULL,
            `next_check_at` DATETIME NULL,
            `started_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`invoice_id`),
            KEY `state_next_check` (`state`, `next_check_at`, `invoice_id`),
            KEY `authorization_revision` (`authorization_id`, `authorization_revision`),
            KEY `payment_id` (`payment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        if ($result === false) { throw new RuntimeException('Não foi possível instalar a fila do cartão recorrente.'); }
    }
}
