<?php

declare(strict_types=1);

$storage = getenv('WB_BROWSER_TEST_STORAGE');
if (!$storage || !str_contains(basename($storage), 'wb-encryption-browser-')) {
    throw new RuntimeException('An isolated browser test storage directory is required.');
}
define('WB_STORAGE', $storage);
define('WB_BASE_PATH', '');
$root = dirname(__DIR__, 2);

if (PHP_SAPI === 'cli') {
    require $root . '/app/bootstrap.php';
    WbFileBrowser\Installer::install('browseradmin', 'BrowserTestPassword123!');
    WbFileBrowser\Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required']]);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $asset = realpath($root . $path);
    if ($asset === false || !str_starts_with($asset, $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit;
    }
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: require-corp');
    $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'wasm' => 'application/wasm', 'css' => 'text/css', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[pathinfo($asset, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($asset);
    exit;
}
$routes = ['/' => '/index.php', '/api/index.php' => '/api/index.php', '/admin/' => '/admin/index.php', '/share/' => '/share/index.php', '/share/folder.php' => '/share/folder.php', '/share/folder-api.php' => '/share/folder-api.php'];
if (!isset($routes[$path])) {
    http_response_code(404);
    exit;
}
require $root . $routes[$path];
