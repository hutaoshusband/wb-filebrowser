<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileOperationsPermissionTest extends DatabaseTestCase
{
    public function testStandardUserCanManageFoldersAndFilesWhenRightsAreGranted(): void
    {
        $member = $this->createUser('editor');
        $workspace = $this->createFolder('Workspace');
        $archive = $this->createFolder('Archive');
        $file = $this->createFile('draft.txt', 'draft', 'text/plain', (int) $workspace['id'], $member);

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $workspace['id'],
                'can_view' => true,
                'can_upload' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_create_folders' => true,
            ],
            [
                'folder_id' => (int) $archive['id'],
                'can_view' => true,
                'can_edit' => true,
            ],
        ]);

        $newFolder = FileManager::createFolder($member, (int) $workspace['id'], 'Notes');
        FileManager::renameFolder($member, (int) $newFolder['id'], 'Renamed Notes');
        FileManager::moveFile($member, (int) $file['id'], (int) $archive['id']);
        FileManager::renameFile($member, (int) $file['id'], 'published.txt');
        FileManager::deleteFolder($member, (int) $newFolder['id']);

        $movedFile = Database::connection()->query('SELECT folder_id, original_name FROM files WHERE id = ' . (int) $file['id'])->fetch();

        $this->assertSame((int) $archive['id'], (int) $movedFile['folder_id']);
        $this->assertSame('published.txt', (string) $movedFile['original_name']);
    }

    public function testStandardUserCannotDeleteWithoutDeletePermission(): void
    {
        $member = $this->createUser('viewer');
        $workspace = $this->createFolder('Workspace');
        $file = $this->createFile('draft.txt', 'draft', 'text/plain', (int) $workspace['id'], $member);

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $workspace['id'],
                'can_view' => true,
                'can_edit' => true,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delete files');

        FileManager::deleteFile($member, (int) $file['id']);
    }
}
