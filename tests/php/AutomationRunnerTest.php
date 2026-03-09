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

    public function testRefreshFolderSizesJobCachesNestedFolderSizes(): void
    {
        $parent = $this->createFolder('Projects');
        $child = $this->createFolder('Specs', (int) $parent['id']);
        $this->createFile('plan.txt', '1234', 'text/plain', (int) $parent['id']);
        $this->createFile('notes.txt', '123456', 'text/plain', (int) $child['id']);

        $result = AutomationRunner::run('refresh_folder_sizes');
        $rows = Database::connection()->query(
            'SELECT id, cached_size_bytes, cached_size_calculated_at FROM folders'
        )->fetchAll();
        $rowsById = [];

        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $this->assertSame('success', $result['job']['last_result']);
        $this->assertSame(10, (int) $rowsById[(int) $parent['id']]['cached_size_bytes']);
        $this->assertSame(6, (int) $rowsById[(int) $child['id']]['cached_size_bytes']);
        $this->assertSame(10, (int) $rowsById[Database::rootFolderId()]['cached_size_bytes']);
        $this->assertNotSame('', (string) $rowsById[(int) $parent['id']]['cached_size_calculated_at']);
    }
}
