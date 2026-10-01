<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use InvalidArgumentException;
use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class UploadHardeningTest extends DatabaseTestCase
{
    public function testUploadInitRejectsChunkCountsThatDoNotMatchTheDeclaredFileSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Declared chunk count does not match file size.');

        FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'mismatch.bin',
            FileManager::CHUNK_SIZE + 1,
            'application/octet-stream',
            1
        );
    }

    public function testUploadCompleteRejectsOversizedSingleChunksRelativeToTheDeclaredSize(): void
    {
        $upload = FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'oversized.bin',
            5,
            'application/octet-stream',
            1
        );
        $before = $this->blobPaths();
        $this->writeChunk((string) $upload['upload_token'], 0, '123456');

        try {
            FileManager::uploadComplete($this->superAdmin(), (string) $upload['upload_token']);
            self::fail('Expected upload completion to reject an oversized chunk.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('chunk size is invalid', strtolower($exception->getMessage()));
        }

        self::assertSame($before, $this->blobPaths());
    }

    public function testUploadCompleteRejectsTruncatedNonFinalChunks(): void
    {
        $upload = FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'truncated.bin',
            FileManager::CHUNK_SIZE + 5,
            'application/octet-stream',
            2
        );
        $before = $this->blobPaths();
        $this->writeChunk((string) $upload['upload_token'], 0, str_repeat('A', FileManager::CHUNK_SIZE - 1));
        $this->writeChunk((string) $upload['upload_token'], 1, '12345');

        try {
            FileManager::uploadComplete($this->superAdmin(), (string) $upload['upload_token']);
            self::fail('Expected upload completion to reject a truncated non-final chunk.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('chunk size is invalid', strtolower($exception->getMessage()));
        }

        self::assertSame($before, $this->blobPaths());
    }

    public function testUploadCompleteAcceptsExactMultiChunkLayouts(): void
    {
        $upload = FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'exact-layout.bin',
            FileManager::CHUNK_SIZE + 5,
            'application/octet-stream',
            2
        );
        $before = $this->blobPaths();
        $this->writeChunk((string) $upload['upload_token'], 0, str_repeat('A', FileManager::CHUNK_SIZE));
        $this->writeChunk((string) $upload['upload_token'], 1, '12345');

        $file = FileManager::uploadComplete($this->superAdmin(), (string) $upload['upload_token']);

        self::assertSame('exact-layout.bin', (string) $file['name']);
        self::assertSame(FileManager::CHUNK_SIZE + 5, (int) $file['size']);
        self::assertCount(count($before) + 1, $this->blobPaths());
        self::assertDirectoryDoesNotExist($this->chunkDirectory((string) $upload['upload_token']));
    }

    public function testUploadCompleteRejectsTamperedMetadataWithInconsistentChunkCounts(): void
    {
        $token = $this->createChunkWorkspace(
            $this->superAdmin(),
            'tampered-metadata.bin',
            FileManager::CHUNK_SIZE + 1,
            1,
            [
                0 => str_repeat('A', FileManager::CHUNK_SIZE + 1),
            ]
        );
        $before = $this->blobPaths();

        try {
            FileManager::uploadComplete($this->superAdmin(), $token);
            self::fail('Expected upload completion to reject inconsistent upload metadata.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('metadata is invalid', strtolower($exception->getMessage()));
        }

        self::assertSame($before, $this->blobPaths());
    }

    public function testUploadCompleteRechecksTheConfiguredUploadLimitAgainstTheActualFileSize(): void
    {
        Settings::saveAdminSettings([
            'uploads' => [
                'max_file_size_mb' => 1,
            ],
        ]);

        $token = $this->createChunkWorkspace(
            $this->superAdmin(),
            'too-large.bin',
            (1024 * 1024) + 1,
            1,
            [
                0 => str_repeat('B', (1024 * 1024) + 1),
            ]
        );
        $before = $this->blobPaths();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Uploads are limited');

        try {
            FileManager::uploadComplete($this->superAdmin(), $token);
        } finally {
            self::assertSame($before, $this->blobPaths());
        }
    }

    public function testUploadCompleteDeletesTemporaryBlobsWhenQuotaChecksFail(): void
    {
        $member = $this->createUser('quota-hardening');
        $this->setUserStorageQuota((int) $member['id'], 900);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => Database::rootFolderId(),
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);
        $this->createFile('existing.bin', str_repeat('A', 400), 'application/octet-stream', Database::rootFolderId(), $member);

        $token = $this->createChunkWorkspace(
            $member,
            'incoming.bin',
            600,
            1,
            [
                0 => str_repeat('B', 600),
            ]
        );
        $before = $this->blobPaths();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');

        try {
            FileManager::uploadComplete($member, $token);
        } finally {
            self::assertSame($before, $this->blobPaths());
        }
    }

    /**
     * @param array<int, string> $chunks
     */
    private function createChunkWorkspace(array $user, string $name, int $size, int $totalChunks, array $chunks): string
    {
        $token = wb_random_token(18);
        $directory = $this->chunkDirectory($token);
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/meta.json', json_encode([
            'token' => $token,
            'folder_id' => Database::rootFolderId(),
            'user_id' => (int) $user['id'],
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => $size,
            'total_chunks' => $totalChunks,
            'created_at' => wb_now(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($chunks as $index => $contents) {
            file_put_contents($directory . '/' . $index . '.part', $contents);
        }

        return $token;
    }

    private function writeChunk(string $token, int $index, string $contents): void
    {
        file_put_contents($this->chunkDirectory($token) . '/' . $index . '.part', $contents);
    }

    private function chunkDirectory(string $token): string
    {
        return wb_storage_path('chunks/' . $token);
    }

    /**
     * @return array<int, string>
     */
    private function blobPaths(): array
    {
        $root = wb_storage_path('uploads');

        if (!is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.blob')) {
                $paths[] = $item->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
