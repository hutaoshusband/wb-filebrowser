<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;

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
            ];
        }

        if ($user === null && !self::publicAccessEnabled($pdo)) {
            return [
                'all' => false,
                'ancestors' => [],
                'content' => [],
                'upload' => [],
            ];
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

        $permissionStatement = $pdo->prepare(
            'SELECT folder_id, can_view, can_upload
             FROM folder_permissions
             WHERE principal_type = :principal_type AND principal_id = :principal_id'
        );
        $permissionStatement->execute([
            ':principal_type' => $principalType,
            ':principal_id' => $principalId,
        ]);

        $viewSeeds = [];
        $uploadSeeds = [];

        foreach ($permissionStatement->fetchAll() as $permission) {
            $folderId = (int) $permission['folder_id'];

            if ((int) $permission['can_view'] === 1) {
                $viewSeeds[] = $folderId;
            }

            if ($user !== null && (int) $permission['can_upload'] === 1) {
                $uploadSeeds[] = $folderId;
            }
        }

        $content = self::expandDescendants($viewSeeds, $childrenMap);
        $upload = self::expandDescendants($uploadSeeds, $childrenMap);
        $ancestors = self::expandAncestors(array_unique(array_merge($content, $upload)), $folderMap);

        if ($content !== [] || $upload !== []) {
            $ancestors[] = Database::rootFolderId();
        }

        $dedupe = static fn (array $values): array => array_values(array_unique(array_map('intval', $values)));

        return [
            'all' => false,
            'ancestors' => $dedupe($ancestors),
            'content' => $dedupe($content),
            'upload' => $dedupe($upload),
        ];
    }

    public static function canOpenFolder(int $folderId, ?array $user, ?PDO $pdo = null): bool
    {
        $scope = self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['ancestors'], true);
    }

    public static function canViewFolderContents(int $folderId, ?array $user, ?PDO $pdo = null): bool
    {
        $scope = self::scope($user, $pdo);

        return $scope['all'] || in_array($folderId, $scope['content'], true);
    }

    public static function canUploadToFolder(int $folderId, ?array $user, ?PDO $pdo = null): bool
    {
        if ($user !== null && in_array($user['role'], ['super_admin', 'admin'], true)) {
            return true;
        }

        $scope = self::scope($user, $pdo);

        return in_array($folderId, $scope['upload'], true);
    }

    public static function canManageStructure(?array $user): bool
    {
        return $user !== null && in_array($user['role'], ['super_admin', 'admin'], true);
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
}
