<?php
declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\{Database, FileManager, FolderShares, Permissions, Settings, SpaceService};
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class SecurityBoundaryRegressionTest extends DatabaseTestCase
{
    public function testEmptyPathCannotReadAnInaccessibleFolder(): void
    {
        $private = $this->createFolder('Private');
        $user = $this->createUser();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('access to this folder');
        FileManager::ensureFolderPath($user, $private['id'], []);
    }

    public function testExistingPathCannotReadAnInaccessibleSibling(): void
    {
        $parent = $this->createFolder('Parent');
        $allowed = $this->createFolder('Allowed', $parent['id']);
        $this->createFolder('Private', $parent['id']);
        $user = $this->createUser();
        Permissions::saveMatrix($this->superAdmin(), 'user', $user['id'], [
            ['folder_id' => $allowed['id'], 'can_view' => true],
        ]);
        self::assertSame($parent['id'], FileManager::ensureFolderPath($user, $parent['id'], [])['id']);
        self::assertSame($allowed['id'], FileManager::ensureFolderPath($user, $parent['id'], ['Allowed'])['id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('access to this folder');
        FileManager::ensureFolderPath($user, $parent['id'], ['Private']);
    }

    public function testDelegatedUploadsShareTheOwnersReservationBudget(): void
    {
        [$owner, $folder, $actor] = $this->quotaLink();
        $reservation = FileManager::uploadInit($owner, $folder, 'normal.txt', 600, 'text/plain', 1);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('user quota');
            FileManager::uploadInit($actor, $folder, 'delegated.txt', 500, 'text/plain', 1);
        } finally {
            FileManager::uploadCancel($owner, $reservation['upload_token']);
        }
    }

    public function testDelegatedCompletionRechecksCurrentQuota(): void
    {
        [$owner, $folder, $actor] = $this->quotaLink();
        $upload = FileManager::uploadInit($actor, $folder, 'delegated.txt', 600, 'text/plain', 1);
        file_put_contents(wb_storage_path('chunks/' . $upload['upload_token'] . '/0.part'), str_repeat('A', 600));
        $this->setUserStorageQuota($owner['id'], 500);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');
        try {
            FileManager::uploadComplete($actor, $upload['upload_token']);
        } finally {
            self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM files')->fetchColumn());
            FileManager::uploadCancel($actor, $upload['upload_token']);
        }
    }

    public function testDelegatedUploadWithinQuotaStillCompletes(): void
    {
        [$owner, $folder, $actor] = $this->quotaLink();
        $upload = FileManager::uploadInit($actor, $folder, 'delegated.txt', 5, 'text/plain', 1);
        file_put_contents(wb_storage_path('chunks/' . $upload['upload_token'] . '/0.part'), 'hello');
        $file = FileManager::uploadComplete($actor, $upload['upload_token']);
        self::assertSame(5, $file['size']);
        self::assertSame($owner['id'], (int) Database::connection()->query('SELECT created_by FROM files')->fetchColumn());
    }

    public function testDelegatedReservationsCountAgainstNormalUploadsAndCanBeReleased(): void
    {
        [$owner, $folder, $actor] = $this->quotaLink();
        $upload = FileManager::uploadInit($actor, $folder, 'delegated.txt', 600, 'text/plain', 1);
        try {
            FileManager::uploadInit($owner, $folder, 'normal.txt', 500, 'text/plain', 1);
            self::fail('The delegated reservation must count against the owner.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('user quota', $exception->getMessage());
        }
        FileManager::uploadCancel($actor, $upload['upload_token']);
        $next = FileManager::uploadInit($owner, $folder, 'normal.txt', 500, 'text/plain', 1);
        self::assertNotEmpty($next['upload_token']);
        FileManager::uploadCancel($owner, $next['upload_token']);
    }

    public function testAdministratorLinkQuotaExemptionAndDemotionAreRechecked(): void
    {
        [$owner, $folder, $actor] = $this->quotaLink();
        $this->setUserStorageQuota($owner['id'], 5);
        Database::connection()->prepare('UPDATE users SET role=? WHERE id=?')->execute(['admin', $owner['id']]);
        $upload = FileManager::uploadInit($actor, $folder, 'delegated.txt', 10, 'text/plain', 1);
        file_put_contents(wb_storage_path('chunks/' . $upload['upload_token'] . '/0.part'), '0123456789');
        Database::connection()->prepare('UPDATE users SET role=? WHERE id=?')->execute(['user', $owner['id']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('user quota');
        try {
            FileManager::uploadComplete($actor, $upload['upload_token']);
        } finally {
            FileManager::uploadCancel($actor, $upload['upload_token']);
        }
    }

    public function testRecursiveDeletePreflightsAllLocksBeforeChangingAnything(): void
    {
        [$user, $folder, $child, $plain, $locked] = $this->lockedTree();
        try {
            FileManager::deleteFolder($user, $folder['id']);
            self::fail('Expected the administrator file lock to reject deletion.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('locked', $exception->getMessage());
        }
        self::assertSame(2, (int) Database::connection()->query('SELECT COUNT(*) FROM files')->fetchColumn());
        foreach ([$plain, $locked] as $file) {
            $name = $file['disk_name'];
            self::assertFileExists(wb_storage_path('uploads/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . $name . '.blob'));
        }
        self::assertSame($folder['id'], (int) Database::connection()->query('SELECT parent_id FROM folders WHERE id=' . $child['id'])->fetchColumn());
        FileManager::deleteFolder($this->superAdmin(), $folder['id']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testRecursiveMoveCannotRehomeLockedFiles(): void
    {
        [$user, $folder] = $this->lockedTree();
        $destination = $this->createFolder('Destination');
        Permissions::saveMatrix($this->superAdmin(), 'user', $user['id'], [
            ['folder_id' => Database::rootFolderId(), 'can_view' => true, 'can_edit' => true, 'can_delete' => true],
        ]);
        $before = Database::connection()->query('SELECT parent_id FROM folders WHERE id=' . $folder['id'])->fetchColumn();
        try {
            FileManager::moveFolder($user, $folder['id'], $destination['id']);
            self::fail('Expected the administrator file lock to reject relocation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('locked', $exception->getMessage());
        }
        self::assertSame($before, Database::connection()->query('SELECT parent_id FROM folders WHERE id=' . $folder['id'])->fetchColumn());
        FileManager::moveFolder($this->superAdmin(), $folder['id'], $destination['id']);
        self::assertSame($destination['id'], (int) Database::connection()->query('SELECT parent_id FROM folders WHERE id=' . $folder['id'])->fetchColumn());
    }

    private function quotaLink(): array
    {
        Settings::saveAdminSettings(['spaces' => ['enabled' => true]]);
        $owner = $this->createUser('quota-owner');
        $this->setUserStorageQuota($owner['id'], 1000);
        $space = SpaceService::provisionForUser($this->superAdmin(), $owner['id']);
        $folder = (int) $space['folder_id'];
        $link = FolderShares::create($owner, $folder, ['access_level' => 'write']);
        return [$owner, $folder, FolderShares::actor($link['token'])];
    }

    private function lockedTree(): array
    {
        $user = $this->createUser('editor');
        $folder = $this->createFolder('Parent');
        $child = $this->createFolder('Nested', $folder['id']);
        $plain = $this->createFile('own.txt', 'own', folderId: $folder['id'], actor: $user);
        $locked = $this->createFile('locked.txt', 'locked', folderId: $child['id']);
        Permissions::saveMatrix($this->superAdmin(), 'user', $user['id'], [
            ['folder_id' => $folder['id'], 'can_view' => true, 'can_edit' => true, 'can_delete' => true],
        ]);
        return [$user, $folder, $child, $plain, $locked];
    }
}
