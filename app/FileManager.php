<?php

declare(strict_types=1);

namespace WbFileBrowser;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class FileManager
{
    public const CHUNK_SIZE = 2097152;

    public static function listFolder(?array $user, int $folderId, string $sort = 'name', string $direction = 'asc'): array
    {
        $pdo = Database::connection();
        $scope = Permissions::scope($user, $pdo);

        if (!Permissions::canOpenFolder($folderId, $user, $pdo)) {
            throw new RuntimeException('You do not have access to this folder.');
        }

        $folder = self::folderById($folderId, $pdo);

        if ($folder === null) {
            throw new RuntimeException('The requested folder was not found.');
        }

        $folders = [];
        $folderStatement = $pdo->prepare('SELECT * FROM folders WHERE parent_id = :parent_id');
        $folderStatement->execute([':parent_id' => $folderId]);

        foreach ($folderStatement->fetchAll() as $childFolder) {
            $childId = (int) $childFolder['id'];

            if (!$scope['all'] && !in_array($childId, $scope['ancestors'], true)) {
                continue;
            }

            $folders[] = self::serializeFolder($childFolder, $user, $pdo, $scope);
        }

        $files = [];

        if (Permissions::canViewFolderContents($folderId, $user, $pdo)) {
            $fileStatement = $pdo->prepare('SELECT * FROM files WHERE folder_id = :folder_id');
            $fileStatement->execute([':folder_id' => $folderId]);

            foreach ($fileStatement->fetchAll() as $file) {
                $files[] = self::serializeFile($file);
            }
        }

        self::sortEntries($folders, $sort, $direction, true);
        self::sortEntries($files, $sort, $direction, false);

        return [
            'folder' => self::serializeFolder($folder, $user, $pdo, $scope),
            'breadcrumbs' => self::buildBreadcrumbs($folderId, $scope, $pdo),
            'folders' => $folders,
            'files' => $files,
            'can_upload' => Permissions::canUploadToFolder($folderId, $user, $pdo),
            'can_manage' => Permissions::canManageStructure($user),
        ];
    }

    public static function search(?array $user, string $query, string $sort = 'name', string $direction = 'asc'): array
    {
        $pdo = Database::connection();
        $scope = Permissions::scope($user, $pdo);
        $query = trim($query);

        if ($query === '') {
            return [
                'folders' => [],
                'files' => [],
            ];
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

        $folders = [];
        $folderStatement = $pdo->prepare('SELECT * FROM folders WHERE name LIKE :query ESCAPE \'\\\'');
        $folderStatement->execute([':query' => $like]);

        foreach ($folderStatement->fetchAll() as $folder) {
            $folderId = (int) $folder['id'];

            if (!$scope['all'] && !in_array($folderId, $scope['ancestors'], true)) {
                continue;
            }

            $folders[] = self::serializeFolder($folder, $user, $pdo, $scope);
        }

        $files = [];
        $fileStatement = $pdo->prepare('SELECT * FROM files WHERE original_name LIKE :query ESCAPE \'\\\'');
        $fileStatement->execute([':query' => $like]);

        foreach ($fileStatement->fetchAll() as $file) {
            if (!$scope['all'] && !in_array((int) $file['folder_id'], $scope['content'], true)) {
                continue;
            }

            $files[] = self::serializeFile($file);
        }

        self::sortEntries($folders, $sort, $direction, true);
        self::sortEntries($files, $sort, $direction, false);

        return [
            'folders' => $folders,
            'files' => $files,
        ];
    }

    public static function createFolder(array $user, int $parentId, string $name): array
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can create folders.');
        }

        $pdo = Database::connection();
        $parent = self::folderById($parentId, $pdo);

        if ($parent === null) {
            throw new RuntimeException('The parent folder was not found.');
        }

