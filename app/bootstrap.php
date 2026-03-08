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
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/FileManager.php';

if (!defined('WB_BASE_PATH')) {
    define('WB_BASE_PATH', wb_detect_base_path());
}

Installer::ensureRuntimeDirectories();
Security::startSession();

function wb_bootstrap_page(string $surface): array
{
    $installed = WbFileBrowser\Installer::isInstalled();

    if ($surface !== 'install' && !$installed) {
        wb_redirect(wb_url('/install/'));
    }

    if ($surface === 'install' && $installed) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Already installed</title><style>body{font-family:Segoe UI,sans-serif;background:#111827;color:#f8fafc;display:grid;place-items:center;min-height:100vh;margin:0}main{max-width:32rem;padding:2rem;background:#1f2937;border:1px solid #374151;border-radius:1rem}a{color:#facc15}</style></head><body><main><h1>wb-filebrowser is already installed</h1><p>The installer is locked. Use the app or admin panel instead.</p><p><a href="' . wb_h(wb_url('/')) . '">Open the file browser</a></p></main></body></html>';
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
