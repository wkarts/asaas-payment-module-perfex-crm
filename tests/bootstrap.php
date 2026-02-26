<?php

declare(strict_types=1);

if (!defined('FCPATH')) {
    define('FCPATH', rtrim(dirname(__DIR__), '/\\') . DIRECTORY_SEPARATOR);
}

if (!class_exists('App_gateway')) {
    class App_gateway
    {
        public function getSetting(string $name): mixed
        {
            return null;
        }

        public function decryptSetting(string $name): string
        {
            return '';
        }
    }
}

if (!function_exists('get_instance')) {
    function &get_instance(): object
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new class {
                public object $db;

                public function __construct()
                {
                    $this->db = new class {
                        public function table_exists(string $table): bool
                        {
                            return false;
                        }

                        public function field_exists(string $field, string $table): bool
                        {
                            return false;
                        }
                    };
                }
            };
        }

        return $instance;
    }
}

require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
