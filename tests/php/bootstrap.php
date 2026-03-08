<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

if (!defined('WB_ROOT')) {
    define('WB_ROOT', $root);
}

if (!defined('WB_STORAGE')) {
    define('WB_STORAGE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wb-filebrowser-tests');
}

if (!defined('WB_BASE_PATH')) {
    define('WB_BASE_PATH', '');
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once $root . '/app/helpers.php';
require_once $root . '/app/Settings.php';
require_once $root . '/app/Installer.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Security.php';
require_once $root . '/app/Auth.php';
require_once $root . '/app/Permissions.php';
require_once $root . '/app/FileManager.php';
require_once $root . '/app/AutomationRunner.php';
require_once $root . '/tests/php/Support/DatabaseTestCase.php';
