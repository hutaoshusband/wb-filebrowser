<?php

// Dev-only router for `php -S 127.0.0.1:8090 dev-router.php`.
// Emulates the production .htaccess COOP/COEP headers for static assets so the
// encryption and video-compression workers can load in the isolated agent cluster.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

if ($path !== '/' && pathinfo($file, PATHINFO_EXTENSION) !== 'php' && is_file($file)) {
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: require-corp');
    $types = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'wasm' => 'application/wasm',
        'css' => 'text/css',
        'woff2' => 'font/woff2',
        'webp' => 'image/webp',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
    ];
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}

return false;
