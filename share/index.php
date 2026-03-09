<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\FileShares;
use WbFileBrowser\Security;

header('X-Robots-Tag: noindex, nofollow, noarchive');
Security::sendPageHeaders();

$bootstrap = wb_bootstrap_page('share');
$token = trim((string) ($_GET['token'] ?? ''));
$payload = null;

try {
    if ($token !== '') {
        $shareRateLimitBuckets = [
            [
                'scope' => 'share-token-ip',
                'identifier' => $token . '|' . Security::clientIp(),
                'limit' => 20,
                'window' => 5 * 60,
            ],
            [
                'scope' => 'share-ip',
                'identifier' => Security::clientIp(),
                'limit' => 60,
                'window' => 5 * 60,
            ],
        ];
        Security::assertRateLimitAvailable($shareRateLimitBuckets, 'Shared file unavailable right now.');
        Security::consumeRateLimit($shareRateLimitBuckets);
    }

    $payload = FileShares::viewPayload($token);
} catch (RuntimeException $exception) {
    http_response_code($exception->getMessage() === 'Shared file unavailable right now.' ? 429 : 404);
}
?>
<!doctype html>
<html lang="en">
<head>
    <?= wb_page_head(($payload === null ? 'Shared file unavailable' : $payload['file']['name']) . ' | wb-filebrowser') ?>
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
                <?php
                $file = $payload['file'];
                $share = $payload['share'];
                $previewMode = (string) ($file['preview_mode'] ?? $payload['preview_mode']);
                $fallbackBadge = wb_file_extension_badge((string) ($file['extension'] ?? ''));
                $fallbackLabel = (string) ($file['fallback_label'] ?? 'Download-only file');
                $fallbackIconUrl = (string) ($file['fallback_icon_url'] ?? wb_url('/media/file-fallbacks/binary.svg'));
                ?>
                <header class="share-header">
                    <div>
                        <p class="install-kicker">Shared file</p>
                        <h1><?= wb_h($file['name']) ?></h1>
                        <p><?= wb_h($file['mime_type']) ?></p>
                    </div>
                    <div class="share-actions">
                        <a class="header-button" href="<?= wb_h($file['download_url']) ?>">Download</a>
                    </div>
                </header>

                <div class="share-view">
                    <div class="preview-frame share-view__frame">
                        <?php if ($previewMode === 'image'): ?>
                            <img src="<?= wb_h($file['preview_url']) ?>" alt="<?= wb_h($file['name']) ?>">
                        <?php elseif ($previewMode === 'pdf'): ?>
                            <iframe src="<?= wb_h($file['preview_url']) ?>" title="Shared PDF preview"></iframe>
                        <?php elseif ($previewMode === 'video'): ?>
                            <video src="<?= wb_h($file['preview_url']) ?>" controls></video>
                        <?php elseif ($previewMode === 'audio'): ?>
                            <audio src="<?= wb_h($file['preview_url']) ?>" controls></audio>
                        <?php elseif ($previewMode === 'text'): ?>
                            <div class="share-text-preview">
                                <pre><?= wb_h((string) ($payload['text_preview'] ?? '')) ?></pre>
                                <?php if (!empty($payload['text_preview_truncated'])): ?>
                                    <p class="share-note">Only the first 256 KB is shown in the browser preview.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="file-fallback file-fallback--share">
                                <img class="file-fallback__icon" src="<?= wb_h($fallbackIconUrl) ?>" alt="">
                                <span class="file-fallback__badge"><?= wb_h($fallbackBadge) ?></span>
                                <strong><?= wb_h($fallbackLabel) ?></strong>
                                <p>Browser preview is unavailable for this format. Use the secure download action to open it locally.</p>
                                <a class="header-button primary-button" href="<?= wb_h($file['download_url']) ?>">Download file</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <aside class="preview-sidebar share-sidebar">
                        <dl>
                            <div><dt>Name</dt><dd><?= wb_h($file['name']) ?></dd></div>
                            <div><dt>Size</dt><dd><?= wb_h($file['size_label']) ?></dd></div>
                            <div><dt>Updated</dt><dd><?= wb_h($file['updated_relative']) ?></dd></div>
                            <div><dt>Shared</dt><dd><?= wb_h(wb_relative_time($share['created_at'])) ?></dd></div>
                            <div><dt>Checksum</dt><dd><?= wb_h($file['checksum']) ?></dd></div>
                        </dl>
                        <div class="share-direct-link">
                            <label class="share-direct-link__label" for="share-direct-link">Direct Link:</label>
                            <input
                                id="share-direct-link"
                                class="share-direct-link__field"
                                type="text"
                                readonly
                                value="<?= wb_h($share['url']) ?>"
                            >
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