        $name = wb_validate_entry_name($name, 'folder');
        $now = wb_now();
        $statement = $pdo->prepare(
            'INSERT INTO folders (parent_id, name, created_by, created_at, updated_at)
             VALUES (:parent_id, :name, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            ':parent_id' => $parentId,
            ':name' => $name,
            ':created_by' => $user['id'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $folder = self::folderById((int) $pdo->lastInsertId(), $pdo);

        return self::serializeFolder($folder, $user, $pdo, Permissions::scope($user, $pdo));
    }

    public static function renameFolder(array $user, int $folderId, string $name): void
    {
        self::assertManageableFolder($user, $folderId);
        $pdo = Database::connection();

        if (self::folderById($folderId, $pdo) === null) {
            throw new RuntimeException('Folder not found.');
        }

        $statement = $pdo->prepare('UPDATE folders SET name = :name, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':name' => wb_validate_entry_name($name, 'folder'),
            ':updated_at' => wb_now(),
            ':id' => $folderId,
        ]);
    }

    public static function moveFolder(array $user, int $folderId, int $targetParentId): void
    {
        self::assertManageableFolder($user, $folderId);
        $pdo = Database::connection();

        if ($folderId === $targetParentId) {
            throw new RuntimeException('A folder cannot be moved into itself.');
        }

        $descendants = self::descendantFolderIds($folderId, $pdo);

        if (in_array($targetParentId, $descendants, true)) {
            throw new RuntimeException('A folder cannot be moved inside one of its descendants.');
        }

        if (self::folderById($targetParentId, $pdo) === null) {
            throw new RuntimeException('Destination folder not found.');
        }

        $statement = $pdo->prepare('UPDATE folders SET parent_id = :parent_id, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':parent_id' => $targetParentId,
            ':updated_at' => wb_now(),
            ':id' => $folderId,
        ]);
    }

    public static function deleteFolder(array $user, int $folderId): void
    {
        self::assertManageableFolder($user, $folderId);
        $pdo = Database::connection();
        $descendants = self::descendantFolderIds($folderId, $pdo);
        $placeholders = implode(',', array_fill(0, count($descendants), '?'));
        $fileStatement = $pdo->prepare(
            'SELECT disk_name, disk_extension FROM files WHERE folder_id IN (' . $placeholders . ')'
        );
        $fileStatement->execute($descendants);

        foreach ($fileStatement->fetchAll() as $file) {
            self::deleteBlob((string) $file['disk_name'], (string) $file['disk_extension']);
        }

        $statement = $pdo->prepare('DELETE FROM folders WHERE id = :id');
        $statement->execute([':id' => $folderId]);
    }

