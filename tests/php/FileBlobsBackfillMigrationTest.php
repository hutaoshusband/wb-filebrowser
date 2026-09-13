<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Installer;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

/**
 * Simulates a pre-deduplication installation with legacy rows, rewinds the
 * backfill flag, and verifies that Installer::migrate() attaches every old
 * files row to a file_blobs record without touching users, folders or the
 * physical files on disk.
 */
final class FileBlobsBackfillMigrationTest extends DatabaseTestCase
{
    public function testBackfillCreatesBlobRowsWithCorrectRefcounts(): void
    {
        $pdo = Database::connection();

        // Legacy rows, including two different-name duplicates of one content.
        $legacy = $this->createFile('legacy-report.txt', 'important contents', 'text/plain');
        $twinA = $this->createFile('twin-a.bin', 'duplicate payload', 'application/octet-stream');
        $twinB = $this->createFile('twin-b.bin', 'duplicate payload', 'application/octet-stream');
        $unique = $this->createFile('unique.bin', 'unique payload', 'application/octet-stream');

        Database::updateSetting('file_blobs_backfill_v1', '0');
        Database::updateSetting('dedup_enabled', '0');

        $twinAPath = FileManager::blobPathFor((string) $twinA['disk_name'], (string) $twinA['disk_extension']);
        $twinBPath = FileManager::blobPathFor((string) $twinB['disk_name'], (string) $twinB['disk_extension']);

        Installer::migrate();

        $this->assertSame('1', Database::setting('file_blobs_backfill_v1'));

        // One blob record per distinct content, refcounted by group size.
        $rows = $pdo->query('SELECT checksum, size, ref_count FROM file_blobs')->fetchAll();
        $this->assertCount(3, $rows, 'Legacy contents collapse into three blob records');

        $twinARow = $this->refetch((int) $twinA['id']);
        $twinBRow = $this->refetch((int) $twinB['id']);
        $legacyRow = $this->refetch((int) $legacy['id']);
        $uniqueRow = $this->refetch((int) $unique['id']);

        $this->assertSame((int) $twinARow['blob_id'], (int) $twinBRow['blob_id'], 'Twins share one blob record');
        $this->assertNotSame((int) $legacyRow['blob_id'], (int) $twinARow['blob_id']);
        $this->assertNotNull($uniqueRow['blob_id']);

        $sharedBlob = $pdo->prepare('SELECT ref_count, disk_name FROM file_blobs WHERE id = :id');
        $sharedBlob->execute([':id' => $twinARow['blob_id']]);
        $shared = $sharedBlob->fetch();
        $this->assertSame(2, (int) $shared['ref_count']);

        // The canonical (lowest-id) twin owns the physical name.
        $this->assertSame((string) $twinA['disk_name'], (string) $shared['disk_name']);

        // Physical files: nothing moved or deleted.
        $this->assertFileExists($twinAPath);
        $this->assertFileExists($twinBPath, 'The superseded duplicate stays on disk until the reconcile job');

        // Both twins resolve to the canonical physical file and stream it.
        $canonicalPath = FileManager::blobPathFor((string) $shared['disk_name'], 'blob');
        $this->assertSame('duplicate payload', (string) file_get_contents($canonicalPath));
    }

    public function testBackfillIsIdempotent(): void
    {
        $this->createFile('idem-a.bin', 'same content here');
        $this->createFile('idem-b.bin', 'same content here');

        Installer::migrate();
        $blobsAfterFirst = Database::connection()
            ->query('SELECT id, checksum, disk_name, size, ref_count FROM file_blobs ORDER BY id')
            ->fetchAll();
        $filesAfterFirst = Database::connection()
            ->query('SELECT id, blob_id FROM files ORDER BY id')
            ->fetchAll();

        Installer::migrate();
        $blobsAfterSecond = Database::connection()
            ->query('SELECT id, checksum, disk_name, size, ref_count FROM file_blobs ORDER BY id')
            ->fetchAll();
        $filesAfterSecond = Database::connection()
            ->query('SELECT id, blob_id FROM files ORDER BY id')
            ->fetchAll();

        $this->assertSame($blobsAfterFirst, $blobsAfterSecond);
        $this->assertSame($filesAfterFirst, $filesAfterSecond);
    }

    public function testBackfillSkipsRowsWithMalformedChecksums(): void
    {
        $weird = $this->createFile('weird.bin', 'checksum garbage');
        Database::connection()
            ->prepare('UPDATE files SET checksum = :checksum WHERE id = :id')
            ->execute([':checksum' => 'not-a-real-hash', ':id' => (int) $weird['id']]);

        Installer::migrate();

        $row = $this->refetch((int) $weird['id']);
        $this->assertNull($row['blob_id'], 'Malformed checksum rows stay classic');

        // Classic rows must remain deletable.
        FileManager::deleteFile($this->superAdmin(), (int) $weird['id']);
        $this->assertFalse($this->refetch((int) $weird['id']));
    }

    public function testMissingOldestTwinCannotHideReadableCopy(): void
    {
        $first = $this->createFile('missing.bin', 'surviving bytes');
        $second = $this->createFile('readable.bin', 'surviving bytes');
        unlink(FileManager::blobPathFor($first['disk_name'], $first['disk_extension']));
        Database::updateSetting('file_blobs_backfill_v1', '0');
        Installer::migrate();
        $location = FileManager::blobLocation(Database::connection(), $this->refetch((int) $second['id']));
        $this->assertSame($second['disk_name'], $location['disk_name']);
        $this->assertSame('surviving bytes', file_get_contents(FileManager::blobPathFor($location['disk_name'], $location['disk_extension'])));
    }

    private function refetch(int $fileId): array|false
    {
        $statement = Database::connection()->prepare('SELECT * FROM files WHERE id = :id');
        $statement->execute([':id' => $fileId]);

        return $statement->fetch();
    }
}
