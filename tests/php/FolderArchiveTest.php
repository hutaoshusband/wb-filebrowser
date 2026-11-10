<?php
declare(strict_types=1);
namespace WbFileBrowser\Tests;

use RuntimeException;
use ZipArchive;
use WbFileBrowser\{Database, FolderArchive, FolderShares, Permissions};
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FolderArchiveTest extends DatabaseTestCase
{
    public function testTreePreservesEmptyFoldersAndStoresBytesWithoutCompression(): void
    {
        $root = $this->createFolder('Export');
        $child = $this->createFolder('Child', $root['id']);
        $this->createFolder('Empty', $child['id']);
        $this->createFile('hello.txt', 'hello', folderId: $root['id']);
        $encrypted = $this->createFile('secret.txt', 'ciphertext', folderId: $child['id']);
        Database::connection()->prepare('UPDATE files SET encryption_format=? WHERE id=?')->execute(['wbenc-v1', $encrypted['id']]);
        $archive = FolderArchive::create($this->superAdmin(), $root['id']);
        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive['path']));
            self::assertSame('hello', $zip->getFromName('Export/hello.txt'));
            self::assertSame('ciphertext', $zip->getFromName('Export/Child/secret.txt.wbencrypted'));
            self::assertNotFalse($zip->locateName('Export/Child/Empty/'));
            self::assertSame(ZipArchive::CM_STORE, $zip->statName('Export/hello.txt')['comp_method']);
            $zip->close();
        } finally { unlink($archive['path']); }
    }

    public function testOnlyReadableContentsAreIncludedThroughVisibleAncestors(): void
    {
        $root = $this->createFolder('Export');
        $child = $this->createFolder('Allowed', $root['id']);
        $hidden = $this->createFolder('Hidden', $root['id']);
        $this->createFile('private.txt', 'private', folderId: $root['id']);
        $this->createFile('hidden.txt', 'hidden', folderId: $hidden['id']);
        $this->createFile('allowed.txt', 'allowed', folderId: $child['id']);
        $user = $this->createUser();
        Permissions::saveMatrix($this->superAdmin(), 'user', $user['id'], [['folder_id' => $child['id'], 'can_view' => true]]);
        $archive = FolderArchive::create($user, $root['id']);
        try {
            $zip = new ZipArchive();
            $zip->open($archive['path']);
            self::assertSame('allowed', $zip->getFromName('Export/Allowed/allowed.txt'));
            self::assertFalse($zip->locateName('Export/private.txt'));
            self::assertFalse($zip->locateName('Export/Hidden/'));
            $zip->close();
        } finally { unlink($archive['path']); }
        $this->expectException(RuntimeException::class);
        FolderArchive::create($user, $hidden['id']);
    }

    public function testShareCannotDownloadOutsideItsRoot(): void
    {
        $root = $this->createFolder('Shared');
        $outside = $this->createFolder('Outside');
        $link = FolderShares::create($this->superAdmin(), $root['id'], []);
        $this->expectException(RuntimeException::class);
        FolderArchive::create(FolderShares::actor($link['token']), $outside['id']);
    }

    public function testShareDownloadsDescendantsAndRevocationPreventsFurtherExports(): void
    {
        $root = $this->createFolder('Shared');
        $child = $this->createFolder('Child', $root['id']);
        $this->createFile('test.txt', 'shared bytes', folderId: $child['id']);
        $link = FolderShares::create($this->superAdmin(), $root['id'], []);
        $actor = FolderShares::actor($link['token']);
        $archive = FolderArchive::create($actor, $root['id']);
        try {
            $zip = new ZipArchive();
            $zip->open($archive['path']);
            self::assertSame('shared bytes', $zip->getFromName('Shared/Child/test.txt'));
            $zip->close();
        } finally { unlink($archive['path']); }
        FolderShares::revoke($this->superAdmin(), $root['id']);
        $this->expectException(RuntimeException::class);
        FolderArchive::create($actor, $root['id']);
    }

    public function testUnauthenticatedExportIsDeniedWhenPublicAccessIsDisabled(): void
    {
        $this->expectException(RuntimeException::class);
        FolderArchive::create(null, Database::rootFolderId());
    }

    public function testUnsafeArchiveNamesAreRejected(): void
    {
        $root = $this->createFolder('Export');
        $file = $this->createFile('safe.txt', 'secret', folderId: $root['id']);
        Database::connection()->prepare('UPDATE files SET original_name=? WHERE id=?')->execute(['../escape.txt', $file['id']]);
        $before = glob(sys_get_temp_dir() . '/wb-zip-*');
        try {
            FolderArchive::create($this->superAdmin(), $root['id']);
            self::fail('Unsafe archive entry accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unsafe archive name', $error->getMessage());
        }
        self::assertSame($before, glob(sys_get_temp_dir() . '/wb-zip-*'));
    }
}
