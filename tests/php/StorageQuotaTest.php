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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');

        FileManager::uploadComplete($member, $token);
    }
}
