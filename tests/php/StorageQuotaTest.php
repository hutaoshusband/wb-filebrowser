<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class StorageQuotaTest extends DatabaseTestCase
{
    public function testQuotaCountsCommittedFilesAndActiveReservations(): void
    {
        $member = $this->createUser('quota-user');
        $this->setUserStorageQuota((int) $member['id'], 1000);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => Database::rootFolderId(),
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);

        $firstUpload = FileManager::uploadInit(
            $member,
            Database::rootFolderId(),
            'first.bin',
            600,
            'application/octet-stream',
            1
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');

        try {
            FileManager::uploadInit(
                $member,
                Database::rootFolderId(),
                'second.bin',
                500,
                'application/octet-stream',
                1
            );
        } finally {
            FileManager::uploadCancel($member, (string) $firstUpload['upload_token']);
        }
    }

    public function testUploadCompletionRechecksQuotaAgainstCurrentUsage(): void
    {
        $member = $this->createUser('quota-finish');
        $this->setUserStorageQuota((int) $member['id'], 900);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => Database::rootFolderId(),
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);
        $this->createFile('existing.bin', str_repeat('A', 400), 'application/octet-stream', Database::rootFolderId(), $member);

        $token = 'feedfacefeedfacefeedfacefeedfacefeed';
        $chunkDirectory = wb_storage_path('chunks/' . $token);
        mkdir($chunkDirectory, 0775, true);
        file_put_contents($chunkDirectory . '/meta.json', json_encode([
            'token' => $token,
            'folder_id' => Database::rootFolderId(),
            'user_id' => (int) $member['id'],
            'original_name' => 'incoming.bin',
            'mime_type' => 'application/octet-stream',
            'size' => 600,
            'total_chunks' => 1,
            'created_at' => wb_now(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($chunkDirectory . '/0.part', str_repeat('B', 600));
        $initialBlobs = glob(wb_storage_path('uploads/*/*/*.blob')) ?: [];

        try {
            FileManager::uploadComplete($member, $token);
            self::fail('Expected quota recheck to reject upload completion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('user quota', $exception->getMessage());
        }

        $remainingBlobs = glob(wb_storage_path('uploads/*/*/*.blob')) ?: [];
        $storedFiles = Database::connection()->prepare(
            'SELECT COUNT(*) FROM files WHERE created_by = :created_by'
        );
        $storedFiles->execute([':created_by' => (int) $member['id']]);

        $this->assertCount(count($initialBlobs), $remainingBlobs);
        $this->assertSame(1, (int) $storedFiles->fetchColumn());
    }

    public function testUploadCompletionSerializesConcurrentRequestsPerToken(): void
    {
        $member = $this->createUser('quota-race');
        $this->setUserStorageQuota((int) $member['id'], 900);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => Database::rootFolderId(),
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);

        $upload = FileManager::uploadInit(
            $member,
            Database::rootFolderId(),
            'incoming.bin',
            600,
            'application/octet-stream',
            1
        );
        $token = (string) $upload['upload_token'];
        file_put_contents(wb_storage_path('chunks/' . $token . '/0.part'), str_repeat('C', 600));

        $results = $this->executeConcurrentIsolatedPhp(sprintf(
            <<<'PHP'
try {
    WbFileBrowser\FileManager::uploadComplete(%s, %s);
    fwrite(STDOUT, "success\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
PHP,
            var_export($member, true),
            var_export($token, true)
        ), 10);

        $successes = array_values(array_filter($results, static fn (array $result): bool => $result['exit_code'] === 0));
        $failures = array_values(array_filter($results, static fn (array $result): bool => $result['exit_code'] !== 0));
        $usage = Database::connection()->prepare(
            'SELECT COUNT(*) AS file_count, COALESCE(SUM(size), 0) AS total_size
             FROM files
             WHERE created_by = :created_by'
        );
        $usage->execute([':created_by' => (int) $member['id']]);
        $row = $usage->fetch() ?: [];

        $this->assertCount(1, $successes, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertCount(9, $failures, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($failures as $failure) {
            $this->assertStringContainsString('Upload session not found.', $failure['stderr'] . $failure['stdout']);
        }

        $this->assertSame(1, (int) ($row['file_count'] ?? 0));
        $this->assertSame(600, (int) ($row['total_size'] ?? 0));
    }
}
