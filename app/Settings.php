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
            'maintenance_enabled' => $normalized['access']['maintenance_enabled'] ? '1' : '0',
            'maintenance_scope' => $normalized['access']['maintenance_scope'],
            'maintenance_message' => $normalized['access']['maintenance_message'],
            'uploads_max_file_size_mb' => (string) $normalized['uploads']['max_file_size_mb'],
            'uploads_allowed_extensions' => self::implodeExtensions($normalized['uploads']['allowed_extensions']),
            'uploads_stale_upload_ttl_hours' => (string) $normalized['uploads']['stale_upload_ttl_hours'],
            'automation_runner_enabled' => $normalized['automation']['runner_enabled'] ? '1' : '0',
            'automation_diagnostic_interval_minutes' => (string) $normalized['automation']['diagnostic_interval_minutes'],
            'automation_cleanup_interval_minutes' => (string) $normalized['automation']['cleanup_interval_minutes'],
            'automation_storage_alert_threshold_pct' => (string) $normalized['automation']['storage_alert_threshold_pct'],
            'automation_folder_size_interval_minutes' => (string) $normalized['automation']['folder_size_interval_minutes'],
            'audit_enabled' => $normalized['security']['audit_enabled'] ? '1' : '0',
            'audit_retention_days' => (string) $normalized['security']['audit_retention_days'],
            'log_auth_success' => $normalized['security']['log_auth_success'] ? '1' : '0',
            'log_auth_failure' => $normalized['security']['log_auth_failure'] ? '1' : '0',
            'log_file_views' => $normalized['security']['log_file_views'] ? '1' : '0',
            'log_file_downloads' => $normalized['security']['log_file_downloads'] ? '1' : '0',
            'log_file_uploads' => $normalized['security']['log_file_uploads'] ? '1' : '0',
            'log_file_management' => $normalized['security']['log_file_management'] ? '1' : '0',
            'log_deletions' => $normalized['security']['log_deletions'] ? '1' : '0',
            'log_admin_actions' => $normalized['security']['log_admin_actions'] ? '1' : '0',
            'log_security_actions' => $normalized['security']['log_security_actions'] ? '1' : '0',
            'display_grid_thumbnails_enabled' => $normalized['display']['grid_thumbnails_enabled'] ? '1' : '0',
            'diagnostic_message' => 'Storage shield checks will start after setup.',
        ];
    }

    public static function grouped(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();

        return [
            'access' => [
                'public_access' => wb_parse_bool(Database::setting('public_access', '0')),
                'maintenance_enabled' => wb_parse_bool(Database::setting('maintenance_enabled', '0')),
                'maintenance_scope' => self::parseMaintenanceScope(Database::setting('maintenance_scope', MaintenanceMode::SCOPE_APP_ONLY)),
                'maintenance_message' => self::parseText(
                    Database::setting('maintenance_message', MaintenanceMode::defaultMessage()),
                    'Maintenance message',
                    2000,
                    false,
                    MaintenanceMode::defaultMessage()
                ),
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
                'folder_size_interval_minutes' => self::parseInteger(
                    Database::setting('automation_folder_size_interval_minutes', '1440'),
                    'Folder size refresh interval',
                    60,
                    10080
                ),
            ],
            'security' => [
                'audit_enabled' => wb_parse_bool(Database::setting('audit_enabled', '0')),
                'audit_retention_days' => self::parseInteger(Database::setting('audit_retention_days', '30'), 'Audit log retention', 1, 3650),
                'log_auth_success' => wb_parse_bool(Database::setting('log_auth_success', '1')),
                'log_auth_failure' => wb_parse_bool(Database::setting('log_auth_failure', '1')),
                'log_file_views' => wb_parse_bool(Database::setting('log_file_views', '1')),
                'log_file_downloads' => wb_parse_bool(Database::setting('log_file_downloads', '1')),
                'log_file_uploads' => wb_parse_bool(Database::setting('log_file_uploads', '1')),
                'log_file_management' => wb_parse_bool(Database::setting('log_file_management', '1')),
                'log_deletions' => wb_parse_bool(Database::setting('log_deletions', '1')),
                'log_admin_actions' => wb_parse_bool(Database::setting('log_admin_actions', '1')),
                'log_security_actions' => wb_parse_bool(Database::setting('log_security_actions', '1')),
            ],
            'display' => [
                'grid_thumbnails_enabled' => wb_parse_bool(Database::setting('display_grid_thumbnails_enabled', '1')),
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
            'maintenance_enabled' => $normalized['access']['maintenance_enabled'] ? '1' : '0',
            'maintenance_scope' => $normalized['access']['maintenance_scope'],
            'maintenance_message' => $normalized['access']['maintenance_message'],
            'uploads_max_file_size_mb' => (string) $normalized['uploads']['max_file_size_mb'],
            'uploads_allowed_extensions' => self::implodeExtensions($normalized['uploads']['allowed_extensions']),
            'uploads_stale_upload_ttl_hours' => (string) $normalized['uploads']['stale_upload_ttl_hours'],
            'automation_runner_enabled' => $normalized['automation']['runner_enabled'] ? '1' : '0',
            'automation_diagnostic_interval_minutes' => (string) $normalized['automation']['diagnostic_interval_minutes'],
            'automation_cleanup_interval_minutes' => (string) $normalized['automation']['cleanup_interval_minutes'],
            'automation_storage_alert_threshold_pct' => (string) $normalized['automation']['storage_alert_threshold_pct'],
            'automation_folder_size_interval_minutes' => (string) $normalized['automation']['folder_size_interval_minutes'],
            'audit_enabled' => $normalized['security']['audit_enabled'] ? '1' : '0',
            'audit_retention_days' => (string) $normalized['security']['audit_retention_days'],
            'log_auth_success' => $normalized['security']['log_auth_success'] ? '1' : '0',
            'log_auth_failure' => $normalized['security']['log_auth_failure'] ? '1' : '0',
            'log_file_views' => $normalized['security']['log_file_views'] ? '1' : '0',
            'log_file_downloads' => $normalized['security']['log_file_downloads'] ? '1' : '0',
            'log_file_uploads' => $normalized['security']['log_file_uploads'] ? '1' : '0',
            'log_file_management' => $normalized['security']['log_file_management'] ? '1' : '0',
            'log_deletions' => $normalized['security']['log_deletions'] ? '1' : '0',
            'log_admin_actions' => $normalized['security']['log_admin_actions'] ? '1' : '0',
            'log_security_actions' => $normalized['security']['log_security_actions'] ? '1' : '0',
            'display_grid_thumbnails_enabled' => $normalized['display']['grid_thumbnails_enabled'] ? '1' : '0',
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
        $accessInput = self::normalizeGroupInput(
            $payload,
            'access',
            ['public_access', 'maintenance_enabled', 'maintenance_scope', 'maintenance_message'],
            $base['access']
        );
        $uploadInput = self::normalizeGroupInput(
            $payload,
            'uploads',
            ['max_file_size_mb', 'allowed_extensions', 'stale_upload_ttl_hours'],
            $base['uploads']
        );
        $automationInput = self::normalizeGroupInput(
            $payload,
            'automation',
            ['runner_enabled', 'diagnostic_interval_minutes', 'cleanup_interval_minutes', 'storage_alert_threshold_pct', 'folder_size_interval_minutes'],
            $base['automation']
        );
        $securityInput = self::normalizeGroupInput(
            $payload,
            'security',
            [
                'audit_enabled',
                'audit_retention_days',
                'log_auth_success',
                'log_auth_failure',
                'log_file_views',
                'log_file_downloads',
                'log_file_uploads',
                'log_file_management',
                'log_deletions',
                'log_admin_actions',
                'log_security_actions',
            ],
            $base['security']
        );
        $displayInput = self::normalizeGroupInput(
            $payload,
            'display',
            ['grid_thumbnails_enabled'],
            $base['display']
        );

        return [
            'access' => [
                'public_access' => wb_parse_bool($accessInput['public_access'] ?? $base['access']['public_access']),
                'maintenance_enabled' => wb_parse_bool($accessInput['maintenance_enabled'] ?? $base['access']['maintenance_enabled']),
                'maintenance_scope' => self::parseMaintenanceScope($accessInput['maintenance_scope'] ?? $base['access']['maintenance_scope']),
                'maintenance_message' => self::parseText(
                    $accessInput['maintenance_message'] ?? $base['access']['maintenance_message'],
                    'Maintenance message',
                    2000,
                    false,
                    MaintenanceMode::defaultMessage()
                ),
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
                'folder_size_interval_minutes' => self::parseInteger(
                    $automationInput['folder_size_interval_minutes'] ?? $base['automation']['folder_size_interval_minutes'],
                    'Folder size refresh interval',
                    60,
                    10080
                ),
            ],
            'security' => [
                'audit_enabled' => wb_parse_bool($securityInput['audit_enabled'] ?? $base['security']['audit_enabled']),
                'audit_retention_days' => self::parseInteger(
                    $securityInput['audit_retention_days'] ?? $base['security']['audit_retention_days'],
                    'Audit log retention',
                    1,
                    3650
                ),
                'log_auth_success' => wb_parse_bool($securityInput['log_auth_success'] ?? $base['security']['log_auth_success']),
                'log_auth_failure' => wb_parse_bool($securityInput['log_auth_failure'] ?? $base['security']['log_auth_failure']),
                'log_file_views' => wb_parse_bool($securityInput['log_file_views'] ?? $base['security']['log_file_views']),
                'log_file_downloads' => wb_parse_bool($securityInput['log_file_downloads'] ?? $base['security']['log_file_downloads']),
                'log_file_uploads' => wb_parse_bool($securityInput['log_file_uploads'] ?? $base['security']['log_file_uploads']),
                'log_file_management' => wb_parse_bool($securityInput['log_file_management'] ?? $base['security']['log_file_management']),
                'log_deletions' => wb_parse_bool($securityInput['log_deletions'] ?? $base['security']['log_deletions']),
                'log_admin_actions' => wb_parse_bool($securityInput['log_admin_actions'] ?? $base['security']['log_admin_actions']),
                'log_security_actions' => wb_parse_bool($securityInput['log_security_actions'] ?? $base['security']['log_security_actions']),
            ],
            'display' => [
                'grid_thumbnails_enabled' => wb_parse_bool($displayInput['grid_thumbnails_enabled'] ?? $base['display']['grid_thumbnails_enabled']),
            ],
        ];
    }

    public static function defaultGrouped(): array
    {
        return [
            'access' => [
                'public_access' => false,
                'maintenance_enabled' => false,
                'maintenance_scope' => MaintenanceMode::SCOPE_APP_ONLY,
                'maintenance_message' => MaintenanceMode::defaultMessage(),
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
                'folder_size_interval_minutes' => 1440,
            ],
            'security' => [
                'audit_enabled' => false,
                'audit_retention_days' => 30,
                'log_auth_success' => true,
                'log_auth_failure' => true,
                'log_file_views' => true,
                'log_file_downloads' => true,
                'log_file_uploads' => true,
                'log_file_management' => true,
                'log_deletions' => true,
                'log_admin_actions' => true,
                'log_security_actions' => true,
            ],
            'display' => [
                'grid_thumbnails_enabled' => true,
            ],
        ];
    }

    private static function defaultMap(): array
    {
        return [
            'app_version' => Installer::VERSION,
            'app_secret' => wb_random_token(32),
            'public_access' => '0',
            'maintenance_enabled' => '0',
            'maintenance_scope' => MaintenanceMode::SCOPE_APP_ONLY,
            'maintenance_message' => MaintenanceMode::defaultMessage(),
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
            'automation_folder_size_interval_minutes' => '1440',
            'audit_enabled' => '0',
            'audit_retention_days' => '30',
            'log_auth_success' => '1',
            'log_auth_failure' => '1',
            'log_file_views' => '1',
            'log_file_downloads' => '1',
            'log_file_uploads' => '1',
            'log_file_management' => '1',
            'log_deletions' => '1',
            'log_admin_actions' => '1',
            'log_security_actions' => '1',
            'display_grid_thumbnails_enabled' => '1',
            'audit_last_pruned_at' => '',
            'ip_bans_last_pruned_at' => '',
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

    private static function parseMaintenanceScope(mixed $value): string
    {
        $scope = trim((string) $value);

        if (!in_array($scope, MaintenanceMode::scopes(), true)) {
            throw new InvalidArgumentException('Maintenance scope is invalid.');
        }

        return $scope;
    }

    private static function parseText(
        mixed $value,
        string $label,
        int $maxLength,
        bool $required = false,
        string $fallback = ''
    ): string {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $value));

        if ($text === '') {
            if ($required) {
                throw new InvalidArgumentException($label . ' is required.');
            }

            return $fallback;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s must be %d characters or fewer.', $label, $maxLength));
        }

        return $text;
    }
}
