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

    #[\PHPUnit\Framework\Attributes\DataProvider('provideScopeBlocksSurfaceData')]
    public function testScopeBlocksSurface(string $scope, string $surface, bool $expected): void
    {
        $this->assertSame($expected, MaintenanceMode::scopeBlocksSurface($scope, $surface));
    }

    public static function provideScopeBlocksSurfaceData(): array
    {
        return [
            // SCOPE_APP_AND_SHARE
            [MaintenanceMode::SCOPE_APP_AND_SHARE, 'app', true],
            [MaintenanceMode::SCOPE_APP_AND_SHARE, 'share', true],
            [MaintenanceMode::SCOPE_APP_AND_SHARE, 'admin', false],
            [MaintenanceMode::SCOPE_APP_AND_SHARE, 'other', false],

            // SCOPE_ALL_NON_ADMIN
            [MaintenanceMode::SCOPE_ALL_NON_ADMIN, 'app', true],
            [MaintenanceMode::SCOPE_ALL_NON_ADMIN, 'share', true],
            [MaintenanceMode::SCOPE_ALL_NON_ADMIN, 'admin', false],
            [MaintenanceMode::SCOPE_ALL_NON_ADMIN, 'other', true],

            // SCOPE_APP_ONLY (default logic)
            [MaintenanceMode::SCOPE_APP_ONLY, 'app', true],
            [MaintenanceMode::SCOPE_APP_ONLY, 'share', false],
            [MaintenanceMode::SCOPE_APP_ONLY, 'admin', false],
            [MaintenanceMode::SCOPE_APP_ONLY, 'other', false],

            // Invalid scope (falls back to default logic)
            ['invalid_scope', 'app', true],
            ['invalid_scope', 'share', false],
            ['invalid_scope', 'admin', false],
            ['invalid_scope', 'other', false],
        ];
    }
}
