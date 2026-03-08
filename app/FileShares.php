<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use PDOException;
use RuntimeException;

final class FileShares
{
    private const TEXT_PREVIEW_LIMIT_BYTES = 262144;

    public static function get(array $user, int $fileId, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::assertCanManageShares($user, $file, $pdo);
        $share = self::activeShareRow($fileId, $pdo);

        return $share === null ? null : self::serializeShare($share, $file);
    }

    public static function create(array $user, int $fileId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::assertCanManageShares($user, $file, $pdo);
        $existing = self::activeShareRow($fileId, $pdo);

        if ($existing !== null) {
            return self::serializeShare($existing, $file);
        }

        $statement = $pdo->prepare(
            'INSERT INTO file_shares (file_id, token, created_by, created_at, updated_at, revoked_at)
             VALUES (:file_id, :token, :created_by, :created_at, :updated_at, NULL)'
        );
        $now = wb_now();

        try {
            $statement->execute([
                ':file_id' => $fileId,
                ':token' => wb_random_token(24),
                ':created_by' => (int) $user['id'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
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

        return self::serializeShare($share, $file);
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
             SET revoked_at = :revoked_at, updated_at = :updated_at
             WHERE file_id = :file_id AND revoked_at IS NULL'
        );
        $now = wb_now();
        $statement->execute([
            ':revoked_at' => $now,
            ':updated_at' => $now,
            ':file_id' => $fileId,
        ]);
    }

    public static function viewPayload(string $token, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $share = self::resolveActiveShare($token, $pdo);
        $file = self::serializeSharedFile($share);
        $previewMode = self::previewMode($file['mime_type'], $file['extension']);
        $textPreview = null;
        $textPreviewTruncated = false;

        if ($previewMode === 'text') {
            [$textPreview, $textPreviewTruncated] = self::readTextPreview(
                (string) $share['disk_name'],
                (string) $share['disk_extension']
            );
        }

        return [
            'share' => self::serializeResolvedShare($share),
            'file' => $file,
            'preview_mode' => $previewMode,
            'text_preview' => $textPreview,
            'text_preview_truncated' => $textPreviewTruncated,
        ];
    }

    public static function stream(string $token, string $disposition = 'inline', ?PDO $pdo = null): never
    {
        $pdo ??= Database::connection();
        $share = self::resolveActiveShare($token, $pdo);

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

    private static function resolveActiveShare(string $token, PDO $pdo): array
    {
        self::assertToken($token);
        $statement = $pdo->prepare(
            'SELECT
                file_shares.file_id,
                file_shares.token,
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
             WHERE file_shares.token = :token AND file_shares.revoked_at IS NULL
             LIMIT 1'
        );
        $statement->execute([':token' => $token]);
        $share = $statement->fetch();

        if (!is_array($share)) {
            throw new RuntimeException('Shared file not found.');
        }

        return $share;
    }

    private static function activeShareRow(int $fileId, PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT *
             FROM file_shares
             WHERE file_id = :file_id AND revoked_at IS NULL
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

        return [
            'file_id' => (int) $file['id'],
            'token' => (string) $share['token'],
            'url' => $urls['view'],
            'download_url' => $urls['download'],
            'created_at' => (string) $share['created_at'],
            'updated_at' => (string) $share['updated_at'],
            'revoked_at' => $share['revoked_at'] === null ? null : (string) $share['revoked_at'],
        ];
    }

    private static function serializeResolvedShare(array $share): array
    {
        $urls = self::shareUrls((string) $share['token']);

        return [
            'file_id' => (int) $share['file_id'],
            'token' => (string) $share['token'],
            'url' => $urls['view'],
            'download_url' => $urls['download'],
            'created_at' => (string) $share['share_created_at'],
            'updated_at' => (string) $share['share_updated_at'],
        ];
    }

    private static function serializeSharedFile(array $share): array
    {
        $extension = strtolower(pathinfo((string) $share['original_name'], PATHINFO_EXTENSION));
        $urls = self::shareStreamUrls((string) $share['token']);

        return [
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
        ];
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
        $base = '/api/index.php?action=share.stream&token=' . $token;

        return [
            'inline' => wb_url($base . '&disposition=inline'),
            'attachment' => wb_url($base . '&disposition=attachment'),
        ];
    }

    private static function previewMode(string $mimeType, string $extension): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if ($mimeType === 'application/pdf') {
            return 'pdf';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mimeType, 'text/') || in_array($extension, ['json', 'md', 'markdown', 'xml', 'yml', 'yaml', 'js', 'ts', 'php', 'css', 'html', 'sql'], true)) {
            return 'text';
        }

        return 'download';
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
}
