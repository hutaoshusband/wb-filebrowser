<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\AuditLog;
use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\FileShares;
use WbFileBrowser\Security;

header('X-Robots-Tag: noindex, nofollow, noarchive');

// Override the default page headers with a relaxed CSP that allows highlight.js from cdnjs
$shareHeaders = Security::pageHeaders();
$shareHeaders['Content-Security-Policy'] = "default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; connect-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
foreach ($shareHeaders as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

$bootstrap = wb_bootstrap_page('share');
try {
    \WbFileBrowser\IpBanService::assertCurrentIpAllowed();
} catch (BlockedAccessException $exception) {
    wb_blocked_page($exception->payload());
}
try {
    \WbFileBrowser\MaintenanceMode::assertAllowed($bootstrap['user'] ?? null, 'share');
} catch (\WbFileBrowser\MaintenanceModeException $exception) {
    wb_maintenance_page($exception->payload());
}
$token = trim((string) ($_GET['token'] ?? ''));
$payload = null;
$shareContext = null;
$passwordError = '';
$termsError = '';
$blockedFlash = $_SESSION['share_blocked_flash'] ?? null;

if (is_array($blockedFlash)) {
    unset($_SESSION['share_blocked_flash']);

    if (($blockedFlash['token'] ?? null) === $token && is_array($blockedFlash['blocked'] ?? null)) {
        wb_blocked_page($blockedFlash['blocked']);
    }
}

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
        Security::assertRateLimitAvailable(
            $shareRateLimitBuckets,
            'Shared file unavailable right now.',
            null,
            ['source' => 'share_view']
        );
        Security::consumeRateLimit($shareRateLimitBuckets);

        $shareContext = FileShares::publicContext($token);

        if (!empty($shareContext['share']['requires_password']) && empty($shareContext['is_unlocked'])) {
            if (wb_request_method() === 'POST') {
                Security::assertCsrfToken(is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : null);
                $passwordRateLimitBuckets = [
                    [
                        'scope' => 'share-password-token-ip',
                        'identifier' => $token . '|' . Security::clientIp(),
                        'limit' => 5,
                        'window' => 15 * 60,
                    ],
                ];
                Security::assertRateLimitAvailable(
                    $passwordRateLimitBuckets,
                    'Shared file unavailable right now.',
                    null,
                    ['source' => 'share_password']
                );

                if (FileShares::unlock($token, (string) ($_POST['share_password'] ?? ''))) {
                    Security::clearRateLimit($passwordRateLimitBuckets);
                    header('Location: ' . ($shareContext['share']['url'] ?? wb_url('/share/?token=' . $token)), true, 303);
                    exit;
                }

                Security::consumeRateLimit($passwordRateLimitBuckets);

                if (Security::rateLimitBlockInfo($passwordRateLimitBuckets) !== null) {
                    AuditLog::record('share.password.lockout', 'security_actions', [
                        'target_type' => 'share',
                        'target_label' => (string) ($shareContext['file']['name'] ?? 'Shared file'),
                        'summary' => 'Blocked password attempts for shared file ' . (string) ($shareContext['file']['name'] ?? 'Shared file'),
                        'metadata' => [
                            'token' => $token,
                        ],
                    ]);
                    Security::assertRateLimitAvailable(
                        $passwordRateLimitBuckets,
                        'Shared file unavailable right now.',
                        null,
                        ['source' => 'share_password']
                    );
                }

                $passwordError = 'Incorrect password.';
            }
        } elseif (!empty($shareContext['requires_terms_acceptance'])) {
            if (wb_request_method() === 'POST') {
                Security::assertCsrfToken(is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : null);

                if (wb_parse_bool($_POST['accept_terms'] ?? false)) {
                    FileShares::acceptTerms();
                    header('Location: ' . ($shareContext['share']['url'] ?? wb_url('/share/?token=' . $token)), true, 303);
                    exit;
                }

                $termsError = 'Please accept the shared file terms to continue.';
            }
        } else {
            $payload = FileShares::viewPayload($token);
        }
    }
} catch (BlockedAccessException $exception) {
    if (wb_request_method() === 'POST') {
        $_SESSION['share_blocked_flash'] = [
            'token' => $token,
            'blocked' => $exception->payload(),
        ];
        header('Location: ' . wb_url('/share/?token=' . $token), true, 303);
        exit;
    }

    wb_blocked_page($exception->payload());
} catch (RuntimeException $exception) {
    http_response_code(404);
}

