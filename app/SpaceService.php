<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

/**
 * Per-user spaces: every enabled user owns a private root folder inside a
 * shared "Spaces" container under the global root. Owners hold implicit
 * full rights inside their own space; visibility of other spaces and of
 * the container itself is withheld from non-admins by Permissions::scope().
 */
final class SpaceService
{
    public const CONTAINER_FOLDER_NAME = 'Spaces';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    /**
     * @return array{enabled: bool, sharing_allowed: bool, max_grant_level: string, auto_create: bool}
     */
    public static function policy(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $key = DatabasePlatform::quoteIdentifier((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'key');
        $values = $pdo->query("SELECT " . $key . ", value FROM settings WHERE " . $key . " IN ('spaces_enabled', 'spaces_user_sharing_allowed', 'spaces_max_grant_level', 'spaces_auto_create_on_user_create')")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'enabled' => wb_parse_bool($values['spaces_enabled'] ?? '0'),
            'sharing_allowed' => wb_parse_bool($values['spaces_user_sharing_allowed'] ?? '1'),
            'max_grant_level' => self::parseGrantLevel($values['spaces_max_grant_level'] ?? 'write'),
            'auto_create' => wb_parse_bool($values['spaces_auto_create_on_user_create'] ?? '0'),
        ];
    }

    public static function featureEnabled(?PDO $pdo = null): bool
    {
        return self::policy($pdo)['enabled'];
    }

    public static function provisionMissingUsers(array $actor, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $users = $pdo->query("SELECT u.id FROM users u LEFT JOIN spaces s ON s.user_id = u.id WHERE u.role = 'user' AND s.id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $userId) {
            self::provisionForUser($actor, (int) $userId, $pdo);
        }
    }

    private static function parseGrantLevel(mixed $value): string
    {
        $level = strtolower(trim((string) $value));

        if (!in_array($level, ['view', 'write'], true)) {
            throw new \InvalidArgumentException('Space grant level must be view or write.');
        }

        return $level;
    }

    public static function findForUser(int $userId, bool $activeOnly = true, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        $sql = 'SELECT * FROM spaces WHERE user_id = :user_id';

        if ($activeOnly) {
            $sql .= ' AND status = \'active\'';
        }

        $sql .= ' LIMIT 1';
        $statement = $pdo->prepare($sql);
        $statement->execute([':user_id' => $userId]);
        $space = $statement->fetch();

        return $space === false ? null : $space;
    }

    public static function findByFolderId(int $folderId, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        $statement = $pdo->prepare('SELECT * FROM spaces WHERE folder_id = :folder_id LIMIT 1');
        $statement->execute([':folder_id' => $folderId]);
        $space = $statement->fetch();

        return $space === false ? null : $space;
    }

    /**
     * The root folder id of the space that contains the given folder, or
     * null when the folder lives outside any registered space.
     */
    public static function spaceRootIdForFolder(int $folderId, ?PDO $pdo = null): ?int
    {
        $pdo ??= Database::connection();
        $lookup = $pdo->prepare('SELECT f.parent_id, s.folder_id AS space_root FROM folders f LEFT JOIN spaces s ON s.folder_id = f.id WHERE f.id = :id');
        $visited = [];
        $current = $folderId;
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $lookup->execute([':id' => $current]);
            $row = $lookup->fetch();
            if ($row === false) {
                break;
            }
            if ($row['space_root'] !== null) {
                return (int) $row['space_root'];
            }
            if ($row['parent_id'] === null) {
                break;
            }
            $current = (int) $row['parent_id'];
        }

