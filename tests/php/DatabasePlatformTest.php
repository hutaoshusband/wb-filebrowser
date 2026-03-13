<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use WbFileBrowser\DatabasePlatform;

final class DatabasePlatformTest extends TestCase
{
    public function testBuildsDriverSpecificDsnStrings(): void
    {
        $this->assertSame(
            'sqlite:' . WB_STORAGE . DIRECTORY_SEPARATOR . 'app.sqlite',
            DatabasePlatform::dsn([
                'driver' => 'sqlite',
                'path' => WB_STORAGE . DIRECTORY_SEPARATOR . 'app.sqlite',
            ])
        );
        $this->assertSame(
            'mysql:host=db.internal;port=3306;dbname=files;charset=utf8mb4',
            DatabasePlatform::dsn([
                'driver' => 'mysql',
                'host' => 'db.internal',
                'port' => 3306,
                'name' => 'files',
            ])
        );
        $this->assertSame(
            'pgsql:host=db.internal;port=5432;dbname=files',
            DatabasePlatform::dsn([
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'name' => 'files',
            ])
        );
    }

    public function testBuildsDriverSpecificUpsertSyntax(): void
    {
        $sqlite = DatabasePlatform::upsertSql(
            'sqlite',
            'settings',
            ['key', 'value', 'updated_at'],
            ['value', 'updated_at'],
            ['key']
        );
        $mysql = DatabasePlatform::upsertSql(
            'mysql',
            'settings',
            ['key', 'value', 'updated_at'],
            ['value', 'updated_at'],
            ['key']
        );
        $pgsql = DatabasePlatform::upsertSql(
            'pgsql',
            'settings',
            ['key', 'value', 'updated_at'],
            ['value', 'updated_at'],
            ['key']
        );

        $this->assertStringContainsString('ON CONFLICT(key) DO UPDATE', $sqlite);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $mysql);
        $this->assertStringContainsString('ON CONFLICT(key) DO UPDATE', $pgsql);
    }

    public function testBuildsDriverSpecificColumnInspectionQueries(): void
    {
        $this->assertSame('PRAGMA table_info(users)', DatabasePlatform::columnInspectionSql('sqlite', 'users'));
        $this->assertSame('SHOW COLUMNS FROM users', DatabasePlatform::columnInspectionSql('mysql', 'users'));
        $this->assertStringContainsString('information_schema.columns', DatabasePlatform::columnInspectionSql('pgsql', 'users'));
    }

    public function testBuildsDriverSpecificSchemaStatements(): void
    {
        $sqliteSchema = implode("\n", DatabasePlatform::schemaStatements('sqlite'));
        $mysqlSchema = implode("\n", DatabasePlatform::schemaStatements('mysql'));
        $pgsqlSchema = implode("\n", DatabasePlatform::schemaStatements('pgsql'));

        $this->assertStringContainsString('AUTOINCREMENT', $sqliteSchema);
        $this->assertStringContainsString('AUTO_INCREMENT', $mysqlSchema);
        $this->assertStringContainsString('BIGSERIAL PRIMARY KEY', $pgsqlSchema);
        $this->assertStringContainsString('active_file_id', $mysqlSchema);
        $this->assertStringContainsString('active_ip_address', $pgsqlSchema);
    }

    public function testSequenceNameIsDerivedForPostgresTables(): void
    {
        $this->assertNull(DatabasePlatform::sequenceName('sqlite', 'users'));
        $this->assertNull(DatabasePlatform::sequenceName('mysql', 'users'));
        $this->assertSame('users_id_seq', DatabasePlatform::sequenceName('pgsql', 'users'));
    }
}
