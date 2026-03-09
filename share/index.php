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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css" integrity="sha512-IcFlCApjMGRGOjIzuoEVRzG0VFfIlVMl5XGXfbB0hAGsiOoMhmRe5Y7IFvkR1onRnOBjnMYCnjRCgnOqol2yBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .share-text-preview pre code.hljs {
            background: transparent;
            padding: 20px;
            font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
            font-size: .9rem;
            line-height: 1.6;
            tab-size: 4;
        }
        .share-text-preview pre {
            margin: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: #08111d;
            border-radius: 0;
        }
        /* Harmonise hljs token colours with the site palette */
        .share-text-preview .hljs-keyword,
        .share-text-preview .hljs-selector-tag,
        .share-text-preview .hljs-built_in { color: #c4a5ff; }
        .share-text-preview .hljs-string,
        .share-text-preview .hljs-addition { color: #7ee787; }
        .share-text-preview .hljs-number,
        .share-text-preview .hljs-literal { color: #79c0ff; }
        .share-text-preview .hljs-comment,
        .share-text-preview .hljs-meta { color: #6a7d98; font-style: italic; }
        .share-text-preview .hljs-function .hljs-title,
        .share-text-preview .hljs-title.function_ { color: #d2a8ff; }
        .share-text-preview .hljs-attr,
        .share-text-preview .hljs-attribute { color: #79c0ff; }
        .share-text-preview .hljs-variable,
        .share-text-preview .hljs-template-variable { color: #ffa657; }
        .share-text-preview .hljs-type,
        .share-text-preview .hljs-title.class_ { color: #ffa657; }
        .share-text-preview .hljs-tag { color: #7ee787; }
        .share-text-preview .hljs-name { color: #7ee787; }
        .share-text-preview .hljs-selector-class { color: #d2a8ff; }
        .share-text-preview .hljs-selector-id { color: #79c0ff; }
        .share-text-preview .hljs-deletion { color: #ffa198; background: rgba(248,81,73,.15); }
        .share-text-preview .hljs-addition { background: rgba(46,160,67,.15); }
        .share-text-preview .hljs-section { color: #79c0ff; font-weight: 700; }
        .share-text-preview .hljs-symbol { color: #ffa657; }
    </style>
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
                $hljsLang = strtolower(trim((string) ($file['extension'] ?? '')));
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
                                <pre><code id="share-code" class="language-<?= wb_h($hljsLang) ?>"><?= wb_h((string) ($payload['text_preview'] ?? '')) ?></code></pre>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js" integrity="sha512-EBLzUFvhGRVMOiaBSTpeY5cHa6yGEEFnTnmRm/KOgFcdCpSXJR+z5Aiv9T+CJNLS8Mp/EEfzMBKoip0fmkBEQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    (function() {
        var el = document.getElementById('share-code');
        if (el) {
            hljs.highlightElement(el);
        }
    })();
    </script>
</body>
</html>
