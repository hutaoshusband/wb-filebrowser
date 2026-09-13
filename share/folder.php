<?php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\{Auth, FileManager, FolderShares, IpBanService, MaintenanceMode, Security};

Security::sendPageHeaders();
header('X-Robots-Tag: noindex, nofollow, noarchive');
$token = (string) ($_GET['token'] ?? '');
$context = null;
$error = '';
try {
    IpBanService::assertCurrentIpAllowed();
    MaintenanceMode::assertAllowed(Auth::currentUser(), 'share');
    if (wb_request_method() === 'POST') Security::assertCsrfToken($_POST['csrf_token'] ?? null);
    $context = FolderShares::context($token, isset($_POST['password']) ? (string) $_POST['password'] : null, !empty($_POST['accept_terms']));

} catch (Throwable $e) {
    http_response_code(403);
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Shared folder unavailable.';
}
$ready = $context && $context['unlocked'] && $context['terms_accepted'];
$bootstrap = ['surface' => 'app', 'base_path' => WB_BASE_PATH, 'csrf_token' => Security::csrfToken(), 'folder_share_token' => $token];
?>
<!doctype html>
<html lang="en"><head><?= wb_page_head('Shared folder | wb-filebrowser') ?></head>
<body data-shell="app">
<?php if ($ready): ?>
    <div id="app"></div>
    <?= wb_bootstrap_script_tag($bootstrap) ?>
    <script type="module" src="<?= wb_h(wb_asset_url('/assets/app.js')) ?>"></script>
<?php else: ?>
    <div class="install-shell"><main class="install-layout"><section class="install-card">
        <div class="install-header"><p class="install-kicker">Shared folder</p><h1><?= wb_h($context['row']['name'] ?? 'Folder link') ?></h1></div>
        <p role="alert"><?= wb_h($error) ?></p>
        <form method="post" class="install-form">
            <input type="hidden" name="csrf_token" value="<?= wb_h(Security::csrfToken()) ?>">
            <?php if ($context && !$context['unlocked']): ?><label>Share password<input type="password" name="password" autocomplete="current-password" required></label><?php endif ?>
            <?php if ($context && !$context['terms_accepted']): ?><p><?= wb_h($context['terms_message']) ?></p><label><input type="checkbox" name="accept_terms" value="1" required> I accept these terms</label><?php endif ?>
            <?php if ($context): ?><button class="header-button primary-button" type="submit">Open folder</button><?php else: ?><a class="header-button" href="<?= wb_h(wb_url('/share/folder.php?token=' . rawurlencode($token))) ?>">Try again</a><?php endif ?>
        </form>
    </section></main></div>
<?php endif ?>
</body></html>
