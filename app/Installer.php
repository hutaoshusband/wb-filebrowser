<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class Installer
{
    public const VERSION = '1.0.0-alpha';

    public static function isInstalled(): bool
    {
        return is_file(self::lockFilePath()) && is_file(self::databasePath());
    }

    public static function databasePath(): string
    {
        return wb_storage_path('app.sqlite');
    }

    public static function lockFilePath(): string
    {
        return wb_storage_path('installed.lock');
    }

    public static function ensureRuntimeDirectories(): void
    {
        foreach ([wb_storage_path(), wb_storage_path('sessions')] as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('Unable to create runtime directory: ' . $path);
            }
        }
    }

    public static function environmentChecks(): array
    {
        $storageWritable = is_writable(wb_storage_path());

        return [
            [
                'label' => 'PHP 8.1 or newer',
                'ok' => PHP_VERSION_ID >= 80100,
                'message' => 'Current version: ' . PHP_VERSION,
            ],
            [
                'label' => 'pdo_sqlite extension',
                'ok' => extension_loaded('pdo_sqlite'),
                'message' => extension_loaded('pdo_sqlite') ? 'Enabled' : 'Missing',
            ],
            [
                'label' => 'fileinfo extension',
                'ok' => extension_loaded('fileinfo'),
                'message' => extension_loaded('fileinfo') ? 'Enabled' : 'Missing',
            ],
            [
                'label' => 'mbstring extension',
                'ok' => extension_loaded('mbstring'),
                'message' => extension_loaded('mbstring') ? 'Enabled' : 'Missing',
            ],
            [
                'label' => 'Storage directory writable',
                'ok' => $storageWritable,
                'message' => $storageWritable ? 'Writable' : 'Not writable! Run: chmod -R 775 storage/',
            ],
            [
                'label' => 'Session directory writable',
                'ok' => is_writable(wb_storage_path('sessions')),
                'message' => is_writable(wb_storage_path('sessions')) ? 'Writable' : 'Not writable! Run: chmod -R 775 storage/sessions/',
            ],
        ];
    }

    public static function install(string $username, string $password, array $settings = []): array
    {
        if (self::isInstalled()) {
            throw new RuntimeException('wb-filebrowser is already installed.');
        }

        foreach (self::environmentChecks() as $check) {
            if (!$check['ok']) {
                throw new RuntimeException('Installation requirements are not met.');
            }
        }

        $username = wb_validate_entry_name($username, 'username');

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('The Super-Admin password must be at least 12 characters long.');
        }

        self::createStorageLayout();
        self::writeStorageShield();

        $createdDatabase = false;

        try {
            $databasePath = self::databasePath();

            if (!file_exists($databasePath)) {
                touch($databasePath);
                $createdDatabase = true;
            }

            $pdo = new PDO('sqlite:' . $databasePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');

            foreach (self::schemaStatements() as $statement) {
                $pdo->exec($statement);
            }

            $now = wb_now();
            $passwordHash = Security::hashPassword($password);

            $pdo->beginTransaction();

            $userStatement = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, status, force_password_reset, is_immutable, created_at, updated_at)
                 VALUES (:username, :password_hash, :role, :status, 0, 1, :created_at, :updated_at)'
            );
            $userStatement->execute([
                ':username' => $username,
                ':password_hash' => $passwordHash,
                ':role' => 'super_admin',
                ':status' => 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $superAdminId = (int) $pdo->lastInsertId();

            $folderStatement = $pdo->prepare(
                'INSERT INTO folders (parent_id, name, created_by, created_at, updated_at)
                 VALUES (NULL, :name, :created_by, :created_at, :updated_at)'
            );
            $folderStatement->execute([
                ':name' => 'Home',
                ':created_by' => $superAdminId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $rootFolderId = (int) $pdo->lastInsertId();

            $probeName = 'public-' . wb_random_token(10) . '.txt';
            $probeRelativePath = 'probe/' . $probeName;
            file_put_contents(wb_storage_path($probeRelativePath), "wb-filebrowser diagnostic probe\n");

            Settings::seedDefaults(
                $pdo,
                Settings::installOverrides($rootFolderId, $probeRelativePath, $settings),
                true
            );
            AutomationRunner::seedJobs($pdo);

            $pdo->commit();

            file_put_contents(
                self::lockFilePath(),
                json_encode([
                    'version' => self::VERSION,
                    'installed_at' => $now,
                    'super_admin_id' => $superAdminId,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return [
                'super_admin_id' => $superAdminId,
                'root_folder_id' => $rootFolderId,
            ];
        } catch (\Throwable $exception) {
            if ($createdDatabase && file_exists(self::databasePath())) {
                @unlink(self::databasePath());
            }

            if (file_exists(self::lockFilePath())) {
                @unlink(self::lockFilePath());
            }

            throw $exception;
        }
    }

    public static function createStorageLayout(): void
    {
        $directories = [
            wb_storage_path(),
            wb_storage_path('uploads'),
            wb_storage_path('chunks'),
            wb_storage_path('sessions'),
            wb_storage_path('logs'),
            wb_storage_path('probe'),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: ' . $directory);
            }
        }
    }

    public static function writeStorageShield(): void
    {
        $contents = <<<HTACCESS
Options -Indexes
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
HTACCESS;

        file_put_contents(wb_storage_path('.htaccess'), $contents);
        
        $webConfig = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <handlers>
            <clear />
        </handlers>
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment="." />
                </hiddenSegments>
                <denyUrlSequences>
                    <add sequence="/" />
                </denyUrlSequences>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
XML;
        file_put_contents(wb_storage_path('web.config'), $webConfig);
    }

    public static function migrate(): void
    {
        if (!self::isInstalled()) {
            return;
        }

        $pdo = new PDO('sqlite:' . self::databasePath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach (self::schemaStatements() as $statement) {
            $pdo->exec($statement);
        }

        self::ensureTableColumns($pdo, 'users', [
            'storage_quota_bytes INTEGER NULL',
        ]);
        self::ensureTableColumns($pdo, 'file_shares', [
            'expires_at TEXT NULL',
            'max_views INTEGER NULL',
            'view_count INTEGER NOT NULL DEFAULT 0',
        ]);
        self::ensureTableColumns($pdo, 'folder_permissions', [
            'can_edit INTEGER NOT NULL DEFAULT 0',
            'can_delete INTEGER NOT NULL DEFAULT 0',
            'can_create_folders INTEGER NOT NULL DEFAULT 0',
        ]);

        Settings::seedDefaults($pdo);
        $statement = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':key' => 'app_version',
            ':value' => self::VERSION,
            ':updated_at' => wb_now(),
        ]);
        AutomationRunner::seedJobs($pdo);
    }

    private static function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN (\'super_admin\', \'admin\', \'user\')),
                status TEXT NOT NULL CHECK (status IN (\'active\', \'suspended\')),
                force_password_reset INTEGER NOT NULL DEFAULT 0,
                is_immutable INTEGER NOT NULL DEFAULT 0,
                storage_quota_bytes INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                last_login_at TEXT
            )',
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL REFERENCES folders(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (parent_id, name)
            )',
            'CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
                original_name TEXT NOT NULL,
                disk_name TEXT NOT NULL UNIQUE,
                disk_extension TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size INTEGER NOT NULL,
                checksum TEXT NOT NULL,
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS file_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                token TEXT NOT NULL UNIQUE,
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                expires_at TEXT NULL,
                max_views INTEGER NULL,
                view_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                revoked_at TEXT NULL
            )',
            'CREATE TABLE IF NOT EXISTS folder_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
                principal_type TEXT NOT NULL CHECK (principal_type IN (\'user\', \'guest\')),
                principal_id INTEGER NOT NULL DEFAULT 0,
                can_view INTEGER NOT NULL DEFAULT 0,
                can_upload INTEGER NOT NULL DEFAULT 0,
                can_edit INTEGER NOT NULL DEFAULT 0,
                can_delete INTEGER NOT NULL DEFAULT 0,
                can_create_folders INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (folder_id, principal_type, principal_id)
            )',
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                success INTEGER NOT NULL DEFAULT 0,
                attempted_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS rate_limits (
                bucket_key TEXT PRIMARY KEY,
                scope TEXT NOT NULL,
                bucket_identifier TEXT NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                window_started_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                category TEXT NOT NULL,
                actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                actor_username TEXT NULL,
                ip_address TEXT NOT NULL,
                target_type TEXT NULL,
                target_id INTEGER NULL,
                target_label TEXT NULL,
                metadata_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS ip_bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_by_username TEXT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NULL,
                revoked_at TEXT NULL,
                revoked_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                revoked_by_username TEXT NULL,
                revoked_reason TEXT NULL CHECK (revoked_reason IN (\'manual\', \'expired\')),
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS automation_jobs (
                job_key TEXT PRIMARY KEY,
                label TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'idle\',
                last_result TEXT NOT NULL DEFAULT \'idle\',
                last_message TEXT NOT NULL DEFAULT \'\',
                last_run_at TEXT NULL,
                next_run_at TEXT NULL,
                last_duration_ms INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_folders_parent_id ON folders(parent_id)',
            'CREATE INDEX IF NOT EXISTS idx_files_folder_id ON files(folder_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_file_shares_active_file ON file_shares(file_id) WHERE revoked_at IS NULL',
            'CREATE INDEX IF NOT EXISTS idx_permissions_principal ON folder_permissions(principal_type, principal_id)',
            'CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username, ip_address, attempted_at)',
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_updated_at ON rate_limits(updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_category_created_at ON audit_logs(category, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_ip_created_at ON audit_logs(ip_address, created_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_ip_bans_active_ip ON ip_bans(ip_address) WHERE revoked_at IS NULL',
            'CREATE INDEX IF NOT EXISTS idx_ip_bans_history ON ip_bans(ip_address, revoked_at, expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_automation_jobs_next_run ON automation_jobs(next_run_at)',
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    private static function ensureTableColumns(PDO $pdo, string $table, array $columns): void
    {
        $existing = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $columnMap = [];

        foreach ($existing as $column) {
            $columnMap[(string) ($column['name'] ?? '')] = true;
        }

        foreach ($columns as $definition) {
            $name = strtolower((string) strtok(trim($definition), ' '));

            if ($name === '' || isset($columnMap[$name])) {
                continue;
            }

            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
        }
    }
}