    public static function renameFile(array $user, int $fileId, string $name): void
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can rename files.');
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE files SET original_name = :name, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':name' => wb_validate_entry_name($name, 'file'),
            ':updated_at' => wb_now(),
            ':id' => $fileId,
        ]);
    }

    public static function moveFile(array $user, int $fileId, int $targetFolderId): void
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can move files.');
        }

        $pdo = Database::connection();

        if (self::folderById($targetFolderId, $pdo) === null) {
            throw new RuntimeException('Destination folder not found.');
        }

        $statement = $pdo->prepare('UPDATE files SET folder_id = :folder_id, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':folder_id' => $targetFolderId,
            ':updated_at' => wb_now(),
            ':id' => $fileId,
        ]);
    }

    public static function deleteFile(array $user, int $fileId): void
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can delete files.');
        }

        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        self::deleteBlob((string) $file['disk_name'], (string) $file['disk_extension']);

        $statement = $pdo->prepare('DELETE FROM files WHERE id = :id');
        $statement->execute([':id' => $fileId]);
    }

    public static function uploadInit(array $user, int $folderId, string $originalName, int $size, string $mimeType, int $totalChunks): array
    {
        if (!Permissions::canUploadToFolder($folderId, $user)) {
            throw new RuntimeException('You do not have permission to upload to this folder.');
        }

        $originalName = wb_validate_entry_name($originalName, 'file');

        if ($size < 0) {
            throw new InvalidArgumentException('File size must be zero or greater.');
        }

        if ($totalChunks < 1) {
            throw new InvalidArgumentException('Upload must contain at least one chunk.');
        }

        $token = wb_random_token(18);
        $directory = wb_storage_path('chunks/' . $token);

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to prepare the upload workspace.');
        }

        $metadata = [
            'token' => $token,
            'folder_id' => $folderId,
            'user_id' => (int) $user['id'],
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'total_chunks' => $totalChunks,
            'created_at' => wb_now(),
        ];
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'meta.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'upload_token' => $token,
            'chunk_size' => self::CHUNK_SIZE,
        ];
    }

    public static function uploadChunk(array $user, string $token, int $index, array $fileUpload): array
    {
        $metadata = self::readUploadMetadata($token);

        if ((int) $metadata['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('This upload session does not belong to you.');
        }

        if ($index < 0 || $index >= (int) $metadata['total_chunks']) {
            throw new RuntimeException('Chunk index is out of range.');
        }

        if (!isset($fileUpload['tmp_name']) || !is_uploaded_file($fileUpload['tmp_name'])) {
            throw new RuntimeException('Upload chunk is missing.');
        }

        $targetPath = wb_storage_path('chunks/' . $token . '/' . $index . '.part');

        if (!move_uploaded_file($fileUpload['tmp_name'], $targetPath)) {
            throw new RuntimeException('Unable to store upload chunk.');
        }

        return [
            'received' => $index + 1,
            'total' => (int) $metadata['total_chunks'],
        ];
    }

    public static function uploadComplete(array $user, string $token): array
    {
        $metadata = self::readUploadMetadata($token);

        if ((int) $metadata['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('This upload session does not belong to you.');
        }

        $folderId = (int) $metadata['folder_id'];

        if (!Permissions::canUploadToFolder($folderId, $user)) {
            throw new RuntimeException('You no longer have permission to upload here.');
        }

        $chunkCount = (int) $metadata['total_chunks'];
        $chunkDirectory = wb_storage_path('chunks/' . $token);

        for ($index = 0; $index < $chunkCount; $index++) {
            if (!is_file($chunkDirectory . DIRECTORY_SEPARATOR . $index . '.part')) {
                throw new RuntimeException('Upload is incomplete.');
            }
        }

        $diskName = wb_random_token(16);
        $diskExtension = 'blob';
        $finalDirectory = wb_storage_path('uploads/' . substr($diskName, 0, 2) . '/' . substr($diskName, 2, 2));

        if (!is_dir($finalDirectory) && !mkdir($finalDirectory, 0775, true) && !is_dir($finalDirectory)) {
            throw new RuntimeException('Unable to create the target file directory.');
        }

        $finalPath = $finalDirectory . DIRECTORY_SEPARATOR . $diskName . '.' . $diskExtension;
        $output = fopen($finalPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to create the final file.');
        }

        $hash = hash_init('sha256');

        try {
            for ($index = 0; $index < $chunkCount; $index++) {
                $partPath = $chunkDirectory . DIRECTORY_SEPARATOR . $index . '.part';
                $input = fopen($partPath, 'rb');

                if ($input === false) {
                    throw new RuntimeException('Unable to read upload chunk.');
                }

                while (!feof($input)) {
                    $buffer = fread($input, 8192);

                    if ($buffer === false) {
                        fclose($input);
                        throw new RuntimeException('Unable to read upload chunk.');
                    }

                    fwrite($output, $buffer);
                    hash_update($hash, $buffer);
                }

                fclose($input);
            }
        } finally {
            fclose($output);
        }

        $mimeType = (string) ($metadata['mime_type'] ?? 'application/octet-stream');

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $finalPath);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    $mimeType = $detected;
                }
            }
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO files (folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum, created_by, created_at, updated_at)
             VALUES (:folder_id, :original_name, :disk_name, :disk_extension, :mime_type, :size, :checksum, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            ':folder_id' => $folderId,
            ':original_name' => $metadata['original_name'],
            ':disk_name' => $diskName,
            ':disk_extension' => $diskExtension,
            ':mime_type' => $mimeType,
            ':size' => filesize($finalPath) ?: 0,
            ':checksum' => hash_final($hash),
            ':created_by' => $user['id'],
            ':created_at' => wb_now(),
            ':updated_at' => wb_now(),
        ]);

        self::deleteDirectory($chunkDirectory);

        $file = self::fileById((int) $pdo->lastInsertId(), $pdo);

        return self::serializeFile($file);
    }

    public static function uploadCancel(array $user, string $token): void
    {
        $metadata = self::readUploadMetadata($token);

        if ((int) $metadata['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('This upload session does not belong to you.');
        }

        self::deleteDirectory(wb_storage_path('chunks/' . $token));
    }

    public static function fileDetails(?array $user, int $fileId): array
    {
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canViewFolderContents((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have access to this file.');
        }

        return self::serializeFile($file);
    }

    public static function streamFile(?array $user, int $fileId, string $disposition = 'inline'): never
    {
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            http_response_code(404);
            exit;
        }

        if (!Permissions::canViewFolderContents((int) $file['folder_id'], $user, $pdo)) {
            http_response_code(403);
            exit;
        }

        Security::sendFile(
            self::blobPath((string) $file['disk_name'], (string) $file['disk_extension']),
            (string) $file['mime_type'],
            (string) $file['original_name'],
            $disposition
        );
    }

    public static function storageStats(): array
    {
        $pdo = Database::connection();
        $used = (int) $pdo->query('SELECT COALESCE(SUM(size), 0) FROM files')->fetchColumn();
        $total = disk_total_space(wb_storage_path());

        return [
            'used_bytes' => $used,
            'used_label' => wb_format_bytes($used),
            'total_bytes' => $total === false ? null : (int) $total,
            'total_label' => $total === false ? 'Unknown' : wb_format_bytes((int) $total),
        ];
    }

    public static function folderTree(?array $user = null): array
    {
        $pdo = Database::connection();
        $scope = Permissions::scope($user, $pdo);
        $folders = $pdo->query('SELECT * FROM folders ORDER BY name ASC')->fetchAll();
        $allowed = $scope['all'] ? null : array_flip($scope['ancestors']);
        $nodes = [];

        foreach ($folders as $folder) {
            $id = (int) $folder['id'];

            if ($allowed !== null && !isset($allowed[$id])) {
                continue;
            }

            $nodes[] = self::serializeFolder($folder, $user, $pdo, $scope);
        }

        return $nodes;
    }

    private static function folderById(int $folderId, PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM folders WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $folderId]);
        $folder = $statement->fetch();

        return is_array($folder) ? $folder : null;
    }

    private static function fileById(int $fileId, PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $fileId]);
        $file = $statement->fetch();

        return is_array($file) ? $file : null;
    }

    private static function buildBreadcrumbs(int $folderId, array $scope, PDO $pdo): array
    {
        $breadcrumbs = [];
        $current = self::folderById($folderId, $pdo);

        while ($current !== null) {
            $currentId = (int) $current['id'];

            if ($scope['all'] || in_array($currentId, $scope['ancestors'], true)) {
                $breadcrumbs[] = [
                    'id' => $currentId,
                    'name' => $currentId === Database::rootFolderId() ? 'Home' : $current['name'],
                ];
            }

            $parentId = $current['parent_id'] === null ? null : (int) $current['parent_id'];
            $current = $parentId === null ? null : self::folderById($parentId, $pdo);
        }

        return array_reverse($breadcrumbs);
    }

    private static function serializeFolder(array $folder, ?array $user, PDO $pdo, array $scope): array
    {
        $folderId = (int) $folder['id'];
        $childCountStatement = $pdo->prepare('SELECT COUNT(*) FROM folders WHERE parent_id = :parent_id');
        $childCountStatement->execute([':parent_id' => $folderId]);

        return [
            'id' => $folderId,
            'type' => 'folder',
            'name' => $folderId === Database::rootFolderId() ? 'Home' : $folder['name'],
            'parent_id' => $folder['parent_id'] === null ? null : (int) $folder['parent_id'],
            'size' => null,
            'size_label' => '-',
            'mime_type' => 'inode/directory',
            'updated_at' => $folder['updated_at'],
            'updated_relative' => wb_relative_time($folder['updated_at']),
            'child_count' => (int) $childCountStatement->fetchColumn(),
            'can_open' => $scope['all'] || in_array($folderId, $scope['ancestors'], true),
            'can_upload' => Permissions::canUploadToFolder($folderId, $user, $pdo),
            'can_manage' => Permissions::canManageStructure($user),
        ];
    }

    private static function serializeFile(array $file): array
    {
        $extension = strtolower(pathinfo((string) $file['original_name'], PATHINFO_EXTENSION));

        return [
            'id' => (int) $file['id'],
            'type' => 'file',
            'name' => $file['original_name'],
            'folder_id' => (int) $file['folder_id'],
            'size' => (int) $file['size'],
            'size_label' => wb_format_bytes((int) $file['size']),
            'mime_type' => $file['mime_type'],
            'updated_at' => $file['updated_at'],
            'updated_relative' => wb_relative_time($file['updated_at']),
            'checksum' => $file['checksum'],
            'extension' => $extension,
            'preview_url' => wb_url('/api/index.php?action=files.stream&id=' . (int) $file['id'] . '&disposition=inline'),
            'download_url' => wb_url('/api/index.php?action=files.stream&id=' . (int) $file['id'] . '&disposition=attachment'),
        ];
    }

    private static function sortEntries(array &$entries, string $sort, string $direction, bool $folders): void
    {
        $direction = strtolower($direction) === 'desc' ? -1 : 1;
        $sort = in_array($sort, ['name', 'size', 'updated_at'], true) ? $sort : 'name';

        usort($entries, static function (array $left, array $right) use ($sort, $direction, $folders): int {
            $leftValue = $left[$sort] ?? null;
            $rightValue = $right[$sort] ?? null;

            if ($sort === 'name') {
                return $direction * strnatcasecmp((string) $leftValue, (string) $rightValue);
            }

            if ($sort === 'size') {
                $leftValue = $leftValue ?? ($folders ? -1 : 0);
                $rightValue = $rightValue ?? ($folders ? -1 : 0);
            }

            return $direction * ($leftValue <=> $rightValue);
        });
    }

    private static function assertManageableFolder(array $user, int $folderId): void
    {
        if (!Permissions::canManageStructure($user)) {
            throw new RuntimeException('Only administrators can manage folders.');
        }

        if ($folderId === Database::rootFolderId()) {
            throw new RuntimeException('The Home folder cannot be modified.');
        }
    }

    private static function descendantFolderIds(int $folderId, PDO $pdo): array
    {
        $statement = $pdo->query('SELECT id, parent_id FROM folders');
        $childrenMap = [];

        foreach ($statement->fetchAll() as $folder) {
            $parentId = $folder['parent_id'] === null ? 0 : (int) $folder['parent_id'];
            $childrenMap[$parentId][] = (int) $folder['id'];
        }

        $stack = [$folderId];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($childrenMap[$current] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_map('intval', array_keys($seen));
    }

    private static function readUploadMetadata(string $token): array
    {
        if (!ctype_xdigit($token)) {
            throw new RuntimeException('Upload session token is invalid.');
        }

        $path = wb_storage_path('chunks/' . $token . '/meta.json');

        if (!is_file($path)) {
            throw new RuntimeException('Upload session not found.');
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload)) {
            throw new RuntimeException('Upload metadata is corrupted.');
        }

        return $payload;
    }

    private static function blobPath(string $diskName, string $diskExtension): string
    {
        return wb_storage_path('uploads/' . substr($diskName, 0, 2) . '/' . substr($diskName, 2, 2) . '/' . $diskName . '.' . $diskExtension);
    }

    private static function deleteBlob(string $diskName, string $diskExtension): void
    {
        $path = self::blobPath($diskName, $diskExtension);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                self::deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
