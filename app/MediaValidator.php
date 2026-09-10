<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

/**
 * Server-side verification of uploaded videos against the video optimization
 * policy. The browser is asked to compress videos locally before upload, but
 * the server never trusts client claims: it inspects the assembled artifact
 * with ffprobe and enforces the same policy the client saw. Validation only
 * runs for "required" mode; "optional" mode intentionally accepts originals.
 *
 * ffprobe is executed with a fixed argument vector (no shell), a timeout and
 * an output cap, and every failure mode - missing binary, timeout, unparsable
 * output, non-compliant media - rejects the upload. Fail closed.
 */
final class MediaValidator
{
    private const PROBE_TIMEOUT_SECONDS = 20;
    private const MAX_OUTPUT_BYTES = 1048576;
    private const MAX_DURATION_SECONDS = 86400;
    private const FPS_TOLERANCE = 0.5;
    private const STREAM_BITRATE_TOLERANCE = 1.1;
    private const OVERALL_BITRATE_TOLERANCE = 1.35;

    private const VIDEO_EXTENSIONS = [
        'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'mpg', 'mpeg',
        'mp2v', 'm2ts', 'mts', 'ts', '3gp', '3g2', 'ogv', 'divx', 'vob', 'mxf',
        'asf', 'rm', 'rmvb', 'f4v', 'm1v', 'm2v', 'mpe', 'ogm', 'qt', 'svi',
        'amv', 'drc', 'mng', 'roq', 'viv', 'wtv', 'yuv', 'dv', 'bik',
    ];

    /** @var array{ok: bool, binary: string}|null */
    private static ?array $availabilityCache = null;

    private static ?string $forcedBinary = null;

    public static function resetRequestCache(): void
    {
        self::$availabilityCache = null;
    }

    /**
     * Test hook: force the ffprobe binary (a non-existent path simulates an
     * uninstalled ffprobe, a valid one skips PATH discovery).
     */
    public static function forceBinaryForTests(?string $binary): void
    {
        self::$forcedBinary = $binary;
        self::resetRequestCache();
    }

    /**
     * @return array{available: bool, binary: string, source: string}
     */
    public static function diagnostics(?PDO $pdo = null): array
    {
        $configured = trim((string) Database::setting('media_ffprobe_path', ''));
        $binary = self::ffprobeBinary($pdo);

        return [
            'available' => $binary !== null,
            'binary' => $binary ?? ($configured !== '' ? $configured : 'ffprobe'),
            'source' => self::$forcedBinary !== null ? 'forced' : ($configured !== '' ? 'setting' : 'auto'),
        ];
    }

    public static function isAvailable(?PDO $pdo = null): bool
    {
        return self::ffprobeBinary($pdo) !== null;
    }

