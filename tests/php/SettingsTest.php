<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\AutomationRunner;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class SettingsTest extends DatabaseTestCase
{
    public function testGroupedSettingsRoundTripAndExposeAutomationJobs(): void
    {
        $saved = Settings::saveAdminSettings([
            'access' => [
                'public_access' => true,
                'maintenance_enabled' => true,
                'maintenance_scope' => 'app_and_share',
                'maintenance_message' => "Updates are running.\nPlease come back soon.",
            ],
            'uploads' => [
                'max_file_size_mb' => 128,
                'allowed_extensions' => 'jpg, pdf',
                'stale_upload_ttl_hours' => 12,
            ],
            'automation' => [
                'runner_enabled' => false,
                'diagnostic_interval_minutes' => 15,
                'cleanup_interval_minutes' => 45,
                'storage_alert_threshold_pct' => 90,
                'folder_size_interval_minutes' => 1440,
            ],
            'security' => [
                'audit_enabled' => true,
                'audit_retention_days' => 14,
                'log_auth_success' => true,
                'log_auth_failure' => true,
                'log_file_views' => true,
                'log_file_downloads' => false,
                'log_file_uploads' => true,
                'log_file_management' => true,
                'log_deletions' => true,
                'log_admin_actions' => true,
                'log_security_actions' => true,
            ],
            'display' => [
                'grid_thumbnails_enabled' => false,
            ],
        ]);

        $payload = Settings::adminPayload();

        $this->assertTrue($saved['access']['public_access']);
        $this->assertTrue($saved['access']['maintenance_enabled']);
        $this->assertSame('app_and_share', $payload['settings']['access']['maintenance_scope']);
        $this->assertSame("Updates are running.\nPlease come back soon.", $payload['settings']['access']['maintenance_message']);
        $this->assertSame(128, $payload['settings']['uploads']['max_file_size_mb']);
        $this->assertSame('jpg, pdf', $payload['settings']['uploads']['allowed_extensions']);
        $this->assertSame(12, $payload['settings']['uploads']['stale_upload_ttl_hours']);
        $this->assertFalse($payload['settings']['automation']['runner_enabled']);
        $this->assertSame(15, $payload['settings']['automation']['diagnostic_interval_minutes']);
        $this->assertSame(1440, $payload['settings']['automation']['folder_size_interval_minutes']);
        $this->assertTrue($payload['settings']['security']['audit_enabled']);
        $this->assertSame(14, $payload['settings']['security']['audit_retention_days']);
        $this->assertFalse($payload['settings']['security']['log_file_downloads']);
        $this->assertFalse($payload['settings']['display']['grid_thumbnails_enabled']);
        $this->assertCount(4, $payload['automation']['jobs']);
        $this->assertSame(15, AutomationRunner::jobs()[0]['interval_minutes']);
    }

    public function testUploadPolicyReportsWhenTheAppLevelLimitIsDisabled(): void
    {
        Settings::saveAdminSettings([
            'uploads' => [
                'max_file_size_mb' => 0,
            ],
        ]);

        $policy = Settings::uploadPolicy();

        $this->assertSame(0, $policy['max_file_size_mb']);
        $this->assertNull($policy['max_file_size_bytes']);
        $this->assertSame('No app limit', $policy['max_file_size_label']);
        $this->assertFalse($policy['has_app_limit']);
    }
}
