<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;
use ZipArchive;

final class FolderArchive
{
    /** Build on disk: libzip reads files in chunks and STORE avoids compression. */
    public static function create(?array $user, int $folderId): array
    {
        $pdo = Database::connection();
        $scope = Permissions::scope($user, $pdo);
        if (!Permissions::canOpenFolder($folderId, $user, $pdo, $scope)) {
            throw new RuntimeException('You do not have access to this folder.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Folder downloads require the PHP zip extension.');
        }
        $folders = [];
        $children = [];
        foreach ($pdo->query('SELECT id, parent_id, name FROM folders') as $row) {
            $folders[(int) $row['id']] = $row;
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }
        if (!isset($folders[$folderId])) {
            throw new RuntimeException('Folder not found.');
        }
        $name = self::safeName((string) $folders[$folderId]['name']);
        $path = tempnam(sys_get_temp_dir(), 'wb-zip-');
        if ($path === false) throw new RuntimeException('Unable to create a temporary archive.');
        chmod($path, 0600);
        // Also clean up on client disconnects and fatal errors during libzip finalization.
        register_shutdown_function(static function () use ($path): void { @unlink($path); });
        $zip = new ZipArchive();
        $closed = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the archive.');
            }
            $files = $pdo->prepare('SELECT * FROM files WHERE folder_id = ? ORDER BY id');
            $pending = [[$folderId, $name . '/']];
            $visited = [];
            $entries = [];
            $bytes = 0;
            $available = disk_free_space(dirname($path));
            while ($pending !== []) {
                [$id, $prefix] = array_pop($pending);
                if (isset($visited[$id])) throw new RuntimeException('Invalid folder tree.');
                $visited[$id] = true;
                if (!Permissions::canOpenFolder($id, $user, $pdo, $scope)) continue;
                self::reserveEntry($entries, $prefix);
                if (!$zip->addEmptyDir($prefix)) throw new RuntimeException('Unable to add a folder to the archive.');
                if (Permissions::canViewFolderContents($id, $user, $pdo, $scope)) {
                    $files->execute([$id]);
                    while ($file = $files->fetch()) {
                        $entry = $prefix . self::safeName((string) $file['original_name'])
                            . (!empty($file['encryption_format']) ? '.wbencrypted' : '');
                        self::reserveEntry($entries, $entry);
                        $location = FileManager::blobLocation($pdo, $file);
                        $source = FileManager::blobPathFor($location['disk_name'], $location['disk_extension']);
                        if (!is_file($source) || !is_readable($source)) throw new RuntimeException('An archive file is unavailable.');
                        $size = filesize($source);
                        if ($size === false) throw new RuntimeException('Unable to read an archive file.');
                        $bytes += $size + strlen($entry) * 2 + 256;
                        if ($available === false || $bytes + 67108864 > $available) {
                            throw new RuntimeException('Insufficient temporary disk space for this folder download.');
                        }
                        if (!$zip->addFile($source, $entry) || !$zip->setCompressionName($entry, ZipArchive::CM_STORE)) {
                            throw new RuntimeException('Unable to add a file to the archive.');
                        }
                    }
                    $files->closeCursor();
                }
                foreach ($children[$id] ?? [] as $childId) {
                    if (Permissions::canOpenFolder($childId, $user, $pdo, $scope)) {
                        $pending[] = [$childId, $prefix . self::safeName((string) $folders[$childId]['name']) . '/'];
                    }
                }
            }
            if (!$zip->close()) throw new RuntimeException('Unable to finish the archive.');
            $closed = true;
            return ['path' => $path, 'name' => $name . '.zip'];
        } catch (\Throwable $error) {
            if (!$closed) { try { $zip->unchangeAll(); $zip->close(); } catch (\Throwable) {} }
            @unlink($path);
            throw $error;
        }
    }

    private static function safeName(string $name): string
    {
        if ($name === '' || rtrim($name, ' .') !== $name || $name === '.' || $name === '..' || preg_match('/[\\x00-\\x1f\\x7f\\\\\/:]/', $name)) {
            throw new RuntimeException('An item has an unsafe archive name.');
        }
        return $name;
    }

    private static function reserveEntry(array &$entries, string $name): void
    {
        $key = rtrim($name, '/');
        if (isset($entries[$key])) throw new RuntimeException('Duplicate archive paths. Rename conflicting items first.');
        if (count($entries) >= 100000) throw new RuntimeException('Too many items. Download a smaller folder.');
        $entries[$key] = true;
    }

    public static function download(?array $user, int $folderId): never
    {
        $buckets = [['scope' => 'folder-download-ip', 'identifier' => Security::clientIp(), 'limit' => 3, 'window' => 300]];
        Security::assertRateLimitAvailable($buckets, 'Too many folder downloads. Please wait a few minutes.');
        Security::consumeRateLimit($buckets);
        // A separate nonblocking lock limits disk pressure without blocking uploads or browsing.
        $lock = fopen(wb_storage_path('.folder-archive.lock'), 'c');
        if ($lock === false) throw new RuntimeException('Unable to start the folder download.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another archive is being prepared. Please try again shortly.');
        }
        $archive = null;
        try {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            set_time_limit(0);
            $archive = self::create($user, $folderId);
            AuditLog::record('folder.download', 'file_downloads', [
                'actor_user' => $user, 'target_type' => 'folder', 'target_id' => $folderId,
                'target_label' => $archive['name'], 'summary' => 'Downloaded folder archive ' . $archive['name'],
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Security::sendFile($archive['path'], 'application/zip', $archive['name'], 'attachment');
    }
}
