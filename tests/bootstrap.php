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

require_once dirname(__DIR__) . '/libraries/AsaasSdkProvider.php';
