<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_130 extends App_module_migration
{
    protected $db;

    public function __construct()
    {
        parent::__construct();

        $ci = &get_instance();
        $ci->load->database();
        $this->db = $ci->db;
    }

    public function up()
    {
        $this->createCustomersMap();
        $this->createPaymentsMap();
        $this->createWebhookEvents();
        $this->createLogs();
    }

    private function createCustomersMap(): void
    {
        $table = db_prefix() . 'asaas_customers_map';
        if (!$this->db->table_exists($table)) {
            $this->db->query("CREATE TABLE `{$table}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `perfex_client_id` INT NOT NULL,
                `asaas_customer_id` VARCHAR(64) NOT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `perfex_client_id` (`perfex_client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }

    private function createPaymentsMap(): void
    {
        $table = db_prefix() . 'asaas_payments_map';
        if (!$this->db->table_exists($table)) {
            $this->db->query("CREATE TABLE `{$table}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `invoice_id` INT NOT NULL,
                `asaas_payment_id` VARCHAR(64) NOT NULL,
                `billing_type` VARCHAR(32) NULL,
                `status` VARCHAR(32) NULL,
                `value` DECIMAL(15,2) NULL,
                `due_date` DATE NULL,
                `last_sync_at` DATETIME NULL,
                `payload_cache` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `invoice_id` (`invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }

    private function createWebhookEvents(): void
    {
        $table = db_prefix() . 'asaas_webhook_events';
        if (!$this->db->table_exists($table)) {
            $this->db->query("CREATE TABLE `{$table}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `event_id` VARCHAR(128) NOT NULL,
                `event_type` VARCHAR(64) NULL,
                `asaas_payment_id` VARCHAR(64) NULL,
                `invoice_id` INT NULL,
                `received_at` DATETIME NULL,
                `processed_at` DATETIME NULL,
                `payload` LONGTEXT NULL,
                `process_status` VARCHAR(32) NULL,
                `error_message` TEXT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `event_id` (`event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }

    private function createLogs(): void
    {
        $table = db_prefix() . 'asaas_logs';
        if (!$this->db->table_exists($table)) {
            $this->db->query("CREATE TABLE `{$table}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(32) NOT NULL,
                `message` TEXT NOT NULL,
                `context` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            return;
        }

        if ($this->db->field_exists('context_json', $table) && !$this->db->field_exists('context', $table)) {
            $this->db->query("ALTER TABLE `{$table}` CHANGE COLUMN `context_json` `context` LONGTEXT NULL");
        }

        if (!$this->db->field_exists('context', $table)) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `context` LONGTEXT NULL");
        }

        if (!$this->db->field_exists('correlation_id', $table)) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `correlation_id` VARCHAR(64) NULL");
        }
    }
}
