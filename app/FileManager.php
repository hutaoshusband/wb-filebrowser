<?php

declare(strict_types=1);

namespace WbFileBrowser;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class FileManager
{
    public const CHUNK_SIZE = 2097152;
    public const MSG_RATE_LIMIT_UPLOAD = 'Too many upload attempts. Please wait a few minutes and try again.';

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
        $folderRows = [];
        $folderStatement = $pdo->prepare('SELECT * FROM folders WHERE parent_id = :parent_id');
        $folderStatement->execute([':parent_id' => $folderId]);

        foreach ($folderStatement->fetchAll() as $childFolder) {
            $childId = (int) $childFolder['id'];

            if (!$scope['all'] && !in_array($childId, $scope['ancestors'], true)) {
                continue;
            }

            $folderRows[] = $childFolder;
        }

        $childCounts = self::getChildCounts(
            array_merge([$folderId], array_map(static fn (array $f): int => (int) $f['id'], $folderRows)),
            $pdo
        );

        foreach ($folderRows as $childFolder) {
            $folders[] = self::serializeFolder(
                $childFolder,
                $user,
                $pdo,
                $scope,
                $childCounts[(int) $childFolder['id']] ?? 0
            );
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
            'folder' => self::serializeFolder($folder, $user, $pdo, $scope, $childCounts[$folderId] ?? 0),
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
        $folderRows = [];
        $folderStatement = $pdo->prepare('SELECT * FROM folders WHERE name LIKE :query ESCAPE \'\\\'');
        $folderStatement->execute([':query' => $like]);

        foreach ($folderStatement->fetchAll() as $folder) {
            $folderId = (int) $folder['id'];

            if (!$scope['all'] && !in_array($folderId, $scope['ancestors'], true)) {
                continue;
            }

            $folderRows[] = $folder;
        }

        $childCounts = self::getChildCounts(
            array_map(static fn (array $f): int => (int) $f['id'], $folderRows),
            $pdo
        );

        foreach ($folderRows as $folder) {
            $folders[] = self::serializeFolder(
                $folder,
                $user,
                $pdo,
                $scope,
                $childCounts[(int) $folder['id']] ?? 0
            );
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

        $folder = self::folderById(Database::lastInsertId($pdo, 'folders'), $pdo);

        if ($folder !== null) {
            AuditLog::record('folder.create', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'folder',
                'target_id' => (int) $folder['id'],
                'target_label' => self::folderPathLabelFromRow($folder, $pdo),
                'summary' => 'Created folder ' . $folder['name'],
            ], $pdo);
        }

        return self::serializeFolder($folder, $user, $pdo, Permissions::scope($user, $pdo));
    }

    /**
     * @param array<int, string> $pathSegments
     */
    public static function ensureFolderPath(array $user, int $parentId, array $pathSegments): array
    {
        $pdo = Database::connection();
        $parent = self::folderById($parentId, $pdo);

        if ($parent === null) {
            throw new RuntimeException('The parent folder was not found.');
        }

        $currentFolderId = $parentId;

        foreach ($pathSegments as $segment) {
            $name = wb_validate_entry_name((string) $segment, 'folder');
            $existing = self::childFolderByName($currentFolderId, $name, $pdo);

            if ($existing !== null) {
                $currentFolderId = (int) $existing['id'];
                continue;
            }

            if (!Permissions::canCreateFoldersIn($currentFolderId, $user, $pdo)) {
                throw new RuntimeException('You do not have permission to create folders here.');
            }

            $created = self::createFolder($user, $currentFolderId, $name);
            $currentFolderId = (int) $created['id'];
        }

        $resolved = self::folderById($currentFolderId, $pdo);

        if ($resolved === null) {
            throw new RuntimeException('The requested folder was not found.');
        }

        return self::serializeFolder($resolved, $user, $pdo, Permissions::scope($user, $pdo));
    }

    public static function renameFolder(array $user, int $folderId, string $name): void
    {
        self::assertEditableFolder($user, $folderId);
        SpaceService::assertNotSpaceRoot($folderId, 'rename');
        $pdo = Database::connection();
        $folder = self::folderById($folderId, $pdo);

        if ($folder === null) {
            throw new RuntimeException('Folder not found.');
        }

        $statement = $pdo->prepare('UPDATE folders SET name = :name, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':name' => wb_validate_entry_name($name, 'folder'),
            ':updated_at' => wb_now(),
            ':id' => $folderId,
        ]);
        $updatedFolder = self::folderById($folderId, $pdo);

