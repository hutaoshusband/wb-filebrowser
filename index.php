<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$bootstrap = wb_bootstrap_page('app');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>wb-filebrowser</title>
    <link rel="stylesheet" href="<?= wb_h(wb_url('/assets/app.css')) ?>">
</head>
<body data-shell="app">
    <div id="app"></div>
    <script>
        window.WB_BOOTSTRAP = <?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script type="module" src="<?= wb_h(wb_url('/assets/app.js')) ?>"></script>
</body>
</html>
