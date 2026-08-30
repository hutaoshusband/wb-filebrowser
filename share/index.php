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
$shareHeaders['Content-Security-Policy'] = "default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; connect-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
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
            FileShares::MSG_RATE_LIMIT_SHARE,
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
                    FileShares::MSG_RATE_LIMIT_SHARE,
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
                        FileShares::MSG_RATE_LIMIT_SHARE,
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
            scrollbar-color: #2e2e31 #141416;
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar,
        .share-text-preview pre::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-track,
        .share-text-preview pre::-webkit-scrollbar-track {
            background: #141416;
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-thumb,
        .share-text-preview pre::-webkit-scrollbar-thumb {
            border: 3px solid #141416;
            border-radius: 999px;
            background: #2e2e31;
        }
        .preview-frame:has(.share-text-preview)::-webkit-scrollbar-thumb:hover,
        .share-text-preview pre::-webkit-scrollbar-thumb:hover {
            background: #3a3a3e;
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
                        <p class="share-header__meta"><?= wb_h($file['mime_type']) ?></p>
                    </div>
                    <div class="share-actions">
                        <a class="header-button share-download" href="<?= wb_h($file['download_url']) ?>">Download</a>
                    </div>
                </header>

                <div class="share-view">
                    <div class="preview-frame share-view__frame">
                        <?php if ($previewMode === 'image'): ?>
                            <img class="preview-frame__image" src="<?= wb_h($file['preview_url']) ?>" alt="<?= wb_h($file['name']) ?>">
                        <?php elseif ($previewMode === 'pdf'): ?>
                            <iframe src="<?= wb_h($file['preview_url']) ?>" title="Shared PDF preview"></iframe>
                        <?php elseif ($previewMode === 'video'): ?>
                            <div class="media-player media-player--video">
                                <div class="media-player__stage">
                                    <video id="share-media" src="<?= wb_h($file['preview_url']) ?>" preload="metadata"></video>
                                </div>
                                <div class="media-player__bar">
                                    <div class="media-player__progress">
                                        <input class="media-player__seek" type="range" min="0" max="0" step="0.1" value="0" data-mp="seek" style="--mp-fill:0%" aria-label="Seek">
                                    </div>
                                    <div class="media-player__controls">
                                        <button class="media-player__btn media-player__btn--play" type="button" data-mp="play" aria-label="Play">
                                            <svg class="media-player__icon media-player__icon--play" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14l12-7z"/></svg>
                                            <svg class="media-player__icon media-player__icon--pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 5h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1H5.5c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Zm9.6 0h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1h-3.4c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Z"/></svg>
                                        </button>
                                        <span class="media-player__time" data-mp="current">0:00</span>
                                        <span class="media-player__spacer"></span>
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
                        <?php elseif ($previewMode === 'audio'): ?>
                            <div class="media-player media-player--audio">
                                <div class="media-player__stage">
                                    <div class="media-player__audio-art">
                                        <div class="media-player__art">
                                            <span class="media-player__eq" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></span>
                                        </div>
                                        <strong><?= wb_h($file['name']) ?></strong>
                                    </div>
                                    <audio id="share-media" src="<?= wb_h($file['preview_url']) ?>" preload="metadata"></audio>
                                </div>
                                <div class="media-player__bar">
                                    <div class="media-player__progress">
                                        <input class="media-player__seek" type="range" min="0" max="0" step="0.1" value="0" data-mp="seek" style="--mp-fill:0%" aria-label="Seek">
                                    </div>
                                    <div class="media-player__controls">
                                        <button class="media-player__btn media-player__btn--play" type="button" data-mp="play" aria-label="Play">
                                            <svg class="media-player__icon media-player__icon--play" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14l12-7z"/></svg>
                                            <svg class="media-player__icon media-player__icon--pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 5h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1H5.5c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Zm9.6 0h3.4c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1h-3.4c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1Z"/></svg>
                                        </button>
                                        <span class="media-player__time" data-mp="current">0:00</span>
                                        <span class="media-player__spacer"></span>
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
                                <p>This file can’t be previewed in your browser. Download it to open it locally.</p>
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
                            <label class="share-direct-link__label" for="share-direct-link">Direct link</label>
                            <div class="share-direct-link__row">
                                <input
                                    id="share-direct-link"
                                    class="share-direct-link__field"
                                    type="text"
                                    readonly
                                    value="<?= wb_h($file['direct_url']) ?>"
                                >
                                <button class="header-button share-direct-link__copy" type="button" data-copy-target="#share-direct-link">Copy</button>
                            </div>
                            <a class="header-button share-direct-link__open" href="<?= wb_h($file['direct_url']) ?>" target="_blank" rel="noopener noreferrer">Open direct link</a>
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
    (function() {
        document.querySelectorAll('[data-copy-target]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = document.querySelector(btn.getAttribute('data-copy-target'));
                if (!target) return;
                var done = function() { btn.textContent = 'Copied'; setTimeout(function() { btn.textContent = 'Copy'; }, 1500); };
                var fallback = function() {
                    target.focus();
                    target.select();
                    target.setSelectionRange(0, target.value.length);
                    try { document.execCommand('copy'); } catch (error) {}
                    done();
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    var fallbackTimer = setTimeout(fallback, 200);
                    navigator.clipboard.writeText(target.value).then(function() {
                        clearTimeout(fallbackTimer);
                        done();
                    }, function() {
                        clearTimeout(fallbackTimer);
                        fallback();
                    });
                } else {
                    fallback();
                }
            });
        });
    })();
    </script>
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

        // The media element may already be ready (or have finished loading its
        // metadata) before these listeners were wired up - e.g. when the page
        // stalls on the highlight.js script while preload="metadata" completes.
        // Sync once from current state so the timeline is never stuck at zero.
        syncPlay();
        syncMute();
        syncTimeline();
    })();
    </script>
</body>
</html>
