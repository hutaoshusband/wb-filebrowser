<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$bootstrap = wb_bootstrap_page('app');
try {
    WbFileBrowser\IpBanService::assertCurrentIpAllowed();
} catch (WbFileBrowser\BlockedAccessException $exception) {
    wb_blocked_page($exception->payload());
}
try {
    WbFileBrowser\MaintenanceMode::assertAllowed($bootstrap['user'] ?? null, 'app');
} catch (WbFileBrowser\MaintenanceModeException $exception) {
    wb_maintenance_page($exception->payload());
}
WbFileBrowser\Security::sendPageHeaders();
?>
<!doctype html>
<html lang="en">
<head>
    <?= wb_page_head('wb-filebrowser') ?>
</head>
<body data-shell="app">
    <div id="app"></div>
    <?= wb_bootstrap_script_tag($bootstrap) ?>
    <script type="module" src="<?= wb_h(wb_url('/assets/app.js')) ?>"></script>
</body>
</html>
