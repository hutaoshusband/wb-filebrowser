<?php

// Dev-only router for `php -S 127.0.0.1:8090 dev-router.php`.
// Emulates the production .htaccess COOP/COEP headers for static assets so the
// encryption and video-compression workers can load in the isolated agent cluster.

declare(strict_types=1);

$notFound = static function (): never {
    http_response_code(404);
    exit;
};
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($rawPath) || !str_starts_with($rawPath, '/')
    || preg_match('/%(?![a-f0-9]{2})|%2f|%5c/i', $rawPath)) {
    $notFound();
}
$path = rawurldecode($rawPath);
if (preg_match('/[\x00-\x1f\x7f\\\\%]/', $path)
    || preg_match('#(?:^|/)\.[^/]*(?:/|$)#', $path)) {
    $notFound();
}

// A parent document root may mount this checkout at /wb-filebrowser/.
$root = realpath(__DIR__);
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$basePath = '';
if ($documentRoot !== false && $root !== $documentRoot) {
    $prefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($root, $prefix)) $notFound();
    $basePath = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($root, strlen($prefix)));
    if (!str_starts_with($path, $basePath . '/') && $path !== $basePath) $notFound();
    $path = substr($path, strlen($basePath)) ?: '/';
}

$routes = [
    '/' => '/index.php', '/index.php' => '/index.php',
    '/admin/' => '/admin/index.php', '/admin/index.php' => '/admin/index.php',
    '/api/' => '/api/index.php', '/api/index.php' => '/api/index.php',
    '/install/' => '/install/index.php', '/install/index.php' => '/install/index.php',
    '/share/' => '/share/index.php', '/share/index.php' => '/share/index.php',
    '/share/folder.php' => '/share/folder.php', '/share/folder-api.php' => '/share/folder-api.php',
    '/embed/' => '/embed/index.php', '/embed/index.php' => '/embed/index.php',
    '/embed/stream/' => '/embed/stream/index.php', '/embed/stream/index.php' => '/embed/stream/index.php',
];
if (isset($routes[$path . '/'])) {
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    header('Location: ' . $basePath . $path . '/' . (is_string($query) ? '?' . $query : ''));
    exit;
}
if (isset($routes[$path])) {
    $script = $routes[$path];
    $_SERVER['SCRIPT_NAME'] = $basePath . $script;
    $_SERVER['SCRIPT_FILENAME'] = $root . $script;
    require $root . $script;
    exit;
}

$publicDirectory = str_starts_with($path, '/assets/') ? 'assets'
    : (str_starts_with($path, '/media/') ? 'media' : null);
if ($publicDirectory !== null || $path === '/install/install.js') {
    $file = realpath($root . $path);
    $allowedRoot = $root . DIRECTORY_SEPARATOR . ($publicDirectory ?? 'install') . DIRECTORY_SEPARATOR;
    if ($file === false || !str_starts_with($file, $allowedRoot) || !is_file($file)) $notFound();
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: require-corp');
    header('X-Content-Type-Options: nosniff');
    $types = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'wasm' => 'application/wasm',
        'css' => 'text/css',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($types[$extension])) $notFound();
    header('Content-Type: ' . $types[$extension]);
    header('Content-Length: ' . (string) filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}

// Never fall through to the built-in server's unrestricted static handler.
$notFound();
