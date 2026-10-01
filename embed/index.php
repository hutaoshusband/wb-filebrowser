<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\FileShares;
use WbFileBrowser\MaintenanceMode;
use WbFileBrowser\MaintenanceModeException;
use WbFileBrowser\Security;

foreach (Security::embedHeaders() as $headerName => $headerValue) {
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
$downloadUrl = wb_url('/api/index.php?action=share.stream&token=' . $token . '&disposition=attachment');
?>
<!doctype html>
<html lang="en">
<head>
    <?= wb_page_head(($payload['name'] ?? 'Shared media unavailable') . ' | wb-filebrowser') ?>
    <meta name="robots" content="noindex,nofollow,noarchive">
</head>
<body class="share-shell embed-shell">
    <main class="share-layout">
        <section class="share-card embed-card">
            <?php if ($payload === null): ?>
                <p class="install-kicker">Shared media</p>
                <h1>This share link is unavailable.</h1>
                <p>The link may be invalid, expired, or disabled by an administrator.</p>
            <?php else: ?>
                <header class="share-header">
                    <div>
                        <p class="install-kicker">Shared media</p>
                        <h1><?= wb_h($payload['name']) ?></h1>
                        <p><?= wb_h($payload['mime_type']) ?> · <?= wb_h($payload['size_label']) ?></p>
                    </div>
                    <div class="share-actions">
                        <a class="header-button" href="<?= wb_h($downloadUrl) ?>">Download</a>
                    </div>
                </header>

                <div class="share-view">
                    <div class="preview-frame share-view__frame">
                        <?php if ($payload['preview_mode'] === 'audio'): ?>
                            <audio controls preload="metadata" src="<?= wb_h($payload['stream_url']) ?>"></audio>
                        <?php else: ?>
                            <video controls playsinline preload="metadata" src="<?= wb_h($payload['stream_url']) ?>"></video>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
