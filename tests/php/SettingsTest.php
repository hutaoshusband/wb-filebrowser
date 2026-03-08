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
            ],
        ]);

        $payload = Settings::adminPayload();

        $this->assertTrue($saved['access']['public_access']);
        $this->assertSame(128, $payload['settings']['uploads']['max_file_size_mb']);
        $this->assertSame('jpg, pdf', $payload['settings']['uploads']['allowed_extensions']);
        $this->assertSame(12, $payload['settings']['uploads']['stale_upload_ttl_hours']);
        $this->assertFalse($payload['settings']['automation']['runner_enabled']);
        $this->assertSame(15, $payload['settings']['automation']['diagnostic_interval_minutes']);
        $this->assertCount(3, $payload['automation']['jobs']);
        $this->assertSame(15, AutomationRunner::jobs()[0]['interval_minutes']);
    }
}
