<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\AutomationRunner;
use WbFileBrowser\Database;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class AutomationRunnerTest extends DatabaseTestCase
{
    public function testTickRunsOnlyDueJobsAndRemovesStaleUploadWorkspaces(): void
    {
        $staleWorkspace = $this->createStaleChunkWorkspace();
        $future = gmdate('c', time() + 3600);
        $past = gmdate('c', time() - 60);

        $this->setJobNextRun('storage_shield_check', $future);
        $this->setJobNextRun('storage_usage_alert', $future);
        $this->setJobNextRun('cleanup_abandoned_uploads', $past);

        $payload = AutomationRunner::tick('http://localhost');
        $jobs = [];

        foreach ($payload['jobs'] as $job) {
            $jobs[$job['job_key']] = $job;
        }

        $this->assertFalse($payload['locked']);
        $this->assertFileDoesNotExist($staleWorkspace);
        $this->assertSame('success', $jobs['cleanup_abandoned_uploads']['last_result']);
        $this->assertSame('Not yet', $jobs['storage_shield_check']['last_run_at'] ?: 'Not yet');
    }

    public function testTickReportsLockedWhenAnotherRunnerOwnsTheLock(): void
    {
        Database::updateSetting('automation_lock_until', gmdate('c', time() + 60));
        $payload = AutomationRunner::tick('http://localhost');

        $this->assertTrue($payload['locked']);
    }

    public function testStorageShieldEvaluationUpdatesDiagnosticState(): void
    {
        $result = AutomationRunner::evaluateStorageShield('http://localhost', static fn (): array => [
            'ok' => true,
            'status_code' => 200,
        ]);

        $diagnostic = Settings::diagnosticState();

        $this->assertSame('warning', $result['state']);
        $this->assertTrue($diagnostic['exposed']);
        $this->assertStringContainsString('/storage/', $diagnostic['message']);
    }
}
