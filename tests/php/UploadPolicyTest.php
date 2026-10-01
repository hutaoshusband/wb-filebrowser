<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class UploadPolicyTest extends DatabaseTestCase
{
    public function testUploadInitAllowsFilesAboveOneGigabyteWhenTheAppLimitIsDisabled(): void
    {
        Settings::saveAdminSettings([
            'uploads' => [
                'max_file_size_mb' => 0,
            ],
        ]);

        $upload = FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'archive.tar',
            1025 * 1024 * 1024,
            'application/x-tar',
            513
        );

        $this->assertArrayHasKey('upload_token', $upload);
        FileManager::uploadCancel($this->superAdmin(), (string) $upload['upload_token']);
    }

    public function testUploadInitRejectsFilesThatExceedTheConfiguredLimit(): void
    {
        Settings::saveAdminSettings([
            'uploads' => [
                'max_file_size_mb' => 1,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Uploads are limited');

        FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'large-video.mp4',
            2 * 1024 * 1024,
            'video/mp4',
            1
        );
    }

    public function testUploadInitRejectsDisallowedExtensions(): void
    {
        Settings::saveAdminSettings([
            'uploads' => [
                'allowed_extensions' => 'png, jpg',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Allowed types: .jpg, .png');

        FileManager::uploadInit(
            $this->superAdmin(),
            Database::rootFolderId(),
            'document.pdf',
            1024,
            'application/pdf',
            1
        );
    }
}
