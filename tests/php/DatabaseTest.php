<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class DatabaseTest extends DatabaseTestCase
{
    public function testRootFolderIdQueriesDatabase(): void
    {
        // Installer::install() creates a Home folder.
        // We verify that calling rootFolderId() correctly returns its ID (which should be 1).
        $rootId = Database::rootFolderId();

        $this->assertIsInt($rootId);
        $this->assertGreaterThan(0, $rootId);

        // Double check against a raw query to make sure it matches the database
        $actualId = Database::connection()
            ->query("SELECT id FROM folders WHERE parent_id IS NULL AND name = 'Home'")
            ->fetchColumn();

        $this->assertSame((int) $actualId, $rootId);
    }

    public function testRootFolderIdThrowsExceptionIfNotFound(): void
    {
        // Remove any Home folder to test the exception
        Database::connection()->exec("DELETE FROM folders WHERE name = 'Home'");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Root folder not found.');

        Database::rootFolderId();
    }
}
