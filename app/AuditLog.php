<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;

final class AuditLog
{
    private const PAGE_SIZE = 25;
    private const PRUNE_INTERVAL_SECONDS = 300;

    /**
     * @return array<string, array{setting: string, label: string}>
     */
    public static function categories(): array
    {
        return [
            'auth_success' => [
                'setting' => 'log_auth_success',
                'label' => 'Auth successes',
            ],
            'auth_failure' => [
                'setting' => 'log_auth_failure',
                'label' => 'Auth failures',
            ],
            'file_views' => [
                'setting' => 'log_file_views',
                'label' => 'File views',
            ],
            'file_downloads' => [
                'setting' => 'log_file_downloads',
                'label' => 'File downloads',
            ],
            'file_uploads' => [
                'setting' => 'log_file_uploads',
                'label' => 'File uploads',
            ],
            'file_management' => [
                'setting' => 'log_file_management',
                'label' => 'File management',
            ],
            'deletions' => [
                'setting' => 'log_deletions',
                'label' => 'Deletions',
            ],
            'admin_actions' => [
                'setting' => 'log_admin_actions',
                'label' => 'Admin actions',
            ],
            'security_actions' => [
                'setting' => 'log_security_actions',
                'label' => 'Security actions',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function record(string $eventType, string $category, array $context = [], ?PDO $pdo = null): void
    {
        if (!Installer::isInstalled() || !self::shouldLog($category)) {
            return;
        }

        $pdo ??= Database::connection();
        self::pruneIfDue($pdo);
        $actor = is_array($context['actor_user'] ?? null) ? $context['actor_user'] : null;
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $summary = trim((string) ($context['summary'] ?? ''));

        if ($summary !== '' && !isset($metadata['summary'])) {
            $metadata['summary'] = $summary;
        }

        $statement = $pdo->prepare(
            'INSERT INTO audit_logs (
                event_type,
                category,
                actor_user_id,
                actor_username,
                ip_address,
                target_type,
                target_id,
                target_label,
                metadata_json,
                created_at
             ) VALUES (
                :event_type,
                :category,
                :actor_user_id,
                :actor_username,
                :ip_address,
                :target_type,
                :target_id,
                :target_label,
                :metadata_json,
                :created_at
             )'
        );
        $statement->execute([
            ':event_type' => $eventType,
            ':category' => $category,
            ':actor_user_id' => $actor['id'] ?? ($context['actor_user_id'] ?? null),
            ':actor_username' => $actor['username'] ?? ($context['actor_username'] ?? null),
            ':ip_address' => (string) ($context['ip_address'] ?? Security::clientIp()),
            ':target_type' => $context['target_type'] ?? null,
            ':target_id' => $context['target_id'] ?? null,
            ':target_label' => $context['target_label'] ?? null,
            ':metadata_json' => self::encodeMetadata($metadata),
            ':created_at' => wb_now(),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function list(array $filters = [], ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::pruneIfDue($pdo, true);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $query = trim((string) ($filters['query'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $categories = self::categories();

        if ($category !== '' && !isset($categories[$category])) {
            $category = '';
        }

        $conditions = [];
        $params = [];

        if ($category !== '') {
            $conditions[] = 'category = :category';
            $params[':category'] = $category;
        }

        if ($query !== '') {
            $conditions[] = '(event_type LIKE :query OR actor_username LIKE :query OR ip_address LIKE :query OR target_label LIKE :query)';
            $params[':query'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $count = $pdo->prepare('SELECT COUNT(*) FROM audit_logs ' . $whereSql);
        $count->execute($params);
        $totalItems = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $select = $pdo->prepare(
            'SELECT *
             FROM audit_logs
             ' . $whereSql . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $select->bindValue($key, $value);
        }

        $select->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $select->bindValue(':offset', $offset, PDO::PARAM_INT);
        $select->execute();

        return [
            'entries' => array_map(static fn (array $row): array => self::serializeRow($row), $select->fetchAll()),
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'query' => $query,
            'category' => $category,
            'categories' => array_map(
                static fn (string $key, array $definition): array => [
                    'key' => $key,
                    'label' => $definition['label'],
                ],
                array_keys($categories),
                array_values($categories)
            ),
        ];
    }

    public static function shouldLog(string $category): bool
    {
        $categories = self::categories();

        if (!isset($categories[$category]) || !wb_parse_bool(Database::setting('audit_enabled', '0'))) {
            return false;
        }

        return wb_parse_bool(Database::setting($categories[$category]['setting'], '1'));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function encodeMetadata(array $metadata): string
    {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeRow(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $summary = is_array($metadata) && is_string($metadata['summary'] ?? null)
            ? trim((string) $metadata['summary'])
            : '';
        $category = (string) ($row['category'] ?? '');
        $categories = self::categories();

        if ($summary === '') {
            $summary = self::fallbackSummary((string) ($row['event_type'] ?? ''), (string) ($row['target_label'] ?? ''));
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'category' => $category,
            'category_label' => $categories[$category]['label'] ?? $category,
            'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'actor_username' => $row['actor_username'] === null ? null : (string) $row['actor_username'],
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'target_type' => $row['target_type'] === null ? null : (string) $row['target_type'],
            'target_id' => $row['target_id'] === null ? null : (int) $row['target_id'],
            'target_label' => $row['target_label'] === null ? null : (string) $row['target_label'],
            'metadata' => is_array($metadata) ? $metadata : [],
            'summary' => $summary,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private static function fallbackSummary(string $eventType, string $targetLabel): string
    {
        $eventLabel = ucwords(str_replace(['.', '_'], ' ', $eventType));

        if ($targetLabel === '') {
            return $eventLabel;
        }

        return trim($eventLabel . ': ' . $targetLabel);
    }

    private static function pruneIfDue(PDO $pdo, bool $force = false): void
    {
        $now = time();
        $lastPrunedAt = strtotime((string) Database::setting('audit_last_pruned_at', '')) ?: 0;

        if (!$force && $lastPrunedAt > 0 && ($now - $lastPrunedAt) < self::PRUNE_INTERVAL_SECONDS) {
            return;
        }

        $retentionDays = max(1, (int) Database::setting('audit_retention_days', '30'));
        $cutoff = gmdate('c', $now - ($retentionDays * 86400));
        $statement = $pdo->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff');
        $statement->execute([':cutoff' => $cutoff]);
        Database::updateSetting('audit_last_pruned_at', wb_now());
    }
}
