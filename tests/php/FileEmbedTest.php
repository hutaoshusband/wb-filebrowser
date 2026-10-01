<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Database;
use WbFileBrowser\FileShares;
use WbFileBrowser\Permissions;
use WbFileBrowser\Security;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileEmbedTest extends DatabaseTestCase
{
    public function testAllowEmbedRoundTripsThroughShareManagement(): void
    {
        $video = $this->createFile('clip.mp4', 'video payload', 'video/mp4');
        $audio = $this->createFile('track.mp3', 'audio payload', 'audio/mpeg');
        $text = $this->createFile('notes.txt', 'plain notes');
        $expectedVideoLabel = 'clip.mp4 (' . wb_format_bytes(strlen('video payload')) . ')';

        $share = FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);

        $this->assertTrue($share['allow_embed']);
        $this->assertStringContainsString('/embed/?token=' . $share['token'], $share['embed_url']);
        $this->assertStringContainsString('/embed/stream/?token=' . $share['token'], $share['discord_url']);
        $this->assertStringContainsString('<iframe src="' . $share['embed_url'] . '" title="' . wb_h($expectedVideoLabel) . '"', $share['embed_html']);
        $this->assertStringContainsString('width="560" height="520"', $share['embed_html']);
        $this->assertStringContainsString('allow="autoplay; fullscreen; picture-in-picture"', $share['embed_html']);
        $this->assertSame($share['token'], FileShares::get($this->superAdmin(), (int) $video['id'])['token']);

        $audioShare = FileShares::create($this->superAdmin(), (int) $audio['id'], ['allow_embed' => true]);

        $this->assertTrue($audioShare['allow_embed']);
        $this->assertStringContainsString('width="100%" height="240"', $audioShare['embed_html']);
        $this->assertStringContainsString('allow="autoplay"', $audioShare['embed_html']);
        $this->assertStringNotContainsString('allowfullscreen', $audioShare['embed_html']);

        $textShare = FileShares::create($this->superAdmin(), (int) $text['id'], ['allow_embed' => true]);

        $this->assertTrue($textShare['allow_embed']);
        $this->assertArrayNotHasKey('embed_url', $textShare);
        $this->assertArrayNotHasKey('embed_html', $textShare);

        $plainShare = FileShares::create($this->superAdmin(), (int) $this->createFile('movie.webm', 'webm payload', 'video/webm')['id']);

        $this->assertFalse($plainShare['allow_embed']);
        $this->assertArrayNotHasKey('embed_url', $plainShare);

        $embedPayload = FileShares::embedPagePayload($share['token']);

        $this->assertSame('clip.mp4', $embedPayload['name']);
        $this->assertSame('video/mp4', $embedPayload['mime_type']);
        $this->assertSame('mp4', $embedPayload['extension']);
        $this->assertSame('video', $embedPayload['preview_mode']);
        $this->assertStringContainsString('/embed/stream/?token=' . $share['token'], $embedPayload['stream_url']);

        $publicContext = FileShares::publicContext($share['token']);

        $this->assertArrayNotHasKey('allow_embed', $publicContext['share']);
        $this->assertArrayNotHasKey('embed_url', $publicContext['share']);
        $this->assertArrayNotHasKey('embed_html', $publicContext['share']);
        $this->assertArrayNotHasKey('discord_url', $publicContext['share']);
    }

    public function testUsersEmbedOnlyWhenTheSettingAllowsIt(): void
    {
        $member = $this->createUser('member', 'user');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => Database::rootFolderId(), 'can_view' => true],
        ]);
        $ownedVideo = $this->createFile('episode.mp4', 'member video', 'video/mp4', null, $member);

        try {
            FileShares::embedPagePayload(
                FileShares::create($member, (int) $ownedVideo['id'], ['allow_embed' => true])['token']
            );
            self::fail('User embeds must be refused while the embed setting is disabled.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shared file not found.', $exception->getMessage());
        }

        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => true]]);

        $userShare = FileShares::create($member, (int) $ownedVideo['id'], ['allow_embed' => true]);

        $this->assertTrue($userShare['allow_embed']);
        $this->assertStringContainsString('/embed/?token=', $userShare['embed_url']);
        $this->assertSame('episode.mp4', FileShares::embedPagePayload($userShare['token'])['name']);

        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => false]]);

        $withdrawn = FileShares::get($member, (int) $ownedVideo['id']);

        $this->assertTrue($withdrawn['allow_embed']);
        $this->assertArrayNotHasKey('embed_url', $withdrawn);
        $this->assertArrayNotHasKey('embed_html', $withdrawn);
    }

    public function testDisablingShareEmbedsWithdrawsUserEmbedsButNotAdminEmbeds(): void
    {
        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => true]]);
        $member = $this->createUser('member', 'user');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => Database::rootFolderId(), 'can_view' => true],
        ]);
        $memberFile = $this->createFile('episode.mp4', 'member video', 'video/mp4', null, $member);
        $adminFile = $this->createFile('trailer.mp4', 'admin video', 'video/mp4');

        $memberShare = FileShares::create($member, (int) $memberFile['id'], ['allow_embed' => true]);
        $adminShare = FileShares::create($this->superAdmin(), (int) $adminFile['id'], ['allow_embed' => true]);

        $this->assertSame('episode.mp4', FileShares::embedPagePayload($memberShare['token'])['name']);

        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => false]]);

        try {
            FileShares::embedPagePayload($memberShare['token']);
            self::fail('User embeds must stop serving when the setting is withdrawn.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shared file not found.', $exception->getMessage());
        }

        try {
            FileShares::streamEmbed($memberShare['token']);
            self::fail('User embed streams must stop serving when the setting is withdrawn.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shared file not found.', $exception->getMessage());
        }

        $this->assertSame('trailer.mp4', FileShares::embedPagePayload($adminShare['token'])['name']);
    }

    public function testSharePasswordsCannotBeCombinedWithEmbedding(): void
    {
        $video = $this->createFile('clip.mp4', 'video payload', 'video/mp4');

        try {
            FileShares::create($this->superAdmin(), (int) $video['id'], ['password' => 'Secret 123', 'allow_embed' => true]);
            self::fail('Embedding combined with a password must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Share passwords cannot be combined with embedding.', $exception->getMessage());
        }

        FileShares::create($this->superAdmin(), (int) $video['id'], ['password' => 'Secret 123']);

        try {
            FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);
            self::fail('Enabling embedding on a password protected share must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Share passwords cannot be combined with embedding.', $exception->getMessage());
        }
    }

    public function testNonAllowlistedMediaFormatsExposeNoEmbedFields(): void
    {
        $matroska = $this->createFile('movie.mkv', 'matroska payload', 'video/x-matroska');
        $share = FileShares::create($this->superAdmin(), (int) $matroska['id'], ['allow_embed' => true]);

        $this->assertTrue($share['allow_embed']);
        $this->assertArrayNotHasKey('embed_url', $share);
        $this->assertArrayNotHasKey('embed_html', $share);
        $this->assertArrayNotHasKey('discord_url', $share);
    }

    public function testEveryEmbedRefusalThrowsTheIdenticalRuntimeException(): void
    {
        $plainVideo = $this->createFile('plain.mp4', 'video payload', 'video/mp4');
        $lockedVideo = $this->createFile('locked.mp4', 'video payload', 'video/mp4');
        $termsVideo = $this->createFile('terms.mp4', 'video payload', 'video/mp4');

        $plainShare = FileShares::create($this->superAdmin(), (int) $plainVideo['id']);
        FileShares::create($this->superAdmin(), (int) $lockedVideo['id'], ['password' => 'Secret 123']);
        Database::connection()
            ->prepare('UPDATE file_shares SET allow_embed = 1 WHERE file_id = :file_id AND revoked_at IS NULL')
            ->execute([':file_id' => (int) $lockedVideo['id']]);
        $lockedShare = FileShares::get($this->superAdmin(), (int) $lockedVideo['id']);
        $termsShare = FileShares::create($this->superAdmin(), (int) $termsVideo['id'], ['allow_embed' => true]);
        Settings::saveAdminSettings(['access' => ['share_terms_enabled' => true]]);

        $refusedTokens = [
            str_repeat('f', 32),
            $plainShare['token'],
            $lockedShare['token'],
            $termsShare['token'],
        ];

        foreach ($refusedTokens as $token) {
            try {
                FileShares::embedPagePayload($token);
                self::fail('Embed resolution must refuse token ' . $token);
            } catch (RuntimeException $exception) {
                $this->assertSame(RuntimeException::class, $exception::class);
                $this->assertSame('Shared file not found.', $exception->getMessage());
            }

            try {
                FileShares::streamEmbed($token);
                self::fail('Embed streaming must refuse token ' . $token);
            } catch (RuntimeException $exception) {
                $this->assertSame(RuntimeException::class, $exception::class);
                $this->assertSame('Shared file not found.', $exception->getMessage());
            }
        }
    }

    public function testSharePagePayloadExposesEmbedInfoForEligibleShares(): void
    {
        $video = $this->createFile('clip.mp4', 'video payload', 'video/mp4');
        $share = FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);

        $payload = FileShares::viewPayload($share['token']);

        $this->assertNotNull($payload['embed']);
        $this->assertStringContainsString('/embed/?token=' . $share['token'], $payload['embed']['html']);
        $this->assertStringContainsString('/embed/stream/?token=' . $share['token'], $payload['embed']['discord_url']);

        $plainShare = FileShares::create($this->superAdmin(), (int) $this->createFile('movie.webm', 'webm payload', 'video/webm')['id']);

        $this->assertNull(FileShares::viewPayload($plainShare['token'])['embed']);
    }

    public function testEmbedMediaMimeTypeAllowlist(): void
    {
        $known = [
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'ogv' => 'video/ogg',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'opus' => 'audio/ogg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'weba' => 'audio/webm',
        ];

        foreach ($known as $extension => $mimeType) {
            $this->assertSame($mimeType, wb_embed_media_mime_type($extension));
            $this->assertSame($mimeType, wb_embed_media_mime_type(strtoupper($extension)));
        }

        foreach (['html', 'svg', 'txt', 'pdf', 'mkv', 'avi', 'exe', 'mp4backup'] as $extension) {
            $this->assertNull(wb_embed_media_mime_type($extension));
        }
    }

    public function testEmbedHtmlEscapesHostileFileNames(): void
    {
        $fileName = '"><script>alert(1)</script>.mp4';
        $file = $this->createFile($fileName, 'video payload', 'video/mp4');
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], ['allow_embed' => true]);
        $escapedName = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;.mp4';

        $this->assertStringNotContainsString('<script>alert(1)</script>', $share['embed_html']);
        $this->assertStringContainsString('title="' . $escapedName . ' (' . wb_format_bytes(strlen('video payload')) . ')"', $share['embed_html']);

        $payload = FileShares::embedPagePayload($share['token']);

        $this->assertSame($fileName, $payload['name']);
        $this->assertSame('video/mp4', $payload['mime_type']);
    }

    public function testEmbedPayloadsAreSessionIndependentAndDoNotCountViews(): void
    {
        Settings::saveAdminSettings(['security' => ['audit_enabled' => true, 'log_file_views' => true]]);
        $video = $this->createFile('clip.mp4', 'video payload', 'video/mp4');
        $share = FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);

        $_SESSION = [];
        $first = FileShares::embedPagePayload($share['token']);
        $second = FileShares::embedPagePayload($share['token']);

        $statement = Database::connection()->prepare('SELECT view_count FROM file_shares WHERE token = :token');
        $statement->execute([':token' => $share['token']]);

        $this->assertSame(0, (int) $statement->fetchColumn());
        $this->assertSame($first['stream_url'], $second['stream_url']);
        $this->assertSame(2, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'share.embed.view'")->fetchColumn());
    }

    public function testEmbedPageWithoutTokenReturnsNotFound(): void
    {
        $result = $this->runIsolatedPhpCapture('$_GET = []; require WB_ROOT . "/embed/index.php";');

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame(404, $result['response_code']);
        $this->assertStringContainsString('This share link is unavailable.', $result['output']);
    }

    public function testEmbedStreamsMediaWithRangeSupport(): void
    {
        $contents = 'fake video payload';
        $video = $this->createFile('clip.mp4', $contents, 'video/mp4');
        $share = FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);
        $token = var_export($share['token'], true);

        $full = $this->runIsolatedPhpCapture('try { \WbFileBrowser\FileShares::streamEmbed(' . $token . '); } catch (\RuntimeException $e) { echo "REFUSED"; }');
        $this->assertSame(0, $full['status'], $full['output']);
        $this->assertSame(200, $full['response_code']);
        $this->assertSame($contents, $full['output']);

        $partial = $this->runIsolatedPhpCapture('$_SERVER["HTTP_RANGE"] = "bytes=5-9"; try { \WbFileBrowser\FileShares::streamEmbed(' . $token . '); } catch (\RuntimeException $e) { echo "REFUSED"; }');
        $this->assertSame(0, $partial['status'], $partial['output']);
        $this->assertSame(206, $partial['response_code']);
        $this->assertSame(substr($contents, 5, 5), $partial['output']);

        $suffix = $this->runIsolatedPhpCapture('$_SERVER["HTTP_RANGE"] = "bytes=-6"; try { \WbFileBrowser\FileShares::streamEmbed(' . $token . '); } catch (\RuntimeException $e) { echo "REFUSED"; }');
        $this->assertSame(0, $suffix['status'], $suffix['output']);
        $this->assertSame(substr($contents, -6), $suffix['output']);

        $unsatisfiable = $this->runIsolatedPhpCapture('$_SERVER["HTTP_RANGE"] = "bytes=9999-"; try { \WbFileBrowser\FileShares::streamEmbed(' . $token . '); } catch (\RuntimeException $e) { echo "REFUSED"; }');
        $this->assertSame(0, $unsatisfiable['status'], $unsatisfiable['output']);
        $this->assertSame(416, $unsatisfiable['response_code']);
        $this->assertSame('', $unsatisfiable['output']);

        $refused = $this->runIsolatedPhpCapture('try { \WbFileBrowser\FileShares::streamEmbed(str_repeat("f", 32)); } catch (\RuntimeException $e) { echo "REFUSED"; }');
        $this->assertSame(0, $refused['status'], $refused['output']);
        $this->assertSame('REFUSED', $refused['output']);
    }

    public function testEmbedHeadersAllowFramingWhileOtherSurfacesStayLocked(): void
    {
        $this->assertStringContainsString('frame-ancestors *', Security::embedHeaders()['Content-Security-Policy']);
        $this->assertStringContainsString('cross-origin', Security::embedHeaders()['Cross-Origin-Resource-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", Security::pageHeaders()['Content-Security-Policy']);
    }

    private function runIsolatedPhpCapture(string $code): array
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'wb-embed-');

        if ($scriptPath === false) {
            self::fail('Unable to create an isolated PHP script.');
        }

        $script = sprintf(
            <<<'PHP'
<?php
declare(strict_types=1);

define('WB_ROOT', %s);
define('WB_STORAGE', %s);
define('WB_BASE_PATH', '');

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

require %s;

register_shutdown_function(static function (): void {
    echo "\nWB_EMBED_STATUS:" . (http_response_code() ?: 200);
});

%s
PHP,
            var_export(WB_ROOT, true),
            var_export(WB_STORAGE, true),
            var_export(WB_ROOT . '/app/bootstrap.php', true),
            $code
        );

        file_put_contents($scriptPath, $script);

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath), $descriptors, $pipes);

            if (!is_resource($process)) {
                self::fail('Unable to start the isolated PHP worker.');
            }

            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        } finally {
            @unlink($scriptPath);
        }

        $responseCode = 0;
        if (preg_match('/WB_EMBED_STATUS:(\d+)/', $output, $matches) === 1) {
            $responseCode = (int) $matches[1];
        }

        $body = (string) preg_replace('/\n?WB_EMBED_STATUS:\d+\n?$/', '', $output);

        return ['status' => $status, 'output' => $body, 'errors' => $errors, 'response_code' => $responseCode];
    }
}
