<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileDeduplicationTest extends DatabaseTestCase
{
    private const CONTENT = 'identical four gigabyte payload stand-in';

    protected function setUp(): void
    {
        parent::setUp();
        Database::updateSetting('dedup_enabled', '1');
    }

    public function testSameContentDifferentNamesSharesOneBlob(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'file.mp4', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'movie.mp4', self::CONTENT);

        $this->assertNotSame((int) $first['id'], (int) $second['id'], 'Two logical rows exist');
        $this->assertNotSame((string) $first['disk_name'], (string) $second['disk_name']);

        $blobs = Database::connection()->query('SELECT COUNT(*) AS c, MAX(ref_count) AS r FROM file_blobs')->fetch();
        $this->assertSame(1, (int) $blobs['c']);
        $this->assertSame(2, (int) $blobs['r']);

        $firstPath = $this->blobPathOf($first);
        $secondPath = $this->blobPathOf($second);

        $this->assertSame($firstPath, $secondPath, 'Both rows resolve to the same physical file');
        $this->assertFileExists($firstPath);
        $this->assertSame(self::CONTENT, (string) file_get_contents($firstPath));
    }

    public function testDeleteOneTwinKeepsBlobAndOtherFile(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'alpha.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'beta.bin', self::CONTENT);

        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);

        $path = $this->blobPathOf($second);
        $this->assertFileExists($path);
        $this->assertSame(self::CONTENT, (string) file_get_contents($path));

        $this->assertNotFalse($this->fetchFile((int) $second['id']), 'Second twin survives');

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(1, (int) $blob['ref_count']);
    }

    public function testDeleteBothTwinsRemovesBlobRowAndFile(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'one.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'two.bin', self::CONTENT);
        $path = $this->blobPathOf($first);

        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);
        FileManager::deleteFile($this->superAdmin(), (int) $second['id']);

        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM file_blobs')->fetchColumn());
    }

    public function testFolderDeletionReleasesSharedBlobReferences(): void
    {
        $folder = $this->createFolder('Dedup folder');
        $inside = $this->uploadAs($this->superAdmin(), 'inside.bin', self::CONTENT, (int) $folder['id']);
        $outside = $this->uploadAs($this->superAdmin(), 'outside.bin', self::CONTENT);
        $path = $this->blobPathOf($outside);

        FileManager::deleteFolder($this->superAdmin(), (int) $folder['id']);

        $this->assertFileExists($path, 'Blob survives while the second twin remains');
        $this->assertNotFalse($this->fetchFile((int) $outside['id']));
        $this->assertFalse($this->fetchFile((int) $inside['id']), 'Folder contents are gone');

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(1, (int) $blob['ref_count']);

        FileManager::deleteFile($this->superAdmin(), (int) $outside['id']);
        $this->assertFileDoesNotExist($path);
    }

    public function testQuotaStaysPerUserLogicalUnderDedup(): void
    {
        $member = $this->createUser('dedup-quota');
        $this->setUserStorageQuota((int) $member['id'], 60);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => Database::rootFolderId(),
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);

        $this->uploadAs($member, 'first.bin', self::CONTENT);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');

        // Even though the bytes are already stored, the second upload counts
        // fully against the user's own logical quota.
        $this->uploadAs($member, 'second.bin', self::CONTENT);
    }

    public function testDisabledDedupUsesClassicPath(): void
    {
        Database::updateSetting('dedup_enabled', '0');

        $first = $this->uploadAs($this->superAdmin(), 'classic-one.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'classic-two.bin', self::CONTENT);

        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM file_blobs')->fetchColumn());
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM files WHERE blob_id IS NOT NULL')->fetchColumn());

        $firstPath = FileManager::blobPathFor((string) $first['disk_name'], 'blob');
        $secondPath = FileManager::blobPathFor((string) $second['disk_name'], 'blob');

        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists($secondPath, 'Classic rows keep their own blob');
    }

    public function testToggleOffKeepsExistingDedupsWorking(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'keep-one.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'keep-two.bin', self::CONTENT);

        Database::updateSetting('dedup_enabled', '0');

        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);
        $path = $this->blobPathOf($second);
        $this->assertFileExists($path);
        $this->assertSame(self::CONTENT, (string) file_get_contents($path));

        // Turning the feature back on rejoins the same blob ledger.
        Database::updateSetting('dedup_enabled', '1');
        $third = $this->uploadAs($this->superAdmin(), 'keep-three.bin', self::CONTENT);

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(2, (int) $blob['ref_count']);
        $this->assertSame($path, $this->blobPathOf($third));
    }

    public function testMissingBlobFileSelfHealsOnNextUpload(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'heal-one.bin', self::CONTENT);
        $path = $this->blobPathOf($first);
        unlink($path);

        $this->uploadAs($this->superAdmin(), 'heal-two.bin', self::CONTENT);

        $this->assertFileExists($path);
        $this->assertSame(self::CONTENT, (string) file_get_contents($path));

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(2, (int) $blob['ref_count']);
    }

    public function testStorageStatsReportLogicalPhysicalAndSaved(): void
    {
        Database::updateSetting('dedup_enabled', '0');
        $this->createFile('legacy.bin', self::CONTENT);
        Database::updateSetting('dedup_enabled', '1');

        $size = strlen(self::CONTENT);
        $this->uploadAs($this->superAdmin(), 'twin-a.bin', self::CONTENT);
        $this->uploadAs($this->superAdmin(), 'twin-b.bin', self::CONTENT);

        $stats = FileManager::storageStats();

        $this->assertSame($size * 3, $stats['used_bytes'], 'Logical counts every row');
        $this->assertSame($size * 2, $stats['physical_bytes'], 'Legacy blob plus one shared blob');
        $this->assertSame($size, $stats['saved_bytes']);
        $this->assertTrue($stats['dedup_enabled']);
    }

    public function testReconcileRepairsRefcountDriftAndRemovesOrphans(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'rec-one.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'rec-two.bin', self::CONTENT);
        $path = $this->blobPathOf($first);

        // Simulate drift: the ledger claims far more references than exist.
        Database::connection()->prepare('UPDATE file_blobs SET ref_count = 99')->execute();

        // Simulate an orphaned physical file left behind by a crash.
        $orphanDirectory = wb_storage_path('uploads/ff/ee');
        if (!is_dir($orphanDirectory)) {
            mkdir($orphanDirectory, 0775, true);
        }
        $orphanPath = $orphanDirectory . DIRECTORY_SEPARATOR . 'ffeeddccbbaa99887766554433221100.blob';
        file_put_contents($orphanPath, 'orphan');
        touch($orphanPath, time() - 48 * 3600);

        $result = FileManager::reconcileFileBlobs(null, 24);

        $this->assertSame(1, $result['repaired']);
        $this->assertSame(1, $result['removed_orphans']);
        $this->assertFileDoesNotExist($orphanPath);
        $this->assertFileExists($path, 'Referenced blob is untouched');

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(2, (int) $blob['ref_count']);

        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);
        FileManager::deleteFile($this->superAdmin(), (int) $second['id']);
    }

    public function testReconcileReportsMissingBlobFile(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'missing-one.bin', self::CONTENT);
        unlink($this->blobPathOf($first));

        $result = FileManager::reconcileFileBlobs();

        $this->assertSame(1, $result['missing']);
    }

    public function testPolicyRejectionBeforeDedupDoesNotTouchSharedBlob(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'guard-one.bin', self::CONTENT);
        $path = $this->blobPathOf($first);

        // An extension allowlist rejects the second upload inside
        // uploadComplete before the dedup branch runs.
        Database::updateSetting('uploads_allowed_extensions', 'txt');

        try {
            $this->uploadAs($this->superAdmin(), 'guard-two.bin', self::CONTENT);
            $this->fail('Expected the disallowed upload to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not allowed here', $exception->getMessage());
        }

        $this->assertFileExists($path);
        $this->assertSame(self::CONTENT, (string) file_get_contents($path));

        $blob = Database::connection()->query('SELECT ref_count FROM file_blobs')->fetch();
        $this->assertSame(1, (int) $blob['ref_count']);
    }

    /**
     * Runs a chunked upload through uploadInit/uploadComplete exactly like
     * the API would, without simulating HTTP, and returns the raw files row.
     */
    public function testAuditFailureAfterCommitKeepsUploadedBytes(): void
    {
        $user = $this->superAdmin();
        $init = FileManager::uploadInit($user, Database::rootFolderId(), 'committed.bin', 5, 'application/octet-stream', 1);
        file_put_contents(wb_storage_path('chunks/' . $init['upload_token'] . '/0.part'), '12345');
        Database::updateSetting('audit_enabled', '1');
        Database::updateSetting('log_file_uploads', '1');
        Database::connection()->exec('DROP TABLE audit_logs');
        try {
            FileManager::uploadComplete($user, $init['upload_token']);
            $this->fail('Expected audit insertion to fail.');
        } catch (\PDOException $exception) {
            $row = Database::connection()->query('SELECT * FROM files')->fetch();
            $this->assertIsArray($row);
            $this->assertSame('12345', file_get_contents($this->blobPathOf($row)));
        }
    }

    public function testStaleDeleteDoesNotReleaseSurvivingTwin(): void
    {
        $first = $this->uploadAs($this->superAdmin(), 'stale-a.bin', self::CONTENT);
        $second = $this->uploadAs($this->superAdmin(), 'stale-b.bin', self::CONTENT);
        FileManager::deleteFile($this->superAdmin(), (int) $first['id']);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $this->assertSame([], FileManager::detachFileReference($pdo, $first));
        $pdo->commit();
        $this->assertSame(1, (int) $pdo->query('SELECT ref_count FROM file_blobs')->fetchColumn());
        $this->assertFileExists($this->blobPathOf($second));
    }

    private function uploadAs(array $user, string $name, string $contents, ?int $folderId = null): array
    {
        $folderId ??= Database::rootFolderId();
        $init = FileManager::uploadInit($user, $folderId, $name, strlen($contents), 'application/octet-stream', 1);
        $token = (string) $init['upload_token'];
        $chunkDirectory = wb_storage_path('chunks/' . $token);
        file_put_contents($chunkDirectory . '/0.part', $contents);

        FileManager::uploadComplete($user, $token);
        $fileId = (int) Database::connection()->lastInsertId();

        $row = $this->fetchFile($fileId);

        if ($row === false) {
            $this->fail('Upload did not create a files row.');
        }

        return $row;
    }

    private function fetchFile(int $fileId): array|false
    {
        $statement = Database::connection()->prepare('SELECT * FROM files WHERE id = :id');
        $statement->execute([':id' => $fileId]);

        return $statement->fetch();
    }

    private function blobPathOf(array $file): string
    {
        $location = FileManager::blobLocation(Database::connection(), $file);

        return FileManager::blobPathFor($location['disk_name'], $location['disk_extension']);
    }
}
