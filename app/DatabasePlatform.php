<?php

declare(strict_types=1);

namespace WbFileBrowser;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class DatabasePlatform
{
    /**
     * @return array<int, string>
     */
    public static function coreTables(): array
    {
        return [
            'users',
            'settings',
            'folders',
            'files',
            'file_shares',
            'folder_permissions',
            'login_attempts',
            'rate_limits',
            'audit_logs',
            'ip_bans',
            'automation_jobs',
        ];
    }

    public static function normalizeDriver(string $driver): string
    {
        $normalized = strtolower(trim($driver));

        if (!in_array($normalized, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported database driver.');
        }

        return $normalized;
    }

    public static function defaultPort(string $driver): ?int
    {
        return match (self::normalizeDriver($driver)) {
            'mysql' => 3306,
            'pgsql' => 5432,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function dsn(array $config): string
    {
        $driver = self::normalizeDriver((string) ($config['driver'] ?? 'sqlite'));

        return match ($driver) {
            'sqlite' => 'sqlite:' . (string) ($config['path'] ?? ''),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($config['host'] ?? ''),
                (int) ($config['port'] ?? 3306),
                (string) ($config['name'] ?? '')
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                (string) ($config['host'] ?? ''),
                (int) ($config['port'] ?? 5432),
                (string) ($config['name'] ?? '')
            ),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function configureConnection(PDO $pdo, array $config): void
    {
        $driver = self::normalizeDriver((string) ($config['driver'] ?? 'sqlite'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @param array<int, string> $insertColumns
     * @param array<int, string> $updateColumns
     * @param array<int, string> $conflictColumns
     */
    public static function upsertSql(
        string $driver,
        string $table,
        array $insertColumns,
        array $updateColumns,
        array $conflictColumns
    ): string {
        $driver = self::normalizeDriver($driver);
        $quotedColumns = implode(', ', $insertColumns);
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $insertColumns));

        if ($driver === 'mysql') {
            $assignments = implode(', ', array_map(
                static fn (string $column): string => $column . ' = VALUES(' . $column . ')',
                $updateColumns
            ));

            return sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                $quotedColumns,
                $placeholders,
                $assignments
            );
        }

        $conflict = implode(', ', $conflictColumns);
        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = excluded.' . $column,
            $updateColumns
        ));

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s',
            $table,
            $quotedColumns,
            $placeholders,
            $conflict,
            $assignments
        );
    }

    public static function columnInspectionSql(string $driver, string $table): string
    {
        $driver = self::normalizeDriver($driver);
        $table = self::assertIdentifier($table);

        return match ($driver) {
            'sqlite' => 'PRAGMA table_info(' . $table . ')',
            'mysql' => 'SHOW COLUMNS FROM ' . $table,
            'pgsql' => 'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table ORDER BY ordinal_position',
        };
    }

    public static function sequenceName(string $driver, string $table): ?string
    {
        return self::normalizeDriver($driver) === 'pgsql'
            ? self::assertIdentifier($table) . '_id_seq'
            : null;
    }

    public static function lastInsertId(PDO $pdo, string $driver, string $table): int
    {
        $sequence = self::sequenceName($driver, $table);
        $value = $sequence === null ? $pdo->lastInsertId() : $pdo->lastInsertId($sequence);

        return (int) $value;
    }

    /**
     * @return array<int, string>
     */
    public static function tableNames(PDO $pdo, string $driver): array
    {
        $driver = self::normalizeDriver($driver);
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
            'pgsql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()',
        };

        return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<int, string>
     */
    public static function listColumns(PDO $pdo, string $driver, string $table): array
    {
        $driver = self::normalizeDriver($driver);
        $statement = $driver === 'pgsql'
            ? $pdo->prepare(self::columnInspectionSql($driver, $table))
            : null;

        if ($statement !== null) {
            $statement->execute([':table' => self::assertIdentifier($table)]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $pdo->query(self::columnInspectionSql($driver, $table))->fetchAll(PDO::FETCH_ASSOC);
        }

        return array_values(array_filter(array_map(static function (array $row) use ($driver): string {
            return match ($driver) {
                'sqlite' => (string) ($row['name'] ?? ''),
                'mysql' => (string) ($row['Field'] ?? ''),
                'pgsql' => (string) ($row['column_name'] ?? ''),
            };
        }, $rows)));
    }

    /**
     * @return array<int, string>
     */
    public static function schemaStatements(string $driver): array
    {
        $driver = self::normalizeDriver($driver);
        $id = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
        };
        $refId = match ($driver) {
            'sqlite' => 'INTEGER',
            'mysql' => 'BIGINT UNSIGNED',
            'pgsql' => 'BIGINT',
        };
        $bool = match ($driver) {
            'sqlite' => 'INTEGER',
            'mysql' => 'TINYINT(1)',
            'pgsql' => 'SMALLINT',
        };
        $bytes = match ($driver) {
            'sqlite' => 'INTEGER',
            'mysql' => 'BIGINT',
            'pgsql' => 'BIGINT',
        };
        $shortText = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
        $tokenText = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
        $ipText = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(45)';
        $timestamp = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
        $description = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(1000)';
        $messageText = 'TEXT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        return [
            'CREATE TABLE IF NOT EXISTS users (
                id ' . $id . ',
                username ' . $shortText . ' NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role ' . $shortText . ' NOT NULL CHECK (role IN (\'super_admin\', \'admin\', \'user\')),
                status ' . $shortText . ' NOT NULL CHECK (status IN (\'active\', \'suspended\')),
                force_password_reset ' . $bool . ' NOT NULL DEFAULT 0,
                is_immutable ' . $bool . ' NOT NULL DEFAULT 0,
                storage_quota_bytes ' . $bytes . ' NULL,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL,
                last_login_at ' . $timestamp . ' NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS settings (
                key ' . $shortText . ' PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS folders (
                id ' . $id . ',
                parent_id ' . $refId . ' NULL REFERENCES folders(id) ON DELETE CASCADE,
                name ' . $shortText . ' NOT NULL,
                description ' . $description . ' NOT NULL DEFAULT \'\',
                cached_size_bytes ' . $bytes . ' NULL,
                cached_size_calculated_at ' . $timestamp . ' NULL,
                created_by ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL,
                UNIQUE (parent_id, name)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS files (
                id ' . $id . ',
                folder_id ' . $refId . ' NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
                original_name ' . $shortText . ' NOT NULL,
                disk_name ' . $tokenText . ' NOT NULL UNIQUE,
                disk_extension ' . $shortText . ' NOT NULL,
                mime_type ' . $shortText . ' NOT NULL,
                size ' . $bytes . ' NOT NULL,
                description ' . $description . ' NOT NULL DEFAULT \'\',
                checksum ' . $tokenText . ' NOT NULL,
                created_by ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS file_shares (
                id ' . $id . ',
                file_id ' . $refId . ' NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                active_file_id ' . $refId . ' NULL,
                token ' . $tokenText . ' NOT NULL UNIQUE,
                created_by ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                expires_at ' . $timestamp . ' NULL,
                max_views INTEGER NULL,
                view_count INTEGER NOT NULL DEFAULT 0,
                password_hash TEXT NULL,
                password_version INTEGER NOT NULL DEFAULT 0,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL,
                revoked_at ' . $timestamp . ' NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS folder_permissions (
                id ' . $id . ',
                folder_id ' . $refId . ' NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
                principal_type ' . $shortText . ' NOT NULL CHECK (principal_type IN (\'user\', \'guest\')),
                principal_id ' . $refId . ' NOT NULL DEFAULT 0,
                can_view ' . $bool . ' NOT NULL DEFAULT 0,
                can_upload ' . $bool . ' NOT NULL DEFAULT 0,
                can_edit ' . $bool . ' NOT NULL DEFAULT 0,
                can_delete ' . $bool . ' NOT NULL DEFAULT 0,
                can_create_folders ' . $bool . ' NOT NULL DEFAULT 0,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL,
                UNIQUE (folder_id, principal_type, principal_id)
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id ' . $id . ',
                username ' . $shortText . ' NOT NULL,
                ip_address ' . $ipText . ' NOT NULL,
                success ' . $bool . ' NOT NULL DEFAULT 0,
                attempted_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS rate_limits (
                bucket_key ' . $shortText . ' PRIMARY KEY,
                scope ' . $shortText . ' NOT NULL,
                bucket_identifier ' . $shortText . ' NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                window_started_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id ' . $id . ',
                event_type ' . $shortText . ' NOT NULL,
                category ' . $shortText . ' NOT NULL,
                actor_user_id ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                actor_username ' . $shortText . ' NULL,
                ip_address ' . $ipText . ' NOT NULL,
                target_type ' . $shortText . ' NULL,
                target_id ' . $refId . ' NULL,
                target_label ' . $description . ' NULL,
                metadata_json TEXT NOT NULL,
                created_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS ip_bans (
                id ' . $id . ',
                ip_address ' . $ipText . ' NOT NULL,
                active_ip_address ' . $ipText . ' NULL,
                reason ' . $description . ' NOT NULL,
                created_by ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                created_by_username ' . $shortText . ' NULL,
                created_at ' . $timestamp . ' NOT NULL,
                expires_at ' . $timestamp . ' NULL,
                revoked_at ' . $timestamp . ' NULL,
                revoked_by ' . $refId . ' NULL REFERENCES users(id) ON DELETE SET NULL,
                revoked_by_username ' . $shortText . ' NULL,
                revoked_reason ' . $shortText . ' NULL CHECK (revoked_reason IN (\'manual\', \'expired\')),
                updated_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE TABLE IF NOT EXISTS automation_jobs (
                job_key ' . $shortText . ' PRIMARY KEY,
                label ' . $shortText . ' NOT NULL,
                status ' . $shortText . ' NOT NULL DEFAULT \'idle\',
                last_result ' . $shortText . ' NOT NULL DEFAULT \'idle\',
                last_message ' . $messageText . ' NOT NULL,
                last_run_at ' . $timestamp . ' NULL,
                next_run_at ' . $timestamp . ' NULL,
                last_duration_ms INTEGER NOT NULL DEFAULT 0,
                created_at ' . $timestamp . ' NOT NULL,
                updated_at ' . $timestamp . ' NOT NULL
            )' . $engine,
            'CREATE INDEX IF NOT EXISTS idx_folders_parent_id ON folders(parent_id)',
            'CREATE INDEX IF NOT EXISTS idx_files_folder_id ON files(folder_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_file_shares_active_file ON file_shares(active_file_id)',
            'CREATE INDEX IF NOT EXISTS idx_permissions_principal ON folder_permissions(principal_type, principal_id)',
            'CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username, ip_address, attempted_at)',
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_updated_at ON rate_limits(updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_category_created_at ON audit_logs(category, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_ip_created_at ON audit_logs(ip_address, created_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_ip_bans_active_ip ON ip_bans(active_ip_address)',
            'CREATE INDEX IF NOT EXISTS idx_ip_bans_history ON ip_bans(ip_address, revoked_at, expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_automation_jobs_next_run ON automation_jobs(next_run_at)',
        ];
    }

    private static function assertIdentifier(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return $value;
    }
}
