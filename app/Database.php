<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (!Installer::isInstalled()) {
            throw new RuntimeException('wb-filebrowser is not installed yet.');
        }

        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $pdo = new PDO('sqlite:' . Installer::databasePath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$connection = $pdo;

        return $pdo;
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $statement = self::connection()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public static function updateSetting(string $key, string $value): void
    {
        $statement = self::connection()->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':key' => $key,
            ':value' => $value,
            ':updated_at' => wb_now(),
        ]);
    }

    public static function rootFolderId(): int
    {
        return (int) (self::setting('root_folder_id', '1') ?? '1');
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }
}