        return null;
    }

    /**
     * The space containing the folder, joined against its registration.
     * Returns null for folders outside any (active) space.
     */
    public static function activeSpaceForFolder(int $folderId, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        $spaceRootId = self::spaceRootIdForFolder($folderId, $pdo);

        if ($spaceRootId === null) {
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT * FROM spaces WHERE folder_id = :folder_id AND status = 'active' LIMIT 1"
        );
        $statement->execute([':folder_id' => $spaceRootId]);
        $space = $statement->fetch();

        return $space === false ? null : $space;
    }

    public static function containerFolderId(?PDO $pdo = null): ?int
    {
        $pdo ??= Database::connection();
        $id = $pdo->query('SELECT f.parent_id FROM spaces s INNER JOIN folders f ON f.id = s.folder_id LIMIT 1')->fetchColumn();
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        $saved = (int) Database::setting('spaces_container_folder_id', '0');
        $statement = $pdo->prepare('SELECT id FROM folders WHERE id = :id');
        $statement->execute([':id' => $saved]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function ensureContainerFolder(PDO $pdo, array $actor): int
    {
        $existing = self::containerFolderId($pdo);

        if ($existing !== null) {
            return $existing;
        }

        $rootId = Database::rootFolderId();
        $name = self::CONTAINER_FOLDER_NAME;
        $base = $name;
        $suffix = 2;

        // Legacy installs may already own a root-level folder with the same
        // name; the UNIQUE(parent_id, name) constraint requires a free slot.
        $statement = $pdo->prepare('SELECT id FROM folders WHERE parent_id = :root_id AND name = :name LIMIT 1');

        while (true) {
            $statement->execute([':root_id' => $rootId, ':name' => $name]);

            if ($statement->fetchColumn() === false) {
                break;
            }

            $name = $base . ' (' . $suffix . ')';
            $suffix++;
        }

        $insert = $pdo->prepare(
            'INSERT INTO folders (parent_id, name, created_by, created_at, updated_at)
             VALUES (:parent_id, :name, :created_by, :created_at, :updated_at)'
        );
        $insert->execute([
            ':parent_id' => $rootId,
            ':name' => $name,
            ':created_by' => $actor['id'] ?? null,
            ':created_at' => wb_now(),
            ':updated_at' => wb_now(),
        ]);

        $id = (int) Database::lastInsertId($pdo, 'folders');
        Database::updateSetting('spaces_container_folder_id', (string) $id);
        return $id;
    }

    /**
     * Idempotently provisions the user's space: creates the container
     * folder and the per-user root folder, reactivating an existing
     * registration (and its folder, which keeps all data) when possible.
     */
    public static function provisionForUser(array $actor, int $userId, ?PDO $pdo = null): array
    {
        $storageLock = new StorageLock();
        $pdo ??= Database::connection();
        $userStatement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $userStatement->execute([':id' => $userId]);
        $user = $userStatement->fetch();

        if ($user === false) {
            throw new RuntimeException('User not found.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $existing = self::findForUser($userId, false, $pdo);

            if ($existing !== null) {
                if ((string) $existing['status'] !== self::STATUS_ACTIVE) {
                    $pdo->prepare(
                        'UPDATE spaces SET status = :status, updated_at = :updated_at WHERE id = :id'
                    )->execute([
                        ':status' => self::STATUS_ACTIVE,
                        ':updated_at' => wb_now(),
                        ':id' => $existing['id'],
                    ]);
                }

                $folderId = (int) $existing['folder_id'];
                $space = self::findForUser($userId, false, $pdo);
                if ($ownsTransaction) { $pdo->commit(); }

                return is_array($space) ? $space : $existing;
            }

            $containerId = self::ensureContainerFolder($pdo, $actor);
            $folderStatement = $pdo->prepare(
                'INSERT INTO folders (parent_id, name, created_by, created_at, updated_at)
                 VALUES (:parent_id, :name, :created_by, :created_at, :updated_at)'
            );
            $folderStatement->execute([
                ':parent_id' => $containerId,
                ':name' => (string) $user['username'],
                ':created_by' => $userId,
                ':created_at' => wb_now(),
                ':updated_at' => wb_now(),
            ]);
            $folderId = (int) Database::lastInsertId($pdo, 'folders');

            $pdo->prepare(
                'INSERT INTO spaces (user_id, folder_id, status, size_limit_bytes, created_at, updated_at)
                 VALUES (:user_id, :folder_id, :status, NULL, :created_at, :updated_at)'
            )->execute([
                ':user_id' => $userId,
                ':folder_id' => $folderId,
                ':status' => self::STATUS_ACTIVE,
                ':created_at' => wb_now(),
                ':updated_at' => wb_now(),
            ]);

            $spaceId = (int) Database::lastInsertId($pdo, 'spaces');
            if ($ownsTransaction) { $pdo->commit(); }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $fetch = $pdo->prepare('SELECT * FROM spaces WHERE id = :id LIMIT 1');
        $fetch->execute([':id' => $spaceId]);
        $space = $fetch->fetch();

        AuditLog::record('space.provision', 'admin_actions', [
            'actor_user' => $actor,
            'target_type' => 'space',
            'target_id' => $userId,
            'target_label' => (string) $user['username'],
            'summary' => 'Provisioned space for ' . $user['username'],
        ], $pdo);

        return is_array($space) ? $space : [];
    }

    public static function setStatusForUser(array $actor, int $userId, string $status, ?PDO $pdo = null): void
    {
        $storageLock = new StorageLock();
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new RuntimeException('Invalid space status.');
        }

        $pdo ??= Database::connection();
        $space = self::findForUser($userId, false, $pdo);

        if ($space === null) {
            throw new RuntimeException('This user has no space.');
        }

        $pdo->prepare('UPDATE spaces SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':status' => $status,
                ':updated_at' => wb_now(),
                ':id' => $space['id'],
            ]);

        AuditLog::record('space.status', 'admin_actions', [
            'actor_user' => $actor,
            'target_type' => 'space',
            'target_id' => $userId,
            'target_label' => $status,
            'summary' => 'Set space status to ' . $status,
        ], $pdo);
    }

    public static function setSizeLimitForUser(int $userId, ?int $sizeLimitBytes, ?PDO $pdo = null): void
    {
        $storageLock = new StorageLock();
        if ($sizeLimitBytes !== null && $sizeLimitBytes < 1) {
            throw new RuntimeException('Space size limit must be positive or null.');
        }
        $pdo ??= Database::connection();
        $space = self::findForUser($userId, false, $pdo);

        if ($space === null) {
            throw new RuntimeException('This user has no space.');
        }

        $pdo->prepare('UPDATE spaces SET size_limit_bytes = :limit, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':limit' => $sizeLimitBytes,
                ':updated_at' => wb_now(),
                ':id' => $space['id'],
            ]);
    }

    /**
     * Super-admin only: deletes the space subtree, its blobs and the
     * registration row. The user account itself is untouched.
     */
    public static function purgeForUser(array $actor, int $userId, ?PDO $pdo = null): void
    {
        if (($actor['role'] ?? '') !== 'super_admin') {
            throw new RuntimeException('Only the Super-Admin can purge spaces.');
        }

        $pdo ??= Database::connection();
        $space = self::findForUser($userId, false, $pdo);

        if ($space === null) {
            throw new RuntimeException('This user has no space.');
        }

        FileManager::deleteFolder($actor, (int) $space['folder_id'], true);
        $pdo->prepare('DELETE FROM spaces WHERE id = :id')->execute([':id' => $space['id']]);

        AuditLog::record('space.purge', 'deletions', [
            'actor_user' => $actor,
            'target_type' => 'space',
            'target_id' => $userId,
            'summary' => 'Purged space of user #' . $userId,
        ], $pdo);
    }

    public static function usageBytes(array $space, ?PDO $pdo = null): int
    {
        $pdo ??= Database::connection();
        $descendantIds = FileManager::descendantFolderIdsForSpace((int) $space['folder_id'], $pdo);
        $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(size), 0) FROM files WHERE folder_id IN (' . $placeholders . ')'
        );
        $statement->execute($descendantIds);

        return (int) $statement->fetchColumn();
    }

    /**
     * The folder a user should land in after login: their space root when a
     * space is active, the global root otherwise.
     */
    public static function homeFolderIdFor(?array $user, ?PDO $pdo = null): ?int
    {
        if ($user === null || !self::featureEnabled($pdo)) {
            return Database::rootFolderId();
        }

        $pdo ??= Database::connection();
        $space = self::findForUser((int) $user['id'], true, $pdo);

        return $space === null ? Database::rootFolderId() : (int) $space['folder_id'];
    }

    /**
     * Session payload describing the caller's space situation.
     */
    public static function sessionContextFor(?array $user, ?PDO $pdo = null): ?array
    {
        if ($user === null) {
            return null;
        }

        $pdo ??= Database::connection();
        $space = self::findForUser((int) $user['id'], false, $pdo);

        if (!self::featureEnabled($pdo)) {
            return $space === null ? null : ['enabled' => false, 'status' => 'unavailable', 'folder_id' => null];
        }

        if ($space === null) {
            return ['enabled' => true, 'status' => 'none', 'folder_id' => null];
        }

        $context = [
            'enabled' => true,
            'status' => (string) $space['status'],
            'folder_id' => (int) $space['folder_id'],
            'size_limit_bytes' => $space['size_limit_bytes'] === null ? null : (int) $space['size_limit_bytes'],
        ];

        if ((string) $space['status'] === self::STATUS_ACTIVE) {
            $used = self::usageBytes($space, $pdo);
            $context['used_bytes'] = $used;
            $context['used_label'] = wb_format_bytes($used);
            $policy = self::policy($pdo);
            $context['can_share'] = $policy['sharing_allowed'];
            $context['max_grant_level'] = $policy['max_grant_level'];
            $context['is_owner'] = true;
        }

        return $context;
    }

    /**
     * Space roots are the registry identity of a user's space and must not
     * be renamed, moved or deleted through regular folder operations.
     */
    public static function assertNotSpaceRoot(int $folderId, string $action, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();

        $ids = FileManager::descendantFolderIdsForSpace($folderId, $pdo);
        $roots = array_map('intval', $pdo->query('SELECT folder_id FROM spaces')->fetchAll(PDO::FETCH_COLUMN));
        if (array_intersect($ids, $roots) !== []) {
            throw new RuntimeException('The space root folder cannot be ' . $action . 'd.');
        }
    }

    /**
     * Non-admins must not move folders or files across space boundaries
     * (global -> space, space -> global, space A -> space B).
     */
    public static function assertSameSpaceOrAdmin(int $sourceFolderId, int $targetFolderId, array $user, ?PDO $pdo = null): void
    {
        if ($user !== null && in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            return;
        }

        $pdo ??= Database::connection();
        $sourceRoot = self::spaceRootIdForFolder($sourceFolderId, $pdo);
        $targetRoot = self::spaceRootIdForFolder($targetFolderId, $pdo);

        if ($sourceRoot !== $targetRoot) {
            throw new RuntimeException('Moving items between spaces is not allowed.');
        }
    }

    /**
     * Per-space size limit, enforced by location (subtree sum), independent
     * of the per-user personal quota.
     */
    public static function assertWithinSpaceQuota(int $folderId, int $incomingBytes, ?PDO $pdo = null, ?string $excludeToken = null): void
    {
        $pdo ??= Database::connection();
        $root = self::spaceRootIdForFolder($folderId, $pdo);
        $space = $root === null ? null : self::findByFolderId($root, $pdo);

        if ($space === null || $space['size_limit_bytes'] === null) {
            return;
        }

        $limit = (int) $space['size_limit_bytes'];
        $used = self::usageBytes($space, $pdo);
        $reserved = self::reservedBytesForSpace((int) $space['folder_id'], $pdo, $excludeToken);
        $projected = $used + $reserved + max(0, $incomingBytes);

        if ($projected > $limit) {
            throw new RuntimeException(sprintf(
                'This upload would exceed the space limit of %s.',
                wb_format_bytes($limit)
            ));
        }
    }

    public static function transferBytes(int $folderId, PDO $pdo): int
    {
        return self::usageBytes(['folder_id' => $folderId], $pdo) + self::reservedBytesForSpace($folderId, $pdo);
    }

    private static function reservedBytesForSpace(int $spaceRootId, PDO $pdo, ?string $excludeToken = null): int
    {
        $descendantIds = array_flip(FileManager::descendantFolderIdsForSpace($spaceRootId, $pdo));
        $chunkRoot = wb_storage_path('chunks');

        if (!is_dir($chunkRoot)) {
            return 0;
        }

        $reserved = 0;
        $items = scandir($chunkRoot) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $excludeToken) {
                continue;
            }

            $metaPath = $chunkRoot . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . 'meta.json';

            if (!is_file($metaPath)) {
                continue;
            }

            $metadata = json_decode((string) file_get_contents($metaPath), true);

            if (is_array($metadata) && isset($descendantIds[(int) ($metadata['folder_id'] ?? 0)])) {
                $reserved += max(0, (int) ($metadata['size'] ?? 0));
            }
        }

        return $reserved;
    }

    /**
     * Whether the actor may edit sharing grants for the given folder:
     * admins always, space owners inside their own space when the global
     * sharing policy allows it.
     */
    public static function canManageSpaceSharing(array $actor, int $folderId, ?PDO $pdo = null): bool
    {
        if (in_array($actor['role'] ?? '', ['admin', 'super_admin'], true)) {
            return true;
        }

        $policy = self::policy($pdo);

        if (!$policy['enabled'] || !$policy['sharing_allowed']) {
            return false;
        }

        $pdo ??= Database::connection();
        $space = self::findForUser((int) $actor['id'], true, $pdo);

        if ($space === null) {
            return false;
        }

        $spaceRootId = self::spaceRootIdForFolder($folderId, $pdo);

        return $spaceRootId === (int) $space['folder_id'];
    }

    /**
     * Highest grant level a space owner may hand out: "view" restricts to
     * read-only grants, "write" allows the full matrix.
     */
    public static function clampEntriesToGrantLevel(array $entries, string $maxLevel): array
    {
        if ($maxLevel === 'write') {
            return $entries;
        }

        foreach ($entries as $index => $entry) {
            $entries[$index] = [
                'folder_id' => (int) ($entry['folder_id'] ?? 0),
                'can_view' => wb_parse_bool($entry['can_view'] ?? false),
                'can_upload' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_create_folders' => false,
            ];
        }

        return $entries;
    }

    /**
     * Current per-user grants on one folder, resolved to usernames and the
     * coarse "view"/"write" level used by the space sharing UI.
     *
     * @return array<int, array{user_id: int, username: string, level: string}>
     */
    public static function folderGrants(int $folderId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $statement = $pdo->prepare(
            "SELECT fp.principal_id, fp.can_view, fp.can_upload, fp.can_edit, fp.can_delete, fp.can_create_folders, u.username
             FROM folder_permissions fp
             INNER JOIN users u ON u.id = fp.principal_id
             WHERE fp.folder_id = :folder_id AND fp.principal_type = 'user'
             ORDER BY u.username ASC"
        );
        $statement->execute([':folder_id' => $folderId]);

        $grants = [];

        foreach ($statement->fetchAll() as $row) {
            $isWrite = (int) $row['can_upload'] === 1
                || (int) $row['can_edit'] === 1
                || (int) $row['can_delete'] === 1
                || (int) $row['can_create_folders'] === 1;

            $grants[] = [
                'user_id' => (int) $row['principal_id'],
                'username' => (string) $row['username'],
                'level' => $isWrite ? 'write' : 'view',
            ];
        }

        return $grants;
    }

    /**
     * Replaces the per-user grants on a folder inside the actor's own space.
     * Grant levels are clamped to the global sharing policy; administrators
     * are skipped (they already hold full access).
     *
     * @param array<int, array{username: string, level: string}> $grants
     */
    public static function saveFolderGrants(array $actor, int $folderId, array $grants, ?PDO $pdo = null): void
    {
        $storageLock = new StorageLock();
        $pdo ??= Database::connection();
        $policy = self::policy($pdo);
        $space = self::findForUser((int) $actor['id'], true, $pdo);
        $isAdmin = in_array($actor['role'] ?? '', ['admin', 'super_admin'], true);

        if (!$isAdmin) {
            if (!$policy['enabled'] || !$policy['sharing_allowed']) {
                throw new RuntimeException('Sharing spaces is disabled by the administrator.');
            }

            if ($space === null) {
                throw new RuntimeException('You do not have a space.');
            }

            $spaceRootId = self::spaceRootIdForFolder($folderId, $pdo);

            if ($spaceRootId !== (int) $space['folder_id']) {
                throw new RuntimeException('You can only share folders inside your own space.');
            }
        }

        $userStatement = $pdo->prepare('SELECT id, role FROM users WHERE username = :username LIMIT 1');
        $entries = [];

        foreach ($grants as $grant) {
            $username = trim((string) ($grant['username'] ?? ''));
            $level = strtolower(trim((string) ($grant['level'] ?? 'view')));

            if ($username === '') {
                continue;
            }

            if (!in_array($level, ['view', 'write'], true)) {
                throw new RuntimeException('Grant level must be view or write.');
            }

            if ($policy['max_grant_level'] !== 'write') {
                $level = 'view';
            }

            $userStatement->execute([':username' => $username]);
            $user = $userStatement->fetch();

            if ($user === false) {
                throw new RuntimeException(sprintf('User "%s" was not found.', $username));
            }

            $userId = (int) $user['id'];

            if ($userId === (int) $actor['id']) {
                continue;
            }

            if (in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
                // Admins already hold full access; do not materialize rows.
                continue;
            }

            $entries[$userId] = [
                'principal_id' => $userId,
                'can_view' => true,
                'can_upload' => $level === 'write',
                'can_edit' => $level === 'write',
                'can_delete' => $level === 'write',
                'can_create_folders' => $level === 'write',
            ];
        }

        $pdo->beginTransaction();

        try {
            $pdo->prepare("DELETE FROM folder_permissions WHERE folder_id = :folder_id AND principal_type = 'user'")
                ->execute([':folder_id' => $folderId]);

            $insert = $pdo->prepare(
                "INSERT INTO folder_permissions
                    (folder_id, principal_type, principal_id, can_view, can_upload, can_edit, can_delete, can_create_folders, created_at, updated_at)
                 VALUES
                    (:folder_id, 'user', :principal_id, :can_view, :can_upload, :can_edit, :can_delete, :can_create_folders, :created_at, :updated_at)"
            );

            foreach ($entries as $entry) {
                $insert->execute([
                    ':folder_id' => $folderId,
                    ':principal_id' => $entry['principal_id'],
                    ':can_view' => $entry['can_view'] ? 1 : 0,
                    ':can_upload' => $entry['can_upload'] ? 1 : 0,
                    ':can_edit' => $entry['can_edit'] ? 1 : 0,
                    ':can_delete' => $entry['can_delete'] ? 1 : 0,
                    ':can_create_folders' => $entry['can_create_folders'] ? 1 : 0,
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

        AuditLog::record('space.permissions.save', 'file_management', [
            'actor_user' => $actor,
            'target_type' => 'folder',
            'target_id' => $folderId,
            'summary' => sprintf('Updated space sharing grants on folder #%d (%d grant(s)).', $folderId, count($entries)),
        ], $pdo);
    }
}
