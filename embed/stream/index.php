<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\FileShares;
use WbFileBrowser\Installer;
use WbFileBrowser\MaintenanceMode;
use WbFileBrowser\MaintenanceModeException;
use WbFileBrowser\Security;

if (!Installer::isInstalled()) {
    http_response_code(404);
    exit;
}

Security::sendApiHeaders();

try {
    WbFileBrowser\IpBanService::assertCurrentIpAllowed();
} catch (BlockedAccessException) {
    http_response_code(403);
    exit;
}

try {
    MaintenanceMode::assertAllowed(null, 'share');
} catch (MaintenanceModeException) {
    http_response_code(503);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
$embedStreamRateLimitBuckets = [
    [
        'scope' => 'embed-stream-token-ip',
        'identifier' => $token . '|' . Security::clientIp(),
        'limit' => 120,
        'window' => 5 * 60,
    ],
    [
        'scope' => 'embed-stream-ip',
        'identifier' => Security::clientIp(),
        'limit' => 480,
        'window' => 5 * 60,
    ],
];

try {
    Security::assertRateLimitAvailable(
        $embedStreamRateLimitBuckets,
        'Shared media unavailable right now.',
        null,
        ['source' => 'embed_stream']
    );
    Security::consumeRateLimit($embedStreamRateLimitBuckets);
} catch (BlockedAccessException) {
    http_response_code(403);
    exit;
}

try {
    FileShares::streamEmbed($token);
} catch (RuntimeException) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
