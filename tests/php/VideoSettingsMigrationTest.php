<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Database;
use WbFileBrowser\Installer;
use WbFileBrowser\MediaValidator;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

/**
 * Simulates an installation from before client-side video compression
 * existed, then runs the boot-time migration and verifies that (a) every
 * pre-existing setting, user, and file survives untouched and (b) the new
 * video policy keys are seeded with their defaults.
 */
final class VideoSettingsMigrationTest extends DatabaseTestCase
{
    private const NEW_KEYS = [
        'video_compression_mode',
        'video_max_height',
        'video_max_fps',
        'video_max_video_bitrate_kbps',
        'video_max_audio_bitrate_kbps',
        'video_min_source_mb',
        'video_min_savings_pct',
        'video_ffmpeg_fallback',
        'media_ffprobe_path',
        'dedup_enabled',
        'file_blobs_backfill_v1',
        'automation_share_deletion_interval_minutes',
        'spaces_enabled',
        'spaces_user_sharing_allowed',
        'spaces_max_grant_level',
        'spaces_auto_create_on_user_create',
        'migration_uploader_names_v1',
    ];

    protected function tearDown(): void
    {
        MediaValidator::forceBinaryForTests(null);

        parent::tearDown();
    }

    public function testMigrationPreservesOldDataAndSeedsNewKeys(): void
    {
        $pdo = Database::connection();

        // Rewind to a pre-feature database state.
        $file = $this->createFile('legacy-report.txt', 'important contents', 'text/plain');
        $statement = $pdo->prepare('UPDATE settings SET value = :value WHERE key = :key');
        $statement->execute([':value' => '512', ':key' => 'uploads_max_file_size_mb']);
        $statement->execute([':value' => 'pdf, png', ':key' => 'uploads_allowed_extensions']);
        $statement->execute([':value' => '1', ':key' => 'log_file_uploads']);

        foreach (self::NEW_KEYS as $key) {
            $pdo->prepare('DELETE FROM settings WHERE key = :key')->execute([':key' => $key]);
        }

        $settingsBefore = $this->allSettings();
        $filesBefore = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $foldersBefore = (int) $pdo->query('SELECT COUNT(*) FROM folders')->fetchColumn();

        $this->assertArrayNotHasKey('video_compression_mode', $settingsBefore);

        Installer::migrate();

        // Old data: byte-for-byte identical settings rows (plus only app_version).
        $settingsAfter = $this->allSettings();
        $this->assertSame('512', $settingsAfter['uploads_max_file_size_mb']);
        $this->assertSame('pdf, png', $settingsAfter['uploads_allowed_extensions']);
        $this->assertSame('1', $settingsAfter['log_file_uploads']);

        foreach ($settingsBefore as $key => $value) {
            if ($key === 'app_version') {
                continue;
            }

            $this->assertSame($value, $settingsAfter[$key], "Setting {$key} changed during migration.");
        }

        // The admin's customizations were not clobbered by the defaults.
        $this->assertSame('512', Database::setting('uploads_max_file_size_mb'));

        // New keys are seeded once, with defaults, and nothing else appeared.
        $expectedNew = [
            'video_compression_mode' => 'off',
            'video_max_height' => '1080',
            'video_max_fps' => '60',
            'video_max_video_bitrate_kbps' => '8000',
            'video_max_audio_bitrate_kbps' => '192',
            'video_min_source_mb' => '20',
            'video_min_savings_pct' => '5',
            'video_ffmpeg_fallback' => '1',
            'media_ffprobe_path' => '',
            'dedup_enabled' => '0',
            // The backfill runs to completion inside the same migration, so
            // the flag is already flipped afterwards.
            'file_blobs_backfill_v1' => '1',
            'automation_share_deletion_interval_minutes' => '15',
            'spaces_enabled' => '0',
            'spaces_user_sharing_allowed' => '1',
            'spaces_max_grant_level' => 'write',
            'spaces_auto_create_on_user_create' => '0',
            // The uploader-name backfill completes inside the same migration, so
            // its flag is already flipped afterwards.
            'migration_uploader_names_v1' => '1',
        ];

        foreach ($expectedNew as $key => $value) {
            $this->assertSame($value, $settingsAfter[$key] ?? null, "{$key} was not seeded with its default.");
        }

        $addedKeys = array_values(array_diff(array_keys($settingsAfter), array_keys($settingsBefore)));
        sort($addedKeys);
        $expectedAdded = self::NEW_KEYS;
        sort($expectedAdded);
        $this->assertSame($expectedAdded, $addedKeys);

        // Content data is untouched.
        $this->assertSame($filesBefore, (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertSame($usersBefore, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame($foldersBefore, (int) $pdo->query('SELECT COUNT(*) FROM folders')->fetchColumn());

        $keptFile = $pdo->prepare('SELECT original_name, mime_type, size FROM files WHERE id = :id');
        $keptFile->execute([':id' => $file['id']]);
        $row = $keptFile->fetch();

        $this->assertSame('legacy-report.txt', $row['original_name']);
        $this->assertSame('text/plain', $row['mime_type']);
    }

    public function testMigrationIsIdempotentAndKeepsCustomizedPolicy(): void
    {
        Database::updateSetting('video_compression_mode', 'optional');
        Database::updateSetting('video_max_height', '720');

        Installer::migrate();
        Installer::migrate();

        $this->assertSame('optional', Database::setting('video_compression_mode'));
        $this->assertSame('720', Database::setting('video_max_height'));
        $this->assertSame('60', Database::setting('video_max_fps'));
    }

    /**
     * @return array<string, string>
     */
    private function allSettings(): array
    {
        $rows = Database::connection()
            ->query('SELECT key, value FROM settings')
            ->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];

        return array_map('strval', $rows);
    }
}
