<?php
// Isolated, real application fixtures for manually checking icons in the browser.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/media/file-fallbacks/')) {
        $file = realpath(dirname(__DIR__, 2) . $path);
        $directory = realpath(dirname(__DIR__, 2) . '/media/file-fallbacks');
        if ($file && str_starts_with($file, $directory . DIRECTORY_SEPARATOR) && str_ends_with($file, '.svg')) {
            header('Content-Type: image/svg+xml');
            readfile($file);
            exit;
        }
        http_response_code(404);
        exit;
    }
    require __DIR__ . '/runtime.php';
    exit;
}

$storage = getenv('WB_BROWSER_TEST_STORAGE');
if (!$storage || !str_contains(basename($storage), 'wb-encryption-browser-')) {
    throw new RuntimeException('Isolated browser test storage is required.');
}
define('WB_STORAGE', $storage);
define('WB_BASE_PATH', '');
require dirname(__DIR__, 2) . '/app/bootstrap.php';
WbFileBrowser\Installer::install('browseradmin', 'BrowserTestPassword123!');
$pdo = WbFileBrowser\Database::connection();
$userId = $pdo->query("SELECT id FROM users WHERE username = 'browseradmin'")->fetchColumn();
foreach ([
    ['config.firefly', "\x01\x00Firefly configuration sample\x00", 'application/octet-stream'],
    ['main.rs', "fn main() {\n    println!(\"Hello from Rust\");\n}\n", 'text/plain'],
] as [$name, $contents, $mime]) {
    $disk = wb_random_token(16);
    $dir = wb_storage_path('uploads/' . substr($disk, 0, 2) . '/' . substr($disk, 2, 2));
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $disk . '.blob', $contents);
    $pdo->prepare('INSERT INTO files (folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([WbFileBrowser\Database::rootFolderId(), $name, $disk, 'blob', $mime, strlen($contents), hash('sha256', $contents), $userId, wb_now(), wb_now()]);
}
echo "Created Firefly and Rust sample files in isolated test storage.\n";