    /**
     * One-shot availability check against an explicit configured path (used
     * when saving settings, where the new path is not stored yet). Does not
     * touch the per-request cache.
     */
    public static function isAvailableWith(?string $configuredPath, ?PDO $pdo = null): bool
    {
        $candidates = [trim((string) $configuredPath), self::$forcedBinary ?? 'ffprobe'];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($candidate !== 'ffprobe' && !is_file($candidate)) {
                continue;
            }

            if (self::runToCompletion([$candidate, '-hide_banner', '-version'], 5) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a usable ffprobe binary: the admin-configured path first, then
     * "ffprobe" from PATH. Cached per request; probing is a single fast
     * `-version` run so the cost only applies to video uploads in required
     * mode and to admin screens.
     */
    public static function ffprobeBinary(?PDO $pdo = null): ?string
    {
        if (self::$availabilityCache !== null) {
            return self::$availabilityCache['ok'] ? self::$availabilityCache['binary'] : null;
        }

        $candidates = [];

        if (self::$forcedBinary !== null) {
            $candidates[] = self::$forcedBinary;
        } else {
            $configured = trim((string) Database::setting('media_ffprobe_path', ''));
            $candidates[] = $configured;
            $candidates[] = 'ffprobe';
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            // Anything other than the bare command name must point at a real file;
            // the bare name is resolved through PATH by the OS.
            if ($candidate !== 'ffprobe' && !is_file($candidate)) {
                continue;
            }

            if (self::runToCompletion([$candidate, '-hide_banner', '-version'], 5) !== null) {
                self::$availabilityCache = ['ok' => true, 'binary' => $candidate];
                return $candidate;
            }
        }

        self::$availabilityCache = ['ok' => false, 'binary' => ''];
        return null;
    }

    /**
     * Runs ffprobe against a media file and returns its parsed JSON report,
     * or null when probing fails for any reason.
     *
     * @return array<string, mixed>|null
     */
    public static function probe(string $path, ?PDO $pdo = null): ?array
    {
        $binary = self::ffprobeBinary($pdo);

        if ($binary === null || !is_file($path)) {
            return null;
        }

        $output = self::runToCompletion(
            [$binary, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', '-i', $path],
            self::PROBE_TIMEOUT_SECONDS
        );

        if ($output === null) {
            return null;
        }

        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !isset($decoded['streams']) || !is_array($decoded['streams'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Whether an upload should be treated as a video: the sniffed MIME type
     * says so, or the file carries a known video extension (covers renamed or
     * misdetected files both ways).
     */
    public static function looksLikeVideo(string $sniffedMime, string $originalName): bool
    {
        if (str_starts_with(strtolower(trim($sniffedMime)), 'video/')) {
            return true;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Entry point used by the upload pipeline. In required mode, video uploads
     * at or above the policy's minimum size must comply with the optimization
     * profile; anything else passes through untouched.
     */
    public static function assertAcceptedVideoUpload(
        string $path,
        string $originalName,
        int $size,
        string $sniffedMime,
        ?PDO $pdo = null
    ): void
    {
        $policy = Settings::videoCompressionPolicy($pdo);

        if ($policy['mode'] !== 'required') {
            return;
        }

        if ($size < $policy['min_source_mb'] * 1024 * 1024) {
            return;
        }

        // MIME sniffing (which may be missing on stripped-down hosts) and the
        // extension both classify; a quick magic-byte read catches MP4-family
        // files renamed to non-video extensions as a final content signal.
        $looksLikeVideo = self::looksLikeVideo($sniffedMime, $originalName)
            || self::hasMp4FamilyMagicBytes($path);

        if (!$looksLikeVideo) {
            return;
        }

        if (!self::isAvailable($pdo)) {
            throw new RuntimeException(
                'This server requires optimized video uploads, but its media verification tool (ffprobe) is unavailable. '
                . 'Please contact the administrator.'
            );
        }

        $probe = self::probe($path, $pdo);

        if ($probe === null) {
            throw new RuntimeException(
                'This video could not be verified as an optimized media file. '
                . 'Required mode accepts H.264/AAC MP4 videos within the size limits; re-encode the file and try again.'
            );
        }

        self::assertProbeComplies($probe, $policy, $size);
    }

    /**
     * Pure policy check over a parsed ffprobe report, separated from process
     * execution so it can be tested without ffprobe.
     *
     * @param array<string, mixed> $probe
     * @param array<string, mixed> $policy
     */
    public static function assertProbeComplies(array $probe, array $policy, ?int $fileSize = null): void
    {
        $streams = array_values(array_filter($probe['streams'] ?? [], is_array(...)));

        if ($streams === []) {
            throw new RuntimeException('The video file contains no media streams.');
        }

        foreach ($streams as $stream) {
            $type = (string) ($stream['codec_type'] ?? '');

            if ($type !== 'video' && $type !== 'audio') {
                throw new RuntimeException('Optimized videos may not contain subtitle, data, or attachment tracks.');
            }
        }

        $videoStreams = array_values(array_filter($streams, static fn (array $stream): bool => ($stream['codec_type'] ?? '') === 'video'));
        $audioStreams = array_values(array_filter($streams, static fn (array $stream): bool => ($stream['codec_type'] ?? '') === 'audio'));

        if (count($videoStreams) !== 1) {
            throw new RuntimeException('Optimized videos must contain exactly one video track.');
        }

        if (count($audioStreams) > 1) {
            throw new RuntimeException('Optimized videos may contain at most one audio track.');
        }

        $formatNames = array_map('trim', explode(',', (string) (($probe['format'] ?? [])['format_name'] ?? '')));

        if (!in_array('mp4', $formatNames, true)) {
            throw new RuntimeException('Videos must be uploaded as MP4 files in required mode.');
        }

        // ffprobe reports the whole mov-demuxer family for .mov/.3gp files
        // too; the container brand distinguishes actual MP4 from QuickTime.
        $majorBrand = strtolower(trim((string) ((($probe['format'] ?? [])['tags'] ?? [])['major_brand'] ?? '')));

        if ($majorBrand !== '' && str_starts_with($majorBrand, 'qt')) {
            throw new RuntimeException('Videos must be uploaded as MP4 files in required mode.');
        }

        $video = $videoStreams[0];

        if (($video['codec_name'] ?? '') !== 'h264') {
            throw new RuntimeException('The video track must use the H.264 codec.');
        }

        foreach ($audioStreams as $audio) {
            if (($audio['codec_name'] ?? '') !== 'aac') {
                throw new RuntimeException('The audio track must use the AAC codec.');
            }
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('The video track reports invalid dimensions.');
        }

        $maxWidth = (int) $policy['max_width'];
        $maxHeight = (int) $policy['max_height'];

        $fitsLandscape = $width <= $maxWidth && $height <= $maxHeight;
        $fitsPortrait = $width <= $maxHeight && $height <= $maxWidth;

        if (!$fitsLandscape && !$fitsPortrait) {
            throw new RuntimeException(sprintf(
                'The video resolution (%dx%d) exceeds the maximum of %dp.',
                $width,
                $height,
                $maxHeight
            ));
        }

        $maxFps = (float) $policy['max_fps'];
        $fps = self::parseFrameRate($video);

        if ($fps !== null && $fps > $maxFps + self::FPS_TOLERANCE) {
            throw new RuntimeException(sprintf(
                'The video frame rate (%s FPS) exceeds the maximum of %d FPS.',
                self::formatRate($fps),
                (int) $maxFps
            ));
        }

        $duration = (float) (($probe['format'] ?? [])['duration'] ?? 0);

        // Declared container duration. A forged huge duration could relax the
        // overall bytes-per-second cap below the per-stream limits, but every
        // other constraint still applies, so this leniency only ever admits
        // files that are individually stream-compliant.
        if ($duration <= 0 || $duration > self::MAX_DURATION_SECONDS) {
            throw new RuntimeException('The video duration is missing or implausible.');
        }

        $maxVideoBitrate = (float) $policy['max_video_bitrate_kbps'] * 1000 * self::STREAM_BITRATE_TOLERANCE;
        $videoBitrate = self::streamBitrate($video, $duration);

        if ($videoBitrate !== null && $videoBitrate > $maxVideoBitrate) {
            throw new RuntimeException('The video bitrate exceeds the configured maximum.');
        }

        $maxAudioBitrate = (float) $policy['max_audio_bitrate_kbps'] * 1000 * self::STREAM_BITRATE_TOLERANCE;

        foreach ($audioStreams as $audio) {
            $audioBitrate = self::streamBitrate($audio, $duration);

            if ($audioBitrate !== null && $audioBitrate > $maxAudioBitrate) {
                throw new RuntimeException('The audio bitrate exceeds the configured maximum.');
            }
        }

        if ($fileSize !== null) {
            $overallCap = ((float) $policy['max_video_bitrate_kbps'] + (float) $policy['max_audio_bitrate_kbps'])
                * 1000 * self::OVERALL_BITRATE_TOLERANCE;
            $bitsPerSecond = ($fileSize * 8) / $duration;

            if ($bitsPerSecond > $overallCap) {
                throw new RuntimeException('The video file is too large for its duration under the configured bitrate policy.');
            }
        }
    }

    /**
     * @param array<string, mixed> $stream
     */
    private static function streamBitrate(array $stream, float $duration): ?float
    {
        if (isset($stream['bit_rate']) && is_numeric($stream['bit_rate'])) {
            return (float) $stream['bit_rate'];
        }

        if (isset($stream['tags'], $stream['tags']['BPS-eng']) && is_numeric($stream['tags']['BPS-eng'])) {
            return (float) $stream['tags']['BPS-eng'];
        }

        if (isset($stream['tags'], $stream['tags']['BPS']) && is_numeric($stream['tags']['BPS'])) {
            return (float) $stream['tags']['BPS'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $videoStream
     */
    private static function parseFrameRate(array $videoStream): ?float
    {
        foreach (['avg_frame_rate', 'r_frame_rate'] as $key) {
            $rate = (string) ($videoStream[$key] ?? '');

            if ($rate === '' || $rate === '0/0' || $rate === 'N/A') {
                continue;
            }

            $parts = explode('/', $rate);

            if (count($parts) === 2) {
                $numerator = (float) $parts[0];
                $denominator = (float) $parts[1];

                if ($denominator > 0 && $numerator > 0) {
                    return $numerator / $denominator;
                }

                continue;
            }

            $value = (float) $parts[0];

            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private static function formatRate(float $rate): string
    {
        return number_format($rate, $rate < 100 ? 2 : 0);
    }

    /**
     * Reads the first bytes of the file and reports whether it starts with an
     * ISO-BMFF `ftyp` box, i.e. belongs to the MP4/MOV/3GP container family.
     */
    private static function hasMp4FamilyMagicBytes(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = (string) fread($handle, 12);

            return strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp';
        } finally {
            fclose($handle);
        }
    }

    /**
     * Runs a fixed argv (never a shell string) with a deadline and an output
     * cap. Returns stdout, or null on non-zero exit, timeout, or oversized
     * output. Stderr is drained to avoid blocking the child process.
     *
     * @param array<int, string> $command
     */
    private static function runToCompletion(array $command, int $timeoutSeconds): ?string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        [$stdin, $stdout, $stderr] = $pipes;
        fclose($stdin);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while (true) {
                $read = [$stdout, $stderr];
                $write = null;
                $except = null;
                $changed = stream_select($read, $write, $except, 0, 200000);

                if ($changed === false) {
                    return null;
                }

                foreach ($read as $handle) {
                    $chunk = fread($handle, 65536);

                    if (is_string($chunk) && $chunk !== '') {
                        if ($handle === $stdout) {
                            $output .= $chunk;

                            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                                return null;
                            }
                        }
                    }
                }

                $status = proc_get_status($process);

                if (!$status['running']) {
                    // Drain whatever is left in the pipes buffered by the OS.
                    stream_set_blocking($stdout, true);
                    $output .= (string) fread($stdout, self::MAX_OUTPUT_BYTES - strlen($output) + 1);

                    if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                        return null;
                    }

                    return $status['exitcode'] === 0 ? $output : null;
                }

                if (microtime(true) > $deadline) {
                    return null;
                }
            }
        } finally {
            proc_terminate($process);
            fclose($stdout);
            fclose($stderr);
            proc_close($process);
        }
    }
}
