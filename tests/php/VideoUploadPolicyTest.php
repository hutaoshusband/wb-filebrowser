<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\MediaValidator;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

/**
 * End-to-end enforcement of the required video optimization policy through
 * the real upload pipeline. Uses real ffmpeg/ffprobe binaries when present
 * and skips otherwise. Fixtures are tiny (320x240) with the policy tightened
 * to 240p, so the "oversized" cases need no large files.
 */
final class VideoUploadPolicyTest extends DatabaseTestCase
{
    /** @var array{ffmpeg: string, ffprobe: string}|null */
    private static ?array $binaries = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $ffmpeg = self::findBinary('ffmpeg');
        $ffprobe = self::findBinary('ffprobe');
        self::$binaries = ($ffmpeg && $ffprobe) ? ['ffmpeg' => $ffmpeg, 'ffprobe' => $ffprobe] : null;
    }

    protected function tearDown(): void
    {
        MediaValidator::forceBinaryForTests(null);

        parent::tearDown();
    }

    public function testCompliantVideoPassesRequiredMode(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'required', 'max_height' => 240]);

        $video = $this->generateVideo('compliant.mp4', 320, 240, 24);
        $file = $this->uploadVideo($video, 'compliant.mp4', 'video/mp4');

        $this->assertSame('compliant.mp4', $file['name']);
        $this->assertGreaterThan(0, (int) $file['size']);
    }

    public function testOversizedVideoIsRejectedInRequiredMode(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'required', 'max_height' => 240]);
        Database::updateSetting('audit_enabled', '1');

        $video = $this->generateVideo('big.mp4', 640, 480, 24);

        try {
            $this->uploadVideo($video, 'big.mp4', 'video/mp4');
            $this->fail('A 480p video must be rejected under a 240p policy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('resolution', $exception->getMessage());
        }

        $count = Database::connection()->query("SELECT COUNT(*) FROM files WHERE original_name = 'big.mp4'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'The rejected video must not be registered.');

        $rejections = Database::connection()
            ->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'file.upload_rejected'")
            ->fetchColumn();
        $this->assertSame(1, (int) $rejections, 'The rejection must be audited.');
    }

    public function testNonMp4ContainerIsRejectedInRequiredMode(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'required', 'max_height' => 240]);

        $video = $this->generateVideo('legacy.avi', 320, 240, 24, 'avi');

        try {
            $this->uploadVideo($video, 'legacy.avi', 'video/x-msvideo');
            $this->fail('An AVI container must be rejected in required mode.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('MP4', $exception->getMessage());
        }
    }

    public function testOptionalModeAcceptsOriginals(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'optional', 'max_height' => 240]);

        $video = $this->generateVideo('original.avi', 640, 480, 24, 'avi');
        $file = $this->uploadVideo($video, 'original.avi', 'video/x-msvideo');

        $this->assertSame('original.avi', $file['name']);
    }

    public function testSmallVideosAreExempt(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        // The default 20 MB threshold applies, so the tiny fixture is exempt
        // even though it is an oversized AVI.
        $this->savePolicy(['mode' => 'required', 'max_height' => 240, 'min_source_mb' => 20]);

        $video = $this->generateVideo('tiny.avi', 640, 480, 24, 'avi');
        $file = $this->uploadVideo($video, 'tiny.avi', 'video/x-msvideo');

        $this->assertSame('tiny.avi', $file['name']);
    }

    public function testRequiredModeFailsClosedWithoutFfprobe(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        // Save while ffprobe still resolves, then make it disappear - the
        // runtime guard must refuse video uploads instead of waving them in.
        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'required', 'max_height' => 240]);

        $video = $this->generateVideo('any.mp4', 320, 240, 24);
        MediaValidator::forceBinaryForTests('Z:/definitely/not/ffprobe.exe');

        $size = (int) filesize($video);

        try {
            FileManager::uploadInit($this->superAdmin(), Database::rootFolderId(), 'any.mp4', $size, 'video/mp4', 1);
            $this->fail('uploadInit must fail closed when ffprobe is unavailable in required mode.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ffprobe', $exception->getMessage());
        }

        $token = $this->primeUploadWorkspace($video, 'any.mp4', 'video/mp4');

        try {
            FileManager::uploadComplete($this->superAdmin(), $token);
            $this->fail('uploadComplete must fail closed when ffprobe is unavailable in required mode.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ffprobe', $exception->getMessage());
        }
    }

    public function testRenamedDisguisedVideoIsStillDetected(): void
    {
        if (self::$binaries === null) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }

        MediaValidator::forceBinaryForTests(self::$binaries['ffprobe']);
        $this->savePolicy(['mode' => 'required', 'max_height' => 240]);

        // 480p video declared with a neutral MIME type: the extension plus
        // the sniffed container must still route it through validation.
        $video = $this->generateVideo('disguised.mp4', 640, 480, 24);

        try {
            $this->uploadVideo($video, 'disguised.mp4', 'application/octet-stream');
            $this->fail('A renamed oversized video must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('resolution', $exception->getMessage());
        }
    }

    private function savePolicy(array $overrides): void
    {
        Settings::saveAdminSettings(['video_compression' => array_merge([
            'mode' => 'required',
            'max_height' => 240,
            'min_source_mb' => 0,
        ], $overrides)]);
    }

    /**
     * Drives the full pipeline: uploadInit, raw chunk parts (bypassing the
     * multipart layer, which tests cannot satisfy), uploadComplete.
     */
    private function uploadVideo(string $path, string $name, string $mimeType): array
    {
        $size = (int) filesize($path);
        $totalChunks = (int) ceil($size / FileManager::CHUNK_SIZE);
        $init = FileManager::uploadInit($this->superAdmin(), Database::rootFolderId(), $name, $size, $mimeType, $totalChunks);
        $this->writeChunkParts($init['upload_token'], $path);

        return FileManager::uploadComplete($this->superAdmin(), $init['upload_token']);
    }

    /**
     * Creates the chunk workspace directly, for cases where uploadInit itself
     * refuses; mirrors what uploadInit would have written.
     */
    private function primeUploadWorkspace(string $path, string $name, string $mimeType): string
    {
        $token = wb_random_token(18);
        $directory = wb_storage_path('chunks/' . $token);
        mkdir($directory, 0775, true);

        $size = (int) filesize($path);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'meta.json', json_encode([
            'token' => $token,
            'folder_id' => Database::rootFolderId(),
            'user_id' => (int) $this->superAdmin()['id'],
            'original_name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'total_chunks' => (int) ceil($size / FileManager::CHUNK_SIZE),
            'created_at' => wb_now(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->writeChunkParts($token, $path);

        return $token;
    }

    private function writeChunkParts(string $token, string $path): void
    {
        $directory = wb_storage_path('chunks/' . $token);
        $size = (int) filesize($path);
        $totalChunks = (int) ceil($size / FileManager::CHUNK_SIZE);
        $contents = (string) file_get_contents($path);

        for ($index = 0; $index < $totalChunks; $index++) {
            $part = substr($contents, $index * FileManager::CHUNK_SIZE, FileManager::CHUNK_SIZE);
            file_put_contents($directory . DIRECTORY_SEPARATOR . $index . '.part', $part);
        }
    }

    private function generateVideo(
        string $name,
        int $width,
        int $height,
        int $fps,
        string $container = 'mp4'
    ): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wb-filebrowser-test-videos';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . $name;

        if (is_file($path)) {
            unlink($path);
        }

        $videoCodec = $container === 'mp4' ? 'libx264' : 'msmpeg4v2';
        $audioCodec = $container === 'mp4' ? 'aac' : 'libmp3lame';
        $command = [
            self::$binaries['ffmpeg'],
            '-v', 'error',
            '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate={$fps}",
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100',
            '-t', '1',
            '-c:v', $videoCodec, '-pix_fmt', 'yuv420p',
            '-c:a', $audioCodec,
            '-y', $path,
        ];

        $escaped = implode(' ', array_map('escapeshellarg', $command));
        exec($escaped, $output, $exitCode);
        unset($output);

        if ($exitCode !== 0 || !is_file($path)) {
            self::$binaries = null;
            $this->markTestSkipped('ffmpeg could not generate a test video on this machine.');
        }

        return $path;
    }

    private static function findBinary(string $name): ?string
    {
        $candidate = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN' ? "$name.exe" : $name;
        $paths = explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: ''));

        foreach ($paths as $base) {
            if ($base === '') {
                continue;
            }

            $path = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
