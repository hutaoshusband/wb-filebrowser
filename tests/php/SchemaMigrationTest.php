<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Database;
use WbFileBrowser\DatabasePlatform;
use WbFileBrowser\FileManager;
use WbFileBrowser\Installer;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

/**
 * Simulates an installation from before deduplication, share "Deletion
 * after" and per-user spaces existed: the new columns and tables are
 * removed from a populated database and the new settings keys are deleted.
 * The boot-time migration must then restore a complete schema while
 * keeping every pre-existing row byte-for-byte intact.
 */
final class SchemaMigrationTest extends DatabaseTestCase
{
    private const NEW_SETTINGS_KEYS = [
        'dedup_enabled',
        'file_blobs_backfill_v1',
        'automation_share_deletion_interval_minutes',
        'spaces_enabled',
        'spaces_user_sharing_allowed',
        'spaces_max_grant_level',
        'spaces_auto_create_on_user_create',
    ];

    public function testMigrationRebuildsNewSchemaAndPreservesOldData(): void
    {
        $pdo = Database::connection();

        // Populate with representative pre-feature data.
        $member = $this->createUser('legacy-member', 'user');
        $folder = $this->createFolder('Legacy folder');
        $file = $this->createFile('legacy-file.txt', 'legacy contents', 'text/plain', (int) $folder['id'], $member);
        $duplicate = $this->createFile('legacy-duplicate.txt', 'legacy contents', 'text/plain', (int) $folder['id'], $member);

        $shareStatement = $pdo->prepare(
            'INSERT INTO file_shares (file_id, active_file_id, token, created_by, expires_at, delete_after, max_views, view_count, password_hash, password_version, created_at, updated_at, revoked_at)
             VALUES (:file_id, :active_file_id, :token, :created_by, NULL, NULL, 2, 1, NULL, 0, :created_at, :updated_at, NULL)'
        );
        $shareStatement->execute([
            ':file_id' => (int) $file['id'],
            ':active_file_id' => (int) $file['id'],
            ':token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ':created_by' => (int) $member['id'],
            ':created_at' => wb_now(),
            ':updated_at' => wb_now(),
        ]);
        $shareId = (int) $pdo->lastInsertId();

        // Rewind to the pre-feature schema: drop the new columns, tables,
        // indexes and settings keys.
        $pdo->exec('DROP INDEX IF EXISTS idx_files_blob_id');
        $pdo->exec('DROP INDEX IF EXISTS idx_spaces_status');
        $pdo->exec('DROP INDEX IF EXISTS idx_file_shares_delete_after');
        $pdo->exec('DROP TABLE IF EXISTS spaces');
        $pdo->exec('DROP TABLE IF EXISTS file_blobs');
        $pdo->exec('ALTER TABLE files DROP COLUMN blob_id');
        $pdo->exec('ALTER TABLE file_shares DROP COLUMN delete_after');

        foreach (self::NEW_SETTINGS_KEYS as $key) {
            $pdo->prepare('DELETE FROM settings WHERE key = :key')->execute([':key' => $key]);
        }

        $this->assertNotContains('blob_id', DatabasePlatform::listColumns(Database::connection(), Database::driver(), 'files'));
        $this->assertNotContains('delete_after', DatabasePlatform::listColumns(Database::connection(), Database::driver(), 'file_shares'));

        $usersBefore = $this->snapshot('SELECT id, username, role, status FROM users ORDER BY id');
        $foldersBefore = $this->snapshot('SELECT id, parent_id, name FROM folders ORDER BY id');
        $filesBefore = $this->snapshot('SELECT id, folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum FROM files ORDER BY id');
        $sharesBefore = $this->snapshot('SELECT id, file_id, token, view_count, max_views FROM file_shares ORDER BY id');

        Installer::migrate();
        Installer::migrate();

        // Schema restored.
        $fileColumns = array_map('strtolower', DatabasePlatform::listColumns(Database::connection(), Database::driver(), 'files'));
        $shareColumns = array_map('strtolower', DatabasePlatform::listColumns(Database::connection(), Database::driver(), 'file_shares'));
        $tables = DatabasePlatform::tableNames(Database::connection(), Database::driver());

        $this->assertContains('blob_id', $fileColumns);
        $this->assertContains('delete_after', $shareColumns);
        $this->assertContains('spaces', $tables);
        $this->assertContains('file_blobs', $tables);

        // Legacy data untouched.
        $this->assertSame($usersBefore, $this->snapshot('SELECT id, username, role, status FROM users ORDER BY id'));
        $this->assertSame($foldersBefore, $this->snapshot('SELECT id, parent_id, name FROM folders ORDER BY id'));
        $this->assertSame($filesBefore, $this->snapshot('SELECT id, folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum FROM files ORDER BY id'));
        $this->assertSame($sharesBefore, $this->snapshot('SELECT id, file_id, token, view_count, max_views FROM file_shares ORDER BY id'));

        // The share survived with its expiry-independent fields reset to the
        // pre-feature defaults.
        $deleteAfter = $pdo->prepare('SELECT delete_after FROM file_shares WHERE id = :id');
        $deleteAfter->execute([':id' => $shareId]);
        $this->assertNull($deleteAfter->fetchColumn());

        // New settings seeded once with defaults.
        $this->assertSame('0', Database::setting('dedup_enabled'));
        $this->assertSame('1', Database::setting('file_blobs_backfill_v1'));
        $this->assertSame('15', Database::setting('automation_share_deletion_interval_minutes'));
        $this->assertSame('0', Database::setting('spaces_enabled'));
        $this->assertSame('write', Database::setting('spaces_max_grant_level'));

        // Backfill attached legacy rows to blob records, metadata-only.
        $legacyRow = $pdo->prepare('SELECT blob_id FROM files WHERE id = :id');
        $legacyRow->execute([':id' => (int) $file['id']]);
        $blobId = $legacyRow->fetchColumn();
        $this->assertNotNull($blobId);

        $duplicateRow = $pdo->prepare('SELECT blob_id FROM files WHERE id = :id');
        $duplicateRow->execute([':id' => (int) $duplicate['id']]);
        $this->assertSame((int) $blobId, (int) $duplicateRow->fetchColumn(), 'Legacy duplicates share one blob record');

        $refCount = $pdo->prepare('SELECT ref_count FROM file_blobs WHERE id = :id');
        $refCount->execute([':id' => $blobId]);
        $this->assertSame(2, (int) $refCount->fetchColumn());

        // Streaming the migrated legacy file still resolves its bytes.
        $fileRow = $pdo->prepare('SELECT * FROM files WHERE id = :id');
        $fileRow->execute([':id' => (int) $file['id']]);
        $row = $fileRow->fetch();
        $location = FileManager::blobLocation(Database::connection(), $row);
        $this->assertFileExists(FileManager::blobPathFor($location['disk_name'], $location['disk_extension']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(string $sql): array
    {
        return Database::connection()->query($sql)->fetchAll();
    }
}
