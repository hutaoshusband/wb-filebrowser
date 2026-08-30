<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

/**
 * File-level SQLite backups stored under storage/backups.
 *
 * Uploads live as blobs on disk and are never removed by the application, so
 * only the database needs backing up to make every file, folder and share
 * recoverable.
 */
class DatabaseBackup
{
    private const NAME_PATTERN = '/^db-backup-[0-9]{8}-[0-9]{6}(-[a-z0-9-]+)?\.sqlite$/';

    public static function directory(): string
    {
        $dir = wb_storage_path('backups');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the backup directory.');
        }

        return $dir;
    }

    public static function livePath(PDO $pdo): string
    {
        $row = $pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
        $path = (string) ($row['file'] ?? '');

        if ($path === '' || $path === ':memory:') {
            throw new RuntimeException('The active database does not support file backups.');
        }

        return $path;
    }

    public static function create(PDO $pdo, string $suffix = ''): array
    {
        $live = self::livePath($pdo);
        $name = 'db-backup-' . date('Ymd-His') . ($suffix !== '' ? '-' . $suffix : '') . '.sqlite';

        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException('Invalid backup name.');
        }

        $target = self::directory() . DIRECTORY_SEPARATOR . $name;
        $temp = $target . '.tmp';

        // Hold a write lock so the database cannot change while it is copied.
        $pdo->beginTransaction();

        try {
            if (!copy($live, $temp)) {
                throw new RuntimeException('Unable to write the backup file.');
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            @unlink($temp);

            throw $exception;
        }

        rename($temp, $target);
        @chmod($target, 0664);

        return self::describe($target);
    }

    public static function list(PDO $pdo): array
    {
        self::directory();
        $backups = [];

        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.sqlite') ?: [] as $path) {
            $backups[] = self::describe($path);
        }

        usort($backups, static fn(array $left, array $right): int => strcasecmp($right['name'], $left['name']));

        return $backups;
    }

    /**
     * Replaces the live database with the chosen backup. A fresh backup of the
     * current state is taken first so a bad restore can itself be undone.
     *
     * @return array the safety backup that was taken
     */
    public static function restore(string $name, PDO $pdo): array
    {
        $backup = self::resolve($name);
        $safety = self::create($pdo, 'pre-restore');
        $live = self::livePath($pdo);

        $pdo->beginTransaction();

        try {
            if (!copy($backup['path'], $live)) {
                throw new RuntimeException('Unable to restore the backup.');
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            copy($safety['path'], $live);

            throw $exception;
        }

        foreach ([$live . '-journal', $live . '-wal', $live . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        $check = new PDO('sqlite:' . $live);
        $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $result = (string) $check->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            throw new RuntimeException('Restored database failed the integrity check: ' . $result);
        }

        return $safety;
    }

    public static function delete(string $name): void
    {
        $backup = self::resolve($name);

        if (!@unlink($backup['path'])) {
            throw new RuntimeException('Unable to delete the backup.');
        }
    }

    public static function resolve(string $name): array
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException('Invalid backup name.');
        }

        $path = self::directory() . DIRECTORY_SEPARATOR . $name;

        if (!is_file($path)) {
            throw new RuntimeException('Backup not found.');
        }

        $data = self::describe($path);
        $data['path'] = $path;

        return $data;
    }

    private static function describe(string $path): array
    {
        $name = basename($path);

        return [
            'name' => $name,
            'size' => (int) filesize($path),
            'size_label' => wb_format_bytes((int) filesize($path)),
            'created_at' => gmdate('c', (int) filemtime($path)),
            'download_url' => wb_url('/api/index.php?action=dbbackup.download&name=' . rawurlencode($name)),
        ];
    }
}
