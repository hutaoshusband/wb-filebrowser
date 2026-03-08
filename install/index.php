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
