<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Auth;
use WbFileBrowser\Database;
use WbFileBrowser\MaintenanceMode;
use WbFileBrowser\MaintenanceModeException;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class MaintenanceModeTest extends DatabaseTestCase
{
    public function testMaintenanceBlocksStandardUserLogin(): void
    {
        Database::updateSetting('maintenance_enabled', '1');
        Database::updateSetting('maintenance_scope', MaintenanceMode::SCOPE_APP_ONLY);
        $user = $this->createUser('member');

        $this->expectException(MaintenanceModeException::class);
        Auth::login((string) $user['username'], 'AnotherSecurePass123!');
    }

    public function testMaintenanceStillAllowsAdminLogin(): void
    {
        Database::updateSetting('maintenance_enabled', '1');
        Database::updateSetting('maintenance_scope', MaintenanceMode::SCOPE_APP_ONLY);
        $admin = $this->createUser('site-admin', 'admin');

        $loggedInAdmin = Auth::login((string) $admin['username'], 'AnotherSecurePass123!');

        $this->assertSame('admin', $loggedInAdmin['role']);
    }

    public function testMaintenancePayloadKeepsAdminSurfaceReachable(): void
    {
        Database::updateSetting('maintenance_enabled', '1');
        Database::updateSetting('maintenance_scope', MaintenanceMode::SCOPE_ALL_NON_ADMIN);

        $appPayload = MaintenanceMode::payload(null, 'app');
        $adminPayload = MaintenanceMode::payload(null, 'admin');

        $this->assertTrue($appPayload['blocks_current_user']);
        $this->assertFalse($adminPayload['blocks_current_user']);
    }
}
