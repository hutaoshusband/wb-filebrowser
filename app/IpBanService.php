<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class IpBanService
{
    /**
     * @return array<string, mixed>
     */
    public static function ban(array $actor, string $ipAddress, string $reason, ?string $expiresAt = null, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::pruneExpiredBans($pdo, true);
        $ipAddress = self::normalizeIpAddress($ipAddress);
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Ban reason is required.');
        }

        if (self::activeBanForIp($ipAddress, $pdo) !== null) {
            throw new RuntimeException('This IP address is already banned.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO ip_bans (
                ip_address,
                reason,
                created_by,
                created_by_username,
                created_at,
                expires_at,
                revoked_at,
                revoked_by,
                revoked_by_username,
                revoked_reason,
                updated_at
             ) VALUES (
                :ip_address,
                :reason,
                :created_by,
                :created_by_username,
                :created_at,
                :expires_at,
                NULL,
                NULL,
                NULL,
                NULL,
                :updated_at
             )'
        );
        $now = wb_now();
        $statement->execute([
            ':ip_address' => $ipAddress,
            ':reason' => $reason,
            ':created_by' => (int) ($actor['id'] ?? 0) ?: null,
            ':created_by_username' => (string) ($actor['username'] ?? ''),
            ':created_at' => $now,
            ':expires_at' => self::normalizeExpiry($expiresAt),
            ':updated_at' => $now,
        ]);
        $ban = self::banById((int) $pdo->lastInsertId(), $pdo);

        if ($ban === null) {
            throw new RuntimeException('Unable to create the IP ban right now.');
        }

        AuditLog::record('security.ip_ban.create', 'security_actions', [
            'actor_user' => $actor,
            'target_type' => 'ip_ban',
            'target_id' => $ban['id'],
            'target_label' => $ipAddress,
            'summary' => 'Banned IP ' . $ipAddress,
            'metadata' => [
                'reason' => $reason,
                'expires_at' => $ban['expires_at'],
            ],
        ], $pdo);

        return self::serializeBan($ban);
    }

    /**
     * @return array<string, mixed>
     */
    public static function unban(array $actor, int $banId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::pruneExpiredBans($pdo, true);
        $ban = self::banById($banId, $pdo);

        if ($ban === null || $ban['revoked_at'] !== null) {
            throw new RuntimeException('Active IP ban not found.');
        }

        $now = wb_now();
        $statement = $pdo->prepare(
            'UPDATE ip_bans
             SET revoked_at = :revoked_at,
                 revoked_by = :revoked_by,
                 revoked_by_username = :revoked_by_username,
                 revoked_reason = :revoked_reason,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            ':revoked_at' => $now,
            ':revoked_by' => (int) ($actor['id'] ?? 0) ?: null,
            ':revoked_by_username' => (string) ($actor['username'] ?? ''),
            ':revoked_reason' => 'manual',
            ':updated_at' => $now,
            ':id' => $banId,
        ]);
        $updated = self::banById($banId, $pdo);

        if ($updated === null) {
            throw new RuntimeException('Unable to update the IP ban right now.');
        }

        AuditLog::record('security.ip_ban.unban', 'security_actions', [
            'actor_user' => $actor,
            'target_type' => 'ip_ban',
            'target_id' => $updated['id'],
            'target_label' => (string) $updated['ip_address'],
            'summary' => 'Unbanned IP ' . $updated['ip_address'],
            'metadata' => [
                'reason' => $updated['reason'],
            ],
        ], $pdo);

        return self::serializeBan($updated);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::pruneExpiredBans($pdo, true);

        return [
            'active_bans' => array_map(
                static fn (array $ban): array => self::serializeBan($ban),
                $pdo->query(
                    'SELECT *
                     FROM ip_bans
                     WHERE revoked_at IS NULL
                     ORDER BY created_at DESC, id DESC'
                )->fetchAll()
            ),
            'ban_history' => array_map(
                static fn (array $ban): array => self::serializeBan($ban),
                $pdo->query(
                    'SELECT *
                     FROM ip_bans
                     WHERE revoked_at IS NOT NULL
                     ORDER BY revoked_at DESC, id DESC
                     LIMIT 100'
                )->fetchAll()
            ),
        ];
    }

    public static function assertCurrentIpAllowed(?PDO $pdo = null): void
    {
        if (!Installer::isInstalled()) {
            return;
        }

        $pdo ??= Database::connection();
        self::pruneExpiredBans($pdo);
        $ban = self::activeBanForIp(Security::clientIp(), $pdo);

        if ($ban !== null) {
            $expiresAt = $ban['expires_at'] === null ? null : (string) $ban['expires_at'];

            if ($expiresAt === null || $expiresAt === '') {
                throw BlockedAccessException::permanent('ip_ban');
            }

            $retryAfterSeconds = max(1, (strtotime($expiresAt) ?: time()) - time());
            throw new BlockedAccessException('ip_ban', $expiresAt, false, $retryAfterSeconds);
        }
    }

    public static function activeBanForIp(string $ipAddress, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        $normalized = self::normalizeIpAddress($ipAddress, false);

        if ($normalized === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT *
             FROM ip_bans
             WHERE ip_address = :ip_address
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            ':ip_address' => $normalized,
            ':now' => wb_now(),
        ]);
        $ban = $statement->fetch();

        return is_array($ban) ? $ban : null;
    }

    private static function pruneExpiredBans(PDO $pdo, bool $force = false): void
    {
        $now = time();
        $lastPrunedAt = strtotime((string) Database::setting('ip_bans_last_pruned_at', '')) ?: 0;

        if (!$force && $lastPrunedAt > 0 && ($now - $lastPrunedAt) < 300) {
            return;
        }

        $select = $pdo->prepare(
            'SELECT *
             FROM ip_bans
             WHERE revoked_at IS NULL
               AND expires_at IS NOT NULL
               AND expires_at <= :now'
        );
        $select->execute([':now' => wb_now()]);

        foreach ($select->fetchAll() as $ban) {
            $statement = $pdo->prepare(
                'UPDATE ip_bans
                 SET revoked_at = :revoked_at,
                     revoked_reason = :revoked_reason,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $revokedAt = wb_now();
            $statement->execute([
                ':revoked_at' => $revokedAt,
                ':revoked_reason' => 'expired',
                ':updated_at' => $revokedAt,
                ':id' => $ban['id'],
            ]);
            AuditLog::record('security.ip_ban.expire', 'security_actions', [
                'target_type' => 'ip_ban',
                'target_id' => (int) $ban['id'],
                'target_label' => (string) $ban['ip_address'],
                'summary' => 'Expired IP ban for ' . $ban['ip_address'],
                'metadata' => [
                    'reason' => $ban['reason'],
                    'expires_at' => $ban['expires_at'],
                ],
            ], $pdo);
        }

        Database::updateSetting('ip_bans_last_pruned_at', wb_now());
    }

    private static function normalizeIpAddress(string $ipAddress, bool $throw = true): ?string
    {
        $normalized = trim($ipAddress);

        if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return $normalized;
        }

        if ($throw) {
            throw new RuntimeException('A valid IP address is required.');
        }

        return null;
    }

    private static function normalizeExpiry(?string $expiresAt): ?string
    {
        $value = trim((string) $expiresAt);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false || $timestamp <= time()) {
            throw new RuntimeException('IP ban expiry must be in the future.');
        }

        return gmdate('c', $timestamp);
    }

    private static function banById(int $banId, PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM ip_bans WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $banId]);
        $ban = $statement->fetch();

        return is_array($ban) ? $ban : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeBan(array $ban): array
    {
        return [
            'id' => (int) ($ban['id'] ?? 0),
            'ip_address' => (string) ($ban['ip_address'] ?? ''),
            'reason' => (string) ($ban['reason'] ?? ''),
            'created_by' => $ban['created_by'] === null ? null : (int) $ban['created_by'],
            'created_by_username' => $ban['created_by_username'] === null ? null : (string) $ban['created_by_username'],
            'created_at' => (string) ($ban['created_at'] ?? ''),
            'expires_at' => $ban['expires_at'] === null ? null : (string) $ban['expires_at'],
            'revoked_at' => $ban['revoked_at'] === null ? null : (string) $ban['revoked_at'],
            'revoked_by' => $ban['revoked_by'] === null ? null : (int) $ban['revoked_by'],
            'revoked_by_username' => $ban['revoked_by_username'] === null ? null : (string) $ban['revoked_by_username'],
            'revoked_reason' => $ban['revoked_reason'] === null ? null : (string) $ban['revoked_reason'],
            'is_active' => $ban['revoked_at'] === null,
        ];
    }
}
