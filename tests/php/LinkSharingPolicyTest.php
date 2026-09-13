<?php
declare(strict_types=1);
namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\{Database, FileManager, FileShares, Installer, Permissions, Settings};
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class LinkSharingPolicyTest extends DatabaseTestCase
{
    public function testOwnUploadsCanBeSharedWithoutSpacesAndOverridesApplyToExistingLinks(): void
    {
        $user = $this->createUser('uploader');
        $folder = $this->createFolder('uploads');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $user['id'], [['folder_id' => $folder['id'], 'can_view' => true]]);
        $file = $this->createFile('mine.txt', 'hello', 'text/plain', (int) $folder['id'], $user);
        $other = $this->createFile('not-mine.txt', 'private', 'text/plain', (int) $folder['id']);
        $this->assertFalse(FileShares::canManageFile($user, $other));
        $link = FileShares::create($user, (int) $file['id']);
        $this->assertNotEmpty(FileShares::publicContext($link['token']));
        Database::updateSetting('user_link_shares_allowed', '0');
        $this->assertFalse(FileShares::canManageFile($user, $file));
        try {
            FileShares::publicContext($link['token']);
            $this->fail('Disabled user links must not resolve.');
        } catch (RuntimeException $error) {
            $this->assertSame('Shared file not found.', $error->getMessage());
        }
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET link_shares_allowed = 1 WHERE id = ?')->execute([$user['id']]);
        $this->assertTrue(FileShares::canManageFile($user, $file));
        $this->assertNotEmpty(FileShares::publicContext($link['token']));
        Database::updateSetting('user_link_shares_allowed', '1');
        $pdo->prepare('UPDATE users SET link_shares_allowed = 0 WHERE id = ?')->execute([$user['id']]);
        $this->assertFalse(FileShares::canManageFile($user, $file));
        $this->expectException(RuntimeException::class);
        FileShares::create($user, (int) $file['id']);
    }

    public function testMigrationBackfillsUploaderWithoutRenamingFilesAndCanHideIt(): void
    {
        $user = $this->createUser('legacy-uploader');
        $file = $this->createFile('original.txt', 'original bytes', 'text/plain', null, $user);
        $pdo = Database::connection();
        $pdo->exec('ALTER TABLE users DROP COLUMN link_shares_allowed');
        $pdo->exec('ALTER TABLE files DROP COLUMN uploader_username');
        $pdo->exec("DELETE FROM settings WHERE key IN ('migration_uploader_names_v1', 'user_link_shares_allowed', 'display_show_uploader')");
        Installer::migrate();
        Installer::migrate();
        $row = $pdo->query('SELECT * FROM files WHERE id = ' . (int) $file['id'])->fetch();
        $this->assertSame('original.txt', $row['original_name']);
        $this->assertSame('legacy-uploader', $row['uploader_username']);
        $this->assertSame($file['checksum'], $row['checksum']);
        $this->assertSame('1', Database::setting('user_link_shares_allowed'));
        $shown = FileManager::fileDetails($this->superAdmin(), (int) $file['id']);
        $this->assertSame('legacy-uploader', $shown['uploader_username']);
        Settings::saveAdminSettings(['display' => ['show_uploader' => false]]);
        $this->assertNull(FileManager::fileDetails($this->superAdmin(), (int) $file['id'])['uploader_username']);
        $this->assertSame('legacy-uploader', $pdo->query('SELECT uploader_username FROM files')->fetchColumn());
    }
}
