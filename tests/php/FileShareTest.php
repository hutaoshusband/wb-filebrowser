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

        $share = FileShares::create($this->superAdmin(), (int) $file['id']);
        $payload = FileShares::viewPayload($share['token']);

        $this->assertSame((int) $file['id'], $share['file_id']);
        $this->assertStringContainsString('/share/?token=', $share['url']);
        $this->assertSame($share['token'], FileShares::get($this->superAdmin(), (int) $file['id'])['token']);
        $this->assertSame('text', $payload['preview_mode']);
        $this->assertSame('notes.txt', $payload['file']['name']);
        $this->assertSame("shared notes\nsecond line", $payload['text_preview']);
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
}
