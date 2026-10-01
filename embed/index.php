<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\FileShares;
use WbFileBrowser\MaintenanceMode;
use WbFileBrowser\MaintenanceModeException;
use WbFileBrowser\Security;

foreach (Security::embedHeaders() as $headerName => $headerValue) {
    if ($headerName === 'Content-Security-Policy') {
        $headerValue = str_replace("script-src 'self'", "script-src 'self' 'unsafe-inline'", $headerValue);
    }
    header($headerName . ': ' . $headerValue);
}

$bootstrap = wb_bootstrap_page('share');
try {
    WbFileBrowser\IpBanService::assertCurrentIpAllowed();
} catch (BlockedAccessException $exception) {
    wb_blocked_page($exception->payload());
}
try {
    MaintenanceMode::assertAllowed($bootstrap['user'] ?? null, 'share');
} catch (MaintenanceModeException $exception) {
    wb_maintenance_page($exception->payload());
}

$token = trim((string) ($_GET['token'] ?? ''));
$payload = null;

if ($token !== '') {
    $embedRateLimitBuckets = [
        [
            'scope' => 'embed-view-token-ip',
            'identifier' => $token . '|' . Security::clientIp(),
            'limit' => 20,
            'window' => 5 * 60,
        ],
        [
            'scope' => 'embed-view-ip',
            'identifier' => Security::clientIp(),
            'limit' => 60,
            'window' => 5 * 60,
        ],
    ];

    try {
        Security::assertRateLimitAvailable(
            $embedRateLimitBuckets,
            'Shared media unavailable right now.',
            null,
            ['source' => 'embed_view']
        );
        Security::consumeRateLimit($embedRateLimitBuckets);
        $payload = FileShares::embedPagePayload($token);
    } catch (BlockedAccessException $exception) {
        wb_blocked_page($exception->payload());
    } catch (RuntimeException) {
        $payload = null;
    }
}

if ($payload === null) {
    http_response_code(404);
}

