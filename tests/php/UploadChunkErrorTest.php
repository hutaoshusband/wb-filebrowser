<?php

declare(strict_types=1);

namespace WbFileBrowser;

/**
 * Mock global functions for testing FileManager::uploadChunk
 */
function move_uploaded_file(string $from, string $to): bool
{
    if (Tests\UploadChunkErrorTest::$mockMoveUploadedFile) {
        if (Tests\UploadChunkErrorTest::$mockMoveUploadedFileSuccess) {
             // If mocked to succeed, we assume the file was already placed by the test
             return true;
        }
        return false;
    }
    return \move_uploaded_file($from, $to);
}

function is_uploaded_file(string $path): bool
{
    return Tests\UploadChunkErrorTest::$mockIsUploadedFile ? Tests\UploadChunkErrorTest::$mockIsUploadedFileSuccess : \is_uploaded_file($path);
}

function unlink(string $filename): bool
{
    return Tests\UploadChunkErrorTest::$mockUnlink ? (Tests\UploadChunkErrorTest::$mockUnlinkSuccess && \unlink($filename)) : \unlink($filename);
}

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class UploadChunkErrorTest extends DatabaseTestCase
{
    public static bool $mockMoveUploadedFile = false;
    public static bool $mockMoveUploadedFileSuccess = true;
    public static bool $mockIsUploadedFile = false;
    public static bool $mockIsUploadedFileSuccess = true;
    public static bool $mockUnlink = false;
    public static bool $mockUnlinkSuccess = true;

    protected function setUp(): void
    {
        parent::setUp();
        self::$mockMoveUploadedFile = false;
        self::$mockMoveUploadedFileSuccess = true;
        self::$mockIsUploadedFile = false;
        self::$mockIsUploadedFileSuccess = true;
        self::$mockUnlink = false;
        self::$mockUnlinkSuccess = true;
    }

    public function testUploadChunkThrowsExceptionWhenWriteFails(): void
    {
        $user = $this->superAdmin();
        $upload = FileManager::uploadInit(
            $user,
            Database::rootFolderId(),
            'test.bin',
            10,
            'application/octet-stream',
            1
        );

        $token = (string) $upload['upload_token'];

        // Mock is_uploaded_file to return true since we are in CLI
        self::$mockIsUploadedFile = true;
        self::$mockIsUploadedFileSuccess = true;

        // Mock move_uploaded_file to return false
        self::$mockMoveUploadedFile = true;
        self::$mockMoveUploadedFileSuccess = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write chunk');

        FileManager::uploadChunk($user, $token, 0, ['tmp_name' => 'anything']);
    }

    public function testUploadChunkUnlinksFileOnSizeAssertionFailure(): void
    {
        $user = $this->superAdmin();
        $upload = FileManager::uploadInit(
            $user,
            Database::rootFolderId(),
            'test.bin',
            10, // Expected size 10
            'application/octet-stream',
            1
        );

        $token = (string) $upload['upload_token'];
        $targetPath = wb_storage_path('chunks/' . $token . '/0.part');

        // We need to bypass move_uploaded_file and manually create a file with WRONG size
        // because we can't easily make move_uploaded_file work in CLI for real tmp files.
        // So we mock it to "succeed" but we've already placed the file.

        self::$mockIsUploadedFile = true;
        self::$mockIsUploadedFileSuccess = true;

        self::$mockMoveUploadedFile = true;
        self::$mockMoveUploadedFileSuccess = true;

        // Manually create the "chunk" with wrong size (5 instead of 10)
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }
        file_put_contents($targetPath, '12345');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Upload chunk size is invalid.');

        try {
            FileManager::uploadChunk($user, $token, 0, ['tmp_name' => 'anything']);
        } catch (RuntimeException $e) {
            // Verify file was unlinked
            $this->assertFileDoesNotExist($targetPath);
            throw $e;
        }
    }
}
