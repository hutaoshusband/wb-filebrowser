<?php

define('WB_ROOT', __DIR__);
require_once 'app/bootstrap.php';
require_once 'app/Installer.php';
require_once 'app/Database.php';

use WbFileBrowser\Installer;
use WbFileBrowser\FileManager;
use WbFileBrowser\Database;

$pdo = Database::connection();
// Temporarily disable foreign key constraints
$pdo->exec('PRAGMA foreign_keys = OFF;');

$pdo->beginTransaction();

$now = wb_now();
for ($i = 0; $i < 5000; $i++) {
    $stmt = $pdo->prepare('INSERT INTO folders (parent_id, name, created_by, created_at, updated_at) VALUES (NULL, ?, 1, ?, ?)');
    $stmt->execute(['folder' . $i, $now, $now]);
}

for ($i = 0; $i < 10000; $i++) {
    $stmt = $pdo->prepare('INSERT INTO files (folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $stmt->execute([rand(1, 5000), 'file'.$i, 'disk'.$i, 'txt', 'text/plain', rand(1024, 1048576), 'checksum', $now, $now]);
}

$pdo->commit();

// Warm up the query
FileManager::refreshFolderSizeCache();

// Run benchmark
$start = microtime(true);
FileManager::refreshFolderSizeCache();
$end = microtime(true);

echo "Time taken for 5000 folders and 10000 files: " . round(($end - $start) * 1000, 2) . " ms\n";

// Cleanup db
$pdo->exec('DELETE FROM files; DELETE FROM folders WHERE id > 1');
$pdo->exec('PRAGMA foreign_keys = ON;');
