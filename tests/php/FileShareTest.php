<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\FileShares;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileShareTest extends DatabaseTestCase
{
    public function testAdministratorCanCreateAndResolveAShareLink(): void
    {
        $file = $this->createFile('notes.txt', "shared notes\nsecond line");

        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'max_views' => 3,
        ]);
        $payload = FileShares::viewPayload($share['token']);

        $this->assertSame((int) $file['id'], $share['file_id']);
        $this->assertStringContainsString('/share/?token=', $share['url']);
        $this->assertSame($share['token'], FileShares::get($this->superAdmin(), (int) $file['id'])['token']);
        $this->assertSame('text', $payload['preview_mode']);
        $this->assertSame('notes.txt', $payload['file']['name']);
        $this->assertSame("shared notes\nsecond line", $payload['text_preview']);
        $this->assertSame(3, $share['max_views']);
        $this->assertFalse($share['requires_password']);
        $this->assertSame(1, $payload['share']['view_count']);
        $this->assertSame(2, $payload['share']['remaining_views']);
        $this->assertStringContainsString('grant=', $payload['file']['preview_url']);
    }

    public function testRevokingAShareLinkMakesThePublicViewerUnavailable(): void
    {
        $file = $this->createFile('brochure.pdf', '%PDF-1.7 demo', 'application/pdf');
        $share = FileShares::create($this->superAdmin(), (int) $file['id']);

        FileShares::revoke($this->superAdmin(), (int) $file['id']);

        $this->assertNull(FileShares::get($this->superAdmin(), (int) $file['id']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        FileShares::viewPayload($share['token']);
    }

    public function testStandardUsersCannotCreateShareLinks(): void
    {
        $member = $this->createUser('member', 'user');
        $file = $this->createFile('notes.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only administrators can manage share links.');

        FileShares::create($member, (int) $file['id']);
    }

    public function testShareMaxViewsRevokesAfterTheLimitIsReached(): void
    {
        $file = $this->createFile('notes.txt', "shared notes\nsecond line");
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'max_views' => 1,
        ]);

        $payload = FileShares::viewPayload($share['token']);

        $this->assertSame(1, $payload['share']['view_count']);
        $this->assertSame(0, $payload['share']['remaining_views']);
        $this->assertNull(FileShares::get($this->superAdmin(), (int) $file['id']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        FileShares::viewPayload($share['token']);
    }

    public function testExpiredShareIsAutomaticallyRevoked(): void
    {
        $file = $this->createFile('notes.txt', "shared notes\nsecond line");
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'expires_at' => gmdate('c', time() + 300),
        ]);

        $statement = \WbFileBrowser\Database::connection()->prepare(
            'UPDATE file_shares SET expires_at = :expires_at, updated_at = :updated_at WHERE token = :token'
        );
        $statement->execute([
            ':expires_at' => gmdate('c', time() - 60),
            ':updated_at' => wb_now(),
            ':token' => $share['token'],
        ]);

        $this->assertNull(FileShares::get($this->superAdmin(), (int) $file['id']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        FileShares::viewPayload($share['token']);
    }

    public function testSharePayloadIncludesFallbackMetadataForDownloadOnlyFiles(): void
    {
        $file = $this->createFile('client.jar', 'binary data', 'application/zip');
        $share = FileShares::create($this->superAdmin(), (int) $file['id']);
        $payload = FileShares::viewPayload($share['token']);

        $this->assertSame('download', $payload['preview_mode']);
        $this->assertSame('download', $payload['file']['preview_mode']);
        $this->assertSame('jar', $payload['file']['fallback_variant']);
        $this->assertSame('Java archive', $payload['file']['fallback_label']);
        $this->assertStringContainsString('/media/file-fallbacks/jar.svg', $payload['file']['fallback_icon_url']);
    }

    public function testPasswordProtectedSharesRequireUnlockAndInvalidateOldSessions(): void
    {
        $file = $this->createFile('vault.txt', 'classified');
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'password' => 'Secret 123',
        ]);
        $context = FileShares::publicContext($share['token']);

        $this->assertTrue($share['requires_password']);
        $this->assertFalse($context['is_unlocked']);
        $this->assertTrue($context['share']['requires_password']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Share password is required.');
        FileShares::viewPayload($share['token']);
    }

    public function testSharePasswordUnlockAndRemovalFlow(): void
    {
        $file = $this->createFile('vault.txt', 'classified');
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'password' => 'Secret 123',
        ]);

        $this->assertFalse(FileShares::unlock($share['token'], 'wrong password'));
        $this->assertTrue(FileShares::unlock($share['token'], 'Secret 123'));
        $this->assertTrue(FileShares::publicContext($share['token'])['is_unlocked']);
        $this->assertSame('vault.txt', FileShares::viewPayload($share['token'])['file']['name']);

        $updated = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'password' => 'Second Secret',
        ]);
        $this->assertTrue($updated['requires_password']);
        $this->assertFalse(FileShares::publicContext($share['token'])['is_unlocked']);
        $this->assertFalse(FileShares::unlock($share['token'], 'Secret 123'));
        $this->assertTrue(FileShares::unlock($share['token'], 'Second Secret'));

        $cleared = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'clear_password' => true,
        ]);
        $this->assertFalse($cleared['requires_password']);
        $this->assertFalse(FileShares::publicContext($share['token'])['share']['requires_password']);
        $this->assertSame('vault.txt', FileShares::viewPayload($share['token'])['file']['name']);
    }
}
