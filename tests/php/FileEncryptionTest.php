<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use InvalidArgumentException;
use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileEncryption;
use WbFileBrowser\FileManager;
use WbFileBrowser\FileShares;
use WbFileBrowser\Installer;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileEncryptionTest extends DatabaseTestCase
{
    private function begin(string $format = FileEncryption::FORMAT, string $bytes = ''): string
    {
        $bytes = $bytes === '' ? $this->container() : $bytes;
        $upload = FileManager::uploadInit($this->superAdmin(), Database::rootFolderId(), 'private.txt', strlen($bytes), 'text/plain', 1, [], $format);
        $token = $upload['upload_token'];
        file_put_contents(wb_storage_path('chunks/' . $token . '/0.part'), $bytes);
        return $token;
    }

    // Structural fixture only: the server cannot authenticate a container without its password.
    private function container(): string
    {
        return FileEncryption::FORMAT . str_repeat("\x7a", 24) . pack('V2', 5, 0) . str_repeat("\0", 8) . str_repeat("\x33", 16 + 5 + 16);
    }

    public function testMigrationPreservesOldFilesAndSettingsAndIsIdempotent(): void
    {
        $file = $this->createFile('legacy.txt', 'legacy content');
        $pdo = Database::connection();
        $pdo->exec('ALTER TABLE files DROP COLUMN encryption_format');
        $pdo->exec("DELETE FROM settings WHERE key = 'uploads_encryption_mode'");
        Installer::migrate();
        Installer::migrate();
        $row = $pdo->query('SELECT * FROM files WHERE id = ' . (int) $file['id'])->fetch();
        self::assertSame('', $row['encryption_format']);
        self::assertSame($file['checksum'], $row['checksum']);
        self::assertSame('off', Settings::uploadPolicy()['encryption_mode']);
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required']]);
        Installer::migrate();
        self::assertSame('required', Settings::uploadPolicy()['encryption_mode']);
    }

    public function testDisabledRejectsEncryptedUploads(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled');
        $this->begin();
    }

    public function testRequiredRejectsPlainUploads(): void
    {
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires local file encryption');
        $this->begin('', 'plain');
    }

    public function testPolicyChangeRejectsAnInflightPlainUpload(): void
    {
        $token = $this->begin('', 'plain');
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires local file encryption');
        FileManager::uploadComplete($this->superAdmin(), $token);
    }

    public function testEncryptedUploadKeepsMetadataAndDisablesPreviewsIncludingSharesAfterRename(): void
    {
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required', 'dedup_enabled' => true]]);
        $file = FileManager::uploadComplete($this->superAdmin(), $this->begin());
        self::assertSame(FileEncryption::FORMAT, $file['encryption_format']);
        self::assertSame('download', $file['preview_mode']);
        $id = (int) $file['id'];
        Database::connection()->prepare('UPDATE files SET original_name = :name WHERE id = :id')->execute([':name' => 'renamed.html', ':id' => $id]);
        $share = FileShares::create($this->superAdmin(), $id);
        $payload = FileShares::viewPayload($share['token']);
        self::assertSame('download', $payload['file']['preview_mode']);
        self::assertSame(FileEncryption::FORMAT, $payload['file']['encryption_format']);
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'off']]);
        self::assertSame(FileEncryption::FORMAT, Database::connection()->query('SELECT encryption_format FROM files WHERE id = ' . $id)->fetchColumn());
    }

    public function testMalformedCiphertextIsNotStored(): void
    {
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'optional']]);
        $token = $this->begin(FileEncryption::FORMAT, str_repeat('x', 85));
        try {
            FileManager::uploadComplete($this->superAdmin(), $token);
            self::fail('Invalid header accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Invalid encrypted file header', $error->getMessage());
        }
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testRejectsIncompatibleVideoPolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Settings::normalizePayload(['uploads' => ['encryption_mode' => 'required'], 'video_compression' => ['mode' => 'required']]);
    }

    public function testEncryptionStillEnforcesExtensionPolicy(): void
    {
        Settings::saveAdminSettings(['uploads' => ['encryption_mode' => 'required', 'allowed_extensions' => 'png']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Allowed types');
        $this->begin();
    }
}
