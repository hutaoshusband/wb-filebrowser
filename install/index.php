<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$bootstrap = wb_bootstrap_page('install');
$checks = WbFileBrowser\Installer::environmentChecks();
$hasBlockingIssues = array_reduce(
    $checks,
    static fn (bool $carry, array $check): bool => $carry || !$check['ok'],
    false
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install wb-filebrowser</title>
    <link rel="stylesheet" href="<?= wb_h(wb_url('/assets/app.css')) ?>">
</head>
<body class="install-shell">
    <main class="install-layout install-layout--split">
        <section class="install-hero">
            <div class="install-hero__panel">
                <p class="install-kicker">Guided setup</p>
                <h1>Get wb-filebrowser live in a couple of minutes.</h1>
                <p class="install-lead">The installer will create a protected storage area, your first Super-Admin account, and a set of sane defaults you can refine later from the admin panel.</p>
                <div class="install-pill-row">
                    <span class="install-pill">Private by default</span>
                    <span class="install-pill">No app upload cap</span>
                    <span class="install-pill">Health checks enabled</span>
                </div>
            </div>

            <div class="install-checklist">
                <?php foreach ($checks as $check): ?>
                    <div class="install-check <?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
                        <div>
                            <strong><?= wb_h($check['label']) ?></strong>
                            <span><?= wb_h($check['message']) ?></span>
                        </div>
                        <small><?= $check['ok'] ? 'Ready' : 'Needs attention' ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="install-story-grid">
                <article class="install-story">
                    <span class="install-story__step">1</span>
                    <div>
                        <strong>Create the owner account</strong>
                        <p>The first account is created as the immutable Super-Admin.</p>
                    </div>
                </article>
                <article class="install-story">
                    <span class="install-story__step">2</span>
                    <div>
                        <strong>Start with recommended defaults</strong>
                        <p>Browsing stays private, uploads are chunked, and cleanup jobs are switched on.</p>
                    </div>
                </article>
                <article class="install-story">
                    <span class="install-story__step">3</span>
                    <div>
                        <strong>Adjust details later</strong>
                        <p>Access rules, allowed file types, and automation intervals stay editable after setup.</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="install-card">
            <div class="install-header">
                <p class="install-kicker">Install wb-filebrowser</p>
                <h2>Secure the first workspace</h2>
                <p>Only three fields are required. Everything else already starts from recommended values.</p>
            </div>

            <form id="install-form" class="install-form">
                <input type="hidden" name="csrf_token" value="<?= wb_h($bootstrap['csrf_token']) ?>">

                <section class="install-section">
                    <div class="install-section__header">
                        <span class="install-section__number">1</span>
                        <div>
                            <h3>Owner account</h3>
                            <p>This login will have full access to users, permissions, uploads, and storage health.</p>
                        </div>
                    </div>
                    <div class="install-field-grid">
                        <label>
                            <span>Super-Admin username</span>
                            <input type="text" name="username" autocomplete="username" placeholder="admin" autofocus required>
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="password" autocomplete="new-password" minlength="12" placeholder="At least 12 characters" required>
                        </label>
                        <label>
                            <span>Confirm password</span>
                            <input type="password" name="password_confirm" autocomplete="new-password" minlength="12" placeholder="Re-enter the password" required>
                        </label>
                    </div>
                    <div class="install-password-row">
                        <span id="install-password-length" class="install-hint">Use at least 12 characters.</span>
                        <span id="install-password-match" class="install-hint">Passwords must match before install.</span>
                    </div>
                </section>

                <section class="install-section">
                    <div class="install-section__header">
                        <span class="install-section__number">2</span>
                        <div>
                            <h3>Default behavior</h3>
                            <p>These values are safe starting points and can be changed later from Settings.</p>
                        </div>
                    </div>
                    <div class="install-choice-grid">
                        <label class="install-choice checkbox-row">
                            <input type="checkbox" name="access[public_access]">
                            <span>
                                <strong>Allow published browsing without login</strong>
                                <small>Leave this off if the file browser should stay private until you publish folders on purpose.</small>
                            </span>
                        </label>
                        <label class="install-choice">
                            <span>App upload limit (MB)</span>
                            <input type="number" name="uploads[max_file_size_mb]" min="0" value="0" inputmode="numeric">
                            <small>Use 0 for no app-level cap. Uploads still stream to the server in 2 MiB chunks.</small>
                        </label>
                        <label class="install-choice install-choice--wide">
                            <span>Allowed extensions</span>
                            <input type="text" name="uploads[allowed_extensions]" placeholder="Leave empty to allow any file type">
                            <small>Examples: png, jpg, pdf. Empty means every extension is accepted.</small>
                        </label>
                    </div>
                </section>

                <details class="install-advanced">
                    <summary>Advanced controls</summary>
                    <p class="install-advanced__intro">Only change these now if you already know the behavior you want.</p>
                    <div class="install-advanced__grid">
                        <label>
                            <span>Abandoned upload retention (hours)</span>
                            <input type="number" name="uploads[stale_upload_ttl_hours]" min="1" value="24" inputmode="numeric">
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="automation[runner_enabled]" checked>
                            <span>
                                <strong>Enable the request-driven automation runner</strong>
                                <small>Recommended. This keeps storage shield checks and stale upload cleanup active.</small>
                            </span>
                        </label>
                        <label>
                            <span>Shield check interval (minutes)</span>
                            <input type="number" name="automation[diagnostic_interval_minutes]" min="5" value="30" inputmode="numeric">
                        </label>
                        <label>
                            <span>Cleanup interval (minutes)</span>
                            <input type="number" name="automation[cleanup_interval_minutes]" min="5" value="60" inputmode="numeric">
                        </label>
                        <label>
                            <span>Storage alert threshold (%)</span>
                            <input type="number" name="automation[storage_alert_threshold_pct]" min="50" max="99" value="85" inputmode="numeric">
                        </label>
                    </div>
                </details>

                <section class="install-summary" aria-live="polite">
                    <div class="install-summary__row">
                        <span>Access</span>
                        <strong id="summary-access">Private browsing until you publish folders.</strong>
                    </div>
                    <div class="install-summary__row">
                        <span>Uploads</span>
                        <strong id="summary-uploads">No app upload limit. Any file type is allowed.</strong>
                    </div>
                    <div class="install-summary__row">
                        <span>Automation</span>
                        <strong id="summary-automation">Shield checks every 30 minutes and cleanup every 60 minutes.</strong>
                    </div>
                </section>

                <?php if ($hasBlockingIssues): ?>
                    <p class="install-feedback is-error">Resolve the failed environment checks above before starting the installer.</p>
                <?php endif; ?>

                <button id="install-submit" type="submit" class="primary-button" <?= $hasBlockingIssues ? 'disabled' : '' ?>>Install wb-filebrowser</button>
                <p id="install-feedback" class="install-feedback" role="status"></p>
            </form>
        </section>
    </main>
    <script>
        window.WB_BOOTSTRAP = <?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const form = document.getElementById('install-form');
        const feedback = document.getElementById('install-feedback');
        const submitButton = document.getElementById('install-submit');
        const passwordLengthHint = document.getElementById('install-password-length');
        const passwordMatchHint = document.getElementById('install-password-match');
        const summaryAccess = document.getElementById('summary-access');
        const summaryUploads = document.getElementById('summary-uploads');
        const summaryAutomation = document.getElementById('summary-automation');
        const installBlocked = <?= $hasBlockingIssues ? 'true' : 'false' ?>;

        const setFeedback = (message, tone = '') => {
            feedback.textContent = message;
            feedback.classList.remove('is-error', 'is-success');
            if (tone) {
                feedback.classList.add(tone);
            }
        };

        const checkboxValue = (formData, name) => formData.get(name) === 'on';

        const numberValue = (formData, name, fallback) => {
            const value = Number(formData.get(name) || fallback);
            return Number.isFinite(value) ? value : fallback;
        };

        const summarizeExtensions = (value) => {
            const cleaned = String(value || '')
                .split(/[\s,]+/)
                .map((item) => item.trim().replace(/^\./, '').toLowerCase())
                .filter(Boolean);

            return cleaned.length > 0
                ? `Allowed types: .${cleaned.join(', .')}.`
                : 'Any file type is allowed.';
        };

        const syncInstallSummary = () => {
            const formData = new FormData(form);
            const password = String(formData.get('password') || '');
            const passwordConfirm = String(formData.get('password_confirm') || '');
            const publicAccess = checkboxValue(formData, 'access[public_access]');
            const runnerEnabled = checkboxValue(formData, 'automation[runner_enabled]');
            const uploadLimitMb = numberValue(formData, 'uploads[max_file_size_mb]', 0);
            const staleHours = numberValue(formData, 'uploads[stale_upload_ttl_hours]', 24);
            const diagnosticMinutes = numberValue(formData, 'automation[diagnostic_interval_minutes]', 30);
            const cleanupMinutes = numberValue(formData, 'automation[cleanup_interval_minutes]', 60);

            summaryAccess.textContent = publicAccess
                ? 'Published folders can be shared without login.'
                : 'Private browsing until you publish folders.';
            summaryUploads.textContent = uploadLimitMb > 0
                ? `App limit: ${uploadLimitMb.toLocaleString()} MB per file. ${summarizeExtensions(formData.get('uploads[allowed_extensions]'))}`
                : `No app upload limit. ${summarizeExtensions(formData.get('uploads[allowed_extensions]'))}`;
            summaryAutomation.textContent = runnerEnabled
                ? `Shield checks every ${diagnosticMinutes} minutes and stale uploads expire after ${staleHours} hours with cleanup every ${cleanupMinutes} minutes.`
                : 'Automation runner disabled. Cleanup and storage shield checks will wait until you re-enable it.';

            passwordLengthHint.textContent = password.length >= 12
                ? 'Password length looks good.'
                : 'Use at least 12 characters.';
            passwordLengthHint.classList.toggle('is-good', password.length >= 12);

            const passwordsMatch = password !== '' && password === passwordConfirm;
            passwordMatchHint.textContent = passwordsMatch
                ? 'Passwords match.'
                : 'Passwords must match before install.';
            passwordMatchHint.classList.toggle('is-good', passwordsMatch);
        };

        form?.addEventListener('input', syncInstallSummary);
        syncInstallSummary();

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            setFeedback('');

            if (installBlocked) {
                setFeedback('Resolve the failed environment checks above before starting the installer.', 'is-error');
                return;
            }

            const formData = new FormData(form);
            const password = String(formData.get('password') || '');
            const passwordConfirm = String(formData.get('password_confirm') || '');
            const numberValue = (name, fallback) => {
                const value = Number(formData.get(name) || fallback);
                return Number.isFinite(value) ? value : fallback;
            };

            if (password !== passwordConfirm) {
                setFeedback('Passwords do not match.', 'is-error');
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Installing...';

            try {
                const response = await fetch('<?= wb_h(wb_url('/api/index.php?action=install.run')) ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        csrf_token: String(formData.get('csrf_token') || ''),
                        username: String(formData.get('username') || ''),
                        password,
                        access: {
                            public_access: checkboxValue(formData, 'access[public_access]'),
                        },
                        uploads: {
                            max_file_size_mb: numberValue('uploads[max_file_size_mb]', 0),
                            allowed_extensions: String(formData.get('uploads[allowed_extensions]') || ''),
                            stale_upload_ttl_hours: numberValue('uploads[stale_upload_ttl_hours]', 24),
                        },
                        automation: {
                            runner_enabled: checkboxValue(formData, 'automation[runner_enabled]'),
                            diagnostic_interval_minutes: numberValue('automation[diagnostic_interval_minutes]', 30),
                            cleanup_interval_minutes: numberValue('automation[cleanup_interval_minutes]', 60),
                            storage_alert_threshold_pct: numberValue('automation[storage_alert_threshold_pct]', 85),
                        },
                    }),
                });
                let payload = null;

                try {
                    payload = await response.json();
                } catch (error) {
                    payload = null;
                }

                if (!response.ok || !payload?.ok) {
                    throw new Error(payload?.message || 'Installation failed.');
                }

                setFeedback('Installation complete. Redirecting...', 'is-success');
                window.location.href = payload.redirect;
            } catch (error) {
                submitButton.disabled = false;
                submitButton.textContent = 'Install wb-filebrowser';
                setFeedback(error instanceof Error ? error.message : 'Installation failed.', 'is-error');
            }
        });
    </script>
</body>
</html>
