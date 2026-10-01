<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\FileShares;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

/**
 * Covers the share "Deletion after" option: a share may schedule the
 * referenced file for deletion, and the due deletions are processed
 * idempotently by FileShares::processDueDeletions().
 */
final class FileShareDeletionTest extends DatabaseTestCase
{
    public function testShareStoresAndSerializesDeletionAfter(): void
    {
        $file = $this->createFile('scheduled.txt', 'delete me later');
        $deleteAfter = gmdate('c', time() + 7200);

        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'delete_after' => $deleteAfter,
        ]);

        $this->assertSame($deleteAfter, $share['delete_after']);
        $this->assertSame(
            $deleteAfter,
            FileShares::get($this->superAdmin(), (int) $file['id'])['delete_after']
        );
    }

    public function testShareCanBeClearedOfDeletionAfter(): void
    {
        $file = $this->createFile('kept.txt', 'keep me');
        $deleteAfter = gmdate('c', time() + 7200);

        FileShares::create($this->superAdmin(), (int) $file['id'], [
            'delete_after' => $deleteAfter,
        ]);
        $updated = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'delete_after' => null,
        ]);

        $this->assertNull($updated['delete_after']);
    }

    public function testDeletionAfterMustBeInTheFuture(): void
    {
        $file = $this->createFile('past.txt', 'content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Share deletion must be scheduled for the future.');

        FileShares::create($this->superAdmin(), (int) $file['id'], [
            'delete_after' => gmdate('c', time() - 60),
        ]);
    }

    public function testDeletionAfterMustFollowExpiration(): void
    {
        $file = $this->createFile('order.txt', 'content');
        $inTwoHours = gmdate('c', time() + 7200);
        $inOneHour = gmdate('c', time() + 3600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Share deletion must happen after the share expires.');

        FileShares::create($this->superAdmin(), (int) $file['id'], [
            'expires_at' => $inTwoHours,
            'delete_after' => $inOneHour,
        ]);
    }

    public function testDueDeletionRemovesFileAndBlob(): void
    {
        $file = $this->createFile('doomed.txt', 'doomed content');
        $fileId = (int) $file['id'];
        $blobPath = FileManager::blobPathFor((string) $file['disk_name'], (string) $file['disk_extension']);

        FileShares::create($this->superAdmin(), $fileId, [
            'delete_after' => gmdate('c', time() + 3600),
        ]);
        $this->backdateDeletionAfter($fileId);

        $this->assertFileExists($blobPath);

        $result = FileShares::processDueDeletions();

        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($blobPath);

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM files WHERE id = :id');
        $count->execute([':id' => $fileId]);
        $this->assertSame(0, (int) $count->fetchColumn());

        // Shares cascade with the file, so the next run finds nothing due.
        $this->assertSame(0, FileShares::processDueDeletions()['deleted']);
    }

    public function testFutureDeletionIsNotProcessedEarly(): void
    {
        $file = $this->createFile('future.txt', 'not yet');
        $blobPath = FileManager::blobPathFor((string) $file['disk_name'], (string) $file['disk_extension']);

        FileShares::create($this->superAdmin(), (int) $file['id'], [
            'delete_after' => gmdate('c', time() + 86400),
        ]);

        $this->assertSame(0, FileShares::processDueDeletions()['deleted']);
        $this->assertFileExists($blobPath);
    }

    public function testDeletionAfterWorksWithoutExpiration(): void
    {
        $file = $this->createFile('only-delete.txt', 'no expiry set');
        $fileId = (int) $file['id'];

        FileShares::create($this->superAdmin(), $fileId, [
            'expires_at' => gmdate('c', time() + 3600),
            'delete_after' => gmdate('c', time() + 7200),
        ]);

        $this->assertSame(0, FileShares::processDueDeletions()['deleted']);
        $this->assertFileRowExists($fileId, true);

        FileShares::create($this->superAdmin(), $fileId, [
            'expires_at' => gmdate('c', time() + 3600),
            'delete_after' => gmdate('c', time() + 7200),
        ]);
        $this->backdateDeletionAfter($fileId);

        $this->assertSame(1, FileShares::processDueDeletions()['deleted']);
        $this->assertFileRowExists($fileId, false);
    }

    public function testDeletionViaShareDoesNotAffectUnrelatedFiles(): void
    {
        $doomed = $this->createFile('doomed-two.txt', 'doomed');
        $survivor = $this->createFile('survivor.txt', 'survivor');

        FileShares::create($this->superAdmin(), (int) $doomed['id'], [
            'delete_after' => gmdate('c', time() + 3600),
        ]);
        $this->backdateDeletionAfter((int) $doomed['id']);

        $this->assertSame(1, FileShares::processDueDeletions()['deleted']);

        $fetch = Database::connection()->prepare('SELECT COUNT(*) FROM files WHERE id = :id');
        $fetch->execute([':id' => (int) $survivor['id']]);
        $this->assertSame(1, (int) $fetch->fetchColumn());
        $this->assertFileExists(
            FileManager::blobPathFor((string) $survivor['disk_name'], (string) $survivor['disk_extension'])
        );
    }

    /**
     * Simulates that the scheduled deletion moment has passed; the API
     * itself refuses to schedule deletions in the past.
     */
    public function testExplicitRevokeCancelsDeletionSchedule(): void
    {
        $file = $this->createFile('revoked.txt', 'keep');
        FileShares::create($this->superAdmin(), (int) $file['id'], ['delete_after' => gmdate('c', time() + 3600)]);
        FileShares::revoke($this->superAdmin(), (int) $file['id']);
        $this->assertNull(Database::connection()->query('SELECT delete_after FROM file_shares')->fetchColumn());
        $this->assertSame(0, FileShares::processDueDeletions()['deleted']);
    }

    public function testOuterTransactionCannotUnlinkCommittedBytes(): void
    {
        $file = $this->createFile('rollback.txt', 'keep');
        FileShares::create($this->superAdmin(), (int) $file['id'], ['delete_after' => gmdate('c', time() + 3600)]);
        $this->backdateDeletionAfter((int) $file['id']);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            FileShares::processDueDeletions($pdo);
            $this->fail('Nested deletion should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('own transaction', $exception->getMessage());
        } finally {
            $pdo->rollBack();
        }
        $this->assertFileExists(FileManager::blobPathFor($file['disk_name'], $file['disk_extension']));
        $this->assertFileRowExists((int) $file['id'], true);
    }

    private function backdateDeletionAfter(int $fileId): void
    {
        Database::connection()
            ->prepare("UPDATE file_shares SET delete_after = :value WHERE file_id = :file_id")
            ->execute([
                ':value' => gmdate('c', time() - 10),
                ':file_id' => $fileId,
            ]);
    }

    private function assertFileRowExists(int $fileId, bool $expected): void
    {
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM files WHERE id = :id');
        $count->execute([':id' => $fileId]);

        $this->assertSame($expected ? 1 : 0, (int) $count->fetchColumn());
    }
}
