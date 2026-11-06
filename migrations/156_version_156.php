<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_156 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../helpers/asaas_recurring_schema_helper.php';
        $CI = &get_instance();
        asaas_recurring_schema_install($CI->db);
    }
}
