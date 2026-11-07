<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_158 extends App_module_migration
{
    public function up()
    {
        $ci = &get_instance();
        require_once __DIR__ . '/../helpers/asaas_recurring_schema_helper.php';
        require_once __DIR__ . '/../helpers/asaas_automatic_pix_schema_helper.php';
        asaas_recurring_schema_install($ci->db);
        asaas_automatic_pix_schema_install($ci->db);
    }
}
