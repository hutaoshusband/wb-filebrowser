<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use WbFileBrowser\Database;
use WbFileBrowser\MediaValidator;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class MediaValidatorTest extends DatabaseTestCase
{
    private const POLICY = [
        'mode' => 'required',
        'max_width' => 1920,
        'max_height' => 1080,
        'max_fps' => 60,
        'max_video_bitrate_kbps' => 8000,
        'max_audio_bitrate_kbps' => 192,
        'min_source_mb' => 20,
        'min_savings_pct' => 5,
        'ffmpeg_fallback' => true,
    ];

    protected function tearDown(): void
    {
        MediaValidator::forceBinaryForTests(null);

        parent::tearDown();
    }

    public function testAcceptsPolicyCompliantMp4(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['width' => 1920, 'height' => 1080, 'bit_rate' => '7900000', 'avg_frame_rate' => '60000/1001']),
            'audio' => self::audioStream(['bit_rate' => '160000']),
        ], ['duration' => '120.5', 'size' => '130000000']);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 130000000);

        $this->assertTrue(true, 'A compliant probe does not throw.');
    }

    public function testAcceptsPortraitVideoWithinSwappedBounds(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['width' => 1080, 'height' => 1920, 'bit_rate' => '6000000', 'avg_frame_rate' => '30/1']),
            'audio' => self::audioStream(),
        ], ['duration' => '10.0', 'size' => '8000000']);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 8000000);

        $this->assertTrue(true, 'Portrait 1080p counts as 1080p-class.');
    }

    public function testRejectsWrongContainer(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
        ], ['format_name' => 'avi', 'duration' => '10.0', 'size' => '1000']);

        $this->assertRejected($probe, 'MP4');
    }

    public function testRejectsWrongVideoCodec(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['codec_name' => 'hevc']),
        ]);

        $this->assertRejected($probe, 'H.264');
    }

    public function testRejectsWrongAudioCodec(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'audio' => self::audioStream(['codec_name' => 'opus']),
        ]);

        $this->assertRejected($probe, 'AAC');
    }

    public function testAcceptsVideoWithoutAudio(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
        ]);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 5000);

        $this->assertTrue(true, 'Silent video is compliant.');
    }

    public function testRejectsOversizedResolution(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['width' => 3840, 'height' => 2160]),
        ]);

        $this->assertRejected($probe, 'resolution');
    }

    public function testRejectsExcessiveFrameRate(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['avg_frame_rate' => '120/1']),
        ]);

        $this->assertRejected($probe, 'frame rate');
    }

    public function testAcceptsFrameRateWithinTolerance(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['avg_frame_rate' => '60000/1001']),
        ]);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 5000);

        $this->assertTrue(true, '59.94 FPS passes a 60 FPS cap.');
    }

    public function testFallsBackToRFrameRateWhenAverageIsUnknown(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['avg_frame_rate' => '0/0', 'r_frame_rate' => '60000/1001']),
        ]);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 5000);

        $oversize = self::probe([
            'video' => self::videoStream(['avg_frame_rate' => '0/0', 'r_frame_rate' => '120/1']),
        ]);

        $this->assertRejected($oversize, 'frame rate');
    }

    public function testTreatsNotAvailableBitratesAsUnknownAndReadsBpsTags(): void
    {
        // Unknown stream bitrate: check skipped, other constraints still bind.
        $unknown = self::probe([
            'video' => self::videoStream(['bit_rate' => 'N/A']),
            'audio' => self::audioStream(['bit_rate' => 'N/A', 'tags' => ['BPS-eng' => '160000']]),
        ]);

        MediaValidator::assertProbeComplies($unknown, self::POLICY, 5000);

        // BPS tag exceeding the cap is still caught.
        $inflated = self::probe([
            'video' => self::videoStream(['bit_rate' => 'N/A']),
            'audio' => self::audioStream(['bit_rate' => 'N/A', 'tags' => ['BPS' => '400000']]),
        ]);

        $this->assertRejected($inflated, 'audio bitrate');
    }

    public function testRejectsQuickTimeBrand(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
        ], ['tags' => ['major_brand' => 'qt  ']]);

        $this->assertRejected($probe, 'MP4');
    }

    public function testAcceptsCommonMp4Brands(): void
    {
        foreach (['isom', 'mp42', 'isom42', 'avc1'] as $brand) {
            $probe = self::probe([
                'video' => self::videoStream(),
            ], ['tags' => ['major_brand' => $brand]]);

            MediaValidator::assertProbeComplies($probe, self::POLICY, 5000);
        }

        $this->assertTrue(true, 'MP4-family brands are accepted.');
    }

    public function testRejectsExcessiveVideoBitrate(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(['bit_rate' => '9500000']),
        ]);

        $this->assertRejected($probe, 'video bitrate');
    }

    public function testRejectsExcessiveAudioBitrate(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'audio' => self::audioStream(['bit_rate' => '320000']),
        ]);

        $this->assertRejected($probe, 'audio bitrate');
    }

    public function testRejectsSubtitleTracks(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'subtitle' => [
                'codec_type' => 'subtitle',
                'codec_name' => 'mov_text',
            ],
        ]);

        $this->assertRejected($probe, 'subtitle');
    }

    public function testRejectsDataTracks(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'data' => [
                'codec_type' => 'data',
                'codec_name' => 'bin_data',
            ],
        ]);

        $this->assertRejected($probe, 'data');
    }

    public function testRejectsMultipleVideoTracks(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'video2' => self::videoStream(),
        ]);

        $this->assertRejected($probe, 'exactly one video track');
    }

    public function testRejectsMultipleAudioTracks(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
            'audio' => self::audioStream(),
            'audio2' => self::audioStream(),
        ]);

        $this->assertRejected($probe, 'at most one audio track');
    }

    public function testRejectsImplausibleDuration(): void
    {
        $probe = self::probe([
            'video' => self::videoStream(),
        ], ['duration' => '0']);

        $this->assertRejected($probe, 'duration');
    }

    public function testRejectsInflatedOverallBitrate(): void
    {
        // 20 MB over 10 seconds is impossible under the combined bitrate caps
        // even when the stream metadata lies about its bitrate.
        $probe = self::probe([
            'video' => self::videoStream(['bit_rate' => '1000']),
        ], ['duration' => '10.0', 'size' => '20000000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too large for its duration');

        MediaValidator::assertProbeComplies($probe, self::POLICY, 20000000);
    }

    public function testLooksLikeVideoDetectsMimeAndExtensions(): void
    {
        $this->assertTrue(MediaValidator::looksLikeVideo('video/mp4', 'clip.bin'));
        $this->assertTrue(MediaValidator::looksLikeVideo('application/octet-stream', 'clip.mov'));
        $this->assertTrue(MediaValidator::looksLikeVideo('text/plain', 'vacation.AVI'));
        $this->assertFalse(MediaValidator::looksLikeVideo('text/plain', 'notes.txt'));
        $this->assertFalse(MediaValidator::looksLikeVideo('application/pdf', 'report.pdf'));
    }

    public function testFfprobeAvailabilityFailsClosedWhenBinaryIsMissing(): void
    {
        MediaValidator::forceBinaryForTests('Z:/definitely/not/ffprobe.exe');

        $this->assertFalse(MediaValidator::isAvailable());
        $this->assertNull(MediaValidator::probe(__FILE__));
    }

    public function testProbeOfMissingFileIsNull(): void
    {
        MediaValidator::forceBinaryForTests(null);

        $this->assertNull(MediaValidator::probe('Z:/missing/file.mp4'));
    }

    public function testProbeOfNonMediaFileFailsClosed(): void
    {
        MediaValidator::forceBinaryForTests(null);

        if (!MediaValidator::isAvailable()) {
            $this->markTestSkipped('ffprobe is not installed on this machine.');
        }

        // A PHP source file is not valid media; probing it must fail closed.
        $this->assertNull(MediaValidator::probe(__FILE__));
    }

    public function testMp4MagicBytesRouteRenamedFilesThroughValidation(): void
    {
        Database::updateSetting('video_compression_mode', 'required');
        Database::updateSetting('video_min_source_mb', '0');

        $path = sys_get_temp_dir() . '/wb-ftyp-probe-test.dat';
        file_put_contents($path, "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41");

        try {
            // Not a real video container, so ffprobe cannot verify it: the
            // magic bytes alone classify it as a video and it fails closed.
            $this->expectException(\RuntimeException::class);

            MediaValidator::assertAcceptedVideoUpload($path, 'notes.dat', strlen((string) file_get_contents($path)), 'text/plain');
        } finally {
            @unlink($path);
        }
    }

    public function testNonVideoContentWithNonVideoNamePassesThrough(): void
    {
        Database::updateSetting('video_compression_mode', 'required');
        Database::updateSetting('video_min_source_mb', '0');

        $path = sys_get_temp_dir() . '/wb-plain-probe-test.dat';
        file_put_contents($path, 'just some plain text, definitely not media');

        try {
            MediaValidator::assertAcceptedVideoUpload($path, 'notes.dat', 40, 'text/plain');

            $this->assertTrue(true, 'Non-video uploads are untouched.');
        } finally {
            @unlink($path);
        }
    }

    private function assertRejected(array $probe, string $needle): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($needle);

        MediaValidator::assertProbeComplies($probe, self::POLICY, 5000);
    }

    private static function videoStream(array $overrides = []): array
    {
        return array_merge([
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1280,
            'height' => 720,
            'avg_frame_rate' => '30/1',
            'r_frame_rate' => '30/1',
            'bit_rate' => '3000000',
        ], $overrides);
    }

    private static function audioStream(array $overrides = []): array
    {
        return array_merge([
            'codec_type' => 'audio',
            'codec_name' => 'aac',
            'sample_rate' => '48000',
            'channels' => 2,
            'bit_rate' => '128000',
        ], $overrides);
    }

    /**
     * @param array<string, array<int|string, mixed>> $streams
     * @param array<string, string> $formatOverrides
     */
    private static function probe(array $streams, array $formatOverrides = []): array
    {
        return [
            'streams' => array_values($streams),
            'format' => array_merge([
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'duration' => '60.0',
                'size' => '5000',
                'bit_rate' => '3000000',
            ], $formatOverrides),
        ];
    }
}
