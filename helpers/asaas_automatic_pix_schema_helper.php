<?php

declare(strict_types=1);

defined('BASEPATH') or exit('No direct script access allowed');

/** Additive schema: installation/migration only, never inside a financial transaction. */
function asaas_automatic_pix_schema_install(object $db): void
{
    $prefix = db_prefix();
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new RuntimeException('Prefixo inválido para instalar o Pix Automático.'); }
    $profiles = $prefix . 'asaas_pix_authorizations';
    if (!$db->table_exists($profiles)) {
        if ($db->query("CREATE TABLE `{$profiles}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `client_id` INT NOT NULL,
            `scope_key` CHAR(64) NOT NULL,
            `environment` VARCHAR(16) NOT NULL,
            `company_id` INT NOT NULL DEFAULT 0,
            `customer_id` VARCHAR(128) NOT NULL,
            `initial_invoice_id` INT NOT NULL,
            `initial_invoice_hash` VARCHAR(128) NOT NULL,
            `initial_amount_cents` BIGINT NOT NULL,
            `cutoff_invoice_id` INT NOT NULL,
            `contract_id` VARCHAR(35) NOT NULL,
            `remote_id` VARCHAR(128) NULL,
            `state` VARCHAR(24) NOT NULL,
            `remote_status` VARCHAR(16) NULL,
            `frequency` VARCHAR(16) NOT NULL,
            `start_date` DATE NOT NULL,
            `contact_id` INT NOT NULL,
            `consent_version` VARCHAR(32) NOT NULL,
            `authorized_at` DATETIME NOT NULL,
            `qrcode_ciphertext` MEDIUMTEXT NULL,
            `conciliation_identifier` VARCHAR(128) NULL,
            `expires_at` DATETIME NULL,
            `cancel_requested_at` DATETIME NULL,
            `reason_code` VARCHAR(48) NULL,
            `scan_offset` INT NOT NULL DEFAULT 0,
            `payment_scan_offset` INT NOT NULL DEFAULT 0,
            `terminal_scan_started_at` DATETIME NULL,
            `next_check_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `contract_id` (`contract_id`),
            UNIQUE KEY `remote_id` (`remote_id`),
            UNIQUE KEY `conciliation_identifier` (`conciliation_identifier`),
            KEY `client_scope` (`client_id`, `scope_key`, `id`),
            KEY `state_check` (`state`, `next_check_at`, `id`),
            KEY `initial_invoice` (`initial_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;") === false) { throw new RuntimeException('Não foi possível instalar as autorizações do Pix Automático.'); }
    }
    $jobs = $prefix . 'asaas_pix_jobs';
    if (!$db->table_exists($jobs)) {
        if ($db->query("CREATE TABLE `{$jobs}` (
            `invoice_id` INT NOT NULL,
            `invoice_hash` VARCHAR(128) NOT NULL,
            `client_id` INT NOT NULL,
            `scope_key` CHAR(64) NOT NULL,
            `authorization_id` INT NOT NULL,
            `kind` VARCHAR(16) NOT NULL,
            `state` VARCHAR(24) NOT NULL,
            `phase` VARCHAR(24) NOT NULL,
            `principal_cents` BIGINT NOT NULL,
            `due_date` DATE NOT NULL,
            `cycle_key` VARCHAR(24) NULL,
            `previous_payment_id` VARCHAR(128) NULL,
            `payment_id` VARCHAR(128) NULL,
            `instruction_id` VARCHAR(128) NULL,
            `instruction_status` VARCHAR(32) NULL,
            `reason_code` VARCHAR(48) NULL,
            `next_check_at` DATETIME NULL,
            `started_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`invoice_id`),
            UNIQUE KEY `payment_id` (`payment_id`),
            UNIQUE KEY `authorization_cycle` (`authorization_id`, `cycle_key`),
            KEY `state_check` (`state`, `next_check_at`, `invoice_id`),
            KEY `authorization_id` (`authorization_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;") === false) { throw new RuntimeException('Não foi possível instalar a fila do Pix Automático.'); }
    }
}
