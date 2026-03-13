<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use WbFileBrowser\DatabasePlatform;

final class DatabaseIntegrationSmokeTest extends TestCase
{
    public function testMysqlSmokeConnectionWhenConfigured(): void
    {
        $this->runSmoke('mysql', 'pdo_mysql', 'WB_TEST_MYSQL_DSN', 'WB_TEST_MYSQL_USER', 'WB_TEST_MYSQL_PASSWORD');
    }

    public function testPostgresSmokeConnectionWhenConfigured(): void
    {
        $this->runSmoke('pgsql', 'pdo_pgsql', 'WB_TEST_PGSQL_DSN', 'WB_TEST_PGSQL_USER', 'WB_TEST_PGSQL_PASSWORD');
    }

    private function runSmoke(
        string $driver,
        string $extension,
        string $dsnEnv,
        string $userEnv,
        string $passwordEnv
    ): void {
        if (!extension_loaded($extension)) {
            $this->markTestSkipped($extension . ' is not available in this PHP runtime.');
        }

        $dsn = trim((string) getenv($dsnEnv));

        if ($dsn === '') {
            $this->markTestSkipped($dsnEnv . ' is not configured.');
        }

        $username = getenv($userEnv);
        $password = getenv($passwordEnv);
        $pdo = new PDO(
            $dsn,
            $username === false ? null : $username,
            $password === false ? null : $password
        );

        DatabasePlatform::configureConnection($pdo, ['driver' => $driver]);

        $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }
}
