<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_151 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../helpers/asaas_monitor_schema_helper.php';
        $ci = &get_instance();
        asaas_monitor_ensure_indexes($ci->db);
    }
}
