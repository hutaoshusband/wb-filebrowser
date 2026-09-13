<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class Permissions
{
    public static function publicAccessEnabled(?PDO $pdo = null): bool
    {
        $pdo ??= Database::connection();

        return wb_parse_bool(Database::setting('public_access', '0'));
    }

    public static function scope(?array $user, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();

        if ($user !== null && in_array($user['role'], ['super_admin', 'admin'], true)) {
            $ids = array_map(
                static fn (array $folder): int => (int) $folder['id'],
                $pdo->query('SELECT id FROM folders')->fetchAll()
            );

            return [
                'all' => true,
                'ancestors' => $ids,
                'content' => $ids,
                'upload' => $ids,
                'edit' => $ids,
                'delete' => $ids,
                'create' => $ids,
            ];
        }

        if ($user === null && !self::publicAccessEnabled($pdo)) {
            return self::emptyScope();
        }

        $principalType = $user === null ? 'guest' : 'user';
        $principalId = $user === null ? 0 : (int) $user['id'];
        $folders = $pdo->query('SELECT id, parent_id FROM folders')->fetchAll();
        $folderMap = [];
        $childrenMap = [];

        foreach ($folders as $folder) {
            $id = (int) $folder['id'];
            $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
            $folderMap[$id] = [
                'id' => $id,
                'parent_id' => $parentId,
            ];
            $childrenMap[$parentId ?? 0][] = $id;
        }

        $spacePolicy = SpaceService::policy($pdo);
        $spaceByFolder = [];
        foreach ($pdo->query('SELECT folder_id, status FROM spaces')->fetchAll() as $spaceRow) {
            $root = (int) $spaceRow['folder_id'];
            foreach (self::expandDescendants([$root], $childrenMap) as $id) {
                $spaceByFolder[$id] = $spaceRow;
            }
            // Grants above a private space must never propagate into it.
            $parent = $folderMap[$root]['parent_id'] ?? 0;
            $childrenMap[$parent] = array_values(array_diff($childrenMap[$parent] ?? [], [$root]));
        }

        $statement = $pdo->prepare(
            'SELECT folder_id, can_view, can_upload, can_edit, can_delete, can_create_folders
             FROM folder_permissions
             WHERE principal_type = :principal_type AND principal_id = :principal_id'
        );
        $statement->execute([
            ':principal_type' => $principalType,
            ':principal_id' => $principalId,
        ]);

        $viewSeeds = [];
        $uploadSeeds = [];
        $editSeeds = [];
        $deleteSeeds = [];
        $createSeeds = [];

        foreach ($statement->fetchAll() as $permission) {
            $folderId = (int) $permission['folder_id'];
            $registeredSpace = $spaceByFolder[$folderId] ?? null;
            if ($registeredSpace !== null) {
                if ($user === null || !$spacePolicy['enabled'] || !$spacePolicy['sharing_allowed'] || $registeredSpace['status'] !== 'active') {
                    continue;
                }
                if ($spacePolicy['max_grant_level'] === 'view') {
                    foreach (['can_upload', 'can_edit', 'can_delete', 'can_create_folders'] as $field) {
                        $permission[$field] = 0;
                    }
                }
            }
            $canUpload = $user !== null && (int) $permission['can_upload'] === 1;
            $canEdit = $user !== null && (int) $permission['can_edit'] === 1;
            $canDelete = $user !== null && (int) $permission['can_delete'] === 1;
            $canCreate = $user !== null && (int) $permission['can_create_folders'] === 1;

            if ((int) $permission['can_view'] === 1 || $canUpload || $canEdit || $canDelete || $canCreate) {
                $viewSeeds[] = $folderId;
            }

            if ($canUpload) {
                $uploadSeeds[] = $folderId;
            }

            if ($canEdit) {
                $editSeeds[] = $folderId;
            }

            if ($canDelete) {
                $deleteSeeds[] = $folderId;
            }

            if ($canCreate) {
                $createSeeds[] = $folderId;
            }
        }

        $content = self::expandDescendants($viewSeeds, $childrenMap);
        $upload = self::expandDescendants($uploadSeeds, $childrenMap);
        $edit = self::expandDescendants($editSeeds, $childrenMap);
        $delete = self::expandDescendants($deleteSeeds, $childrenMap);
        $create = self::expandDescendants($createSeeds, $childrenMap);
        $ancestors = self::expandAncestors(
            array_unique(array_merge($content, $upload, $edit, $delete, $create)),
            $folderMap
        );

        if ($content !== [] || $upload !== [] || $edit !== [] || $delete !== [] || $create !== []) {
            $ancestors[] = Database::rootFolderId();
        }

        $dedupe = static fn (array $values): array => array_values(array_unique(array_map('intval', $values)));

        $scope = [
            'all' => false,
            'ancestors' => $dedupe($ancestors),
            'content' => $dedupe($content),
            'upload' => $dedupe($upload),
            'edit' => $dedupe($edit),
            'delete' => $dedupe($delete),
            'create' => $dedupe($create),
        ];

        return self::applySpaces($user, $scope, $folderMap, $childrenMap, $pdo);
    }

    /**
     * Scope for logged-in users with per-user spaces factored in: the owner
     * holds implicit full rights inside their space subtree, and the
     * "Spaces" container plus the global-root auto-grant stay invisible to
     * space-only users. Called after the plain folder_permissions scope is
     * computed; space-less users pass through unchanged.
     */
    private static function applySpaces(?array $user, array $scope, array $folderMap, array $childrenMap, PDO $pdo): array
    {
        if ($user === null || !SpaceService::featureEnabled($pdo)) {
            return $scope;
        }

        $space = SpaceService::findForUser((int) $user['id'], true, $pdo);
        $containerId = SpaceService::containerFolderId($pdo);

        if ($space === null && $containerId === null) {
            return $scope;
        }

        // Registry folders must never be reachable through scope lists for
        // non-admins, even when a grant chain touches them.
        $pruneContainer = static function (array $values) use ($containerId): array {
            if ($containerId === null) {
                return $values;
            }

            return array_values(array_filter(
                $values,
                static fn (int $folderId): bool => $folderId !== $containerId
            ));
        };

        $scope['ancestors'] = $pruneContainer($scope['ancestors']);
        $scope['content'] = $pruneContainer($scope['content']);
        $scope['upload'] = $pruneContainer($scope['upload']);
        $scope['edit'] = $pruneContainer($scope['edit']);
        $scope['delete'] = $pruneContainer($scope['delete']);
        $scope['create'] = $pruneContainer($scope['create']);

        $space = $user === null ? null : SpaceService::findForUser((int) $user['id'], true, $pdo);

        if ($space !== null) {
            $spaceRootId = (int) $space['folder_id'];
            $spaceSubtree = self::expandDescendants([$spaceRootId], $childrenMap);

            $scope['content'] = array_values(array_unique(array_merge($scope['content'], $spaceSubtree)));
            $scope['upload'] = array_values(array_unique(array_merge($scope['upload'], $spaceSubtree)));
            $scope['edit'] = array_values(array_unique(array_merge($scope['edit'], $spaceSubtree)));
            $scope['delete'] = array_values(array_unique(array_merge($scope['delete'], $spaceSubtree)));
            $scope['create'] = array_values(array_unique(array_merge($scope['create'], $spaceSubtree)));
            $scope['ancestors'] = array_values(array_unique(array_merge($scope['ancestors'], $spaceSubtree)));

            // Consumed by serializeFile to flag files the owner may publish
            // via public share links.
            $scope['own_space_root'] = $spaceRootId;
            $scope['own_space_ids'] = $spaceSubtree;
        }

        $hasGlobalGrants = false;
        $spaceRoots = [];

        $statement = $pdo->query('SELECT folder_id FROM spaces');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $spaceFolderId) {
            $spaceRoots[(int) $spaceFolderId] = true;
        }

        if ($space === null) {
            // Space-less users keep the global root as their navigation
            // anchor (legacy behaviour): grants inside other people's
            // spaces render as extra roots in their folder tree.
            $hasGlobalGrants = true;
        } else {
            foreach ($scope['content'] as $folderId) {
                $current = $folderId;

                while (isset($folderMap[$current])) {
                    if (isset($spaceRoots[$current])) {
                        continue 2;
                    }

                    $parent = $folderMap[$current]['parent_id'];

                    if ($parent === null) {
                        $hasGlobalGrants = true;
                        continue 2;
                    }

                    $current = $parent;
                }
            }
        }

        $rootId = Database::rootFolderId();
        $rootIndex = array_search($rootId, $scope['ancestors'], true);

        if ($hasGlobalGrants) {
            if ($rootIndex === false) {
                $scope['ancestors'][] = $rootId;
            }
        } elseif ($rootIndex !== false) {
            // Grants that live entirely inside spaces must not unlock the
            // global root.
            unset($scope['ancestors'][$rootIndex]);
            $scope['ancestors'] = array_values($scope['ancestors']);
        }

        return $scope;
    }

    public static function canOpenFolder(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['ancestors'], true);
    }

    public static function canViewFolderContents(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['content'], true);
    }

    public static function canUploadToFolder(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['upload'], true);
    }

    public static function canEditFolder(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['edit'], true);
    }

    public static function canDeleteFolder(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['delete'], true);
    }

    public static function canCreateFoldersIn(int $folderId, ?array $user, ?PDO $pdo = null, ?array $scope = null): bool
    {
        $scope ??= self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['create'], true);
    }

    public static function canManageStructure(?array $user): bool
    {
        return $user !== null && in_array($user['role'], ['super_admin', 'admin'], true);
    }

    /**
     * @return array{folders: array<int, array<string, mixed>>, permissions: array<int, array<string, mixed>>}
     */
    public static function matrix(array $actor, string $principalType, int $principalId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $storageLock = new StorageLock();
        self::assertPrincipalAccess($actor, $principalType, $principalId, $pdo);
        $statement = $pdo->prepare(
            'SELECT folder_id, can_view, can_upload, can_edit, can_delete, can_create_folders
             FROM folder_permissions
             WHERE principal_type = :principal_type AND principal_id = :principal_id'
        );
        $statement->execute([
            ':principal_type' => $principalType,
            ':principal_id' => $principalType === 'guest' ? 0 : $principalId,
        ]);

        return [
            'folders' => FileManager::folderTree($actor),
            'permissions' => $statement->fetchAll(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public static function saveMatrix(array $actor, string $principalType, int $principalId, array $entries, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();

        if (!is_array($entries)) {
            throw new RuntimeException('Permission entries must be an array.');
        }

        $storageLock = new StorageLock();
        self::assertPrincipalAccess($actor, $principalType, $principalId, $pdo);
        $pdo->beginTransaction();

        try {
            $deleteStatement = $pdo->prepare(
                'DELETE FROM folder_permissions WHERE principal_type = :principal_type AND principal_id = :principal_id'
            );
            $deleteStatement->execute([
                ':principal_type' => $principalType,
                ':principal_id' => $principalType === 'guest' ? 0 : $principalId,
            ]);

            $insertStatement = $pdo->prepare(
                'INSERT INTO folder_permissions (
                    folder_id,
                    principal_type,
                    principal_id,
                    can_view,
                    can_upload,
                    can_edit,
                    can_delete,
                    can_create_folders,
                    created_at,
                    updated_at
                 ) VALUES (
                    :folder_id,
                    :principal_type,
                    :principal_id,
                    :can_view,
                    :can_upload,
                    :can_edit,
                    :can_delete,
                    :can_create_folders,
                    :created_at,
                    :updated_at
                 )'
            );

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $canUpload = $principalType === 'user' && wb_parse_bool($entry['can_upload'] ?? false);
                $canEdit = $principalType === 'user' && wb_parse_bool($entry['can_edit'] ?? false);
                $canDelete = $principalType === 'user' && wb_parse_bool($entry['can_delete'] ?? false);
                $canCreateFolders = $principalType === 'user' && wb_parse_bool($entry['can_create_folders'] ?? false);
                $canView = wb_parse_bool($entry['can_view'] ?? false) || $canUpload || $canEdit || $canDelete || $canCreateFolders;

                if (!$canView && !$canUpload && !$canEdit && !$canDelete && !$canCreateFolders) {
                    continue;
                }

                $insertStatement->execute([
                    ':folder_id' => (int) ($entry['folder_id'] ?? 0),
                    ':principal_type' => $principalType,
                    ':principal_id' => $principalType === 'guest' ? 0 : $principalId,
                    ':can_view' => $canView ? 1 : 0,
                    ':can_upload' => $canUpload ? 1 : 0,
                    ':can_edit' => $canEdit ? 1 : 0,
                    ':can_delete' => $canDelete ? 1 : 0,
                    ':can_create_folders' => $canCreateFolders ? 1 : 0,
                    ':created_at' => wb_now(),
                    ':updated_at' => wb_now(),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private static function emptyScope(): array
    {
        return [
            'all' => false,
            'ancestors' => [],
            'content' => [],
            'upload' => [],
            'edit' => [],
            'delete' => [],
            'create' => [],
        ];
    }

    private static function expandDescendants(array $seedIds, array $childrenMap): array
    {
        $stack = array_values(array_unique(array_map('intval', $seedIds)));
        $seen = [];

        while ($stack !== []) {
            $folderId = array_pop($stack);

            if (isset($seen[$folderId])) {
                continue;
            }

            $seen[$folderId] = true;

            foreach ($childrenMap[$folderId] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_map('intval', array_keys($seen));
    }

    private static function expandAncestors(array $seedIds, array $folderMap): array
    {
        $seen = [];

        foreach ($seedIds as $folderId) {
            $current = (int) $folderId;

            while ($current > 0 && isset($folderMap[$current]) && !isset($seen[$current])) {
                $seen[$current] = true;
                $parentId = $folderMap[$current]['parent_id'];
                $current = $parentId ?? 0;
            }
        }

        return array_map('intval', array_keys($seen));
    }

    private static function assertPrincipalAccess(array $actor, string $principalType, int $principalId, PDO $pdo): void
    {
        if (!in_array($principalType, ['guest', 'user'], true)) {
            throw new RuntimeException('Unknown permission principal.');
        }

        if ($principalType === 'guest') {
            return;
        }

        $principalStatement = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $principalStatement->execute([':id' => $principalId]);
        $principalRole = $principalStatement->fetchColumn();

        if ($principalRole === false) {
            throw new RuntimeException('User not found.');
        }

        if ($actor['role'] !== 'super_admin' && $principalRole !== 'user') {
            throw new RuntimeException('Admins can only manage standard user permissions.');
        }
    }
}