$pageFile = $payload['file'] ?? ($shareContext['file'] ?? null);
?>
<!doctype html>
<html lang="en">
<head>
    <?= wb_page_head((($pageFile['name'] ?? 'Shared file unavailable')) . ' | wb-filebrowser') ?>
    <meta name="robots" content="noindex,nofollow,noarchive">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Force the text preview to fill the frame and scroll inside it */
        .preview-frame:has(.share-text-preview) {
            place-items: stretch;
            overflow: auto;
        }
        .share-text-preview {
            overflow: visible;
        }
        .share-text-preview pre {
            margin: 0;
            width: 100%;
            min-height: 100%;
            overflow-x: auto;
            overflow-y: visible;
            background: #08111d;
        }
        .preview-frame:has(.share-text-preview),
        .share-text-preview pre {
            scrollbar-width: thin;
            scrollbar-color: rgba(250, 204, 21, 0.88) rgba(7, 15, 28, 0.96);
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar,
        .share-text-preview pre::-webkit-scrollbar {
            width: 14px;
            height: 14px;
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-track,
        .share-text-preview pre::-webkit-scrollbar-track {
            background: linear-gradient(180deg, rgba(14, 24, 40, 0.98), rgba(6, 12, 22, 0.98));
            border-radius: 999px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-thumb,
        .share-text-preview pre::-webkit-scrollbar-thumb {
            border: 3px solid rgba(7, 15, 28, 0.96);
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(250, 204, 21, 0.95), rgba(121, 192, 255, 0.82));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.34), 0 0 14px rgba(250, 204, 21, 0.16);
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-thumb:hover,
        .share-text-preview pre::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, rgba(253, 224, 71, 0.98), rgba(147, 197, 253, 0.9));
        }
        .share-text-preview pre code.hljs {
            background: transparent;
            padding: 20px;
            font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
            font-size: .9rem;
            line-height: 1.6;
            tab-size: 4;
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
            <?php if ($payload === null && $shareContext === null): ?>
                <p class="install-kicker">Shared file</p>
                <h1>This share link is unavailable.</h1>
                <p>The link may be invalid, expired, or disabled by an administrator.</p>
            <?php elseif ($payload === null): ?>
                <?php
                $file = $shareContext['file'];
                $share = $shareContext['share'];
                ?>
                <header class="share-header">
                    <div>
                        <p class="install-kicker">Shared file</p>
                        <h1><?= wb_h($file['name']) ?></h1>
                        <p><?= wb_h($file['mime_type']) ?></p>
                    </div>
                </header>

                <div class="share-view">
                    <div class="preview-frame share-view__frame">
                        <?php if (!empty($shareContext['share']['requires_password']) && empty($shareContext['is_unlocked'])): ?>
                            <form class="share-password-card" method="post" autocomplete="off">
                                <p class="install-kicker">Protected access</p>
                                <h2>Enter password</h2>
                                <p class="share-lock-note">This shared file is locked.</p>
                                <input type="hidden" name="csrf_token" value="<?= wb_h(Security::csrfToken()) ?>">
                                <label class="share-password-card__field">
                                    <span>Password</span>
                                    <input
                                        type="password"
                                        name="share_password"
                                        autocomplete="current-password"
                                        required
                                        autofocus
                                    >
                                </label>
                                <?php if ($passwordError !== ''): ?>
                                    <p class="share-password-card__error"><?= wb_h($passwordError) ?></p>
                                <?php endif; ?>
                                <button class="header-button primary-button" type="submit">Unlock share</button>
                            </form>
                        <?php else: ?>
                            <form class="share-password-card share-terms-card" method="post" autocomplete="off">
                                <p class="install-kicker">Shared file terms</p>
                                <h2>Accept to continue</h2>
                                <p class="share-lock-note share-terms-card__copy"><?= nl2br(wb_h((string) ($shareContext['terms_message'] ?? ''))) ?></p>
                                <input type="hidden" name="csrf_token" value="<?= wb_h(Security::csrfToken()) ?>">
                                <label class="checkbox-control checkbox-control--row share-terms-card__checkbox">
                                    <input class="checkbox-control__input" type="checkbox" name="accept_terms" value="1" required>
                                    <span class="checkbox-control__indicator" aria-hidden="true"></span>
                                    <span class="checkbox-control__label">I accept these conditions for opening or downloading this shared file.</span>
                                </label>
                                <?php if ($termsError !== ''): ?>
                                    <p class="share-password-card__error"><?= wb_h($termsError) ?></p>
                                <?php endif; ?>
                                <button class="header-button primary-button" type="submit">Continue to file</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <aside class="preview-sidebar share-sidebar">
                        <dl>
                            <div><dt>Name</dt><dd><?= wb_h($file['name']) ?></dd></div>
                            <div><dt>Size</dt><dd><?= wb_h($file['size_label']) ?></dd></div>
                            <div><dt>Updated</dt><dd><?= wb_h($file['updated_relative']) ?></dd></div>
                            <div><dt>Shared</dt><dd><?= wb_h(wb_relative_time($share['created_at'])) ?></dd></div>
                        </dl>
                    </aside>
                </div>
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
                                value="<?= wb_h($file['direct_url']) ?>"
                            >
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
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