session_write_close();
$downloadUrl = $payload['download_url'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <?= wb_page_head(($payload['name'] ?? 'Shared media unavailable') . ' | wb-filebrowser') ?>
    <meta name="robots" content="noindex,nofollow,noarchive">
</head>
<body class="share-shell">
    <main class="share-layout">
        <section class="share-card">
            <?php if ($payload === null): ?>
                <p class="install-kicker">Shared file</p>
                <h1>This share link is unavailable.</h1>
                <p>The link may be invalid, expired, or disabled by an administrator.</p>
            <?php else: ?>
                <header class="share-header">
                    <div>
                        <p class="install-kicker">Shared file</p>
                        <h1><?= wb_h($payload['name']) ?></h1>
                        <p class="share-header__meta"><?= wb_h($payload['mime_type']) ?> · <?= wb_h($payload['size_label']) ?></p>
                    </div>
                    <div class="share-actions">
                        <a class="header-button share-download" href="<?= wb_h($downloadUrl) ?>">Download</a>
                    </div>
                </header>

                <div class="share-view share-view--embed">
                    <div class="preview-frame share-view__frame">
                        <?php if ($payload['preview_mode'] === 'audio'): ?>
                            <div class="media-player media-player--audio">
                                <div class="media-player__stage">
                                    <div class="media-player__audio-art">
                                        <div class="media-player__art">
                                            <span class="media-player__eq" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></span>
                                        </div>
                                        <strong><?= wb_h($payload['name']) ?></strong>
                                    </div>
                                    <audio id="share-media" src="<?= wb_h($payload['stream_url']) ?>" preload="metadata"></audio>
                                </div>
                                <div class="media-player__bar">
                                    <div class="media-player__controls">
                                        <button class="media-player__btn media-player__btn--play" type="button" data-mp="play" aria-label="Play">
                                            <svg class="media-player__icon media-player__icon--play" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14l12-7z"/></svg>
                                            <svg class="media-player__icon media-player__icon--pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 5h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1H5.5c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Zm9.6 0h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1h-3.4c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Z"/></svg>
                                        </button>
                                        <span class="media-player__time" data-mp="current">0:00</span>
                                        <input class="media-player__seek" type="range" min="0" max="0" step="0.1" value="0" data-mp="seek" style="--mp-fill:0%" aria-label="Seek">
                                        <span class="media-player__time" data-mp="duration">0:00</span>
                                        <span class="media-player__volume">
                                            <button class="media-player__btn" type="button" data-mp="mute" aria-label="Mute">
                                                <svg class="media-player__icon media-player__icon--unmuted" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5c0 .55.45 1 1 1h2.6l3.7 3.1c.66.55 1.65.08 1.65-.77V6.17c0-.85-1-1.32-1.65-.77L7.6 8.5H5c-.55 0-1 .45-1 1Z"/><path d="M15.4 9.3a.9.9 0 0 1 1.26-.14 4.4 4.4 0 0 1 0 5.68.9.9 0 1 1-1.4-1.13 2.6 2.6 0 0 0 0-3.42.9.9 0 0 1 .14-1.13Z"/><path d="M17.6 6.5a.9.9 0 0 1 1.26-.15 7.6 7.6 0 0 1 0 11.3.9.9 0 1 1-1.2-1.34 5.8 5.8 0 0 0 0-8.62.9.9 0 0 1-.06-1.19Z"/></svg>
                                                <svg class="media-player__icon media-player__icon--muted" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5c0 .55.45 1 1 1h2.6l3.7 3.1c.66.55 1.65.08 1.65-.77V6.17c0-.85-1-1.32-1.65-.77L7.6 8.5H5c-.55 0-1 .45-1 1Z"/><path d="M15.3 9.05a.9.9 0 0 1 1.27 0l1.63 1.64 1.63-1.64a.9.9 0 1 1 1.27 1.28L19.47 12l1.63 1.64a.9.9 0 1 1-1.27 1.27L18.2 13.27l-1.63 1.64a.9.9 0 1 1-1.27-1.27L16.93 12 15.3 10.33a.9.9 0 0 1 0-1.28Z"/></svg>
                                            </button>
                                            <input class="media-player__volume" type="range" min="0" max="1" step="0.05" value="1" data-mp="volume" style="--mp-fill:100%" aria-label="Volume">
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="media-player media-player--video">
                                <div class="media-player__stage">
                                    <video id="share-media" src="<?= wb_h($payload['stream_url']) ?>" preload="metadata"></video>
                                </div>
                                <div class="media-player__bar">
                                    <div class="media-player__controls">
                                        <button class="media-player__btn media-player__btn--play" type="button" data-mp="play" aria-label="Play">
                                            <svg class="media-player__icon media-player__icon--play" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14l12-7z"/></svg>
                                            <svg class="media-player__icon media-player__icon--pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 5h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1H5.5c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Zm9.6 0h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1h-3.4c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Z"/></svg>
                                        </button>
                                        <span class="media-player__time" data-mp="current">0:00</span>
                                        <input class="media-player__seek" type="range" min="0" max="0" step="0.1" value="0" data-mp="seek" style="--mp-fill:0%" aria-label="Seek">
                                        <span class="media-player__time" data-mp="duration">0:00</span>
                                        <span class="media-player__volume">
                                            <button class="media-player__btn" type="button" data-mp="mute" aria-label="Mute">
                                                <svg class="media-player__icon media-player__icon--unmuted" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5c0 .55.45 1 1 1h2.6l3.7 3.1c.66.55 1.65.08 1.65-.77V6.17c0-.85-1-1.32-1.65-.77L7.6 8.5H5c-.55 0-1 .45-1 1Z"/><path d="M15.4 9.3a.9.9 0 0 1 1.26-.14 4.4 4.4 0 0 1 0 5.68.9.9 0 1 1-1.4-1.13 2.6 2.6 0 0 0 0-3.42.9.9 0 0 1 .14-1.13Z"/><path d="M17.6 6.5a.9.9 0 0 1 1.26-.15 7.6 7.6 0 0 1 0 11.3.9.9 0 1 1-1.2-1.34 5.8 5.8 0 0 0 0-8.62.9.9 0 0 1-.06-1.19Z"/></svg>
                                                <svg class="media-player__icon media-player__icon--muted" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5c0 .55.45 1 1 1h2.6l3.7 3.1c.66.55 1.65.08 1.65-.77V6.17c0-.85-1-1.32-1.65-.77L7.6 8.5H5c-.55 0-1 .45-1 1Z"/><path d="M15.3 9.05a.9.9 0 0 1 1.27 0l1.63 1.64 1.63-1.64a.9.9 0 1 1 1.27 1.28L19.47 12l1.63 1.64a.9.9 0 1 1-1.27 1.27L18.2 13.27l-1.63 1.64a.9.9 0 1 1-1.27-1.27L16.93 12 15.3 10.33a.9.9 0 0 1 0-1.28Z"/></svg>
                                            </button>
                                            <input class="media-player__volume" type="range" min="0" max="1" step="0.05" value="1" data-mp="volume" style="--mp-fill:100%" aria-label="Volume">
                                        </span>
                                        <button class="media-player__btn" type="button" data-mp="fullscreen" aria-label="Fullscreen">
                                            <svg class="media-player__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h4.5a1 1 0 0 1 0 2H6v2.5a1 1 0 0 1-2 0V4Zm11.5 0H20v4.5a1 1 0 0 1-2 0V6h-2.5a1 1 0 0 1 0-2ZM4 15.5a1 1 0 0 1 2 0V18h2.5a1 1 0 0 1 0 2H4v-4.5Zm16 0V20h-4.5a1 1 0 0 1 0-2H18v-2.5a1 1 0 0 1 2 0Z"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
    (function() {
        var media = document.getElementById('share-media');
        if (!media) {
            return;
        }
        var root = media.closest('.media-player');
        var playBtn = root.querySelector('[data-mp="play"]');
        var muteBtn = root.querySelector('[data-mp="mute"]');
        var fsBtn = root.querySelector('[data-mp="fullscreen"]');
        var seek = root.querySelector('[data-mp="seek"]');
        var volume = root.querySelector('[data-mp="volume"]');
        var current = root.querySelector('[data-mp="current"]');
        var duration = root.querySelector('[data-mp="duration"]');
        var dragging = false;

        function fmt(s) {
            s = Math.max(0, Math.floor(Number(s) || 0));
            var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            var p = function(n) { return String(n).padStart(2, '0'); };
            return h > 0 ? h + ':' + p(m) + ':' + p(sec) : m + ':' + p(sec);
        }

        function fill(input, ratio) {
            if (!input) { return; }
            var pct = Math.max(0, Math.min(100, ratio * 100));
            input.style.setProperty('--mp-fill', pct + '%');
        }

        function syncPlay() {
            root.classList.toggle('is-playing', !media.paused);
            root.classList.toggle('is-paused', media.paused);
            if (playBtn) { playBtn.setAttribute('aria-label', media.paused ? 'Play' : 'Pause'); }
        }

        function syncMute() {
            var muted = media.muted || media.volume === 0;
            root.classList.toggle('is-muted', muted);
            if (muteBtn) { muteBtn.setAttribute('aria-label', muted ? 'Unmute' : 'Mute'); }
            if (volume) { volume.value = muted ? 0 : media.volume; fill(volume, muted ? 0 : media.volume); }
        }

        function syncTimeline() {
            var d = media.duration;
            if (duration) { duration.textContent = isFinite(d) ? fmt(d) : '0:00'; }
            if (current) { current.textContent = fmt(media.currentTime); }
            if (seek) {
                seek.max = isFinite(d) ? d : 0;
                if (!dragging) { seek.value = media.currentTime; }
                fill(seek, isFinite(d) && d > 0 ? media.currentTime / d : 0);
            }
        }

        if (playBtn) { playBtn.addEventListener('click', function() { media.paused ? media.play() : media.pause(); }); }
        if (muteBtn) { muteBtn.addEventListener('click', function() { media.muted = !media.muted; syncMute(); }); }
        if (fsBtn) {
            fsBtn.addEventListener('click', function() {
                if (document.fullscreenElement) { document.exitFullscreen && document.exitFullscreen(); }
                else if (root.requestFullscreen) { root.requestFullscreen(); }
            });
        }
        if (seek) {
            seek.addEventListener('input', function() {
                var v = Number(seek.value);
                if (isFinite(v) && isFinite(media.duration) && media.duration > 0) { media.currentTime = v; }
                if (current) { current.textContent = fmt(v); }
                fill(seek, isFinite(media.duration) && media.duration > 0 ? v / media.duration : 0);
            });
            seek.addEventListener('pointerdown', function() { dragging = true; });
            window.addEventListener('pointerup', function() { dragging = false; });
        }
        if (volume) {
            volume.addEventListener('input', function() {
                var v = Number(volume.value);
                if (isFinite(v)) {
                    media.volume = v;
                    if (v > 0 && media.muted) { media.muted = false; }
                    syncMute();
                }
            });
        }

        media.addEventListener('click', function() { media.paused ? media.play() : media.pause(); });
        media.addEventListener('timeupdate', syncTimeline);
        media.addEventListener('loadedmetadata', syncTimeline);
        media.addEventListener('durationchange', syncTimeline);
        media.addEventListener('play', syncPlay);
        media.addEventListener('pause', syncPlay);
        media.addEventListener('ended', syncPlay);
        media.addEventListener('volumechange', syncMute);

        syncPlay();
        syncMute();
        syncTimeline();
    })();
    </script>
</body>
</html>
