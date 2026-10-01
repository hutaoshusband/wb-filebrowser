<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\FileShares;
use WbFileBrowser\Permissions;
use WbFileBrowser\SpaceService;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class SpacesTest extends DatabaseTestCase
{
    public function testSavingEnabledSpacesProvisionsMissingUsersWithoutReactivatingDisabledSpaces(): void
    {
        $alice = $this->createUser('new-space-owner');
        $disabled = $this->createUser('disabled-owner');
        $admin = $this->createUser('another-admin', 'admin');
        SpaceService::provisionForUser($this->superAdmin(), (int) $disabled['id']);
        SpaceService::setStatusForUser($this->superAdmin(), (int) $disabled['id'], 'disabled');
        Settings::saveAdminSettings(['spaces' => ['enabled' => true]]);
        $space = SpaceService::findForUser((int) $alice['id']);
        $this->assertNotNull($space);
        $this->assertSame((int) $space['folder_id'], SpaceService::homeFolderIdFor($alice));
        $this->assertNull(SpaceService::findForUser((int) $disabled['id']));
        $this->assertNull(SpaceService::findForUser((int) $admin['id']));
        Settings::saveAdminSettings(['spaces' => ['enabled' => true]]);
        $this->assertSame($space['folder_id'], SpaceService::findForUser((int) $alice['id'])['folder_id']);
    }

    public function testNavigationExposesSharedRootsWithoutOtherPrivateSpaces(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('nav-alice');
        $bob = $this->createUser('nav-bob');
        $carol = $this->createUser('nav-carol');
        $own = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $shared = SpaceService::provisionForUser($this->superAdmin(), (int) $bob['id']);
        $private = SpaceService::provisionForUser($this->superAdmin(), (int) $carol['id']);
        SpaceService::saveFolderGrants($bob, (int) $shared['folder_id'], [['username' => $alice['username'], 'level' => 'view']]);
        $roots = FileManager::navigationRoots($alice);
        $ids = array_column($roots, 'id');
        $this->assertContains((int) $own['folder_id'], $ids);
        $this->assertContains((int) $shared['folder_id'], $ids);
        $this->assertNotContains((int) $private['folder_id'], $ids);
        $this->assertNotContains(SpaceService::containerFolderId(), $ids);
        $listing = FileManager::listFolder($alice, (int) $own['folder_id']);
        $this->assertTrue($listing['folder']['protected_root']);
        $this->assertFalse($listing['folder']['can_delete']);
        $this->assertTrue($listing['folder']['can_create_folders']);
        $this->assertSame((int) $own['folder_id'], $listing['folder']['space_root_id']);
    }

    public function testGlobalDisableExplainsUnavailableSpaceInSession(): void
    {
        $alice = $this->createUser('unavailable-owner');
        SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $context = SpaceService::sessionContextFor($alice);
        $this->assertFalse($context['enabled']);
        $this->assertSame('unavailable', $context['status']);
        $this->assertNull($context['folder_id']);
    }

    private function enableSpaces(): void
    {
        Database::updateSetting('spaces_enabled', '1');
        Database::updateSetting('spaces_user_sharing_allowed', '1');
        Database::updateSetting('spaces_max_grant_level', 'write');
    }

    public function testProvisionCreatesContainerAndRootIdempotently(): void
    {
        $this->enableSpaces();
        $member = $this->createUser('alice', 'user');

        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $member['id']);

        $this->assertSame(SpaceService::STATUS_ACTIVE, (string) $space['status']);
        $containerId = SpaceService::containerFolderId();
        $this->assertNotNull($containerId);

        $container = Database::connection()
            ->prepare('SELECT parent_id, name FROM folders WHERE id = :id');
        $container->execute([':id' => $containerId]);
        $containerRow = $container->fetch();
        $this->assertSame(Database::rootFolderId(), (int) $containerRow['parent_id']);
        $this->assertSame('Spaces', (string) $containerRow['name']);

        $folder = Database::connection()
            ->prepare('SELECT parent_id, name FROM folders WHERE id = :id');
        $folder->execute([':id' => $space['folder_id']]);
        $folderRow = $folder->fetch();
        $this->assertSame($containerId, (int) $folderRow['parent_id']);
        $this->assertSame('alice', (string) $folderRow['name']);

        // Idempotent: provisioning again keeps the same folder.
        $again = SpaceService::provisionForUser($this->superAdmin(), (int) $member['id']);
        $this->assertSame((int) $space['folder_id'], (int) $again['folder_id']);
    }

    public function testOwnerSeesOnlyTheirSpaceAndNeverTheContainer(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice2', 'user');
        $bob = $this->createUser('bob2', 'user');
        $aliceSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $bobSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $bob['id']);
        $containerId = (int) SpaceService::containerFolderId();

        FileManager::createFolder($alice, (int) $aliceSpace['folder_id'], 'Documents');

        $scope = Permissions::scope($alice);

        $this->assertNotContains($containerId, $scope['ancestors'], 'Container is invisible');
        $this->assertNotContains($containerId, $scope['content']);
        $this->assertNotContains($bobSpace['folder_id'], $scope['content'], 'Sibling space invisible');
        $this->assertFalse(Permissions::canOpenFolder($bobSpace['folder_id'], $alice), 'Cannot open sibling space');
        $this->assertFalse(Permissions::canOpenFolder($containerId, $alice), 'Cannot open container');
        $this->assertNotContains(Database::rootFolderId(), $scope['ancestors'], 'Space-only user does not get the global root');
        $this->assertTrue(Permissions::canOpenFolder((int) $aliceSpace['folder_id'], $alice));
        $this->assertTrue(Permissions::canUploadToFolder((int) $aliceSpace['folder_id'], $alice));
        $this->assertTrue(Permissions::canEditFolder((int) $aliceSpace['folder_id'], $alice));
        $this->assertTrue(Permissions::canDeleteFolder((int) $aliceSpace['folder_id'], $alice));
    }

    public function testOwnerImplicitRightsWorkWithoutPermissionRows(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice3', 'user');
        $aliceSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);

        $docs = FileManager::createFolder($alice, (int) $aliceSpace['folder_id'], 'Docs');
        FileManager::renameFolder($alice, (int) $docs['id'], 'Documents');
        FileManager::saveFolderDescription($alice, (int) $docs['id'], 'my docs');

        $this->assertSame('Documents', (string) FileManager::listFolder($alice, (int) $docs['id'])['folder']['name']);
    }

    public function testDisabledSpaceFallsBackToLegacyScope(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice4', 'user');
        $aliceSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $spaceRootId = (int) $aliceSpace['folder_id'];

        SpaceService::setStatusForUser($this->superAdmin(), (int) $alice['id'], SpaceService::STATUS_DISABLED);

        $scope = Permissions::scope($alice);
        $this->assertNotContains($spaceRootId, $scope['content'], 'Disabled space disappears from scope');
        $this->assertFalse(Permissions::canOpenFolder($spaceRootId, $alice));

        // Re-enabling restores access to the same folder (data kept).
        SpaceService::setStatusForUser($this->superAdmin(), (int) $alice['id'], SpaceService::STATUS_ACTIVE);
        $this->assertTrue(Permissions::canOpenFolder($spaceRootId, $alice));
    }

    public function testFeatureDisabledKeepsScopeUntouched(): void
    {
        $this->enableSpaces();
        $member = $this->createUser('legacy-member', 'user');
        $public = $this->createFolder('Legacy public');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => (int) $public['id'], 'can_view' => true],
        ]);
        $scopeBefore = Permissions::scope($member);

        Database::updateSetting('spaces_enabled', '0');
        $scopeAfter = Permissions::scope($member);

        $this->assertSame($scopeBefore['content'], $scopeAfter['content']);
        $this->assertSame($scopeBefore['ancestors'], $scopeAfter['ancestors']);
    }

    public function testRecipientSeesGrantedSpaceFolderOnly(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice5', 'user');
        $carol = $this->createUser('carol5', 'user');
        $aliceSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);

        $project = FileManager::createFolder($alice, (int) $aliceSpace['folder_id'], 'Project');
        $this->createFile('inside-project.txt', 'shared bytes', 'text/plain', (int) $project['id'], $alice);

        SpaceService::saveFolderGrants($alice, (int) $project['id'], [
            ['username' => 'carol5', 'level' => 'write'],
        ]);

        $carolScope = Permissions::scope($carol);
        $this->assertContains((int) $project['id'], $carolScope['content']);
        $this->assertTrue(Permissions::canViewFolderContents((int) $project['id'], $carol));
        $this->assertTrue(Permissions::canUploadToFolder((int) $project['id'], $carol));
        $this->assertNotContains(SpaceService::containerFolderId(), $carolScope['ancestors']);
        // Space-less users keep the global root as their navigation anchor.
        $this->assertContains(Database::rootFolderId(), $carolScope['ancestors']);
        $this->assertNotContains((int) $aliceSpace['folder_id'], $carolScope['content'], 'Space root itself stays out of content');

        $listing = FileManager::listFolder($carol, (int) $project['id']);
        $this->assertSame('inside-project.txt', $listing['files'][0]['name']);
    }

    public function testCrossSpaceMovesAreDeniedForUsers(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice6', 'user');
        $bob = $this->createUser('bob6', 'user');
        $aliceSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $bobSpace = SpaceService::provisionForUser($this->superAdmin(), (int) $bob['id']);

        $aliceFolder = FileManager::createFolder($alice, (int) $aliceSpace['folder_id'], 'Alice stuff');
        $bobFolder = FileManager::createFolder($bob, (int) $bobSpace['folder_id'], 'Bob stuff');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Moving items between spaces is not allowed.');

        // Alice received a write grant inside Bob's space, which would
        // normally allow the edit checks to pass.
        SpaceService::saveFolderGrants($bob, (int) $bobSpace['folder_id'], [
            ['username' => 'alice6', 'level' => 'write'],
        ]);
        FileManager::moveFolder($alice, (int) $aliceFolder['id'], (int) $bobFolder['id']);
    }

    public function testSpaceRootCannotBeRenamedMovedOrDeleted(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice7', 'user');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $spaceRootId = (int) $space['folder_id'];

        $operations = [
            'rename' => fn () => FileManager::renameFolder($alice, $spaceRootId, 'renamed'),
            'owner delete' => fn () => FileManager::deleteFolder($alice, $spaceRootId),
            'admin delete' => fn () => FileManager::deleteFolder($this->superAdmin(), $spaceRootId),
        ];

        foreach ($operations as $label => $closure) {
            try {
                $closure();
                $this->fail("Expected the '$label' operation to fail.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('space root', $exception->getMessage());
            }
        }
    }

    public function testGrantContainmentAndClamping(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice8', 'user');
        $carol = $this->createUser('carol8', 'user');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $outside = $this->createFolder('Outside folder');

        try {
            SpaceService::saveFolderGrants($alice, (int) $outside['id'], [
                ['username' => 'carol8', 'level' => 'write'],
            ]);
            $this->fail('Expected sharing a folder outside the space to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('own space', $exception->getMessage());
        }

        Database::updateSetting('spaces_max_grant_level', 'view');
        SpaceService::saveFolderGrants($alice, (int) $space['folder_id'], [
            ['username' => 'carol8', 'level' => 'write'],
        ]);

        $grants = SpaceService::folderGrants((int) $space['folder_id']);
        $this->assertCount(1, $grants);
        $this->assertSame('view', $grants[0]['level'], 'Write grant is clamped to the policy level');

        Database::updateSetting('spaces_user_sharing_allowed', '0');

        try {
            SpaceService::saveFolderGrants($alice, (int) $space['folder_id'], []);
            $this->fail('Expected sharing to be blocked by policy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled by the administrator', $exception->getMessage());
        }
    }

    public function testOwnerCanCreatePublicShareForOwnSpaceFile(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice9', 'user');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $file = $this->createFile('shared-video.bin', 'video bytes', 'application/octet-stream', (int) $space['folder_id'], $alice);

        $share = FileShares::create($alice, (int) $file['id']);
        $this->assertStringContainsString('/share/?token=', $share['url']);
    }

    public function testSpaceQuotaRejectsBytesAboveLimit(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice10', 'user');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        SpaceService::setSizeLimitForUser((int) $alice['id'], 50);

        $folderId = (int) $space['folder_id'];

        SpaceService::assertWithinSpaceQuota($folderId, 40);
        SpaceService::assertWithinSpaceQuota($folderId, 50);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('space limit');

        SpaceService::assertWithinSpaceQuota($folderId, 51);
    }

    public function testPurgeRemovesSpaceSubtreeAndRequiresSuperAdmin(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('alice11', 'user');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $file = $this->createFile('purge-me.txt', 'purge bytes', 'text/plain', (int) $space['folder_id'], $alice);
        $blobPath = FileManager::blobPathFor((string) $file['disk_name'], (string) $file['disk_extension']);

        $admin = $this->createUser('plainadmin', 'admin');

        try {
            SpaceService::purgeForUser($admin, (int) $alice['id']);
            $this->fail('Expected purge to require super admin.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Only the Super-Admin', $exception->getMessage());
        }

        SpaceService::purgeForUser($this->superAdmin(), (int) $alice['id']);

        $this->assertFileDoesNotExist($blobPath);
        $this->assertFalse($this->spaceRowExists((int) $alice['id']));
        $this->assertFalse($this->folderRowExists((int) $space['folder_id']));
    }

    public function testRootGrantsNeverLeakPrivateSpacesToUsersOrGuests(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('private-owner');
        $bob = $this->createUser('root-reader');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        Database::updateSetting('public_access', '1');
        foreach ([['user', (int) $bob['id'], $bob], ['guest', 0, null]] as [$type, $id, $actor]) {
            Permissions::saveMatrix($this->superAdmin(), $type, $id, [['folder_id' => Database::rootFolderId(), 'can_view' => true, 'can_upload' => true]]);
            $this->assertFalse(Permissions::canViewFolderContents((int) $space['folder_id'], $actor));
            $this->assertFalse(Permissions::canUploadToFolder((int) $space['folder_id'], $actor));
        }
    }

    public function testPolicyChangesImmediatelyRestrictExistingGrantsAndPublicLinks(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('policy-owner');
        $bob = $this->createUser('policy-reader');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $folder = (int) $space['folder_id'];
        SpaceService::saveFolderGrants($alice, $folder, [['username' => $bob['username'], 'level' => 'write']]);
        $file = $this->createFile('link.txt', 'secret', 'text/plain', $folder, $alice);
        $share = FileShares::create($alice, (int) $file['id']);
        Database::updateSetting('spaces_max_grant_level', 'view');
        $this->assertTrue(Permissions::canViewFolderContents($folder, $bob));
        $this->assertFalse(Permissions::canUploadToFolder($folder, $bob));
        SpaceService::setStatusForUser($this->superAdmin(), (int) $alice['id'], SpaceService::STATUS_DISABLED);
        $this->assertFalse(Permissions::canViewFolderContents($folder, $bob));
        $this->expectException(RuntimeException::class);
        FileShares::publicContext($share['token']);
    }

    public function testFullSpaceQuotaUploadCompletesForGrantee(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('quota-owner');
        $bob = $this->createUser('quota-writer');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $folder = (int) $space['folder_id'];
        SpaceService::setSizeLimitForUser((int) $alice['id'], 5);
        SpaceService::saveFolderGrants($alice, $folder, [['username' => $bob['username'], 'level' => 'write']]);
        $init = FileManager::uploadInit($bob, $folder, 'full.txt', 5, 'text/plain', 1);
        file_put_contents(wb_storage_path('chunks/' . $init['upload_token'] . '/0.part'), '12345');
        $file = FileManager::uploadComplete($bob, $init['upload_token']);
        $this->assertSame(5, $file['size']);
        $this->assertSame(5, SpaceService::usageBytes($space));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('space limit');
        FileManager::uploadInit($alice, $folder, 'extra.txt', 1, 'text/plain', 1);
    }

    public function testAdminCannotMoveFilesPastSpaceLimit(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('move-owner');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        SpaceService::setSizeLimitForUser((int) $alice['id'], 1);
        $file = $this->createFile('too-big.txt', '12');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('space limit');
        FileManager::moveFile($this->superAdmin(), (int) $file['id'], (int) $space['folder_id']);
    }

    public function testContainerCannotBypassPurgeAndLegacyNamesRemainSeparate(): void
    {
        $this->enableSpaces();
        $legacy = $this->createFolder('Spaces');
        $alice = $this->createUser('container-owner');
        SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $container = SpaceService::containerFolderId();
        $this->assertNotSame((int) $legacy['id'], $container);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('space root');
        FileManager::deleteFolder($this->superAdmin(), $container);
    }

    public function testCrossSpaceFolderTransferRevokesOldGrantsAndLinks(): void
    {
        $this->enableSpaces();
        $alice = $this->createUser('transfer-owner');
        $bob = $this->createUser('transfer-reader');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $alice['id']);
        $folder = $this->createFolder('Transferred');
        $file = $this->createFile('transferred.txt', 'bytes', 'text/plain', (int) $folder['id']);
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $bob['id'], [['folder_id' => (int) $folder['id'], 'can_view' => true]]);
        FileShares::create($this->superAdmin(), (int) $file['id'], ['delete_after' => gmdate('c', time() + 3600)]);
        FileManager::moveFolder($this->superAdmin(), (int) $folder['id'], (int) $space['folder_id']);
        $this->assertFalse(Permissions::canViewFolderContents((int) $folder['id'], $bob));
        $this->assertTrue(Permissions::canViewFolderContents((int) $folder['id'], $alice));
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM file_shares')->fetchColumn());
    }

    public function testConcurrentReservationsCannotOverbookSpace(): void
    {
        $this->enableSpaces();
        $user = $this->createUser('concurrent-owner');
        $space = SpaceService::provisionForUser($this->superAdmin(), (int) $user['id']);
        SpaceService::setSizeLimitForUser((int) $user['id'], 5);
        $code = 'define("WB_STORAGE", ' . var_export(WB_STORAGE, true) . '); require ' . var_export(WB_ROOT . '/app/bootstrap.php', true) . ';'
            . '$user = ' . var_export($user, true) . '; try { WbFileBrowser\\FileManager::uploadInit($user, '
            . (int) $space['folder_id'] . ', "parallel.txt", 5, "text/plain", 1); echo "reserved"; } catch (RuntimeException $e) { echo $e->getMessage(); }';
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors . implode(' | ', $results));
        }
        $this->assertCount(1, array_filter($results, static fn ($value) => $value === 'reserved'));
        $this->assertCount(1, array_filter($results, static fn ($value) => str_contains($value, 'space limit')));
    }

    private function spaceRowExists(int $userId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM spaces WHERE user_id = :id');
        $statement->execute([':id' => $userId]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function folderRowExists(int $folderId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM folders WHERE id = :id');
        $statement->execute([':id' => $folderId]);

        return (int) $statement->fetchColumn() > 0;
    }
}