        if ($updatedFolder !== null) {
            AuditLog::record('folder.rename', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'folder',
                'target_id' => $folderId,
                'target_label' => self::folderPathLabelFromRow($updatedFolder, $pdo),
                'summary' => 'Renamed folder to ' . $updatedFolder['name'],
                'metadata' => [
                    'previous_name' => (string) $folder['name'],
                ],
            ], $pdo);
        }
    }

    public static function moveFolder(array $user, int $folderId, int $targetParentId): void
    {
        $storageLock = new StorageLock();
        self::assertEditableFolder($user, $folderId);
        SpaceService::assertNotSpaceRoot($folderId, 'move');
        SpaceService::assertSameSpaceOrAdmin($folderId, $targetParentId, $user);
        $pdo = Database::connection();
        $folder = self::folderById($folderId, $pdo);

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

        $crossSpace = SpaceService::spaceRootIdForFolder($folderId, $pdo) !== SpaceService::spaceRootIdForFolder($targetParentId, $pdo);
        if ($crossSpace) {
            SpaceService::assertWithinSpaceQuota($targetParentId, SpaceService::transferBytes($folderId, $pdo), $pdo);
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('UPDATE folders SET parent_id = :parent_id, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                ':parent_id' => $targetParentId,
                ':updated_at' => wb_now(),
                ':id' => $folderId,
            ]);
            if ($crossSpace) {
                $placeholders = implode(',', array_fill(0, count($descendants), '?'));
                $pdo->prepare('DELETE FROM folder_permissions WHERE folder_id IN (' . $placeholders . ')')->execute($descendants);
                $pdo->prepare('DELETE FROM file_shares WHERE file_id IN (SELECT id FROM files WHERE folder_id IN (' . $placeholders . '))')->execute($descendants);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $updatedFolder = self::folderById($folderId, $pdo);

        if ($updatedFolder !== null) {
            AuditLog::record('folder.move', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'folder',
                'target_id' => $folderId,
                'target_label' => self::folderPathLabelFromRow($updatedFolder, $pdo),
                'summary' => 'Moved folder ' . $updatedFolder['name'],
                'metadata' => [
                    'from' => $folder === null ? null : self::folderPathLabelFromRow($folder, $pdo),
                ],
            ], $pdo);
        }
    }

    public static function deleteFolder(array $user, int $folderId, bool $systemPurge = false): void
    {
        $storageLock = new StorageLock();
        self::assertDeletableFolder($user, $folderId);

        if ($systemPurge && ($user['role'] ?? '') !== 'super_admin') {
            throw new RuntimeException('Only the Super-Admin can purge spaces.');
        }
        if (!$systemPurge) {
            SpaceService::assertNotSpaceRoot($folderId, 'delete');
        }

        $pdo = Database::connection();
        $folder = self::folderById($folderId, $pdo);
        $descendants = self::descendantFolderIds($folderId, $pdo);
        $folderLabel = $folder === null ? 'Unknown folder' : self::folderPathLabelFromRow($folder, $pdo);
        $placeholders = implode(',', array_fill(0, count($descendants), '?'));
        $fileStatement = $pdo->prepare(
            'SELECT * FROM files WHERE folder_id IN (' . $placeholders . ')'
        );
        $fileStatement->execute($descendants);
        $fileRows = $fileStatement->fetchAll();

        // Rows first (cascades included), blob unlinks after the commit.
        $pdo->beginTransaction();

        try {
            $blobReferences = [];

            foreach ($fileRows as $file) {
                foreach (self::detachFileReference($pdo, $file) as $reference) {
                    $blobReferences[] = $reference;
                }
            }

            $statement = $pdo->prepare('DELETE FROM folders WHERE id = :id');
            $statement->execute([':id' => $folderId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        foreach ($blobReferences as $reference) {
            $path = self::blobPath((string) $reference['disk_name'], (string) $reference['disk_extension']);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        AuditLog::record('folder.delete', 'deletions', [
            'actor_user' => $user,
            'target_type' => 'folder',
            'target_id' => $folderId,
            'target_label' => $folderLabel,
            'summary' => 'Deleted folder ' . $folderLabel,
            'metadata' => [
                'descendant_count' => count($descendants),
            ],
        ], $pdo);
    }

    public static function saveFolderDescription(array $user, int $folderId, string $description): array
    {
        self::assertEditableFolder($user, $folderId);
        $pdo = Database::connection();
        $folder = self::folderById($folderId, $pdo);

        if ($folder === null) {
            throw new RuntimeException('Folder not found.');
        }

        $normalized = self::normalizeDescription($description);
        $statement = $pdo->prepare(
            'UPDATE folders SET description = :description, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':description' => $normalized,
            ':updated_at' => wb_now(),
            ':id' => $folderId,
        ]);

        $updatedFolder = self::folderById($folderId, $pdo);

        if ($updatedFolder !== null) {
            AuditLog::record('folder.description.update', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'folder',
                'target_id' => $folderId,
                'target_label' => self::folderPathLabelFromRow($updatedFolder, $pdo),
                'summary' => 'Updated folder description for ' . $updatedFolder['name'],
            ], $pdo);

            return self::serializeFolder($updatedFolder, $user, $pdo, Permissions::scope($user, $pdo));
        }

        throw new RuntimeException('Folder not found.');
    }

    /**
     * Files created by an administrator are locked for every other account
     * except their owner and the Super-Admin: they stay visible and shareable
     * by link, but cannot be modified.
     */
    public static function fileIsLockedFor(array $file, ?array $user, PDO $pdo): bool
    {
        if ($user === null) {
            return false;
        }

        $creatorId = (int) ($file['created_by'] ?? 0);

        if ($creatorId === 0 || $creatorId === (int) $user['id']) {
            return false;
        }

        if (($user['role'] ?? '') === 'super_admin') {
            return false;
        }

        static $roleCache = [];

        if (!isset($roleCache[$creatorId])) {
            $statement = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $statement->execute([':id' => $creatorId]);
            $roleCache[$creatorId] = (string) ($statement->fetchColumn() ?: '');
        }

        return in_array($roleCache[$creatorId], ['admin', 'super_admin'], true);
    }

    public static function assertFileNotLockedFor(array $file, ?array $user, PDO $pdo): void
    {
        if (self::fileIsLockedFor($file, $user, $pdo)) {
            throw new RuntimeException('This file is locked by its administrator owner.');
        }
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
        self::assertFileNotLockedFor($file, $user, $pdo);

        $statement = $pdo->prepare('UPDATE files SET original_name = :name, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            ':name' => wb_validate_entry_name($name, 'file'),
            ':updated_at' => wb_now(),
            ':id' => $fileId,
        ]);
        $updatedFile = self::fileById($fileId, $pdo);

        if ($updatedFile !== null) {
            AuditLog::record('file.rename', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'file',
                'target_id' => $fileId,
                'target_label' => self::filePathLabel($updatedFile, $pdo),
                'summary' => 'Renamed file to ' . $updatedFile['original_name'],
                'metadata' => [
                    'previous_name' => (string) $file['original_name'],
                ],
            ], $pdo);
        }
    }

    public static function moveFile(array $user, int $fileId, int $targetFolderId): void
    {
        $storageLock = new StorageLock();
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canEditFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to move this file.');
        }
        self::assertFileNotLockedFor($file, $user, $pdo);

        if (self::folderById($targetFolderId, $pdo) === null) {
            throw new RuntimeException('Destination folder not found.');
        }

        if (!Permissions::canEditFolder($targetFolderId, $user, $pdo)) {
            throw new RuntimeException('You do not have permission to move items into that folder.');
        }

        SpaceService::assertSameSpaceOrAdmin((int) $file['folder_id'], $targetFolderId, $user);

        $crossSpace = SpaceService::spaceRootIdForFolder((int) $file['folder_id'], $pdo) !== SpaceService::spaceRootIdForFolder($targetFolderId, $pdo);
        if ($crossSpace) {
            SpaceService::assertWithinSpaceQuota($targetFolderId, (int) $file['size'], $pdo);
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('UPDATE files SET folder_id = :folder_id, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                ':folder_id' => $targetFolderId,
                ':updated_at' => wb_now(),
                ':id' => $fileId,
            ]);
            if ($crossSpace) {
                $pdo->prepare('DELETE FROM file_shares WHERE file_id = :id')->execute([':id' => $fileId]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $updatedFile = self::fileById($fileId, $pdo);

        if ($updatedFile !== null) {
            AuditLog::record('file.move', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'file',
                'target_id' => $fileId,
                'target_label' => self::filePathLabel($updatedFile, $pdo),
                'summary' => 'Moved file ' . $updatedFile['original_name'],
                'metadata' => [
                    'from' => self::filePathLabel($file, $pdo),
                ],
            ], $pdo);
        }
    }

    public static function deleteFile(array $user, int $fileId): void
    {
        $storageLock = new StorageLock();
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canDeleteFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to delete files here.');
        }
        self::assertFileNotLockedFor($file, $user, $pdo);

        $fileLabel = self::filePathLabel($file, $pdo);

        // The blob unlink happens after the transaction commits so a rollback
        // can never leave a committed row without its bytes.
        $pdo->beginTransaction();

        try {
            $blobReferences = self::detachFileReference($pdo, $file);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        foreach ($blobReferences as $reference) {
            $path = self::blobPath((string) $reference['disk_name'], (string) $reference['disk_extension']);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        AuditLog::record('file.delete', 'deletions', [
            'actor_user' => $user,
            'target_type' => 'file',
            'target_id' => $fileId,
            'target_label' => $fileLabel,
            'summary' => 'Deleted file ' . $file['original_name'],
        ], $pdo);
    }

    /**
     * Deletes a files row without permission checks, transaction handling or
     * filesystem work and returns the physical blob references that were
     * attached to it. Callers unlink the returned entries only after their
     * own surrounding transaction has committed. Rows that share a
     * deduplicated blob only release their reference here; the blob row and
     * its file disappear when the last reference is gone.
     *
     * @return array<int, array{disk_name: string, disk_extension: string, deduped: bool}>
     */
    public static function detachFileReference(PDO $pdo, array $file): array
    {
        $statement = $pdo->prepare('DELETE FROM files WHERE id = :id');
        $statement->execute([':id' => (int) $file['id']]);

        if ($statement->rowCount() === 0) {
            return [];
        }

        $blobId = $file['blob_id'] ?? null;

        if ($blobId !== null) {
            return self::decrementBlobReference($pdo, (int) $blobId);
        }

        return [
            [
                'disk_name' => (string) $file['disk_name'],
                'disk_extension' => (string) $file['disk_extension'],
                'deduped' => false,
            ],
        ];
    }

    /**
     * Decrements a blob's reference count and deletes the blob row once the
     * last reference is gone. The UPDATE/DELETE pair is atomic per row, so
     * exactly one concurrent deleter observes the zero count.
     *
     * @return array<int, array{disk_name: string, disk_extension: string, deduped: bool}>
     */
    private static function decrementBlobReference(PDO $pdo, int $blobId): array
    {
        $statement = $pdo->prepare(
            'UPDATE file_blobs SET ref_count = ref_count - 1 WHERE id = :id'
        );
        $statement->execute([':id' => $blobId]);

        $select = $pdo->prepare('SELECT ref_count, disk_name, disk_extension FROM file_blobs WHERE id = :id LIMIT 1');
        $select->execute([':id' => $blobId]);
        $blob = $select->fetch();

        if ($blob === false) {
            return [];
        }

        if ((int) $blob['ref_count'] > 0) {
            return [];
        }

        $delete = $pdo->prepare('DELETE FROM file_blobs WHERE id = :id AND ref_count <= 0');
        $delete->execute([':id' => $blobId]);

        return [
            [
                'disk_name' => (string) $blob['disk_name'],
                'disk_extension' => (string) $blob['disk_extension'],
                'deduped' => true,
            ],
        ];
    }

    /**
     * Resolves the physical location for a files row: deduplicated rows read
     * through their file_blobs record, classic rows own their blob. Falls
     * back to the row's own disk name when the blob record is missing, so a
     * damaged blob row cannot hide an otherwise readable classic file.
     *
     * @return array{disk_name: string, disk_extension: string}
     */
    public static function blobLocation(PDO $pdo, array $file): array
    {
        $blobId = $file['blob_id'] ?? null;

        if ($blobId !== null) {
            $select = $pdo->prepare('SELECT disk_name, disk_extension FROM file_blobs WHERE id = :id LIMIT 1');
            $select->execute([':id' => (int) $blobId]);
            $blob = $select->fetch();

            if ($blob !== false) {
                return [
                    'disk_name' => (string) $blob['disk_name'],
                    'disk_extension' => (string) $blob['disk_extension'],
                ];
            }
        }

        return [
            'disk_name' => (string) $file['disk_name'],
            'disk_extension' => (string) $file['disk_extension'],
        ];
    }

    public static function blobPathFor(string $diskName, string $diskExtension): string
    {
        return self::blobPath($diskName, $diskExtension);
    }

    /**
     * Public wrapper around the descendant walk, used by SpaceService for
     * space-scoped aggregates.
     *
     * @return array<int, int>
     */
    public static function descendantFolderIdsForSpace(int $folderId, ?PDO $pdo = null): array
    {
        return self::descendantFolderIds($folderId, $pdo ?? Database::connection());
    }

    public static function saveFileDescription(array $user, int $fileId, string $description): array
    {
        $pdo = Database::connection();
        $file = self::fileById($fileId, $pdo);

        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        if (!Permissions::canEditFolder((int) $file['folder_id'], $user, $pdo)) {
            throw new RuntimeException('You do not have permission to edit this file.');
        }
        self::assertFileNotLockedFor($file, $user, $pdo);

        $normalized = self::normalizeDescription($description);
        $statement = $pdo->prepare(
            'UPDATE files SET description = :description, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':description' => $normalized,
            ':updated_at' => wb_now(),
            ':id' => $fileId,
        ]);

        $updatedFile = self::fileById($fileId, $pdo);

        if ($updatedFile !== null) {
            AuditLog::record('file.description.update', 'file_management', [
                'actor_user' => $user,
                'target_type' => 'file',
                'target_id' => $fileId,
                'target_label' => self::filePathLabel($updatedFile, $pdo),
                'summary' => 'Updated file description for ' . $updatedFile['original_name'],
            ], $pdo);

            return self::serializeFile($updatedFile, $user, $pdo, Permissions::scope($user, $pdo));
        }

        throw new RuntimeException('File not found.');
    }

    /**
     * @param array<int, string> $relativePathSegments
     */
    public static function uploadInit(
        array $user,
        int $folderId,
        string $originalName,
        int $size,
        string $mimeType,
        int $totalChunks,
        array $relativePathSegments = []
    ): array
    {
        $storageLock = new StorageLock();
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

        self::assertDeclaredChunkCountMatchesSize($size, $totalChunks);
        self::assertWithinStorageQuota($user, $size);
        SpaceService::assertWithinSpaceQuota($folderId, $size);
        self::assertVideoUploadCanBeVerified($originalName, $size, $mimeType);

        if ($relativePathSegments !== []) {
            $folder = self::ensureFolderPath($user, $folderId, $relativePathSegments);
            $folderId = (int) $folder['id'];
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

        try {
            if (!move_uploaded_file($fileUpload['tmp_name'], $targetPath)) {
                throw new RuntimeException('Failed to write chunk');
            }

            self::assertChunkFileSize($targetPath, $metadata, $index);
        } catch (\Exception $e) {
            @unlink($targetPath);
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Failed to write chunk', 0, $e);
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
            $partPath = $chunkDirectory . DIRECTORY_SEPARATOR . $index . '.part';

            if (!is_file($partPath)) {
                throw new RuntimeException('Upload is incomplete.');
            }

            self::assertChunkFileSize($partPath, $metadata, $index);
        }

        $diskName = wb_random_token(16);
        $diskExtension = 'blob';
        $finalDirectory = wb_storage_path('uploads/' . substr($diskName, 0, 2) . '/' . substr($diskName, 2, 2));

        if (!is_dir($finalDirectory) && !mkdir($finalDirectory, 0775, true) && !is_dir($finalDirectory)) {
            throw new RuntimeException('Unable to create the target file directory.');
        }

        $finalPath = $finalDirectory . DIRECTORY_SEPARATOR . $diskName . '.' . $diskExtension;
        $selfHealedBlobPath = null;
        $committed = false;
        $output = fopen($finalPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to create the final file.');
        }

        $hash = hash_init('sha256');

        try {
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

            if ($finalSize !== (int) $metadata['size']) {
                throw new RuntimeException('Upload size does not match the declared size.');
            }

            Settings::assertUploadAllowed((string) $metadata['original_name'], $finalSize, $pdo);

            try {
                MediaValidator::assertAcceptedVideoUpload(
                    $finalPath,
                    (string) $metadata['original_name'],
                    $finalSize,
                    $mimeType,
                    $pdo
                );
            } catch (RuntimeException $exception) {
                AuditLog::record('file.upload_rejected', 'file_uploads', [
                    'actor_user' => $user,
                    'target_type' => 'file',
                    'target_id' => 0,
                    'target_label' => (string) $metadata['original_name'],
                    'summary' => 'Rejected non-compliant video upload ' . $metadata['original_name'],
                    'metadata' => [
                        'size' => $finalSize,
                        'mime_type' => $mimeType,
                        'reason' => $exception->getMessage(),
                    ],
                ], $pdo);

                throw $exception;
            }

            $storageLock = new StorageLock();
            // Another completion may have consumed this token while bytes were assembled.
            self::readUploadMetadata($token);
            if (!Permissions::canUploadToFolder($folderId, $user, $pdo)) {
                throw new RuntimeException('You no longer have permission to upload here.');
            }
            self::assertWithinStorageQuota($user, $finalSize, $token, $pdo);
            SpaceService::assertWithinSpaceQuota($folderId, $finalSize, $pdo, $token);
            $checksum = hash_final($hash);
            $dedupEnabled = Settings::dedupEnabled($pdo);

            // Deduplication: DB work happens in one short transaction; the
            // staged file at $finalPath either becomes the blob (new content)
            // or is unlinked (duplicate). The catch-all below must only
            // unlink paths that are not yet committed as a shared blob.
            $selfHealedBlobPath = null;

            if ($dedupEnabled) {
                $attempts = 0;
                $reusedExistingBlob = false;

                while (true) {
                    $attempts++;
                    $pdo->beginTransaction();

                    try {
                        $select = $pdo->prepare(
                            'SELECT id, disk_name, disk_extension FROM file_blobs
                             WHERE checksum = :checksum AND size = :size LIMIT 1'
                        );
                        $select->execute([':checksum' => $checksum, ':size' => $finalSize]);
                        $existingBlob = $select->fetch();

                        if ($existingBlob !== false) {
                            $blobId = (int) $existingBlob['id'];
                            $existingPath = self::blobPath(
                                (string) $existingBlob['disk_name'],
                                (string) $existingBlob['disk_extension']
                            );

                            if (!is_file($existingPath)) {
                                // Self-heal: the blob record outlived its
                                // file (crash or failed unlink). Adopt the
                                // freshly assembled copy and keep it even if
                                // this upload later fails — it repairs the
                                // broken references.
                                if (!@rename($finalPath, $existingPath)) {
                                    throw new RuntimeException('Unable to restore the deduplicated file.');
                                }

                                $selfHealedBlobPath = $existingPath;
                                $reusedExistingBlob = true;
                                $pdo->prepare(
                                    'UPDATE file_blobs SET ref_count = ref_count + 1 WHERE id = :id'
                                )->execute([':id' => $blobId]);
                            } else {
                                $reusedExistingBlob = true;
                                $pdo->prepare(
                                    'UPDATE file_blobs SET ref_count = ref_count + 1 WHERE id = :id'
                                )->execute([':id' => $blobId]);
                            }

                            $fileId = self::insertFileRow($pdo, $user, $folderId, $metadata, $mimeType, $finalSize, $checksum, $blobId, null);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO file_blobs (checksum, disk_name, disk_extension, size, ref_count, created_at)
                                 VALUES (:checksum, :disk_name, :disk_extension, :size, 1, :created_at)'
                            )->execute([
                                ':checksum' => $checksum,
                                ':disk_name' => $diskName,
                                ':disk_extension' => $diskExtension,
                                ':size' => $finalSize,
                                ':created_at' => wb_now(),
                            ]);

                            $blobId = Database::lastInsertId($pdo, 'file_blobs');
                            $fileId = self::insertFileRow($pdo, $user, $folderId, $metadata, $mimeType, $finalSize, $checksum, $blobId, $diskName);
                        }

                        $pdo->commit();
                        $committed = true;
                        break;
                    } catch (\Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $duplicateBlob = $exception instanceof PDOException
                            && $selfHealedBlobPath === null
                            && self::isUniqueConstraintViolation($exception);

                        if ($duplicateBlob && $attempts < 3) {
                            // Keep the staged bytes until a retry has committed.
                            continue;
                        }

                        throw $exception;
                    }
                }

                if ($reusedExistingBlob && $selfHealedBlobPath === null) {
                    // The duplicate copy we assembled is unreferenced now.
                    @unlink($finalPath);
                }
            } else {
                $pdo->beginTransaction();

                try {
                    $fileId = self::insertFileRow($pdo, $user, $folderId, $metadata, $mimeType, $finalSize, $checksum, null, $diskName);
                    $pdo->commit();
                    $committed = true;
                } catch (\Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $exception;
                }
            }

            self::deleteDirectory($chunkDirectory);

            $file = self::fileById($fileId, $pdo);

            if ($file !== null) {
                AuditLog::record('file.upload', 'file_uploads', [
                    'actor_user' => $user,
                    'target_type' => 'file',
                    'target_id' => (int) $file['id'],
                    'target_label' => self::filePathLabel($file, $pdo),
                    'summary' => 'Uploaded file ' . $file['original_name'],
                    'metadata' => [
                        'size' => (int) $file['size'],
                        'mime_type' => (string) $file['mime_type'],
                        'deduplicated' => $dedupEnabled && $file['blob_id'] !== null && (int) $file['blob_id'] !== 0,
                    ],
                ], $pdo);

                return self::serializeFile($file, $user, $pdo, Permissions::scope($user, $pdo));
            }
        } catch (\Throwable $exception) {
            if (!$committed && $selfHealedBlobPath === null) {
                @unlink($finalPath);
            }
            throw $exception;
        }

        throw new RuntimeException('Upload failed.');
    }

    private static function insertFileRow(
        PDO $pdo,
        array $user,
        int $folderId,
        array $metadata,
        string $mimeType,
        int $finalSize,
        string $checksum,
        ?int $blobId,
        ?string $diskName
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO files (folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum, blob_id, created_by, created_at, updated_at)
             VALUES (:folder_id, :original_name, :disk_name, :disk_extension, :mime_type, :size, :checksum, :blob_id, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            ':folder_id' => $folderId,
            ':original_name' => $metadata['original_name'],
            // Deduplicated rows read their bytes through the blob record, so
            // this fresh name stays vestigial for them.
            ':disk_name' => $diskName ?? wb_random_token(16),
            ':disk_extension' => 'blob',
            ':mime_type' => $mimeType,
            ':size' => $finalSize,
            ':checksum' => $checksum,
            ':blob_id' => $blobId,
            ':created_by' => $user['id'],
            ':created_at' => wb_now(),
            ':updated_at' => wb_now(),
        ]);
        return Database::lastInsertId($pdo, 'files');
    }

    private static function isUniqueConstraintViolation(PDOException $exception): bool
    {
        $driver = Database::driver();
        $code = (string) $exception->getCode();

        if ($driver === 'mysql') {
            return $code === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }

        if ($driver === 'pgsql') {
            return $code === '23505';
        }

        return $code === '23000' || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    public static function uploadCancel(array $user, string $token): void
    {
        $metadata = self::readUploadMetadata($token);

        if ((int) $metadata['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('This upload session does not belong to you.');
        }

        self::deleteDirectory(wb_storage_path('chunks/' . $token));
    }

    /**
     * Rejects required-mode video uploads up front when the server cannot
     * verify them (ffprobe missing), so nobody transfers gigabytes only for
     * the final check to fail.
     */
    private static function assertVideoUploadCanBeVerified(string $originalName, int $size, string $mimeType): void
    {
        $policy = Settings::videoCompressionPolicy();

        if ($policy['mode'] !== 'required') {
            return;
        }

        if ($size < $policy['min_source_mb'] * 1024 * 1024) {
            return;
        }

        if (!MediaValidator::looksLikeVideo($mimeType, $originalName)) {
            return;
        }

        if (!MediaValidator::isAvailable()) {
            throw new RuntimeException(
                'This server requires optimized video uploads, but its media verification tool (ffprobe) is unavailable. '
                . 'Please contact the administrator.'
            );
        }
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

        $dispositionType = $disposition === 'attachment' ? 'attachment' : 'inline';
        AuditLog::record($dispositionType === 'attachment' ? 'file.download' : 'file.view', $dispositionType === 'attachment' ? 'file_downloads' : 'file_views', [
            'actor_user' => $user,
            'target_type' => 'file',
            'target_id' => $fileId,
            'target_label' => self::filePathLabel($file, $pdo),
            'summary' => ($dispositionType === 'attachment' ? 'Downloaded file ' : 'Viewed file ') . $file['original_name'],
        ], $pdo);

        $location = self::blobLocation($pdo, $file);

        Security::sendFile(
            self::blobPath((string) $location['disk_name'], (string) $location['disk_extension']),
            (string) $file['mime_type'],
            (string) $file['original_name'],
            $disposition
        );
    }

    public static function storageStats(): array
    {
        $pdo = Database::connection();
        $used = (int) $pdo->query('SELECT COALESCE(SUM(size), 0) FROM files')->fetchColumn();
        $dedupEnabled = Settings::dedupEnabled($pdo);

        // Physical footprint: classic rows own their blob, deduplicated
        // rows share theirs through file_blobs.
        $legacyBytes = (int) $pdo->query(
            'SELECT COALESCE(SUM(size), 0) FROM files WHERE blob_id IS NULL'
        )->fetchColumn();
        $blobBytes = 0;
        $hasBlobTable = in_array('file_blobs', DatabasePlatform::tableNames($pdo, Database::driver()), true);

        if ($hasBlobTable) {
            $blobBytes = (int) $pdo->query('SELECT COALESCE(SUM(size), 0) FROM file_blobs')->fetchColumn();
        }

        $physical = $legacyBytes + $blobBytes;
        $saved = max(0, $used - $physical);

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
            'physical_bytes' => $physical,
            'physical_label' => wb_format_bytes($physical),
            'saved_bytes' => $saved,
            'saved_label' => wb_format_bytes($saved),
            'dedup_enabled' => $dedupEnabled,
        ];
    }

    /**
     * Reconciles the file_blobs ledger with reality: recomputes reference
     * counts, removes drained blob rows (unlinks their files), reports
     * blob records whose file vanished, and reclaims orphaned physical
     * files that no row references anymore. Orphan cleanup skips files
     * touched recently so an in-flight upload completion is never collected.
     *
     * @return array{repaired: int, removed_blobs: int, removed_orphans: int, missing: int}
     */
    public static function reconcileFileBlobs(?PDO $pdo = null, int $orphanGraceHours = 24): array
    {
        $storageLock = new StorageLock();
        $pdo ??= Database::connection();

        if (!in_array('file_blobs', DatabasePlatform::tableNames($pdo, Database::driver()), true)) {
            return ['repaired' => 0, 'removed_blobs' => 0, 'removed_orphans' => 0, 'missing' => 0];
        }

        $repaired = 0;
        $removedBlobs = 0;
        $missing = 0;
        $pathsToUnlink = [];

        $pdo->beginTransaction();

        try {
            $blobs = $pdo->query('SELECT b.*, COALESCE(c.actual, 0) AS actual FROM file_blobs b LEFT JOIN (SELECT blob_id, COUNT(*) AS actual FROM files WHERE blob_id IS NOT NULL GROUP BY blob_id) c ON c.blob_id = b.id')->fetchAll();

            foreach ($blobs as $blob) {
                $blobId = (int) $blob['id'];
                $actual = (int) $blob['actual'];

                if ($actual !== (int) $blob['ref_count']) {
                    $repaired++;
                }

                if ($actual === 0) {
                    $pdo->prepare('DELETE FROM file_blobs WHERE id = :id')->execute([':id' => $blobId]);
                    $pathsToUnlink[] = [(string) $blob['disk_name'], (string) $blob['disk_extension']];
                    $removedBlobs++;
                } else {
                    if ($actual !== (int) $blob['ref_count']) {
                        $pdo->prepare('UPDATE file_blobs SET ref_count = :count WHERE id = :id')
                            ->execute([':count' => $actual, ':id' => $blobId]);
                    }

                    $path = self::blobPath((string) $blob['disk_name'], (string) $blob['disk_extension']);

                    if (!is_file($path)) {
                        $missing++;
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        foreach ($pathsToUnlink as [$name, $extension]) {
            @unlink(self::blobPath($name, $extension));
        }

        // Orphan sweep: physical files under uploads/ referenced by no row.
        $referenced = [];
        $statement = $pdo->query('SELECT disk_name FROM file_blobs');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $diskName) {
            $referenced[(string) $diskName] = true;
        }
        $statement = $pdo->query('SELECT disk_name FROM files WHERE blob_id IS NULL');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $diskName) {
            $referenced[(string) $diskName] = true;
        }

        $removedOrphans = 0;
        $graceCutoff = time() - (max(1, $orphanGraceHours) * 3600);
        $uploadsRoot = wb_storage_path('uploads');

        if (is_dir($uploadsRoot)) {
            foreach (scandir($uploadsRoot) ?: [] as $levelOne) {
                if ($levelOne === '.' || $levelOne === '..' || strlen($levelOne) !== 2) {
                    continue;
                }

                $levelOnePath = $uploadsRoot . DIRECTORY_SEPARATOR . $levelOne;

                if (!is_dir($levelOnePath)) {
                    continue;
                }

                foreach (scandir($levelOnePath) ?: [] as $levelTwo) {
                    if ($levelTwo === '.' || $levelTwo === '..' || strlen($levelTwo) !== 2) {
                        continue;
                    }

                    $levelTwoPath = $levelOnePath . DIRECTORY_SEPARATOR . $levelTwo;

                    if (!is_dir($levelTwoPath)) {
                        continue;
                    }

                    foreach (scandir($levelTwoPath) ?: [] as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }

                        $diskName = pathinfo($item, PATHINFO_FILENAME);

                        if (isset($referenced[$diskName])) {
                            continue;
                        }

                        $path = $levelTwoPath . DIRECTORY_SEPARATOR . $item;
                        $mtime = filemtime($path) ?: 0;

                        if ($mtime > $graceCutoff) {
                            continue;
                        }

                        if (@unlink($path)) {
                            $removedOrphans++;
                        }
                    }

                    // Prune empty shard directories left behind by cleanup.
                    @rmdir($levelTwoPath);
                }
            }
        }

        return [
            'repaired' => $repaired,
            'removed_blobs' => $removedBlobs,
            'removed_orphans' => $removedOrphans,
            'missing' => $missing,
        ];
    }

    public static function refreshFolderSizeCache(?PDO $pdo = null): int
    {
        $pdo ??= Database::connection();
        $folderRows = $pdo->query('SELECT id, parent_id FROM folders')->fetchAll();

        if ($folderRows === []) {
            return 0;
        }

        $childrenMap = [];
        $sizes = [];

        foreach ($folderRows as $folder) {
            $folderId = (int) $folder['id'];
            $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
            $childrenMap[$parentId ?? 0][] = $folderId;
            $sizes[$folderId] = 0;
        }

        $fileSizes = $pdo->query(
            'SELECT folder_id, COALESCE(SUM(size), 0) AS folder_file_size FROM files GROUP BY folder_id'
        )->fetchAll();

        foreach ($fileSizes as $row) {
            $folderId = (int) ($row['folder_id'] ?? 0);

            if (isset($sizes[$folderId])) {
                $sizes[$folderId] = (int) ($row['folder_file_size'] ?? 0);
            }
        }

        $visited = [];
        $computeSize = static function (int $folderId) use (&$computeSize, &$sizes, $childrenMap, &$visited): int {
            if (isset($visited[$folderId])) {
                return $sizes[$folderId] ?? 0;
            }

            $visited[$folderId] = true;
            $totalSize = $sizes[$folderId] ?? 0;

            foreach ($childrenMap[$folderId] ?? [] as $childId) {
                $totalSize += $computeSize($childId);
            }

            $sizes[$folderId] = $totalSize;

            return $totalSize;
        };

        foreach (array_keys($sizes) as $folderId) {
            $computeSize((int) $folderId);
        }

        $calculatedAt = wb_now();
        $pdo->beginTransaction();

        try {
            foreach (array_chunk(array_keys($sizes), 250) as $chunk) {
                $cases = [];
                $params = [':calculated_at' => $calculatedAt];

                foreach ($chunk as $index => $folderId) {
                    $cases[] = "WHEN :id{$index} THEN :size{$index}";
                    $params[":id{$index}"] = $folderId;
                    $params[":size{$index}"] = $sizes[$folderId];
                }

                $ids = implode(', ', array_map(static fn (int $i): string => ":id{$i}", array_keys($chunk)));
                $sql = sprintf(
                    'UPDATE folders SET cached_size_bytes = CASE id %s END, cached_size_calculated_at = :calculated_at WHERE id IN (%s)',
                    implode(' ', $cases),
                    $ids
                );

                $pdo->prepare($sql)->execute($params);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return count($sizes);
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
        $allowedRows = [];

        foreach ($folders as $folder) {
            $id = (int) $folder['id'];

            if ($allowed !== null && !isset($allowed[$id])) {
                continue;
            }

            $allowedRows[] = $folder;
        }

        $childCounts = self::getChildCounts(
            array_map(static fn (array $f): int => (int) $f['id'], $allowedRows),
            $pdo
        );

        foreach ($allowedRows as $folder) {
            $nodes[] = self::serializeFolder(
                $folder,
                $user,
                $pdo,
                $scope,
                $childCounts[(int) $folder['id']] ?? 0
            );
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

    /**
     * @param array<int> $folderIds
     * @return array<int, int>
     */
    private static function getChildCounts(array $folderIds, PDO $pdo): array
    {
        if ($folderIds === []) {
            return [];
        }

        $counts = array_fill_keys($folderIds, 0);

        foreach (array_chunk($folderIds, 250) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $pdo->prepare(
                'SELECT parent_id, COUNT(*) as count FROM folders WHERE parent_id IN (' . $placeholders . ') GROUP BY parent_id'
            );
            $statement->execute($chunk);

            foreach ($statement->fetchAll() as $row) {
                $counts[(int) $row['parent_id']] = (int) $row['count'];
            }
        }

        return $counts;
    }

    private static function serializeFolder(array $folder, ?array $user, PDO $pdo, array $scope, ?int $childCount = null): array
    {
        $folderId = (int) $folder['id'];

        if ($childCount === null) {
            $childCountStatement = $pdo->prepare('SELECT COUNT(*) FROM folders WHERE parent_id = :parent_id');
            $childCountStatement->execute([':parent_id' => $folderId]);
            $childCount = (int) $childCountStatement->fetchColumn();
        }

        $isRoot = $folderId === Database::rootFolderId();
        $cachedSize = $folder['cached_size_bytes'] === null ? null : (int) $folder['cached_size_bytes'];
        $ownSpaceRoot = $scope['own_space_root'] ?? null;

        return [
            'id' => $folderId,
            'type' => 'folder',
            'name' => $isRoot ? 'Home' : $folder['name'],
            'parent_id' => $folder['parent_id'] === null ? null : (int) $folder['parent_id'],
            'size' => $cachedSize,
            'size_label' => $cachedSize === null ? '-' : wb_format_bytes($cachedSize),
            'mime_type' => 'inode/directory',
            'description' => (string) ($folder['description'] ?? ''),
            'updated_at' => $folder['updated_at'],
            'updated_relative' => wb_relative_time($folder['updated_at']),
            'cached_size_calculated_at' => $folder['cached_size_calculated_at'] === null ? null : (string) $folder['cached_size_calculated_at'],
            'child_count' => $childCount,
            'can_open' => $scope['all'] || in_array($folderId, $scope['ancestors'], true),
            'can_upload' => Permissions::canUploadToFolder($folderId, $user, $pdo, $scope),
            'can_create_folders' => Permissions::canCreateFoldersIn($folderId, $user, $pdo, $scope),
            'can_edit' => !$isRoot && Permissions::canEditFolder($folderId, $user, $pdo, $scope),
            'can_delete' => !$isRoot && Permissions::canDeleteFolder($folderId, $user, $pdo, $scope),
            'can_manage_sharing' => $scope['all']
                || ($ownSpaceRoot !== null && in_array($folderId, $scope['own_space_ids'] ?? [], true)),
        ];
    }

    private static function serializeFile(array $file, ?array $user, PDO $pdo, array $scope): array
    {
        $extension = strtolower(pathinfo((string) $file['original_name'], PATHINFO_EXTENSION));
        $folderId = (int) $file['folder_id'];
        $preview = wb_file_preview_metadata((string) $file['mime_type'], $extension);
        $ownSpaceRoot = $scope['own_space_root'] ?? null;
        $inOwnSpace = $ownSpaceRoot !== null && in_array($folderId, $scope['own_space_ids'] ?? [], true);
        $locked = self::fileIsLockedFor($file, $user, $pdo);

        return array_merge([
            'id' => (int) $file['id'],
            'type' => 'file',
            'name' => $file['original_name'],
            'folder_id' => $folderId,
            'size' => (int) $file['size'],
            'size_label' => wb_format_bytes((int) $file['size']),
            'mime_type' => $file['mime_type'],
            'description' => (string) ($file['description'] ?? ''),
            'updated_at' => $file['updated_at'],
            'updated_relative' => wb_relative_time($file['updated_at']),
            'checksum' => $file['checksum'],
            'extension' => $extension,
            'locked' => $locked,
            'can_share' => $scope['all'] || $inOwnSpace,
            'can_edit' => !$locked && Permissions::canEditFolder($folderId, $user, $pdo, $scope),
            'can_delete' => !$locked && Permissions::canDeleteFolder($folderId, $user, $pdo, $scope),
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

    private static function folderPathLabel(int $folderId, PDO $pdo): string
    {
        $folder = self::folderById($folderId, $pdo);

        return $folder === null ? 'Unknown folder' : self::folderPathLabelFromRow($folder, $pdo);
    }

    private static function folderPathLabelFromRow(array $folder, PDO $pdo): string
    {
        $segments = [];
        $current = $folder;

        while (true) {
            $currentId = (int) ($current['id'] ?? 0);

            if ($currentId === Database::rootFolderId()) {
                array_unshift($segments, 'Home');
                break;
            }

            array_unshift($segments, (string) ($current['name'] ?? ''));
            $parentId = $current['parent_id'] === null ? null : (int) $current['parent_id'];

            if ($parentId === null) {
                break;
            }

            $parent = self::folderById($parentId, $pdo);

            if ($parent === null) {
                break;
            }

            $current = $parent;
        }

        return implode(' / ', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

    private static function filePathLabel(array $file, PDO $pdo): string
    {
        return self::folderPathLabel((int) $file['folder_id'], $pdo) . ' / ' . (string) $file['original_name'];
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

        $size = (int) ($payload['size'] ?? -1);
        $totalChunks = (int) ($payload['total_chunks'] ?? 0);

        if ($size < 0 || $totalChunks < 1 || $totalChunks !== self::expectedChunkCount($size)) {
            throw new RuntimeException('Upload metadata is invalid.');
        }

        $payload['size'] = $size;
        $payload['total_chunks'] = $totalChunks;

        return $payload;
    }

    private static function assertDeclaredChunkCountMatchesSize(int $size, int $totalChunks): void
    {
        if ($totalChunks !== self::expectedChunkCount($size)) {
            throw new InvalidArgumentException('Declared chunk count does not match file size.');
        }
    }

    private static function expectedChunkCount(int $size): int
    {
        $normalizedSize = max(0, $size);

        return max(1, intdiv($normalizedSize + self::CHUNK_SIZE - 1, self::CHUNK_SIZE));
    }

    private static function assertChunkFileSize(string $path, array $metadata, int $index): void
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException('Upload chunk is unreadable.');
        }

        if ((int) $size !== self::expectedChunkSize((int) $metadata['size'], $index)) {
            throw new RuntimeException('Upload chunk size is invalid.');
        }
    }

    private static function expectedChunkSize(int $size, int $index): int
    {
        $start = $index * self::CHUNK_SIZE;
        $remaining = max(0, $size - $start);

        return min(self::CHUNK_SIZE, $remaining);
    }

    private static function childFolderByName(int $parentId, string $name, PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM folders WHERE parent_id = :parent_id AND name = :name ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([
            ':parent_id' => $parentId,
            ':name' => $name,
        ]);
        $folder = $statement->fetch();

        return is_array($folder) ? $folder : null;
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

    private static function normalizeDescription(string $description): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($description));

        if (mb_strlen($normalized) > 1000) {
            throw new RuntimeException('Descriptions must be 1000 characters or fewer.');
        }

        return $normalized;
    }
}
