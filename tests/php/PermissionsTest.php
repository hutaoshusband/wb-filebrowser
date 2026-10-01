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
        $this->assertSame(0, (int) $matrix['permissions'][0]['can_edit']);
        $this->assertSame(0, (int) $matrix['permissions'][0]['can_delete']);
        $this->assertSame(0, (int) $matrix['permissions'][0]['can_create_folders']);
    }

    public function testSpecificUserPermissionsSaveAndLoadManagementRights(): void
    {
        $folder = $this->createFolder('Member Uploads');
        $childFolder = $this->createFolder('Child', (int) $folder['id']);
        $user = $this->createUser('member-one');

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $user['id'], [
            [
                'folder_id' => $folder['id'],
                'can_view' => true,
                'can_upload' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_create_folders' => true,
            ],
        ]);

        $matrix = Permissions::matrix($this->superAdmin(), 'user', (int) $user['id']);

        $this->assertCount(1, $matrix['permissions']);
        $this->assertSame($folder['id'], (int) $matrix['permissions'][0]['folder_id']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_view']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_upload']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_edit']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_delete']);
        $this->assertSame(1, (int) $matrix['permissions'][0]['can_create_folders']);

        $this->assertTrue(Permissions::canUploadToFolder((int) $childFolder['id'], $user));
        $this->assertTrue(Permissions::canEditFolder((int) $childFolder['id'], $user));
        $this->assertTrue(Permissions::canDeleteFolder((int) $childFolder['id'], $user));
        $this->assertTrue(Permissions::canCreateFoldersIn((int) $childFolder['id'], $user));
    }

    public function testCanManageStructure(): void
    {
        $this->assertFalse(Permissions::canManageStructure(null));
        $this->assertTrue(Permissions::canManageStructure(['role' => 'super_admin']));
        $this->assertTrue(Permissions::canManageStructure(['role' => 'admin']));
        $this->assertFalse(Permissions::canManageStructure(['role' => 'user']));
        $this->assertFalse(Permissions::canManageStructure(['role' => 'guest']));
        $this->assertFalse(Permissions::canManageStructure(['role' => 'editor']));
    }

    public function testPublicAccessEnabled(): void
    {
        $this->assertFalse(Permissions::publicAccessEnabled());

        \WbFileBrowser\Database::updateSetting('public_access', '1');
        $this->assertTrue(Permissions::publicAccessEnabled());

        \WbFileBrowser\Database::updateSetting('public_access', '0');
        $this->assertFalse(Permissions::publicAccessEnabled());

        \WbFileBrowser\Database::updateSetting('public_access', 'true');
        $this->assertTrue(Permissions::publicAccessEnabled());

        \WbFileBrowser\Database::updateSetting('public_access', 'false');
        $this->assertFalse(Permissions::publicAccessEnabled());
    }
}
