<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$bootstrap = wb_bootstrap_page('install');
$checks = WbFileBrowser\Installer::environmentChecks();
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
    <main class="install-layout">
        <section class="install-card">
            <div class="install-header">
                <p class="install-kicker">Drop-in setup</p>
                <h1>Install wb-filebrowser</h1>
                <p>Create the first Super-Admin and let the app scaffold its local storage.</p>
            </div>
            <div class="install-checklist">
                <?php foreach ($checks as $check): ?>
                    <div class="install-check <?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
                        <strong><?= wb_h($check['label']) ?></strong>
                        <span><?= wb_h($check['message']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <form id="install-form" class="install-form">
                <input type="hidden" name="csrf_token" value="<?= wb_h($bootstrap['csrf_token']) ?>">
                <label>
                    <span>Super-Admin username</span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="new-password" minlength="12" required>
                </label>
                <label>
                    <span>Confirm password</span>
                    <input type="password" name="password_confirm" autocomplete="new-password" minlength="12" required>
                </label>
                <details class="install-advanced">
                    <summary>Advanced setup</summary>
                    <div class="install-advanced__grid">
                        <label class="checkbox-row">
                            <input type="checkbox" name="access[public_access]">
                            <span>Allow published folders to be browsed without login</span>
                        </label>
                        <label>
                            <span>Max upload size (MB)</span>
                            <input type="number" name="uploads[max_file_size_mb]" min="1" value="1024">
                        </label>
                        <label>
                            <span>Allowed extensions</span>
                            <input type="text" name="uploads[allowed_extensions]" placeholder="png, jpg, pdf">
                        </label>
                        <label>
                            <span>Abandoned upload retention (hours)</span>
                            <input type="number" name="uploads[stale_upload_ttl_hours]" min="1" value="24">
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="automation[runner_enabled]" checked>
                            <span>Enable the request-driven automation runner</span>
                        </label>
                        <label>
                            <span>Shield check interval (minutes)</span>
                            <input type="number" name="automation[diagnostic_interval_minutes]" min="5" value="30">
                        </label>
                        <label>
                            <span>Cleanup interval (minutes)</span>
                            <input type="number" name="automation[cleanup_interval_minutes]" min="5" value="60">
                        </label>
                        <label>
                            <span>Storage alert threshold (%)</span>
                            <input type="number" name="automation[storage_alert_threshold_pct]" min="50" max="99" value="85">
                        </label>
                    </div>
                </details>
                <button type="submit">Install wb-filebrowser</button>
                <p id="install-feedback" class="install-feedback" role="status"></p>
            </form>
        </section>
    </main>
    <script>
        window.WB_BOOTSTRAP = <?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const form = document.getElementById('install-form');
        const feedback = document.getElementById('install-feedback');

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            feedback.textContent = '';
            const formData = new FormData(form);
            const password = String(formData.get('password') || '');
            const passwordConfirm = String(formData.get('password_confirm') || '');
            const checkboxValue = (name) => formData.get(name) === 'on';
            const numberValue = (name, fallback) => {
                const value = Number(formData.get(name) || fallback);
                return Number.isFinite(value) ? value : fallback;
            };

            if (password !== passwordConfirm) {
                feedback.textContent = 'Passwords do not match.';
                return;
            }

            try {
                const response = await fetch('<?= wb_h(wb_url('/api/index.php?action=install.run')) ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        csrf_token: String(formData.get('csrf_token') || ''),
                        username: String(formData.get('username') || ''),
                        password,
                        access: {
                            public_access: checkboxValue('access[public_access]'),
                        },
                        uploads: {
                            max_file_size_mb: numberValue('uploads[max_file_size_mb]', 1024),
                            allowed_extensions: String(formData.get('uploads[allowed_extensions]') || ''),
                            stale_upload_ttl_hours: numberValue('uploads[stale_upload_ttl_hours]', 24),
                        },
                        automation: {
                            runner_enabled: checkboxValue('automation[runner_enabled]'),
                            diagnostic_interval_minutes: numberValue('automation[diagnostic_interval_minutes]', 30),
                            cleanup_interval_minutes: numberValue('automation[cleanup_interval_minutes]', 60),
                            storage_alert_threshold_pct: numberValue('automation[storage_alert_threshold_pct]', 85),
                        },
                    }),
                });
                const payload = await response.json();

                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Installation failed.');
                }

                feedback.textContent = 'Installation complete. Redirecting...';
                window.location.href = payload.redirect;
            } catch (error) {
                feedback.textContent = error instanceof Error ? error.message : 'Installation failed.';
            }
        });
    </script>
</body>
</html>
