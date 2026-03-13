<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use PDOException;
use RuntimeException;

final class FileShares
{
    private const TEXT_PREVIEW_LIMIT_BYTES = 262144;
    private const STREAM_GRANT_TTL_SECONDS = 600;

    public static function get(array $user, int $fileId, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::assertCanManageShares($user, $file, $pdo);
        $share = self::activeShareRow($fileId, $pdo);

        return $share === null ? null : self::serializeShare($share, $file);
    }

    public static function create(array $user, int $fileId, array $options = [], ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::assertCanManageShares($user, $file, $pdo);
        $options = self::normalizeOptions($options);
        $existing = self::activeShareRow($fileId, $pdo);
        $now = wb_now();
        $passwordAuditEvent = null;

        if ($existing !== null) {
            [$passwordHash, $passwordVersion, $passwordAuditEvent] = self::updatedPasswordState($existing, $options);
            $update = $pdo->prepare(
                'UPDATE file_shares
                 SET expires_at = :expires_at,
                     max_views = :max_views,
                     password_hash = :password_hash,
                     password_version = :password_version,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':expires_at' => $options['expires_at'],
                ':max_views' => $options['max_views'],
                ':password_hash' => $passwordHash,
                ':password_version' => $passwordVersion,
                ':updated_at' => $now,
                ':id' => $existing['id'],
            ]);

            $share = self::activeShareRow($fileId, $pdo);

            if ($share === null) {
                throw new RuntimeException('Unable to update the share link right now.');
            }

            $serialized = self::serializeShare($share, $file);
            AuditLog::record('share.create', 'admin_actions', [
                'actor_user' => $user,
                'target_type' => 'file',
                'target_id' => (int) $file['id'],
                'target_label' => (string) $file['original_name'],
                'summary' => 'Updated share link for ' . $file['original_name'],
                'metadata' => [
                    'expires_at' => $serialized['expires_at'],
                    'max_views' => $serialized['max_views'],
                    'share_url' => $serialized['url'],
                    'requires_password' => $serialized['requires_password'],
                ],
            ], $pdo);
            self::recordPasswordAuditEvent($passwordAuditEvent, $user, $file, $serialized, $pdo);

            return $serialized;
        }

        $passwordHash = $options['update_password'] ? $options['password_hash'] : null;
        $passwordVersion = $options['update_password'] ? 1 : 0;
        $passwordAuditEvent = $options['update_password'] ? 'share.password.set' : null;

