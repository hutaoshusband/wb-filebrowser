<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;
use Throwable;

final class AutomationRunner
{
    /**
     * @return array<string, array{label: string, interval_key: string}>
     */
    private static function definitions(): array
    {
        return [
            'storage_shield_check' => [
                'label' => 'Storage shield check',
                'interval_key' => 'automation_diagnostic_interval_minutes',
            ],
            'cleanup_abandoned_uploads' => [
                'label' => 'Abandoned upload cleanup',
                'interval_key' => 'automation_cleanup_interval_minutes',
            ],
            'storage_usage_alert' => [
                'label' => 'Storage usage alert',
                'interval_key' => 'automation_diagnostic_interval_minutes',
            ],
        ];
    }

    public static function seedJobs(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO automation_jobs (
                job_key, label, status, last_result, last_message, last_run_at, next_run_at, last_duration_ms, created_at, updated_at
             ) VALUES (
                :job_key, :label, :status, :last_result, :last_message, :last_run_at, :next_run_at, :last_duration_ms, :created_at, :updated_at
             )
             ON CONFLICT(job_key) DO UPDATE SET label = excluded.label, updated_at = excluded.updated_at'
        );

        foreach (self::definitions() as $jobKey => $definition) {
            $statement->execute([
                ':job_key' => $jobKey,
                ':label' => $definition['label'],
                ':status' => 'idle',
                ':last_result' => 'idle',
                ':last_message' => 'Waiting for the first run.',
                ':last_run_at' => null,
                ':next_run_at' => wb_now(),
                ':last_duration_ms' => 0,
                ':created_at' => wb_now(),
                ':updated_at' => wb_now(),
            ]);
        }
    }

    public static function syncJobs(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        self::seedJobs($pdo);
        $jobs = self::rawJobs($pdo);
        $statement = $pdo->prepare(
            'UPDATE automation_jobs
             SET label = :label, next_run_at = :next_run_at, updated_at = :updated_at
             WHERE job_key = :job_key'
        );

        foreach (self::definitions() as $jobKey => $definition) {
            $job = $jobs[$jobKey] ?? null;
            $existingNextRun = is_array($job) ? (string) ($job['next_run_at'] ?? '') : '';
            $nextRunAt = $existingNextRun !== ''
                ? $existingNextRun
                : self::nextRunAt($jobKey, is_array($job) ? (string) ($job['last_run_at'] ?? '') : '');

            $statement->execute([
                ':job_key' => $jobKey,
                ':label' => $definition['label'],
                ':next_run_at' => $nextRunAt,
                ':updated_at' => wb_now(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function jobs(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::syncJobs($pdo);
        $runnerEnabled = wb_parse_bool(Database::setting('automation_runner_enabled', '1'));
        $rows = array_values(self::rawJobs($pdo));

        usort($rows, static function (array $left, array $right): int {
            return array_search($left['job_key'], array_keys(self::definitions()), true)
                <=> array_search($right['job_key'], array_keys(self::definitions()), true);
        });

        return array_map(static function (array $row) use ($runnerEnabled): array {
            $nextRunAt = (string) ($row['next_run_at'] ?? '');
            $due = $nextRunAt === '' || strtotime($nextRunAt) <= time();
            $row['interval_minutes'] = self::intervalMinutes((string) $row['job_key']);
            $row['is_due'] = $due;
            $row['is_paused'] = !$runnerEnabled;
            $row['last_duration_ms'] = (int) ($row['last_duration_ms'] ?? 0);

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public static function tick(?string $origin = null, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::syncJobs($pdo);

        if (!wb_parse_bool(Database::setting('automation_runner_enabled', '1'))) {
            return self::payload($pdo, false);
        }

        $token = self::acquireLock($pdo);

        if ($token === null) {
            return self::payload($pdo, true);
        }

        try {
            foreach (self::jobsDueNow($pdo) as $jobKey) {
                self::runJobInternal($jobKey, $origin, $pdo);
            }
        } finally {
            self::releaseLock($pdo, $token);
        }

        return self::payload($pdo, false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(string $jobKey, ?string $origin = null, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::syncJobs($pdo);

        if (!isset(self::definitions()[$jobKey])) {
            throw new RuntimeException('Unknown automation job.');
        }

        $token = self::acquireLock($pdo);

        if ($token === null) {
            throw new RuntimeException('Automation runner is busy. Try again in a moment.');
        }

        try {
            $job = self::runJobInternal($jobKey, $origin, $pdo);
        } finally {
            self::releaseLock($pdo, $token);
        }

        return [
            'job' => $job,
            ...self::payload($pdo, false),
        ];
    }

    /**
     * @return array{state: string, message: string}
     */
    public static function evaluateStorageShield(?string $origin = null, ?callable $fetcher = null): array
    {
        $probeRelativePath = (string) Database::setting('probe_relative_path', '');

        if ($probeRelativePath === '') {
            return [
                'state' => 'error',
                'message' => 'No storage probe is configured yet.',
            ];
        }

        $probeUrl = wb_absolute_url('/storage/' . $probeRelativePath, $origin);

        if ($probeUrl === null) {
            return [
                'state' => 'error',
                'message' => 'The current host could not be resolved for the storage probe.',
            ];
        }

        $response = ($fetcher ?? static fn (string $url): array => self::fetchUrl($url))($probeUrl . '?check=' . rawurlencode(wb_now()));
        $statusCode = (int) ($response['status_code'] ?? 0);
        $exposed = (bool) ($response['ok'] ?? false);

        if ($exposed) {
            Installer::writeStorageShield();
        }

        $message = $exposed
            ? 'Your server served a file directly from /storage/. We automatically regenerated .htaccess and web.config to shield it. Refresh to test, or manually configure your web server.'
            : ($statusCode > 0
                ? 'The latest probe could not be fetched directly. Storage appears shielded from public access.'
                : 'The storage probe could not be reached from the current host, so storage is treated as shielded.');

        Database::updateSetting('diagnostic_exposed', $exposed ? '1' : '0');
        Database::updateSetting('diagnostic_checked_at', wb_now());
        Database::updateSetting('diagnostic_message', $message);

        return [
            'state' => $exposed ? 'warning' : 'success',
            'message' => $message,
        ];
    }

    private static function payload(PDO $pdo, bool $locked): array
    {
        return [
            'jobs' => self::jobs($pdo),
            'locked' => $locked,
            'diagnostic' => Settings::diagnosticState(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function rawJobs(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT * FROM automation_jobs');
        $rows = $statement->fetchAll();

        return array_reduce($rows, static function (array $carry, array $row): array {
            $carry[(string) $row['job_key']] = $row;

            return $carry;
        }, []);
    }

    /**
     * @return array<int, string>
     */
    private static function jobsDueNow(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT job_key FROM automation_jobs
             WHERE next_run_at IS NULL OR next_run_at = "" OR next_run_at <= :now
             ORDER BY next_run_at ASC, job_key ASC'
        );
        $statement->execute([':now' => wb_now()]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function runJobInternal(string $jobKey, ?string $origin, PDO $pdo): array
    {
        $start = microtime(true);
        self::updateJobState($pdo, $jobKey, [
            'status' => 'running',
            'updated_at' => wb_now(),
        ]);

        try {
            $result = match ($jobKey) {
                'storage_shield_check' => self::evaluateStorageShield($origin),
                'cleanup_abandoned_uploads' => self::cleanupAbandonedUploads($pdo),
                'storage_usage_alert' => self::checkStorageUsage($pdo),
                default => throw new RuntimeException('Unknown automation job.'),
            };
            $duration = (int) round((microtime(true) - $start) * 1000);
            $payload = [
                'status' => $result['state'],
                'last_result' => $result['state'],
                'last_message' => $result['message'],
                'last_run_at' => wb_now(),
                'next_run_at' => self::nextRunAt($jobKey, wb_now()),
                'last_duration_ms' => $duration,
                'updated_at' => wb_now(),
            ];
            self::updateJobState($pdo, $jobKey, $payload);
        } catch (Throwable $exception) {
            self::updateJobState($pdo, $jobKey, [
                'status' => 'error',
                'last_result' => 'error',
                'last_message' => $exception->getMessage(),
                'last_run_at' => wb_now(),
                'next_run_at' => self::nextRunAt($jobKey, wb_now()),
                'last_duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'updated_at' => wb_now(),
            ]);

            throw $exception;
        }

        $statement = $pdo->prepare('SELECT * FROM automation_jobs WHERE job_key = :job_key LIMIT 1');
        $statement->execute([':job_key' => $jobKey]);
        $job = $statement->fetch();

        return is_array($job) ? $job : ['job_key' => $jobKey];
    }

    /**
     * @return array{state: string, message: string}
     */
    private static function cleanupAbandonedUploads(PDO $pdo): array
    {
        $policy = Settings::uploadPolicy($pdo);
        $removed = FileManager::cleanupStaleUploads((int) $policy['stale_upload_ttl_hours']);

        return [
            'state' => 'success',
            'message' => $removed === 1
                ? 'Removed 1 abandoned upload workspace.'
                : sprintf('Removed %d abandoned upload workspaces.', $removed),
        ];
    }

    /**
     * @return array{state: string, message: string}
     */
    private static function checkStorageUsage(PDO $pdo): array
    {
        $threshold = (int) Database::setting('automation_storage_alert_threshold_pct', '85');
        $stats = FileManager::storageStats();
        $totalBytes = $stats['total_bytes'];

        if (!is_int($totalBytes) || $totalBytes <= 0) {
            return [
                'state' => 'warning',
                'message' => 'Total storage capacity could not be detected, so capacity alerts are unavailable.',
            ];
        }

        $usedBytes = (int) ($stats['used_bytes'] ?? 0);
        $usage = ($usedBytes / $totalBytes) * 100;

        if ($usage >= $threshold) {
            return [
                'state' => 'warning',
                'message' => sprintf(
                    'Storage usage is at %.1f%% of capacity. The alert threshold is %d%%.',
                    $usage,
                    $threshold
                ),
            ];
        }

        return [
            'state' => 'success',
            'message' => sprintf(
                'Storage usage is %.1f%% of capacity. Alerts begin at %d%%.',
                $usage,
                $threshold
            ),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function updateJobState(PDO $pdo, string $jobKey, array $values): void
    {
        $assignments = [];
        $parameters = [':job_key' => $jobKey];

        foreach ($values as $key => $value) {
            $assignments[] = $key . ' = :' . $key;
            $parameters[':' . $key] = $value;
        }

        $statement = $pdo->prepare(
            'UPDATE automation_jobs SET ' . implode(', ', $assignments) . ' WHERE job_key = :job_key'
        );
        $statement->execute($parameters);
    }

    private static function nextRunAt(string $jobKey, string $lastRunAt): string
    {
        $timestamp = strtotime($lastRunAt);
        $base = $timestamp === false ? time() : $timestamp;

        return gmdate('c', $base + (self::intervalMinutes($jobKey) * 60));
    }

    private static function intervalMinutes(string $jobKey): int
    {
        $definition = self::definitions()[$jobKey] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown automation job.');
        }

        return max(5, (int) Database::setting($definition['interval_key'], '30'));
    }

    private static function acquireLock(PDO $pdo): ?string
    {
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
            $statement->execute([':key' => 'automation_lock_until']);
            $lockUntil = (string) ($statement->fetchColumn() ?: '');
            $lockTimestamp = strtotime($lockUntil);

            if ($lockTimestamp !== false && $lockTimestamp > time()) {
                $pdo->commit();

                return null;
            }

            $token = wb_random_token(16);
            $upsert = $pdo->prepare(
                'INSERT INTO settings (key, value, updated_at)
                 VALUES (:key, :value, :updated_at)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );

            foreach ([
                'automation_lock_token' => $token,
                'automation_lock_until' => gmdate('c', time() + 30),
            ] as $key => $value) {
                $upsert->execute([
                    ':key' => $key,
                    ':value' => $value,
                    ':updated_at' => wb_now(),
                ]);
            }

            $pdo->commit();

            return $token;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private static function releaseLock(PDO $pdo, string $token): void
    {
        $statement = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $statement->execute([':key' => 'automation_lock_token']);
        $currentToken = (string) ($statement->fetchColumn() ?: '');

        if (!hash_equals($currentToken, $token)) {
            return;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );

        foreach ([
            'automation_lock_token' => '',
            'automation_lock_until' => '',
        ] as $key => $value) {
            $upsert->execute([
                ':key' => $key,
                ':value' => $value,
                ':updated_at' => wb_now(),
            ]);
        }
    }

    /**
     * @return array{ok: bool, status_code: int}
     */
    private static function fetchUrl(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 3,
                'header' => "Cache-Control: no-cache\r\n",
            ],
        ]);

        @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = is_array($headers) ? (string) ($headers[0] ?? '') : '';
        $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int) $matches[1] : 0;

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
        ];
    }
}
