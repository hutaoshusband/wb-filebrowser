<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Auth;
use WbFileBrowser\Database;
use WbFileBrowser\FileShares;
use WbFileBrowser\Installer;
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
        $this->assertStringContainsString('width="560" height="315"', $share['embed_html']);
        $this->assertStringContainsString('allow="autoplay; fullscreen; picture-in-picture"', $share['embed_html']);
        $this->assertSame($share['token'], FileShares::get($this->superAdmin(), (int) $video['id'])['token']);

        $audioShare = FileShares::create($this->superAdmin(), (int) $audio['id'], ['allow_embed' => true]);

        $this->assertTrue($audioShare['allow_embed']);
        $this->assertStringContainsString('width="100%" height="200"', $audioShare['embed_html']);
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

    public function testStandardUsersCannotCreateSharesWithDefaultSettings(): void
    {
        $member = $this->createUser('member', 'user');
        $file = $this->createFile('clip.mp4', 'video payload', 'video/mp4');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only administrators can manage share links.');

        FileShares::create($member, (int) $file['id'], ['allow_embed' => true]);
    }

    public function testEnabledStandardUsersShareOnlyInsideAccessibleFolders(): void
    {
        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => true]]);
        $member = $this->createUser('member', 'user');
        $teamFolder = $this->createFolder('Team');
        $privateFolder = $this->createFolder('Private');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => (int) $teamFolder['id'], 'can_view' => true],
        ]);
        $teamFile = $this->createFile('episode.mp4', 'member video', 'video/mp4', (int) $teamFolder['id']);
        $privateFile = $this->createFile('secret.mp4', 'private video', 'video/mp4', (int) $privateFolder['id']);

        $share = FileShares::create($member, (int) $teamFile['id'], ['allow_embed' => true]);

        $this->assertTrue($share['allow_embed']);
        $this->assertStringContainsString('/embed/?token=', $share['embed_url']);
        $this->assertSame('episode.mp4', FileShares::embedPagePayload($share['token'])['name']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only administrators can manage share links.');

        FileShares::create($member, (int) $privateFile['id'], ['allow_embed' => true]);
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

        Auth::login('superadmin', 'SuperSecurePass123!');
        $response = $this->request('files.share.create', 'POST', [
            'file_id' => (int) $video['id'],
            'password' => 'Secret 123',
            'allow_embed' => true,
        ]);

        $this->assertSame(400, $response['status'], $response['body']);
        $this->assertStringContainsString('Share passwords cannot be combined with embedding.', $response['body']);
    }

    public function testEnablingEmbeddingOnAPasswordProtectedShareIsRejected(): void
    {
        $video = $this->createFile('clip.mp4', 'video payload', 'video/mp4');
        FileShares::create($this->superAdmin(), (int) $video['id'], ['password' => 'Secret 123']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Share passwords cannot be combined with embedding.');

        FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);
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

    public function testEmbedPageWithoutTokenReturnsNotFound(): void
    {
        $code = '$_GET = [];' . <<<'PHP'
ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body]);
});
require WB_ROOT . '/embed/index.php';
PHP;
        $result = $this->executeConcurrentIsolatedPhp($code, 1)[0];

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $response = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('This share link is unavailable.', $response['body']);
    }

    public function testDisablingShareEmbedsWithdrawsUserEmbedsButNotAdminEmbeds(): void
    {
        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => true]]);
        $member = $this->createUser('member', 'user');
        $teamFolder = $this->createFolder('Team');
        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => (int) $teamFolder['id'], 'can_view' => true],
        ]);
        $memberFile = $this->createFile('episode.mp4', 'member video', 'video/mp4', (int) $teamFolder['id']);
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

        $withdrawn = FileShares::get($this->superAdmin(), (int) $memberFile['id']);

        $this->assertTrue($withdrawn['allow_embed']);
        $this->assertArrayNotHasKey('embed_url', $withdrawn);
        $this->assertArrayNotHasKey('embed_html', $withdrawn);
    }

    public function testEveryEmbedRefusalThrowsTheIdenticalRuntimeException(): void
    {
        $plainVideo = $this->createFile('plain.mp4', 'video payload', 'video/mp4');
        $lockedVideo = $this->createFile('locked.mp4', 'video payload', 'video/mp4');
        $termsVideo = $this->createFile('terms.mp4', 'video payload', 'video/mp4');
        $containerVideo = $this->createFile(' rip .mkv', 'matroska payload', 'video/x-matroska');

        $plainShare = FileShares::create($this->superAdmin(), (int) $plainVideo['id']);
        FileShares::create($this->superAdmin(), (int) $lockedVideo['id'], ['password' => 'Secret 123']);
        Database::connection()
            ->prepare('UPDATE file_shares SET allow_embed = 1 WHERE file_id = :file_id AND revoked_at IS NULL')
            ->execute([':file_id' => (int) $lockedVideo['id']]);
        $lockedShare = FileShares::get($this->superAdmin(), (int) $lockedVideo['id']);
        $termsShare = FileShares::create($this->superAdmin(), (int) $termsVideo['id'], ['allow_embed' => true]);
        $containerShare = FileShares::create($this->superAdmin(), (int) $containerVideo['id'], ['allow_embed' => true]);
        Settings::saveAdminSettings(['access' => ['share_terms_enabled' => true]]);

        $refusedTokens = [
            str_repeat('f', 32),
            $plainShare['token'],
            $lockedShare['token'],
            $termsShare['token'],
            $containerShare['token'],
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

    public function testMigrationRestoresTheAllowEmbedColumnAndSetting(): void
    {
        $pdo = Database::connection();
        $dropped = false;

        try {
            $pdo->exec('ALTER TABLE file_shares DROP COLUMN allow_embed');
            $dropped = true;
        } catch (\PDOException) {
        }

        if (!$dropped) {
            $pdo->exec('DROP TABLE file_shares');
            $pdo->exec(
                'CREATE TABLE file_shares (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                    token TEXT NOT NULL UNIQUE,
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    expires_at TEXT NULL,
                    max_views INTEGER NULL,
                    view_count INTEGER NOT NULL DEFAULT 0,
                    password_hash TEXT NULL,
                    password_version INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    revoked_at TEXT NULL
                )'
            );
        }

        $deleteSetting = $pdo->prepare('DELETE FROM settings WHERE key = :key');
        $deleteSetting->execute([':key' => 'share_embeds_enabled']);
        Database::disconnect();

        Installer::migrate();

        $columns = Database::connection()->query('PRAGMA table_info(file_shares)')->fetchAll();
        $allowEmbed = null;

        foreach ($columns as $column) {
            if ($column['name'] === 'allow_embed') {
                $allowEmbed = $column;
            }
        }

        $this->assertNotNull($allowEmbed);
        $this->assertSame(1, (int) $allowEmbed['notnull']);
        $this->assertSame('0', $allowEmbed['dflt_value']);
        $this->assertSame('0', Database::setting('share_embeds_enabled'));
        $this->assertFalse(Settings::shareEmbedsEnabled());
    }

    public function testShareApiServesStandardUsersOnlyWhenEmbedsAreEnabled(): void
    {
        $member = $this->createUser('member', 'user');
        $file = $this->createFile('clip.mp4', 'video payload', 'video/mp4');
        Auth::login('member', 'AnotherSecurePass123!');

        $denied = $this->request('files.share.create', 'POST', [
            'file_id' => (int) $file['id'],
            'allow_embed' => true,
        ]);

        $this->assertSame(400, $denied['status'], $denied['body']);
        $this->assertStringContainsString('Only administrators can manage share links.', $denied['body']);

        Permissions::saveMatrix($this->superAdmin(), 'user', (int) $member['id'], [
            ['folder_id' => (int) $file['folder_id'], 'can_view' => true],
        ]);
        Settings::saveAdminSettings(['access' => ['share_embeds_enabled' => true]]);

        $session = $this->request('auth.session', 'GET');
        $sessionBody = json_decode($session['body'], true);

        $this->assertSame(200, $session['status'], $session['body']);
        $this->assertTrue($sessionBody['share_embeds_enabled']);

        $created = $this->request('files.share.create', 'POST', [
            'file_id' => (int) $file['id'],
            'allow_embed' => true,
        ]);
        $createdShare = json_decode($created['body'], true)['share'] ?? null;

        $this->assertSame(201, $created['status'], $created['body']);
        $this->assertNotNull($createdShare);
        $this->assertTrue($createdShare['allow_embed']);
        $this->assertStringContainsString('/embed/?token=' . $createdShare['token'], $createdShare['embed_url']);
        $this->assertStringContainsString('/embed/stream/?token=' . $createdShare['token'], $createdShare['discord_url']);

        $fetched = $this->request('files.share.get', 'GET', [], true, '', ['file_id' => (int) $file['id']]);
        $fetchedShare = json_decode($fetched['body'], true)['share'] ?? null;

        $this->assertSame(200, $fetched['status'], $fetched['body']);
        $this->assertSame($createdShare['token'], $fetchedShare['token']);
        $this->assertTrue($fetchedShare['allow_embed']);

        $revoked = $this->request('files.share.revoke', 'POST', [
            'file_id' => (int) $file['id'],
        ]);

        $this->assertSame(200, $revoked['status'], $revoked['body']);
        $this->assertNull(FileShares::get($this->superAdmin(), (int) $file['id']));
    }

    public function testEmbedStreamsMediaWithRangeSupport(): void
    {
        $contents = 'fake video payload';
        $video = $this->createFile('clip.mp4', $contents, 'video/mp4');
        $share = FileShares::create($this->superAdmin(), (int) $video['id'], ['allow_embed' => true]);
        $blobPath = wb_storage_path(
            'uploads/' . substr((string) $video['disk_name'], 0, 2) . '/' . substr((string) $video['disk_name'], 2, 2) . '/'
            . (string) $video['disk_name'] . '.' . (string) $video['disk_extension']
        );

        $streamWorker = static fn (string $rangeHeader, string $token): string => '$_SERVER["HTTP_RANGE"] = ' . var_export($rangeHeader, true) . ';'
            . "\n" . 'try { \WbFileBrowser\FileShares::streamEmbed(' . var_export($token, true) . '); } catch (\RuntimeException $e) { echo "REFUSED"; }';

        $full = $this->executeConcurrentIsolatedPhp($streamWorker('', $share['token']), 1)[0];
        $this->assertSame(0, $full['exit_code'], $full['stderr']);
        $this->assertSame($contents, $full['stdout']);

        $partial = $this->executeConcurrentIsolatedPhp($streamWorker('bytes=5-9', $share['token']), 1)[0];
        $this->assertSame(0, $partial['exit_code'], $partial['stderr']);
        $this->assertSame(substr($contents, 5, 5), $partial['stdout']);

        $suffix = $this->executeConcurrentIsolatedPhp($streamWorker('bytes=-6', $share['token']), 1)[0];
        $this->assertSame(0, $suffix['exit_code'], $suffix['stderr']);
        $this->assertSame(substr($contents, -6), $suffix['stdout']);

        $multiRange = $this->executeConcurrentIsolatedPhp($streamWorker('bytes=0-1,3-4', $share['token']), 1)[0];
        $this->assertSame(0, $multiRange['exit_code'], $multiRange['stderr']);
        $this->assertSame($contents, $multiRange['stdout']);

        $unsatisfiable = $this->executeConcurrentIsolatedPhp($streamWorker('bytes=9999-', $share['token']), 1)[0];
        $this->assertSame(0, $unsatisfiable['exit_code'], $unsatisfiable['stderr']);
        $this->assertSame('', $unsatisfiable['stdout']);

        $refused = $this->executeConcurrentIsolatedPhp($streamWorker('', str_repeat('f', 32)), 1)[0];
        $this->assertSame(0, $refused['exit_code'], $refused['stderr']);
        $this->assertSame('REFUSED', $refused['stdout']);

        $badMimeWorker = 'try { \WbFileBrowser\Security::sendMediaFile(' . var_export($blobPath, true) . ', "text/html", "clip.mp4"); } catch (\Throwable $e) { echo "ERR"; }';
        $badMime = $this->executeConcurrentIsolatedPhp($badMimeWorker, 1)[0];
        $this->assertSame(0, $badMime['exit_code'], $badMime['stderr']);
        $this->assertSame('', $badMime['stdout']);
    }

    private function request(string $action, string $method, array $post = [], bool $csrf = true, string $extra = '', array $query = []): array
    {
        $code = '$_SESSION = ' . var_export($_SESSION, true) . ';' .
            '$_GET = ' . var_export(array_merge(['action' => $action], $query), true) . ';' .
            '$_POST = ' . var_export($post, true) . ';' .
            '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' .
            ($csrf ? '$_SERVER["HTTP_X_CSRF_TOKEN"] = \WbFileBrowser\Security::csrfToken();' : '') . $extra . <<<'PHP'
ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body]);
});
require WB_ROOT . '/api/index.php';
PHP;
        $result = $this->executeConcurrentIsolatedPhp($code, 1)[0];
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }
}