        $statement = $pdo->prepare(
            'INSERT INTO file_shares (
                file_id,
                active_file_id,
                token,
                created_by,
                expires_at,
                max_views,
                view_count,
                password_hash,
                password_version,
                created_at,
                updated_at,
                revoked_at
             ) VALUES (
                :file_id,
                :active_file_id,
                :token,
                :created_by,
                :expires_at,
                :max_views,
                0,
                :password_hash,
                :password_version,
                :created_at,
                :updated_at,
                NULL
             )'
        );

        try {
            $statement->execute([
                ':file_id' => $fileId,
                ':active_file_id' => $fileId,
                ':token' => wb_random_token(24),
                ':created_by' => (int) $user['id'],
                ':expires_at' => $options['expires_at'],
                ':max_views' => $options['max_views'],
                ':password_hash' => $passwordHash,
                ':password_version' => $passwordVersion,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            self::cleanupInactiveShares($pdo);
            $existing = self::activeShareRow($fileId, $pdo);

            if ($existing !== null) {
                return self::serializeShare($existing, $file);
            }

            throw $exception;
        }

        $share = self::activeShareRow($fileId, $pdo);

        if ($share === null) {
            throw new RuntimeException('Unable to create a share link right now.');
        }

        $serialized = self::serializeShare($share, $file);
        AuditLog::record('share.create', 'admin_actions', [
            'actor_user' => $user,
            'target_type' => 'file',
            'target_id' => (int) $file['id'],
            'target_label' => (string) $file['original_name'],
            'summary' => 'Created share link for ' . $file['original_name'],
            'metadata' => [
                'expires_at' => $serialized['expires_at'],
                'max_views' => $serialized['max_views'],
                'share_url' => $serialized['url'],
                'requires_password' => $serialized['requires_password'],
            ],
        ], $pdo);
        self::recordPasswordAuditEvent($passwordAuditEvent, $user, $file, $serialized, $pdo);

        return $serialized;
    }

    public static function revoke(array $user, int $fileId, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::assertCanManageShares($user, $file, $pdo);
        $statement = $pdo->prepare(
            'UPDATE file_shares
             SET revoked_at = :revoked_at, active_file_id = NULL, updated_at = :updated_at
             WHERE file_id = :file_id AND revoked_at IS NULL'
        );
        $now = wb_now();
        $statement->execute([
            ':revoked_at' => $now,
            ':updated_at' => $now,
            ':file_id' => $fileId,
        ]);
        AuditLog::record('share.revoke', 'admin_actions', [
            'actor_user' => $user,
            'target_type' => 'file',
            'target_id' => (int) $file['id'],
            'target_label' => (string) $file['original_name'],
            'summary' => 'Revoked share link for ' . $file['original_name'],
        ], $pdo);
    }

    public static function publicContext(string $token, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $share = self::resolveActiveShare($token, $pdo);
        $termsPolicy = Settings::shareTermsPolicy($pdo);
        $isTermsAccepted = self::hasAcceptedShareTerms($termsPolicy);

        return [
            'share' => self::serializeResolvedShare($share),
            'file' => self::serializeSharedFile($share),
            'is_unlocked' => self::isShareUnlocked($share),
            'terms_message' => $termsPolicy['message'],
            'terms_version' => $termsPolicy['version'],
            'is_terms_accepted' => $isTermsAccepted,
            'requires_terms_acceptance' => $termsPolicy['enabled'] && !$isTermsAccepted,
        ];
    }

    public static function acceptTerms(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $termsPolicy = Settings::shareTermsPolicy($pdo);
        Security::startSession();
        $_SESSION['share_terms_acceptance'] = [
            'version' => $termsPolicy['version'],
            'accepted_at' => wb_now(),
        ];
    }

    public static function streamRedirectUrl(string $token = '', string $grant = '', ?PDO $pdo = null): ?string
    {
        $pdo ??= Database::connection();
        $effectiveToken = trim($token);

        if ($effectiveToken === '' && trim($grant) !== '') {
            $payload = Security::verifySignedPayload($grant);
            $effectiveToken = (string) ($payload['token'] ?? '');
        }

        if ($effectiveToken === '') {
            return null;
        }

        self::cleanupInactiveShares($pdo);
        self::resolveActiveShare($effectiveToken, $pdo);

        if (!self::requiresShareTermsAcceptance(Settings::shareTermsPolicy($pdo))) {
            return null;
        }

        return self::shareUrls($effectiveToken)['view'];
    }

    public static function unlock(string $token, string $password, ?PDO $pdo = null): bool
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $share = self::resolveActiveShare($token, $pdo);
        $passwordHash = (string) ($share['password_hash'] ?? '');

        if ($passwordHash === '') {
            return true;
        }

        if (!password_verify($password, $passwordHash)) {
            return false;
        }

        self::markShareUnlocked($share);

        return true;
    }

    public static function viewPayload(string $token, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $pdo->beginTransaction();

        try {
            $share = self::resolveActiveShare($token, $pdo);
            self::assertShareUnlocked($share);
            self::assertShareTermsAccepted($pdo);
            $share = self::incrementViewCount($share, $pdo);
            $file = self::serializeSharedFile($share);
            $previewMode = (string) $file['preview_mode'];
            $textPreview = null;
            $textPreviewTruncated = false;

            if ($previewMode === 'text') {
                [$textPreview, $textPreviewTruncated] = self::readTextPreview(
                    (string) $share['disk_name'],
                    (string) $share['disk_extension']
                );
            }

            $pdo->commit();
            AuditLog::record('share.view', 'file_views', [
                'target_type' => 'file',
                'target_id' => (int) $share['file_id'],
                'target_label' => (string) $share['original_name'],
                'summary' => 'Viewed shared file ' . $share['original_name'],
                'metadata' => [
                    'view_count' => (int) $share['view_count'],
                ],
            ], $pdo);

            return [
                'share' => self::serializeResolvedShare($share),
                'file' => $file,
                'preview_mode' => $previewMode,
                'text_preview' => $textPreview,
                'text_preview_truncated' => $textPreviewTruncated,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public static function stream(string $token, string $disposition = 'inline', ?PDO $pdo = null): never
    {
        $pdo ??= Database::connection();
        self::cleanupInactiveShares($pdo);
        $share = self::resolveActiveShare($token, $pdo);
        self::assertShareUnlocked($share);
        self::assertShareTermsAccepted($pdo);
        $dispositionType = $disposition === 'attachment' ? 'attachment' : 'inline';

        if ($dispositionType === 'attachment') {
            AuditLog::record('share.download', 'file_downloads', [
                'target_type' => 'file',
                'target_id' => (int) $share['file_id'],
                'target_label' => (string) $share['original_name'],
                'summary' => 'Downloaded shared file ' . $share['original_name'],
            ], $pdo);
        }

        Security::sendFile(
            self::blobPath((string) $share['disk_name'], (string) $share['disk_extension']),
            (string) $share['mime_type'],
            (string) $share['original_name'],
            $disposition
        );
    }

    public static function streamGranted(string $grant, ?PDO $pdo = null): never
    {
        $payload = Security::verifySignedPayload($grant);

        if (($payload['type'] ?? '') !== 'share-stream') {
            throw new RuntimeException('Shared file not found.');
        }

        $pdo ??= Database::connection();
        $share = self::resolveActiveShare((string) ($payload['token'] ?? ''), $pdo);
        self::assertShareUnlocked($share);
        self::assertShareTermsAccepted($pdo);
        $disposition = (string) ($payload['disposition'] ?? 'inline');

        if ($disposition === 'attachment') {
            AuditLog::record('share.download', 'file_downloads', [
                'target_type' => 'file',
                'target_id' => (int) $share['file_id'],
                'target_label' => (string) $share['original_name'],
                'summary' => 'Downloaded shared file ' . $share['original_name'],
            ], $pdo);
        }

        Security::sendFile(
            self::blobPath((string) $share['disk_name'], (string) $share['disk_extension']),
            (string) $share['mime_type'],
            (string) $share['original_name'],
            $disposition
        );
    }

    private static function assertCanManageShares(array $user, array $file, PDO $pdo): void
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can manage share links.');
        }

        if (!Permissions::canViewFolderContents((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have access to this file.');
        }
    }

    private static function cleanupInactiveShares(PDO $pdo): void
    {
        $now = wb_now();
        $statement = $pdo->prepare(
            'UPDATE file_shares
             SET revoked_at = :now, active_file_id = NULL, updated_at = :now
             WHERE revoked_at IS NULL
               AND (
                    (expires_at IS NOT NULL AND expires_at <= :now)
                    OR
                    (max_views IS NOT NULL AND view_count >= max_views)
               )'
        );
        $statement->execute([':now' => $now]);
    }

    private static function resolveActiveShare(string $token, PDO $pdo): array
    {
        self::assertToken($token);
        $now = wb_now();
        $statement = $pdo->prepare(
            'SELECT
                file_shares.id,
                file_shares.file_id,
                file_shares.token,
                file_shares.expires_at,
                file_shares.max_views,
                file_shares.view_count,
                file_shares.password_hash,
                file_shares.password_version,
                file_shares.revoked_at,
                file_shares.created_at AS share_created_at,
                file_shares.updated_at AS share_updated_at,
                files.folder_id,
                files.original_name,
                files.disk_name,
                files.disk_extension,
                files.mime_type,
                files.size,
                files.checksum,
                files.updated_at
             FROM file_shares
             INNER JOIN files ON files.id = file_shares.file_id
             WHERE file_shares.token = :token
               AND file_shares.revoked_at IS NULL
               AND (file_shares.expires_at IS NULL OR file_shares.expires_at > :now)
               AND (file_shares.max_views IS NULL OR file_shares.view_count < file_shares.max_views)
             LIMIT 1'
        );
        $statement->execute([
            ':token' => $token,
            ':now' => $now,
        ]);
        $share = $statement->fetch();

        if (!is_array($share)) {
            throw new RuntimeException('Shared file not found.');
        }

        return $share;
    }

    private static function incrementViewCount(array $share, PDO $pdo): array
    {
        $newViewCount = (int) $share['view_count'] + 1;
        $maxViews = $share['max_views'] === null ? null : (int) $share['max_views'];
        $now = wb_now();
        $revokedAt = $maxViews !== null && $newViewCount >= $maxViews ? $now : null;
        $activeFileId = $revokedAt === null ? (int) $share['file_id'] : null;
        $statement = $pdo->prepare(
            'UPDATE file_shares
             SET view_count = :view_count,
                 updated_at = :updated_at,
                 active_file_id = :active_file_id,
                 revoked_at = :revoked_at
             WHERE id = :id'
        );
        $statement->execute([
            ':view_count' => $newViewCount,
            ':updated_at' => $now,
            ':active_file_id' => $activeFileId,
            ':revoked_at' => $revokedAt,
            ':id' => $share['id'],
        ]);
        $share['view_count'] = $newViewCount;
        $share['share_updated_at'] = $now;
        $share['revoked_at'] = $revokedAt;

        return $share;
    }

    private static function activeShareRow(int $fileId, PDO $pdo): ?array
    {
        self::cleanupInactiveShares($pdo);
        $statement = $pdo->prepare(
            'SELECT *
             FROM file_shares
             WHERE active_file_id = :file_id AND revoked_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([':file_id' => $fileId]);
        $share = $statement->fetch();

        return is_array($share) ? $share : null;
    }

    private static function fileById(int $fileId, PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $fileId]);
        $file = $statement->fetch();

        return is_array($file) ? $file : null;
    }

    private static function serializeShare(array $share, array $file): array
    {
        $urls = self::shareUrls((string) $share['token']);
        $viewCount = (int) ($share['view_count'] ?? 0);
        $maxViews = $share['max_views'] === null ? null : (int) $share['max_views'];

        return [
            'file_id' => (int) $file['id'],
            'token' => (string) $share['token'],
            'url' => $urls['view'],
            'download_url' => $urls['download'],
            'created_at' => (string) $share['created_at'],
            'updated_at' => (string) $share['updated_at'],
            'expires_at' => $share['expires_at'] === null ? null : (string) $share['expires_at'],
            'max_views' => $maxViews,
            'view_count' => $viewCount,
            'remaining_views' => $maxViews === null ? null : max(0, $maxViews - $viewCount),
            'revoked_at' => $share['revoked_at'] === null ? null : (string) $share['revoked_at'],
            'requires_password' => ((string) ($share['password_hash'] ?? '')) !== '',
        ];
    }

    private static function serializeResolvedShare(array $share): array
    {
        $urls = self::shareUrls((string) $share['token']);
        $viewCount = (int) ($share['view_count'] ?? 0);
        $maxViews = $share['max_views'] === null ? null : (int) $share['max_views'];

        return [
            'file_id' => (int) $share['file_id'],
            'token' => (string) $share['token'],
            'url' => $urls['view'],
            'download_url' => $urls['download'],
            'created_at' => (string) $share['share_created_at'],
            'updated_at' => (string) $share['share_updated_at'],
            'expires_at' => $share['expires_at'] === null ? null : (string) $share['expires_at'],
            'max_views' => $maxViews,
            'view_count' => $viewCount,
            'remaining_views' => $maxViews === null ? null : max(0, $maxViews - $viewCount),
            'requires_password' => ((string) ($share['password_hash'] ?? '')) !== '',
        ];
    }

    private static function serializeSharedFile(array $share): array
    {
        $extension = strtolower(pathinfo((string) $share['original_name'], PATHINFO_EXTENSION));
        $urls = self::shareStreamUrls((string) $share['token']);
        $preview = wb_file_preview_metadata((string) $share['mime_type'], $extension);

        return array_merge([
            'id' => (int) $share['file_id'],
            'type' => 'file',
            'name' => (string) $share['original_name'],
            'folder_id' => (int) $share['folder_id'],
            'size' => (int) $share['size'],
            'size_label' => wb_format_bytes((int) $share['size']),
            'mime_type' => (string) $share['mime_type'],
            'updated_at' => (string) $share['updated_at'],
            'updated_relative' => wb_relative_time((string) $share['updated_at']),
            'checksum' => (string) $share['checksum'],
            'extension' => $extension,
            'preview_url' => $urls['inline'],
            'download_url' => $urls['attachment'],
        ], $preview);
    }

    private static function shareUrls(string $token): array
    {
        $viewPath = '/share/?token=' . $token;
        $downloadPath = '/api/index.php?action=share.stream&token=' . $token . '&disposition=attachment';

        return [
            'view' => wb_absolute_url($viewPath) ?? wb_url($viewPath),
            'download' => wb_absolute_url($downloadPath) ?? wb_url($downloadPath),
        ];
    }

    private static function shareStreamUrls(string $token): array
    {
        return [
            'inline' => wb_url('/api/index.php?action=share.stream&grant=' . rawurlencode(self::streamGrant($token, 'inline'))),
            'attachment' => wb_url('/api/index.php?action=share.stream&grant=' . rawurlencode(self::streamGrant($token, 'attachment'))),
        ];
    }

    /**
     * @return array{0: ?string, 1: int, 2: ?string}
     */
    private static function updatedPasswordState(array $existing, array $options): array
    {
        $currentHash = $existing['password_hash'] === null ? null : (string) $existing['password_hash'];
        $currentVersion = (int) ($existing['password_version'] ?? 0);

        if ($options['update_password']) {
            return [
                (string) $options['password_hash'],
                $currentVersion + 1,
                $currentHash === null || $currentHash === '' ? 'share.password.set' : 'share.password.change',
            ];
        }

        if ($options['clear_password'] && $currentHash !== null && $currentHash !== '') {
            return [null, $currentVersion + 1, 'share.password.remove'];
        }

        return [$currentHash, $currentVersion, null];
    }

    private static function recordPasswordAuditEvent(?string $eventType, array $user, array $file, array $share, PDO $pdo): void
    {
        if ($eventType === null) {
            return;
        }

        $summary = match ($eventType) {
            'share.password.change' => 'Changed share password for ' . $file['original_name'],
            'share.password.remove' => 'Removed share password for ' . $file['original_name'],
            default => 'Enabled share password for ' . $file['original_name'],
        };

        AuditLog::record($eventType, 'admin_actions', [
            'actor_user' => $user,
            'target_type' => 'file',
            'target_id' => (int) $file['id'],
            'target_label' => (string) $file['original_name'],
            'summary' => $summary,
            'metadata' => [
                'share_url' => $share['url'],
                'requires_password' => $share['requires_password'],
            ],
        ], $pdo);
    }

    private static function streamGrant(string $token, string $disposition): string
    {
        return Security::signPayload([
            'type' => 'share-stream',
            'token' => $token,
            'disposition' => $disposition === 'attachment' ? 'attachment' : 'inline',
            'expires_at' => time() + self::STREAM_GRANT_TTL_SECONDS,
        ]);
    }

    private static function normalizeOptions(array $options): array
    {
        $expiresAt = trim((string) ($options['expires_at'] ?? ''));
        $maxViewsInput = $options['max_views'] ?? null;
        $passwordInput = (string) ($options['password'] ?? '');
        $clearPassword = wb_parse_bool($options['clear_password'] ?? false);
        $normalizedExpiresAt = null;
        $normalizedMaxViews = null;
        $updatePassword = trim($passwordInput) !== '';

        if ($updatePassword && $clearPassword) {
            throw new RuntimeException('Choose either a new share password or remove the current one.');
        }

        if ($expiresAt !== '') {
            $timestamp = strtotime($expiresAt);

            if ($timestamp === false || $timestamp <= time()) {
                throw new RuntimeException('Share expiration must be in the future.');
            }

            $normalizedExpiresAt = gmdate('c', $timestamp);
        }

        if ($maxViewsInput !== null && $maxViewsInput !== '') {
            $maxViews = filter_var($maxViewsInput, FILTER_VALIDATE_INT);

            if ($maxViews === false || $maxViews < 1) {
                throw new RuntimeException('Share view limit must be at least 1.');
            }

            $normalizedMaxViews = $maxViews;
        }

        if ($updatePassword && mb_strlen($passwordInput) > 255) {
            throw new RuntimeException('Share password must be 255 characters or fewer.');
        }

        return [
            'expires_at' => $normalizedExpiresAt,
            'max_views' => $normalizedMaxViews,
            'password_hash' => $updatePassword ? Security::hashPassword($passwordInput) : null,
            'update_password' => $updatePassword,
            'clear_password' => $clearPassword,
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private static function readTextPreview(string $diskName, string $diskExtension): array
    {
        $path = self::blobPath($diskName, $diskExtension);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Shared file not found.');
        }

        $preview = '';
        $remaining = self::TEXT_PREVIEW_LIMIT_BYTES + 1;

        try {
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));

                if ($chunk === false) {
                    break;
                }

                $preview .= $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        $truncated = strlen($preview) > self::TEXT_PREVIEW_LIMIT_BYTES;

        if ($truncated) {
            $preview = substr($preview, 0, self::TEXT_PREVIEW_LIMIT_BYTES);
        }

        return [$preview, $truncated];
    }

    private static function assertToken(string $token): void
    {
        if (!ctype_xdigit($token) || strlen($token) < 32) {
            throw new RuntimeException('Shared file not found.');
        }
    }

    private static function blobPath(string $diskName, string $diskExtension): string
    {
        return wb_storage_path(
            'uploads/' . substr($diskName, 0, 2) . '/' . substr($diskName, 2, 2) . '/' . $diskName . '.' . $diskExtension
        );
    }

    private static function assertShareUnlocked(array $share): void
    {
        if (!self::isShareUnlocked($share)) {
            throw new RuntimeException('Share password is required.');
        }
    }

    private static function assertShareTermsAccepted(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $termsPolicy = Settings::shareTermsPolicy($pdo);

        if (!self::requiresShareTermsAcceptance($termsPolicy)) {
            return;
        }

        throw new RuntimeException('Share terms are required.');
    }

    private static function isShareUnlocked(array $share): bool
    {
        $passwordHash = trim((string) ($share['password_hash'] ?? ''));

        if ($passwordHash === '') {
            return true;
        }

        Security::startSession();
        $token = (string) ($share['token'] ?? '');
        $storedVersion = $_SESSION['share_unlocks'][$token] ?? null;

        return (int) $storedVersion === (int) ($share['password_version'] ?? 0);
    }

    private static function markShareUnlocked(array $share): void
    {
        Security::startSession();

        if (!isset($_SESSION['share_unlocks']) || !is_array($_SESSION['share_unlocks'])) {
            $_SESSION['share_unlocks'] = [];
        }

        $_SESSION['share_unlocks'][(string) $share['token']] = (int) ($share['password_version'] ?? 0);
    }

    /**
     * @param array{enabled: bool, message: string, version: int} $termsPolicy
     */
    private static function hasAcceptedShareTerms(array $termsPolicy): bool
    {
        if (!$termsPolicy['enabled']) {
            return true;
        }

        Security::startSession();
        $accepted = $_SESSION['share_terms_acceptance'] ?? null;

        if (!is_array($accepted)) {
            return false;
        }

        return (int) ($accepted['version'] ?? 0) === $termsPolicy['version'];
    }

    /**
     * @param array{enabled: bool, message: string, version: int} $termsPolicy
     */
    private static function requiresShareTermsAcceptance(array $termsPolicy): bool
    {
        return $termsPolicy['enabled'] && !self::hasAcceptedShareTerms($termsPolicy);
    }
}
