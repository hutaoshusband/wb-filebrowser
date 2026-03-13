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
        return is_file(self::lockFilePath()) && (DatabaseConfig::exists() || is_file(wb_storage_path('app.sqlite')));
    }

    public static function databasePath(): string
    {
        $config = DatabaseConfig::read() ?? DatabaseConfig::loadInstalled();

        if (is_array($config) && (string) ($config['driver'] ?? '') === 'sqlite') {
            return (string) $config['path'];
        }

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
        $sessionWritable = is_writable(wb_storage_path('sessions'));

        return [
            [
                'key' => 'php',
                'label' => 'PHP 8.1 or newer',
                'ok' => PHP_VERSION_ID >= 80100,
                'message' => 'Current version: ' . PHP_VERSION,
                'blocking' => true,
                'scope' => 'core',
            ],
            [
                'key' => 'pdo_sqlite',
                'label' => 'pdo_sqlite extension',
                'ok' => extension_loaded('pdo_sqlite'),
                'message' => extension_loaded('pdo_sqlite') ? 'Enabled' : 'Missing',
                'blocking' => false,
                'scope' => 'driver',
                'driver' => 'sqlite',
            ],
            [
                'key' => 'pdo_mysql',
                'label' => 'pdo_mysql extension',
                'ok' => extension_loaded('pdo_mysql'),
                'message' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing',
                'blocking' => false,
                'scope' => 'driver',
                'driver' => 'mysql',
            ],
            [
                'key' => 'pdo_pgsql',
                'label' => 'pdo_pgsql extension',
                'ok' => extension_loaded('pdo_pgsql'),
                'message' => extension_loaded('pdo_pgsql') ? 'Enabled' : 'Missing',
                'blocking' => false,
                'scope' => 'driver',
                'driver' => 'pgsql',
            ],
            [
                'key' => 'fileinfo',
                'label' => 'fileinfo extension',
                'ok' => extension_loaded('fileinfo'),
                'message' => extension_loaded('fileinfo') ? 'Enabled' : 'Missing',
                'blocking' => true,
                'scope' => 'core',
            ],
            [
                'key' => 'mbstring',
                'label' => 'mbstring extension',
                'ok' => extension_loaded('mbstring'),
                'message' => extension_loaded('mbstring') ? 'Enabled' : 'Missing',
                'blocking' => true,
                'scope' => 'core',
            ],
            [
                'key' => 'storage',
                'label' => 'Storage directory writable',
                'ok' => $storageWritable,
                'message' => $storageWritable ? 'Writable' : 'Not writable! Run: chmod -R 775 storage/',
                'blocking' => true,
                'scope' => 'core',
            ],
            [
                'key' => 'sessions',
                'label' => 'Session directory writable',
                'ok' => $sessionWritable,
                'message' => $sessionWritable ? 'Writable' : 'Not writable! Run: chmod -R 775 storage/sessions/',
                'blocking' => true,
                'scope' => 'core',
            ],
        ];
    }

    public static function install(string $username, string $password, array $settings = []): array
    {
        if (self::isInstalled()) {
            throw new RuntimeException('wb-filebrowser is already installed.');
        }

        self::assertCoreEnvironmentChecks();
        $username = wb_validate_entry_name($username, 'username');

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('The Super-Admin password must be at least 12 characters long.');
        }

        $databaseInput = is_array($settings['database'] ?? null) ? $settings['database'] : [];
        $config = DatabaseConfig::normalize($databaseInput);
        self::assertDatabaseDriverAvailable((string) $config['driver']);
        self::assertDatabaseConfigValid($config);

        self::createStorageLayout();
        self::writeStorageShield();

        $pdo = null;
        $configWritten = false;
        $createdSqliteFile = false;
        $dropSchemaOnFailure = false;

        try {
            if ((string) $config['driver'] === 'sqlite') {
                self::ensureSqlitePathReady((string) $config['path']);
                $createdSqliteFile = !is_file((string) $config['path']);
            }

            $pdo = self::connect($config);
            self::assertInstallTargetIsEmpty($pdo, (string) $config['driver']);
            DatabaseConfig::write($config);
            $configWritten = true;
            $dropSchemaOnFailure = true;

            foreach (DatabasePlatform::schemaStatements((string) $config['driver']) as $statement) {
                self::executeSchemaStatement($pdo, (string) $config['driver'], $statement);
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
            $superAdminId = DatabasePlatform::lastInsertId($pdo, (string) $config['driver'], 'users');

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
            $rootFolderId = DatabasePlatform::lastInsertId($pdo, (string) $config['driver'], 'folders');

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
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($dropSchemaOnFailure && $pdo instanceof PDO) {
                self::dropKnownSchema($pdo);
            }

            if ($createdSqliteFile && is_file((string) $config['path'])) {
                @unlink((string) $config['path']);
            }

            if ($configWritten && DatabaseConfig::exists()) {
                @unlink(DatabaseConfig::path());
            }

            if (is_file(self::lockFilePath())) {
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

        $config = DatabaseConfig::loadInstalled();

        if (!is_array($config)) {
            return;
        }

        $pdo = self::connect($config);

        $schemaStatements = DatabasePlatform::schemaStatements((string) $config['driver']);
        $tableStatements = array_slice($schemaStatements, 0, count(DatabasePlatform::coreTables()));
        $indexStatements = array_slice($schemaStatements, count(DatabasePlatform::coreTables()));

        foreach ($tableStatements as $statement) {
            self::executeSchemaStatement($pdo, (string) $config['driver'], $statement);
        }

        self::ensureTableColumns($pdo, 'users', [
            'storage_quota_bytes ' . self::bytesType((string) $config['driver']) . ' NULL',
        ], (string) $config['driver']);
        self::ensureTableColumns($pdo, 'file_shares', [
            'expires_at ' . self::timestampType((string) $config['driver']) . ' NULL',
            'max_views INTEGER NULL',
            'view_count INTEGER NOT NULL DEFAULT 0',
            'password_hash TEXT NULL',
            'password_version INTEGER NOT NULL DEFAULT 0',
            'active_file_id ' . self::referenceType((string) $config['driver']) . ' NULL',
        ], (string) $config['driver']);
        self::ensureTableColumns($pdo, 'folders', [
            'description ' . self::descriptionType((string) $config['driver']) . ' NOT NULL DEFAULT \'\'',
            'cached_size_bytes ' . self::bytesType((string) $config['driver']) . ' NULL',
            'cached_size_calculated_at ' . self::timestampType((string) $config['driver']) . ' NULL',
        ], (string) $config['driver']);
        self::ensureTableColumns($pdo, 'files', [
            'description ' . self::descriptionType((string) $config['driver']) . ' NOT NULL DEFAULT \'\'',
        ], (string) $config['driver']);
        self::ensureTableColumns($pdo, 'folder_permissions', [
            'can_edit ' . self::booleanType((string) $config['driver']) . ' NOT NULL DEFAULT 0',
            'can_delete ' . self::booleanType((string) $config['driver']) . ' NOT NULL DEFAULT 0',
            'can_create_folders ' . self::booleanType((string) $config['driver']) . ' NOT NULL DEFAULT 0',
        ], (string) $config['driver']);
        self::ensureTableColumns($pdo, 'ip_bans', [
            'active_ip_address ' . self::ipType((string) $config['driver']) . ' NULL',
        ], (string) $config['driver']);

        self::syncActiveConstraintColumns($pdo);

        foreach ($indexStatements as $statement) {
            self::executeSchemaStatement($pdo, (string) $config['driver'], $statement);
        }

        Settings::seedDefaults($pdo);
        $statement = $pdo->prepare(
            DatabasePlatform::upsertSql((string) $config['driver'], 'settings', ['key', 'value', 'updated_at'], ['value', 'updated_at'], ['key'])
        );
        $statement->execute([
            ':key' => 'app_version',
            ':value' => self::VERSION,
            ':updated_at' => wb_now(),
        ]);
        AutomationRunner::seedJobs($pdo);
    }

    /**
     * @param array<int, string> $columns
     */
    private static function ensureTableColumns(PDO $pdo, string $table, array $columns, string $driver): void
    {
        $existing = array_flip(array_map('strtolower', DatabasePlatform::listColumns($pdo, $driver, $table)));

        foreach ($columns as $definition) {
            $name = strtolower((string) strtok(trim($definition), ' '));

            if ($name === '' || isset($existing[$name])) {
                continue;
            }

            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connect(array $config): PDO
    {
        try {
            $pdo = new PDO(
                DatabasePlatform::dsn($config),
                (string) ($config['driver'] === 'sqlite' ? null : ($config['username'] ?? '')),
                (string) ($config['driver'] === 'sqlite' ? null : ($config['password'] ?? ''))
            );
        } catch (\PDOException) {
            throw new RuntimeException('Unable to connect to the selected database.');
        }

        DatabasePlatform::configureConnection($pdo, $config);

        return $pdo;
    }

    private static function assertCoreEnvironmentChecks(): void
    {
        foreach (self::environmentChecks() as $check) {
            if (($check['blocking'] ?? false) && !($check['ok'] ?? false)) {
                throw new RuntimeException('Installation requirements are not met.');
            }
        }
    }

    private static function assertDatabaseDriverAvailable(string $driver): void
    {
        $extension = match ($driver) {
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            default => throw new RuntimeException('Unsupported database driver.'),
        };

        if (!extension_loaded($extension)) {
            throw new RuntimeException('The selected database driver is not available.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertDatabaseConfigValid(array $config): void
    {
        $driver = (string) $config['driver'];

        if ($driver === 'sqlite') {
            if (trim((string) ($config['path'] ?? '')) === '') {
                throw new RuntimeException('SQLite database path is required.');
            }

            return;
        }

        foreach (['host' => 'Database host', 'name' => 'Database name', 'username' => 'Database username'] as $key => $label) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new RuntimeException($label . ' is required.');
            }
        }
    }

    private static function ensureSqlitePathReady(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the selected SQLite directory.');
        }
    }

    private static function assertInstallTargetIsEmpty(PDO $pdo, string $driver): void
    {
        $tables = DatabasePlatform::tableNames($pdo, $driver);

        if ($tables === []) {
            return;
        }

        $matches = array_values(array_intersect($tables, DatabasePlatform::coreTables()));

        if ($matches !== []) {
            throw new RuntimeException('The selected database already contains wb-filebrowser tables.');
        }

        throw new RuntimeException('The selected database must be empty before installation.');
    }

    private static function dropKnownSchema(PDO $pdo): void
    {
        $tables = array_reverse(DatabasePlatform::coreTables());

        foreach ($tables as $table) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private static function syncActiveConstraintColumns(PDO $pdo): void
    {
        $pdo->exec(
            'UPDATE file_shares
             SET active_file_id = CASE WHEN revoked_at IS NULL THEN file_id ELSE NULL END'
        );
        $statement = $pdo->prepare(
            'UPDATE ip_bans
             SET active_ip_address = CASE
                 WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)
                 THEN ip_address
                 ELSE NULL
             END'
        );
        $statement->execute([':now' => wb_now()]);
    }

    private static function executeSchemaStatement(PDO $pdo, string $driver, string $statement): void
    {
        if (preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+([A-Za-z0-9_]+)\s+ON\s+([A-Za-z0-9_]+)/i', $statement, $matches) === 1) {
            $indexName = $matches[2];
            $tableName = $matches[3];

            if (self::indexExists($pdo, $driver, $tableName, $indexName)) {
                return;
            }

            $statement = preg_replace('/\s+IF\s+NOT\s+EXISTS/i', '', $statement) ?? $statement;
        }

        $pdo->exec($statement);
    }

    private static function indexExists(PDO $pdo, string $driver, string $table, string $index): bool
    {
        $table = self::assertIdentifier($table);
        $index = self::assertIdentifier($index);

        return match ($driver) {
            'sqlite' => self::sqliteIndexExists($pdo, $table, $index),
            'mysql' => self::mysqlIndexExists($pdo, $table, $index),
            'pgsql' => self::pgsqlIndexExists($pdo, $table, $index),
            default => false,
        };
    }

    private static function sqliteIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $rows = $pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }

    private static function mysqlIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->query('SHOW INDEX FROM ' . $table);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ((string) ($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }

    private static function pgsqlIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = :table
               AND indexname = :index
             LIMIT 1'
        );
        $statement->execute([
            ':table' => $table,
            ':index' => $index,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private static function assertIdentifier(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new RuntimeException('Invalid database identifier.');
        }

        return $value;
    }

    private static function booleanType(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'TINYINT(1)',
            'pgsql' => 'SMALLINT',
            default => 'INTEGER',
        };
    }

    private static function referenceType(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'BIGINT UNSIGNED',
            'pgsql' => 'BIGINT',
            default => 'INTEGER',
        };
    }

    private static function bytesType(string $driver): string
    {
        return $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    }

    private static function timestampType(string $driver): string
    {
        return $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
    }

    private static function descriptionType(string $driver): string
    {
        return $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(1000)';
    }

    private static function ipType(string $driver): string
    {
        return $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(45)';
    }
}
