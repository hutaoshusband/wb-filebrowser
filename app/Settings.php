<?php

declare(strict_types=1);

namespace WbFileBrowser;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Settings
{
    public static function seedDefaults(?PDO $pdo = null, array $overrides = [], bool $overrideExisting = false): void
    {
        $pdo ??= Database::connection();
        $settings = array_merge(self::defaultMap(), $overrides);
        $existing = $pdo->query('SELECT key FROM settings')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existingMap = array_flip(array_map('strval', $existing));
        $statement = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );

        foreach ($settings as $key => $value) {
            if (!$overrideExisting && isset($existingMap[$key])) {
                continue;
            }

            $statement->execute([
                ':key' => $key,
                ':value' => (string) $value,
                ':updated_at' => wb_now(),
            ]);
        }
    }

    public static function installOverrides(int $rootFolderId, string $probeRelativePath, array $payload = []): array
    {
        $normalized = self::normalizePayload($payload, self::defaultGrouped());

        return [
            'app_version' => Installer::VERSION,
            'root_folder_id' => (string) $rootFolderId,
            'probe_relative_path' => $probeRelativePath,
            'public_access' => $normalized['access']['public_access'] ? '1' : '0',
            'uploads_max_file_size_mb' => (string) $normalized['uploads']['max_file_size_mb'],
            'uploads_allowed_extensions' => self::implodeExtensions($normalized['uploads']['allowed_extensions']),
            'uploads_stale_upload_ttl_hours' => (string) $normalized['uploads']['stale_upload_ttl_hours'],
            'automation_runner_enabled' => $normalized['automation']['runner_enabled'] ? '1' : '0',
            'automation_diagnostic_interval_minutes' => (string) $normalized['automation']['diagnostic_interval_minutes'],
            'automation_cleanup_interval_minutes' => (string) $normalized['automation']['cleanup_interval_minutes'],
            'automation_storage_alert_threshold_pct' => (string) $normalized['automation']['storage_alert_threshold_pct'],
            'diagnostic_message' => 'Storage shield checks will start after setup.',
        ];
    }

    public static function grouped(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();

        return [
            'access' => [
                'public_access' => wb_parse_bool(Database::setting('public_access', '0')),
            ],
            'uploads' => [
                'max_file_size_mb' => self::parseUploadLimitMb(Database::setting('uploads_max_file_size_mb', '0')),
                'allowed_extensions' => implode(', ', self::allowedExtensions($pdo)),
                'stale_upload_ttl_hours' => self::parseInteger(Database::setting('uploads_stale_upload_ttl_hours', '24'), 'Upload retention window', 1, 720),
            ],
            'automation' => [
                'runner_enabled' => wb_parse_bool(Database::setting('automation_runner_enabled', '1')),
                'diagnostic_interval_minutes' => self::parseInteger(Database::setting('automation_diagnostic_interval_minutes', '30'), 'Storage shield interval', 5, 1440),
                'cleanup_interval_minutes' => self::parseInteger(Database::setting('automation_cleanup_interval_minutes', '60'), 'Cleanup interval', 5, 1440),
                'storage_alert_threshold_pct' => self::parseInteger(Database::setting('automation_storage_alert_threshold_pct', '85'), 'Storage alert threshold', 50, 99),
            ],
        ];
    }

    public static function adminPayload(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();

        return [
            'settings' => self::grouped($pdo),
            'diagnostics' => self::diagnosticState(),
            'upload_policy' => self::uploadPolicy($pdo),
            'automation' => [
                'jobs' => AutomationRunner::jobs($pdo),
                'runner_enabled' => wb_parse_bool(Database::setting('automation_runner_enabled', '1')),
            ],
        ];
    }

    public static function saveAdminSettings(array $payload, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $normalized = self::normalizePayload($payload, self::grouped($pdo));
        $statement = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );

        $updates = [
            'public_access' => $normalized['access']['public_access'] ? '1' : '0',
            'uploads_max_file_size_mb' => (string) $normalized['uploads']['max_file_size_mb'],
            'uploads_allowed_extensions' => self::implodeExtensions($normalized['uploads']['allowed_extensions']),
            'uploads_stale_upload_ttl_hours' => (string) $normalized['uploads']['stale_upload_ttl_hours'],
            'automation_runner_enabled' => $normalized['automation']['runner_enabled'] ? '1' : '0',
            'automation_diagnostic_interval_minutes' => (string) $normalized['automation']['diagnostic_interval_minutes'],
            'automation_cleanup_interval_minutes' => (string) $normalized['automation']['cleanup_interval_minutes'],
            'automation_storage_alert_threshold_pct' => (string) $normalized['automation']['storage_alert_threshold_pct'],
        ];

        foreach ($updates as $key => $value) {
            $statement->execute([
                ':key' => $key,
                ':value' => $value,
                ':updated_at' => wb_now(),
            ]);
        }

        AutomationRunner::syncJobs($pdo);

        return $normalized;
    }

    public static function uploadPolicy(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $maxFileSizeMb = self::parseUploadLimitMb(Database::setting('uploads_max_file_size_mb', '0'));
        $maxFileSizeBytes = $maxFileSizeMb === 0 ? null : $maxFileSizeMb * 1024 * 1024;
        $allowedExtensions = self::allowedExtensions($pdo);
        $staleUploadTtlHours = self::parseInteger(Database::setting('uploads_stale_upload_ttl_hours', '24'), 'Upload retention window', 1, 720);

        return [
            'max_file_size_mb' => $maxFileSizeMb,
            'max_file_size_bytes' => $maxFileSizeBytes,
            'max_file_size_label' => $maxFileSizeBytes === null ? 'No app limit' : wb_format_bytes($maxFileSizeBytes),
            'has_app_limit' => $maxFileSizeBytes !== null,
            'allowed_extensions' => $allowedExtensions,
            'allowed_extensions_label' => $allowedExtensions === []
                ? 'Any file type'
                : implode(', ', array_map(static fn (string $extension): string => '.' . $extension, $allowedExtensions)),
            'stale_upload_ttl_hours' => $staleUploadTtlHours,
        ];
    }

    public static function assertUploadAllowed(string $originalName, int $size, ?PDO $pdo = null): void
    {
        $policy = self::uploadPolicy($pdo);

        if ($policy['max_file_size_bytes'] !== null && $size > $policy['max_file_size_bytes']) {
            throw new RuntimeException(sprintf(
                'Uploads are limited to %s per file.',
                $policy['max_file_size_label']
            ));
        }

        $allowedExtensions = $policy['allowed_extensions'];

        if ($allowedExtensions === []) {
            return;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException(
                'This file type is not allowed here. Allowed types: ' . $policy['allowed_extensions_label'] . '.'
            );
        }
    }

    public static function diagnosticState(): array
    {
        return [
            'exposed' => wb_parse_bool(Database::setting('diagnostic_exposed', '0')),
            'checked_at' => Database::setting('diagnostic_checked_at', ''),
            'message' => Database::setting('diagnostic_message', ''),
            'probe_path' => Database::setting('probe_relative_path', ''),
            'probe_url' => wb_url('/storage/' . Database::setting('probe_relative_path', '')),
        ];
    }

    public static function allowedExtensions(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $stored = Database::setting('uploads_allowed_extensions', '');

        return self::normalizeExtensions($stored);
    }

    public static function normalizePayload(array $payload, ?array $base = null): array
    {
        $base ??= self::defaultGrouped();
        $accessInput = self::normalizeGroupInput($payload, 'access', ['public_access'], $base['access']);
        $uploadInput = self::normalizeGroupInput(
            $payload,
            'uploads',
            ['max_file_size_mb', 'allowed_extensions', 'stale_upload_ttl_hours'],
            $base['uploads']
        );
        $automationInput = self::normalizeGroupInput(
            $payload,
            'automation',
            ['runner_enabled', 'diagnostic_interval_minutes', 'cleanup_interval_minutes', 'storage_alert_threshold_pct'],
            $base['automation']
        );

        return [
            'access' => [
                'public_access' => wb_parse_bool($accessInput['public_access'] ?? $base['access']['public_access']),
            ],
            'uploads' => [
                'max_file_size_mb' => self::parseUploadLimitMb(
                    $uploadInput['max_file_size_mb'] ?? $base['uploads']['max_file_size_mb'],
                ),
                'allowed_extensions' => self::normalizeExtensions($uploadInput['allowed_extensions'] ?? $base['uploads']['allowed_extensions']),
                'stale_upload_ttl_hours' => self::parseInteger(
                    $uploadInput['stale_upload_ttl_hours'] ?? $base['uploads']['stale_upload_ttl_hours'],
                    'Upload retention window',
                    1,
                    720
                ),
            ],
            'automation' => [
                'runner_enabled' => wb_parse_bool($automationInput['runner_enabled'] ?? $base['automation']['runner_enabled']),
                'diagnostic_interval_minutes' => self::parseInteger(
                    $automationInput['diagnostic_interval_minutes'] ?? $base['automation']['diagnostic_interval_minutes'],
                    'Storage shield interval',
                    5,
                    1440
                ),
                'cleanup_interval_minutes' => self::parseInteger(
                    $automationInput['cleanup_interval_minutes'] ?? $base['automation']['cleanup_interval_minutes'],
                    'Cleanup interval',
                    5,
                    1440
                ),
                'storage_alert_threshold_pct' => self::parseInteger(
                    $automationInput['storage_alert_threshold_pct'] ?? $base['automation']['storage_alert_threshold_pct'],
                    'Storage alert threshold',
                    50,
                    99
                ),
            ],
        ];
    }

    public static function defaultGrouped(): array
    {
        return [
            'access' => [
                'public_access' => false,
            ],
            'uploads' => [
                'max_file_size_mb' => 0,
                'allowed_extensions' => '',
                'stale_upload_ttl_hours' => 24,
            ],
            'automation' => [
                'runner_enabled' => true,
                'diagnostic_interval_minutes' => 30,
                'cleanup_interval_minutes' => 60,
                'storage_alert_threshold_pct' => 85,
            ],
        ];
    }

    private static function defaultMap(): array
    {
        return [
            'app_version' => Installer::VERSION,
            'public_access' => '0',
            'diagnostic_exposed' => '0',
            'diagnostic_checked_at' => '',
            'diagnostic_message' => 'Storage shield checks will start after setup.',
            'probe_relative_path' => '',
            'root_folder_id' => '1',
            'board_name' => 'wb-filebrowser',
            'help_mode' => 'local',
            'uploads_max_file_size_mb' => '0',
            'uploads_allowed_extensions' => '',
            'uploads_stale_upload_ttl_hours' => '24',
            'automation_runner_enabled' => '1',
            'automation_diagnostic_interval_minutes' => '30',
            'automation_cleanup_interval_minutes' => '60',
            'automation_storage_alert_threshold_pct' => '85',
            'automation_lock_token' => '',
            'automation_lock_until' => '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private static function normalizeGroupInput(array $payload, string $group, array $topLevelKeys, array $fallback): array
    {
        $groupInput = is_array($payload[$group] ?? null) ? $payload[$group] : [];

        foreach ($topLevelKeys as $key) {
            if (array_key_exists($key, $payload) && !array_key_exists($key, $groupInput)) {
                $groupInput[$key] = $payload[$key];
            }
        }

        if ($groupInput === []) {
            return $fallback;
        }

        return array_merge($fallback, $groupInput);
    }

    /**
     * @param array<int, string>|string $value
     * @return array<int, string>
     */
    private static function normalizeExtensions(array|string $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\s,]+/', (string) $value) ?: []);
        $extensions = [];

        foreach ($items as $item) {
            $extension = strtolower(ltrim(trim((string) $item), '.'));

            if ($extension === '') {
                continue;
            }

            if (!preg_match('/^[a-z0-9][a-z0-9._+-]*$/', $extension)) {
                throw new InvalidArgumentException('Allowed extensions may only contain letters, numbers, dots, plus, underscore, and dash.');
            }

            $extensions[$extension] = true;
        }

        ksort($extensions);

        return array_keys($extensions);
    }

    /**
     * @param array<int, string> $extensions
     */
    private static function implodeExtensions(array $extensions): string
    {
        return implode(',', self::normalizeExtensions($extensions));
    }

    private static function parseInteger(mixed $value, string $label, int $min, int $max): int
    {
        if ($value === '' || $value === null) {
            throw new InvalidArgumentException($label . ' is required.');
        }

        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        if ($intValue === false) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }

        if ($intValue < $min || $intValue > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $label, $min, $max));
        }

        return (int) $intValue;
    }

    private static function parseUploadLimitMb(mixed $value): int
    {
        return self::parseInteger($value, 'Upload max file size', 0, self::maxUploadLimitMb());
    }

    private static function maxUploadLimitMb(): int
    {
        $bytesPerMegabyte = 1024 * 1024;
        $phpMax = intdiv(PHP_INT_MAX, $bytesPerMegabyte);
        $jsMaxSafeInteger = 9007199254740991;
        $jsMax = intdiv($jsMaxSafeInteger, $bytesPerMegabyte);

        return min($phpMax, $jsMax);
    }
}
