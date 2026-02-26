<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_132 extends App_module_migration
{
    public function up()
    {
        $ci = &get_instance();
        $ci->load->database();
        $db = $ci->db;

        $table = db_prefix() . 'asaas_logs';
        if (!$db->table_exists($table)) {
            $db->query("CREATE TABLE `{$table}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(32) NOT NULL,
                `message` TEXT NOT NULL,
                `context` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `correlation_id` VARCHAR(64) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            return;
        }

        if ($db->field_exists('context_json', $table) && !$db->field_exists('context', $table)) {
            $db->query("ALTER TABLE `{$table}` CHANGE COLUMN `context_json` `context` LONGTEXT NULL");
        }

        if (!$db->field_exists('context', $table)) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `context` LONGTEXT NULL");
        }

        if (!$db->field_exists('correlation_id', $table)) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `correlation_id` VARCHAR(64) NULL");
        }
    }
}
