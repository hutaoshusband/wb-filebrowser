<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Permissions;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class PermissionsTest extends DatabaseTestCase
{
    public function testGuestPermissionsSaveAndLoadForPublishedFolders(): void
    {
        $folder = $this->createFolder('Public');

        Permissions::saveMatrix($this->superAdmin(), 'guest', 0, [
            [
                'folder_id' => $folder['id'],
                'can_view' => true,
                'can_upload' => false,
            ],
        ]);

        $matrix = Permissions::matrix($this->superAdmin(), 'guest', 0);

        $this->assertCount(1, $matrix['permissions']);
        $this->assertSame($folder['id'], (int) $matrix['permissions'][0]['folder_id']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_view']);
        $this->assertSame(0, (int) $matrix['permissions'][0]['can_upload']);
    }

    public function testSpecificUserPermissionsSaveAndLoadUploadRights(): void
    {
        $folder = $this->createFolder('Member Uploads');
        $user = $this->createUser('member-one');

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $user['id'], [
            [
                'folder_id' => $folder['id'],
                'can_view' => true,
                'can_upload' => true,
            ],
        ]);

        $matrix = Permissions::matrix($this->superAdmin(), 'user', (int) $user['id']);

        $this->assertCount(1, $matrix['permissions']);
        $this->assertSame($folder['id'], (int) $matrix['permissions'][0]['folder_id']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_view']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_upload']);
    }
}
