<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use WbFileBrowser\Database;
use WbFileBrowser\DatabaseConfig;
use WbFileBrowser\Installer;

final class DatabaseConfigTest extends TestCase
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

    public function testWriteAndReadNormalizeSqliteConfig(): void
    {
        Installer::ensureRuntimeDirectories();

        DatabaseConfig::write([
            'driver' => 'sqlite',
            'path' => 'storage/nested/app.sqlite',
        ]);

        $this->assertFileExists(DatabaseConfig::path());

        $config = DatabaseConfig::read();
        $this->assertIsArray($config);
        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame(
            WB_STORAGE . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'app.sqlite',
            $config['path']
        );
    }

    public function testLoadInstalledMigratesLegacySqliteConfig(): void
    {
        Installer::ensureRuntimeDirectories();
        touch(Installer::lockFilePath());
        touch(WB_STORAGE . DIRECTORY_SEPARATOR . 'app.sqlite');

        $config = DatabaseConfig::loadInstalled();

        $this->assertSame('sqlite', $config['driver']);
        $this->assertFileExists(DatabaseConfig::path());
        $this->assertSame(WB_STORAGE . DIRECTORY_SEPARATOR . 'app.sqlite', $config['path']);
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
