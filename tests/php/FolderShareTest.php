<?php
declare(strict_types=1);
namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\{Database, FileManager, FolderShares, Permissions, Settings, SpaceService};
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FolderShareTest extends DatabaseTestCase
{
    public function testViewLinkCoversDescendantsButCannotWriteOrEscape(): void
    {
        $folder = $this->createFolder('Shared');
        $child = $this->createFolder('Child', $folder['id']);
        $outside = $this->createFolder('Private');
        $link = FolderShares::create($this->superAdmin(), $folder['id'], []);
        $this->assertStringStartsWith('http://localhost/share/folder.php?token=', $link['url']);
        $actor = FolderShares::actor($link['token']);
        $this->assertTrue(Permissions::canViewFolderContents($child['id'], $actor));
        $this->assertFalse(Permissions::canViewFolderContents($outside['id'], $actor));
        $this->assertFalse(Permissions::canUploadToFolder($folder['id'], $actor));
        $this->assertSame('Child', FileManager::listFolder($actor, $folder['id'])['folders'][0]['name']);
        $this->expectException(RuntimeException::class);
        FileManager::createFolder($actor, $folder['id'], 'Forbidden');
    }

    public function testWriteLinkUsesExistingFolderAndUploadPermissions(): void
    {
        $folder = $this->createFolder('Shared');
        $link = FolderShares::create($this->superAdmin(), $folder['id'], ['access_level' => 'write']);
        $actor = FolderShares::actor($link['token']);
        $child = FileManager::createFolder($actor, $folder['id'], 'Guest folder');
        $actor = FolderShares::actor($link['token']);
        FileManager::renameFolder($actor, $child['id'], 'Renamed');
        $upload = FileManager::uploadInit($actor, $folder['id'], 'hello.txt', 5, 'text/plain', 1);
        $this->assertNotEmpty($upload['upload_token']);
        FileManager::uploadCancel($actor, $upload['upload_token']);
        FileManager::deleteFolder($actor, $child['id']);
        $this->assertEmpty(FileManager::listFolder($actor, $folder['id'])['folders']);
    }

    public function testPasswordChangeAndRevocationInvalidateGuestAccess(): void
    {
        $folder = $this->createFolder('Shared');
        $link = FolderShares::create($this->superAdmin(), $folder['id'], ['password' => 'first-password']);
        $this->assertFalse(FolderShares::context($link['token'])['unlocked']);
        $this->assertTrue(FolderShares::context($link['token'], 'first-password')['unlocked']);
        FolderShares::create($this->superAdmin(), $folder['id'], ['password' => 'second-password']);
        $this->assertFalse(FolderShares::context($link['token'])['unlocked']);
        FolderShares::revoke($this->superAdmin(), $folder['id']);
        $this->expectException(RuntimeException::class);
        FolderShares::context($link['token']);
    }

    public function testViewLimitCountsVisitsRatherThanEveryFileRequest(): void
    {
        $folder = $this->createFolder('Shared');
        $link = FolderShares::create($this->superAdmin(), $folder['id'], ['max_views' => 1]);
        FolderShares::actor($link['token']);
        FolderShares::actor($link['token']);
        $this->assertSame(1, FolderShares::get($this->superAdmin(), $folder['id'])['view_count']);
        unset($_SESSION['folder_share_access']);
        $this->expectException(RuntimeException::class);
        FolderShares::actor($link['token']);
    }

    public function testSitePolicyDowngradesExistingWriteLinksAndDisablesLinks(): void
    {
        Settings::saveAdminSettings(['spaces' => ['enabled' => true]]);
        $user = $this->createUser('owner');
        $space = SpaceService::provisionForUser($this->superAdmin(), $user['id']);
        $folderId = (int) $space['folder_id'];
        $link = FolderShares::create($user, $folderId, ['access_level' => 'write']);
        $this->assertTrue(Permissions::canUploadToFolder($folderId, FolderShares::actor($link['token'])));
        Settings::saveAdminSettings(['access' => ['user_link_share_level' => 'view']]);
        $this->assertFalse(Permissions::canUploadToFolder($folderId, FolderShares::actor($link['token'])));
        Settings::saveAdminSettings(['access' => ['user_link_share_level' => 'none']]);
        $this->expectException(RuntimeException::class);
        FolderShares::actor($link['token']);
    }

    public function testScheduledDeletionRemovesFolderAndDescendants(): void
    {
        $folder = $this->createFolder('Shared');
        $this->createFolder('Child', $folder['id']);
        FolderShares::create($this->superAdmin(), $folder['id'], ['delete_after' => gmdate('c', time() + 3600)]);
        Database::connection()->prepare('UPDATE folder_shares SET delete_after=?')->execute([gmdate('c', time() - 60)]);
        FolderShares::processDueDeletions();
        $q = Database::connection()->prepare('SELECT COUNT(*) FROM folders WHERE id=?');
        $q->execute([$folder['id']]);
        $this->assertSame(0, (int) $q->fetchColumn());
    }
}
