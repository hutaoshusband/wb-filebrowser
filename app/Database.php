<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;
    private static ?array $config = null;

    public static function connection(): PDO
    {
        if (!Installer::isInstalled()) {
            throw new RuntimeException('wb-filebrowser is not installed yet.');
        }

        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = self::config();
        $pdo = new PDO(
            DatabasePlatform::dsn($config),
            $config['driver'] === 'sqlite' ? null : (string) ($config['username'] ?? ''),
            $config['driver'] === 'sqlite' ? null : (string) ($config['password'] ?? '')
        );
        DatabasePlatform::configureConnection($pdo, $config);

        self::$connection = $pdo;

        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (is_array(self::$config)) {
            return self::$config;
        }

        $config = DatabaseConfig::loadInstalled();

        if (!is_array($config)) {
            throw new RuntimeException('Database configuration is missing.');
        }

        self::$config = $config;

        return $config;
    }

    public static function driver(): string
    {
        return (string) self::config()['driver'];
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
        $statement = self::prepareUpsert(
            self::connection(),
            'settings',
            ['key', 'value', 'updated_at'],
            ['value', 'updated_at'],
            ['key']
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

    /**
     * @param array<int, string> $insertColumns
     * @param array<int, string> $updateColumns
     * @param array<int, string> $conflictColumns
     */
    public static function prepareUpsert(
        PDO $pdo,
        string $table,
        array $insertColumns,
        array $updateColumns,
        array $conflictColumns
    ): PDOStatement {
        return $pdo->prepare(
            DatabasePlatform::upsertSql(self::driver(), $table, $insertColumns, $updateColumns, $conflictColumns)
        );
    }

    public static function lastInsertId(PDO $pdo, string $table): int
    {
        return DatabasePlatform::lastInsertId($pdo, self::driver(), $table);
    }

    public static function disconnect(): void
    {
        self::$connection = null;
        self::$config = null;
    }
}
