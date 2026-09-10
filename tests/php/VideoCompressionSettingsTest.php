<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use InvalidArgumentException;
use WbFileBrowser\Database;
use WbFileBrowser\MediaValidator;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class VideoCompressionSettingsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        MediaValidator::forceBinaryForTests(null);

        parent::tearDown();
    }

    public function testDefaultsAreDisabledAndSeeded(): void
    {
        $group = Settings::grouped()['video_compression'];

        $this->assertSame('off', $group['mode']);
        $this->assertSame(1080, $group['max_height']);
        $this->assertSame(60, $group['max_fps']);
        $this->assertSame(8000, $group['max_video_bitrate_kbps']);
        $this->assertSame(192, $group['max_audio_bitrate_kbps']);
        $this->assertSame(20, $group['min_source_mb']);
        $this->assertSame(5, $group['min_savings_pct']);
        $this->assertTrue($group['ffmpeg_fallback']);
        $this->assertSame('', $group['media_ffprobe_path']);

        foreach ([
            'video_compression_mode' => 'off',
            'video_max_height' => '1080',
            'video_max_fps' => '60',
            'video_max_video_bitrate_kbps' => '8000',
            'video_max_audio_bitrate_kbps' => '192',
            'video_min_source_mb' => '20',
            'video_min_savings_pct' => '5',
            'video_ffmpeg_fallback' => '1',
            'media_ffprobe_path' => '',
        ] as $key => $expected) {
            $this->assertSame($expected, Database::setting($key), $key);
        }
    }

    public function testUploadPolicyExposesVideoCompressionToClients(): void
    {
        Settings::saveAdminSettings(['video_compression' => [
            'mode' => 'required',
            'max_height' => 720,
            'max_fps' => 30,
            'max_video_bitrate_kbps' => 4000,
            'max_audio_bitrate_kbps' => 128,
            'min_source_mb' => 5,
            'min_savings_pct' => 10,
        ]]);

        // required mode needs ffprobe; make sure it is resolvable.
        MediaValidator::forceBinaryForTests(null);
        $available = MediaValidator::isAvailable();

        if (!$available) {
            // Without ffprobe on the machine, saving required mode must refuse.
            $this->assertRequiredModeWasRefused();
            return;
        }

        $policy = Settings::uploadPolicy()['video_compression'];

        $this->assertSame('required', $policy['mode']);
        $this->assertSame(720, $policy['max_height']);
        // The 16:9 width is derived from the height class.
        $this->assertSame(1280, $policy['max_width']);
        $this->assertSame(30, $policy['max_fps']);
        $this->assertSame(4000, $policy['max_video_bitrate_kbps']);
        $this->assertSame(128, $policy['max_audio_bitrate_kbps']);
        $this->assertSame(5, $policy['min_source_mb']);
        $this->assertSame(10, $policy['min_savings_pct']);
    }

    public function testSettingsRoundTripViaAdminSave(): void
    {
        $saved = Settings::saveAdminSettings(['video_compression' => [
            'mode' => 'optional',
            'max_height' => 2160,
            'max_fps' => 120,
            'max_video_bitrate_kbps' => 20000,
            'max_audio_bitrate_kbps' => 256,
            'min_source_mb' => 50,
            'min_savings_pct' => 15,
            'ffmpeg_fallback' => false,
        ]]);

        $this->assertSame('optional', $saved['video_compression']['mode']);
        $this->assertSame(2160, $saved['video_compression']['max_height']);
        $this->assertSame(120, $saved['video_compression']['max_fps']);
        $this->assertSame(20000, $saved['video_compression']['max_video_bitrate_kbps']);
        $this->assertSame(256, $saved['video_compression']['max_audio_bitrate_kbps']);
        $this->assertSame(50, $saved['video_compression']['min_source_mb']);
        $this->assertSame(15, $saved['video_compression']['min_savings_pct']);
        $this->assertFalse($saved['video_compression']['ffmpeg_fallback']);

        $reloaded = Settings::grouped()['video_compression'];

        $this->assertSame('optional', $reloaded['mode']);
        $this->assertSame(2160, $reloaded['max_height']);
        $this->assertFalse($reloaded['ffmpeg_fallback']);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('off, optional, or required');

        Settings::saveAdminSettings(['video_compression' => ['mode' => 'sometimes']]);
    }

    public function testOutOfRangeValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Settings::saveAdminSettings(['video_compression' => ['max_height' => 100]]);
    }

    public function testMissingGroupKeepsCurrentValues(): void
    {
        Settings::saveAdminSettings(['video_compression' => ['mode' => 'optional', 'min_source_mb' => 7]]);

        Settings::saveAdminSettings(['uploads' => ['max_file_size_mb' => 10]]);

        $group = Settings::grouped()['video_compression'];

        $this->assertSame('optional', $group['mode']);
        $this->assertSame(7, $group['min_source_mb']);
    }

    public function testRequiredModeNeedsFfprobe(): void
    {
        MediaValidator::forceBinaryForTests('Z:/definitely/not/ffprobe.exe');

        try {
            Settings::saveAdminSettings(['video_compression' => ['mode' => 'required']]);
            $this->fail('Saving required mode without ffprobe must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ffprobe', $exception->getMessage());
        }

        $this->assertNotSame('required', Settings::grouped()['video_compression']['mode']);
    }

    public function testRequiredModeCanBeEnabledWithAnFfprobePathInTheSameSave(): void
    {
        $binary = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN'
            ? trim((string) (shell_exec('where ffprobe 2>NUL') ?? ''))
            : trim((string) (shell_exec('command -v ffprobe 2>/dev/null') ?? ''));
        $binary = $binary === '' ? '' : explode("\n", $binary)[0];

        if ($binary === '' || !is_file($binary)) {
            $this->markTestSkipped('ffprobe is not installed on this machine.');
        }

        // The path is not stored yet when required mode is validated.
        $saved = Settings::saveAdminSettings(['video_compression' => [
            'mode' => 'required',
            'media_ffprobe_path' => $binary,
        ]]);

        $this->assertSame('required', $saved['video_compression']['mode']);
        $this->assertSame($binary, $saved['video_compression']['media_ffprobe_path']);
        $this->assertSame('required', Settings::videoCompressionPolicy()['mode']);
    }

    public function testStaleFfprobePathDoesNotWedgeUnrelatedSaves(): void
    {
        Database::updateSetting('media_ffprobe_path', 'Z:/gone/ffprobe.exe');

        // Saving another group keeps the stored (stale) path instead of failing.
        $saved = Settings::saveAdminSettings(['uploads' => ['max_file_size_mb' => 10]]);

        $this->assertSame('Z:/gone/ffprobe.exe', $saved['video_compression']['media_ffprobe_path']);
        $this->assertSame(10, $saved['uploads']['max_file_size_mb']);
        $this->assertSame('Z:/gone/ffprobe.exe', Settings::grouped()['video_compression']['media_ffprobe_path']);
    }

    public function testFfprobePathMustExistWhenSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not point to an existing file');

        Settings::saveAdminSettings(['video_compression' => ['media_ffprobe_path' => 'Z:/nope/ffprobe.exe']]);
    }

    public function testAdminPayloadCarriesMediaValidationDiagnostics(): void
    {
        MediaValidator::forceBinaryForTests(null);

        $payload = Settings::adminPayload();

        $this->assertArrayHasKey('media_validation', $payload);
        $this->assertArrayHasKey('available', $payload['media_validation']);
        $this->assertArrayHasKey('binary', $payload['media_validation']);
    }

    private function assertRequiredModeWasRefused(): void
    {
        $this->assertNotSame('required', Settings::grouped()['video_compression']['mode']);
    }
}
