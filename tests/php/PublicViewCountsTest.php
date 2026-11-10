<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\{Database, FileShares, Installer, Settings};
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class PublicViewCountsTest extends DatabaseTestCase
{
    public function testHiddenCountsStillRecordViewsAndEnforceLimits(): void
    {
        self::assertFalse(Settings::publicViewCountsEnabled());
        $file = $this->createFile('views.txt', 'hello');
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], ['max_views' => 2]);
        $payload = FileShares::viewPayload($share['token']);
        self::assertNull($payload['public_view_count']);
        self::assertSame(1, $payload['share']['view_count']);
        self::assertSame(1, FileShares::get($this->superAdmin(), (int) $file['id'])['view_count']);
        FileShares::viewPayload($share['token']);
        $this->expectException(RuntimeException::class);
        FileShares::viewPayload($share['token']);
    }

    public function testOneSettingControlsAllShareAndEmbedCountsWithoutAddingViews(): void
    {
        $first = $this->createFile('first.mp4', 'video', 'video/mp4');
        $second = $this->createFile('second.mp4', 'video two', 'video/mp4');
        $links = [];
        foreach ([$first, $second] as $file) {
            $links[] = FileShares::create($this->superAdmin(), (int) $file['id'], ['allow_embed' => true]);
        }
        self::assertNull(FileShares::embedPagePayload($links[0]['token'])['public_view_count']);
        Settings::saveAdminSettings(['access' => ['public_view_counts_enabled' => true]]);
        self::assertTrue(Settings::adminPayload()['settings']['access']['public_view_counts_enabled']);
        // An unrelated settings save must preserve the master switch.
        Settings::saveAdminSettings(['access' => ['public_access' => false]]);
        foreach ($links as $share) {
            self::assertSame(0, FileShares::embedPagePayload($share['token'])['public_view_count']);
            self::assertSame(1, FileShares::viewPayload($share['token'])['public_view_count']);
            self::assertSame(1, FileShares::embedPagePayload($share['token'])['public_view_count']);
        }
        Settings::saveAdminSettings(['access' => ['public_view_counts_enabled' => false]]);
        foreach ($links as $share) {
            self::assertNull(FileShares::embedPagePayload($share['token'])['public_view_count']);
            self::assertNull(FileShares::viewPayload($share['token'])['public_view_count']);
            self::assertSame(2, FileShares::get($this->superAdmin(), $share['file_id'])['view_count']);
        }
    }

    public function testMigrationSeedsTheSwitchAndPreservesExistingDataAndPreference(): void
    {
        $file = $this->createFile('old.txt', 'existing file');
        $share = FileShares::create($this->superAdmin(), (int) $file['id']);
        FileShares::viewPayload($share['token']);
        Database::connection()->exec("DELETE FROM settings WHERE key = 'public_view_counts_enabled'");
        Installer::migrate();
        self::assertSame('0', Database::setting('public_view_counts_enabled', 'missing'));
        self::assertSame(1, FileShares::get($this->superAdmin(), (int) $file['id'])['view_count']);
        Settings::saveAdminSettings(['access' => ['public_view_counts_enabled' => true]]);
        Installer::migrate();
        self::assertTrue(Settings::publicViewCountsEnabled());
    }
}
