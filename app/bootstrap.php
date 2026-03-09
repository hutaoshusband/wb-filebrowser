<?php

declare(strict_types=1);

use WbFileBrowser\Auth;
use WbFileBrowser\Installer;
use WbFileBrowser\Security;

if (!defined('WB_ROOT')) {
    define('WB_ROOT', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
}

if (!defined('WB_STORAGE')) {
    define('WB_STORAGE', WB_ROOT . DIRECTORY_SEPARATOR . 'storage');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/BlockedAccessException.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/FileManager.php';
require_once __DIR__ . '/FileShares.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/IpBanService.php';
require_once __DIR__ . '/AutomationRunner.php';

if (!defined('WB_BASE_PATH')) {
    define('WB_BASE_PATH', wb_detect_base_path());
}

Installer::ensureRuntimeDirectories();

if (Installer::isInstalled()) {
    Installer::migrate();
}

Security::startSession();

function wb_bootstrap_page(string $surface): array
{
    $installed = WbFileBrowser\Installer::isInstalled();

    if ($surface !== 'install' && !$installed) {
        wb_redirect(wb_url('/install/'));
    }

    if ($surface === 'install' && $installed) {
        Security::sendPageHeaders();
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head>' . wb_page_head('Already installed | wb-filebrowser') . '</head><body class="install-shell"><main class="install-layout"><section class="install-card"><div class="install-header"><p class="install-kicker">Installer locked</p><h1>wb-filebrowser is already installed</h1><p>The installer is locked. Use the app or admin panel instead.</p></div><div class="quick-actions"><a class="header-button primary-button" href="' . wb_h(wb_url('/')) . '">Open the file browser</a><a class="header-button" href="' . wb_h(wb_url('/admin/#/dashboard')) . '">Open admin</a></div></section></main></body></html>';
        exit;
    }

    $user = $installed ? Auth::currentUser() : null;

    return [
        'surface' => $surface,
        'installed' => $installed,
        'base_path' => WB_BASE_PATH,
        'app_version' => Installer::VERSION,
        'csrf_token' => Security::csrfToken(),
        'user' => $user,
    ];
}
