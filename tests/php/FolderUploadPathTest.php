<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FolderUploadPathTest extends DatabaseTestCase
{
    public function testEnsureFolderPathCreatesNestedFoldersAndReusesExistingSegments(): void
    {
        $actor = $this->superAdmin();
        $workspace = $this->createFolder('Workspace');
        $existing = $this->createFolder('Designs', (int) $workspace['id'], $actor);

        $resolved = FileManager::ensureFolderPath($actor, (int) $workspace['id'], ['Designs', '2026', 'Final']);

        $this->assertSame('Final', $resolved['name']);

        $folders = Database::connection()->query(
            'SELECT id, parent_id, name FROM folders ORDER BY id ASC'
        )->fetchAll();

        $designFolders = array_values(array_filter(
            $folders,
            static fn (array $folder): bool => (string) $folder['name'] === 'Designs'
        ));

        $this->assertCount(1, $designFolders);
        $this->assertSame((int) $existing['id'], (int) $designFolders[0]['id']);
        $this->assertNotEmpty(array_filter(
            $folders,
            static fn (array $folder): bool => (string) $folder['name'] === '2026'
        ));
        $this->assertNotEmpty(array_filter(
            $folders,
            static fn (array $folder): bool => (string) $folder['name'] === 'Final'
        ));
    }

    public function testUploadInitUsesResolvedFolderWhenRelativePathSegmentsAreProvided(): void
    {
        $actor = $this->superAdmin();
        $workspace = $this->createFolder('Workspace');

        $upload = FileManager::uploadInit(
            $actor,
            (int) $workspace['id'],
            'wireframe.png',
            12,
            'image/png',
            1,
            ['Designs', '2026']
        );

        file_put_contents(wb_storage_path('chunks/' . $upload['upload_token'] . '/0.part'), 'hello world!');
        $file = FileManager::uploadComplete($actor, (string) $upload['upload_token']);

        $folder = Database::connection()->prepare('SELECT name, parent_id FROM folders WHERE id = :id LIMIT 1');
        $folder->execute([':id' => (int) $file['folder_id']]);
        $resolvedFolder = $folder->fetch();

        $this->assertIsArray($resolvedFolder);
        $this->assertSame('2026', (string) $resolvedFolder['name']);
        $this->assertSame('wireframe.png', (string) $file['name']);
    }

    public function testEnsureFolderPathRejectsInvalidSegments(): void
    {
        $actor = $this->superAdmin();
        $workspace = $this->createFolder('Workspace');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Folder name cannot contain path separators.');

        FileManager::ensureFolderPath($actor, (int) $workspace['id'], ['safe', 'bad/name']);
    }

    public function testEnsureFolderPathRequiresCreatePermissionWhenMissingSegmentMustBeCreated(): void
    {
        $member = $this->createUser('uploader');
        $workspace = $this->createFolder('Workspace');

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            [
                'folder_id' => (int) $workspace['id'],
                'can_view' => true,
                'can_upload' => true,
                'can_edit' => false,
                'can_delete' => false,
                'can_create_folders' => false,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('create folders');

        FileManager::ensureFolderPath($member, (int) $workspace['id'], ['Missing']);
    }
}
