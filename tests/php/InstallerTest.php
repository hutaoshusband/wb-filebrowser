<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\DatabaseConfig;
use WbFileBrowser\Installer;

final class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStorage();
        Database::disconnect();
    }

    protected function tearDown(): void
    {
        Database::disconnect();
        $this->resetStorage();
        parent::tearDown();
    }

    public function testEnsureRuntimeDirectoriesCreatesPaths(): void
    {
        $this->assertDirectoryDoesNotExist(wb_storage_path());
        $this->assertDirectoryDoesNotExist(wb_storage_path('sessions'));

        Installer::ensureRuntimeDirectories();

        $this->assertDirectoryExists(wb_storage_path());
        $this->assertDirectoryExists(wb_storage_path('sessions'));
    }

    public function testCreateStorageLayoutCreatesSubdirectories(): void
    {
        $this->assertDirectoryDoesNotExist(wb_storage_path());

        Installer::createStorageLayout();

        $this->assertDirectoryExists(wb_storage_path());
        $this->assertDirectoryExists(wb_storage_path('uploads'));
        $this->assertDirectoryExists(wb_storage_path('chunks'));
        $this->assertDirectoryExists(wb_storage_path('sessions'));
        $this->assertDirectoryExists(wb_storage_path('logs'));
        $this->assertDirectoryExists(wb_storage_path('probe'));
    }

    public function testWriteStorageShieldCreatesHtaccessAndWebConfig(): void
    {
        Installer::createStorageLayout();

        $this->assertFileDoesNotExist(wb_storage_path('.htaccess'));
        $this->assertFileDoesNotExist(wb_storage_path('web.config'));

        Installer::writeStorageShield();

        $this->assertFileExists(wb_storage_path('.htaccess'));
        $this->assertFileExists(wb_storage_path('web.config'));

        $htaccess = file_get_contents(wb_storage_path('.htaccess'));
        $this->assertStringContainsString('Require all denied', $htaccess);

        $webConfig = file_get_contents(wb_storage_path('web.config'));
        $this->assertStringContainsString('<denyUrlSequences>', $webConfig);
    }

    public function testEnvironmentChecksReturnsExpectedArrayStructure(): void
    {
        // Need to make sure storage exists so 'Storage directory writable' can be realistically assessed
        Installer::createStorageLayout();

        $checks = Installer::environmentChecks();

        $this->assertIsArray($checks);
        $this->assertNotEmpty($checks);

        $labels = array_column($checks, 'label');
        $this->assertContains('PHP 8.1 or newer', $labels);
        $this->assertContains('pdo_sqlite extension', $labels);
        $this->assertContains('Storage directory writable', $labels);

        foreach ($checks as $check) {
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('ok', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('blocking', $check);
            $this->assertArrayHasKey('scope', $check);
            $this->assertIsBool($check['ok']);
            $this->assertIsBool($check['blocking']);
        }
    }

    public function testInstallCreatesDatabaseAndSuperAdmin(): void
    {
        Installer::ensureRuntimeDirectories();
        $result = Installer::install('superadmin', 'SuperSecurePass123!');

        $this->assertArrayHasKey('super_admin_id', $result);
        $this->assertArrayHasKey('root_folder_id', $result);
        $this->assertTrue(Installer::isInstalled());

        $this->assertFileExists(Installer::databasePath());
        $this->assertFileExists(DatabaseConfig::path());
        $this->assertFileExists(Installer::lockFilePath());

        $db = Database::connection();
        $stmt = $db->query('SELECT COUNT(*) FROM users');
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt = $db->query("SELECT username FROM users WHERE id = {$result['super_admin_id']}");
        $this->assertEquals('superadmin', $stmt->fetchColumn());
    }

    public function testInstallSupportsCustomSqlitePathAndWritesConfig(): void
    {
        Installer::ensureRuntimeDirectories();

        $result = Installer::install('superadmin', 'SuperSecurePass123!', [
            'database' => [
                'driver' => 'sqlite',
                'path' => 'storage/custom-data/app.sqlite',
            ],
        ]);

        $this->assertGreaterThan(0, $result['super_admin_id']);
        $this->assertFileExists(WB_STORAGE . DIRECTORY_SEPARATOR . 'custom-data' . DIRECTORY_SEPARATOR . 'app.sqlite');

        $config = DatabaseConfig::read();
        $this->assertIsArray($config);
        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame(
            WB_STORAGE . DIRECTORY_SEPARATOR . 'custom-data' . DIRECTORY_SEPARATOR . 'app.sqlite',
            $config['path']
        );
    }

    public function testInstallThrowsIfPasswordTooShort(): void
    {
        Installer::ensureRuntimeDirectories();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Super-Admin password must be at least 12 characters long.');

        Installer::install('superadmin', 'short');
    }

    public function testInstallThrowsIfAlreadyInstalled(): void
    {
        Installer::ensureRuntimeDirectories();
        Installer::install('superadmin', 'SuperSecurePass123!');
        $this->assertTrue(Installer::isInstalled());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wb-filebrowser is already installed.');

        Installer::install('anotheradmin', 'SuperSecurePass123!');
    }

    public function testMigrateUpdatesAppVersion(): void
    {
        Installer::ensureRuntimeDirectories();
        Installer::install('superadmin', 'SuperSecurePass123!');
        
        $db = Database::connection();
        // Change version to test if migrate puts it back
        $db->exec("UPDATE settings SET value = '0.9.0' WHERE key = 'app_version'");
        
        $this->assertEquals('0.9.0', Database::setting('app_version'));

        Installer::migrate();

        $this->assertEquals(Installer::VERSION, Database::setting('app_version'));
    }

    public function testLegacyInstallStateIsRecognizedWithoutConfigFile(): void
    {
        Installer::ensureRuntimeDirectories();

        touch(Installer::lockFilePath());
        touch(WB_STORAGE . DIRECTORY_SEPARATOR . 'app.sqlite');

        $this->assertFalse(DatabaseConfig::exists());
        $this->assertTrue(Installer::isInstalled());
    }

    private function resetStorage(): void
    {
        if (is_dir(WB_STORAGE)) {
            $this->deleteDirectory(WB_STORAGE);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
