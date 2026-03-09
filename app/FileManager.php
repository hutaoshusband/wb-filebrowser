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

        if (!Permissions::canOpenFolder($folderId, $user, $pdo, $scope)) {
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

        if (Permissions::canViewFolderContents($folderId, $user, $pdo, $scope)) {
            $fileStatement = $pdo->prepare('SELECT * FROM files WHERE folder_id = :folder_id');
            $fileStatement->execute([':folder_id' => $folderId]);

            foreach ($fileStatement->fetchAll() as $file) {
                $files[] = self::serializeFile($file, $user, $pdo, $scope);
            }
        }

        self::sortEntries($folders, $sort, $direction, true);
        self::sortEntries($files, $sort, $direction, false);

        return [
            'folder' => self::serializeFolder($folder, $user, $pdo, $scope),
            'breadcrumbs' => self::buildBreadcrumbs($folderId, $scope, $pdo),
            'folders' => $folders,
            'files' => $files,
            'can_upload' => Permissions::canUploadToFolder($folderId, $user, $pdo, $scope),
            'can_create_folders' => Permissions::canCreateFoldersIn($folderId, $user, $pdo, $scope),
            'can_edit' => $folderId !== Database::rootFolderId() && Permissions::canEditFolder($folderId, $user, $pdo, $scope),
            'can_delete' => $folderId !== Database::rootFolderId() && Permissions::canDeleteFolder($folderId, $user, $pdo, $scope),
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

            $files[] = self::serializeFile($file, $user, $pdo, $scope);
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
        $pdo = Database::connection();
        $parent = self::folderById($parentId, $pdo);

        if ($parent === null) {
            throw new RuntimeException('The parent folder was not found.');
        }

        if (!Permissions::canCreateFoldersIn($parentId, $user, $pdo)) {
            throw new RuntimeException('You do not have permission to create folders here.');
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
        self::assertEditableFolder($user, $folderId);
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
        self::assertEditableFolder($user, $folderId);
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

        if (!Permissions::canEditFolder($targetParentId, $user, $pdo)) {
            throw new RuntimeException('You do not have permission to move items into that folder.');
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
        self::assertDeletableFolder($user, $folderId);
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
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canEditFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to rename files here.');
        }

        $statement = $pdo->prepare('UPDATE files SET original_name = :name, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':name' => wb_validate_entry_name($name, 'file'),
            ':updated_at' => wb_now(),
            ':id' => $fileId,
        ]);
    }

    public static function moveFile(array $user, int $fileId, int $targetFolderId): void
    {
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canEditFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to move this file.');
        }

        if (self::folderById($targetFolderId, $pdo) === null) {
            throw new RuntimeException('Destination folder not found.');
        }

        if (!Permissions::canEditFolder($targetFolderId, $user, $pdo)) {
            throw new RuntimeException('You do not have permission to move items into that folder.');
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
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canDeleteFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to delete files here.');
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
        Settings::assertUploadAllowed($originalName, $size);

        if ($size < 0) {
            throw new InvalidArgumentException('File size must be zero or greater.');
        }

        if ($totalChunks < 1) {
            throw new InvalidArgumentException('Upload must contain at least one chunk.');
        }

        self::assertWithinStorageQuota($user, $size);

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

                if (is_string($detected) && $detected !== '') {
                    $mimeType = $detected;
                }
            }
        }

        $pdo = Database::connection();
        $finalSize = (int) (filesize($finalPath) ?: 0);
        self::assertWithinStorageQuota($user, $finalSize, $token, $pdo);
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
            ':size' => $finalSize,
            ':checksum' => hash_final($hash),
            ':created_by' => $user['id'],
            ':created_at' => wb_now(),
            ':updated_at' => wb_now(),
        ]);

        self::deleteDirectory($chunkDirectory);

        $file = self::fileById((int) $pdo->lastInsertId(), $pdo);

        return self::serializeFile($file, $user, $pdo, Permissions::scope($user, $pdo));
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

        return self::serializeFile($file, $user, $pdo, Permissions::scope($user, $pdo));
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
        $total = @disk_total_space(wb_storage_path());
        
        if ($total === false) {
            $total = @disk_total_space(WB_ROOT);
        }
        if ($total === false) {
            $total = @disk_total_space('/');
        }
        if ($total === false && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $total = @disk_total_space('C:');
        }

        return [
            'used_bytes' => $used,
            'used_label' => wb_format_bytes($used),
            'total_bytes' => $total === false ? null : (int) $total,
            'total_label' => $total === false ? 'Unknown' : wb_format_bytes((int) $total),
        ];
    }

    public static function cleanupStaleUploads(int $ttlHours): int
    {
        $chunkRoot = wb_storage_path('chunks');

        if (!is_dir($chunkRoot)) {
            return 0;
        }

        $removed = 0;
        $cutoff = time() - (max(1, $ttlHours) * 3600);
        $items = scandir($chunkRoot) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $directory = $chunkRoot . DIRECTORY_SEPARATOR . $item;

            if (!is_dir($directory)) {
                continue;
            }

            $timestamp = filemtime($directory) ?: 0;
            $metaPath = $directory . DIRECTORY_SEPARATOR . 'meta.json';

            if (is_file($metaPath)) {
                $metadata = json_decode((string) file_get_contents($metaPath), true);
                $createdAt = is_array($metadata) ? strtotime((string) ($metadata['created_at'] ?? '')) : false;
                $timestamp = $createdAt === false ? $timestamp : $createdAt;
            }

            if ($timestamp === 0 || $timestamp > $cutoff) {
                continue;
            }

            self::deleteDirectory($directory);
            $removed += 1;
        }

        return $removed;
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
        $isRoot = $folderId === Database::rootFolderId();

        return [
            'id' => $folderId,
            'type' => 'folder',
            'name' => $isRoot ? 'Home' : $folder['name'],
            'parent_id' => $folder['parent_id'] === null ? null : (int) $folder['parent_id'],
            'size' => null,
            'size_label' => '-',
            'mime_type' => 'inode/directory',
            'updated_at' => $folder['updated_at'],
            'updated_relative' => wb_relative_time($folder['updated_at']),
            'child_count' => (int) $childCountStatement->fetchColumn(),
            'can_open' => $scope['all'] || in_array($folderId, $scope['ancestors'], true),
            'can_upload' => Permissions::canUploadToFolder($folderId, $user, $pdo, $scope),
            'can_create_folders' => Permissions::canCreateFoldersIn($folderId, $user, $pdo, $scope),
            'can_edit' => !$isRoot && Permissions::canEditFolder($folderId, $user, $pdo, $scope),
            'can_delete' => !$isRoot && Permissions::canDeleteFolder($folderId, $user, $pdo, $scope),
        ];
    }

    private static function serializeFile(array $file, ?array $user, PDO $pdo, array $scope): array
    {
        $extension = strtolower(pathinfo((string) $file['original_name'], PATHINFO_EXTENSION));
        $folderId = (int) $file['folder_id'];
        $preview = wb_file_preview_metadata((string) $file['mime_type'], $extension);

        return array_merge([
            'id' => (int) $file['id'],
            'type' => 'file',
            'name' => $file['original_name'],
            'folder_id' => $folderId,
            'size' => (int) $file['size'],
            'size_label' => wb_format_bytes((int) $file['size']),
            'mime_type' => $file['mime_type'],
            'updated_at' => $file['updated_at'],
            'updated_relative' => wb_relative_time($file['updated_at']),
            'checksum' => $file['checksum'],
            'extension' => $extension,
            'can_edit' => Permissions::canEditFolder($folderId, $user, $pdo, $scope),
            'can_delete' => Permissions::canDeleteFolder($folderId, $user, $pdo, $scope),
            'preview_url' => wb_url('/api/index.php?action=files.stream&id=' . (int) $file['id'] . '&disposition=inline'),
            'download_url' => wb_url('/api/index.php?action=files.stream&id=' . (int) $file['id'] . '&disposition=attachment'),
        ], $preview);
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

    private static function assertEditableFolder(array $user, int $folderId): void
    {
        if (!Permissions::canEditFolder($folderId, $user)) {
            throw new RuntimeException('You do not have permission to edit this folder.');
        }

        if ($folderId === Database::rootFolderId()) {
            throw new RuntimeException('The Home folder cannot be modified.');
        }
    }

    private static function assertDeletableFolder(array $user, int $folderId): void
    {
        if (!Permissions::canDeleteFolder($folderId, $user)) {
            throw new RuntimeException('You do not have permission to delete this folder.');
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

    private static function assertWithinStorageQuota(array $user, int $incomingBytes, ?string $excludeToken = null, ?PDO $pdo = null): void
    {
        if (($user['role'] ?? null) !== 'user') {
            return;
        }

        $pdo ??= Database::connection();
        $quota = self::storageQuotaBytesForUser((int) $user['id'], $pdo);

        if ($quota === null) {
            return;
        }

        $used = self::storageUsageBytesForUser((int) $user['id'], $pdo);
        $reserved = self::reservedUploadBytesForUser((int) $user['id'], $excludeToken);
        $projected = $used + $reserved + max(0, $incomingBytes);

        if ($projected > $quota) {
            throw new RuntimeException(sprintf(
                'This upload would exceed the user quota of %s.',
                wb_format_bytes($quota)
            ));
        }
    }

    private static function storageQuotaBytesForUser(int $userId, PDO $pdo): ?int
    {
        $statement = $pdo->prepare('SELECT storage_quota_bytes FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    private static function storageUsageBytesForUser(int $userId, PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(size), 0)
             FROM files
             WHERE created_by = :created_by'
        );
        $statement->execute([':created_by' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private static function reservedUploadBytesForUser(int $userId, ?string $excludeToken = null): int
    {
        $chunkRoot = wb_storage_path('chunks');

        if (!is_dir($chunkRoot)) {
            return 0;
        }

        $items = scandir($chunkRoot) ?: [];
        $reserved = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || ($excludeToken !== null && $item === $excludeToken)) {
                continue;
            }

            $metaPath = $chunkRoot . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . 'meta.json';

            if (!is_file($metaPath)) {
                continue;
            }

            $metadata = json_decode((string) file_get_contents($metaPath), true);

            if (!is_array($metadata) || (int) ($metadata['user_id'] ?? 0) !== $userId) {
                continue;
            }

            $reserved += max(0, (int) ($metadata['size'] ?? 0));
        }

        return $reserved;
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
