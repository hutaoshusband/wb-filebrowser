<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileNotesAndListingTest extends DatabaseTestCase
{
    public function testDescriptionsAreVisibleInListingsForAuthorizedUsers(): void
    {
        $folder = $this->createFolder('Shared docs');
        $file = $this->createFile('brief.txt', 'hello', 'text/plain', (int) $folder['id']);
        $member = $this->createUser('member');

        FileManager::saveFolderDescription($this->superAdmin(), (int) $folder['id'], 'Visible folder note');
        FileManager::saveFileDescription($this->superAdmin(), (int) $file['id'], 'Visible file note');

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $folder['id'],
                'can_view' => true,
            ],
        ]);

        $rootListing = FileManager::listFolder($member, Database::rootFolderId());
        $folderListing = FileManager::listFolder($member, (int) $folder['id']);

        $this->assertSame('Visible folder note', $rootListing['folders'][0]['description']);
        $this->assertSame('Visible file note', $folderListing['files'][0]['description']);
    }

    public function testDescriptionWritesRequireEditPermission(): void
    {
        $folder = $this->createFolder('Private docs');
        $file = $this->createFile('secret.txt', 'secret', 'text/plain', (int) $folder['id']);
        $member = $this->createUser('member');

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $folder['id'],
                'can_view' => true,
            ],
        ]);

        try {
            FileManager::saveFileDescription($member, (int) $file['id'], 'Should fail');
            $this->fail('Expected file description edit to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('You do not have permission to edit this file.', $exception->getMessage());
        }

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $folder['id'],
                'can_view' => true,
                'can_edit' => true,
            ],
        ]);

        $updatedFolder = FileManager::saveFolderDescription($member, (int) $folder['id'], 'Allowed folder note');
        $updatedFile = FileManager::saveFileDescription($member, (int) $file['id'], 'Allowed file note');

        $this->assertSame('Allowed folder note', $updatedFolder['description']);
        $this->assertSame('Allowed file note', $updatedFile['description']);
    }
}
