<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class FolderShares
{
    public static function canManage(array $user, int $folderId, ?PDO $pdo = null): bool
    {
        $pdo ??= Database::connection();
        return !isset($user['folder_share_scope']) && FileShares::userLinksAllowed($user, $pdo)
            && SpaceService::canManageSpaceSharing($user, $folderId, $pdo)
            && Permissions::canViewFolderContents($folderId, $user, $pdo);
    }

    private static function assertManager(array $user, int $folderId, PDO $pdo): void
    {
        $q = $pdo->prepare('SELECT id FROM folders WHERE id = ?');
        $q->execute([$folderId]);
        if (!$q->fetchColumn() || !self::canManage($user, $folderId, $pdo)) {
            throw new RuntimeException('You do not have permission to manage links for this folder.');
        }
    }

    public static function get(array $user, int $folderId): ?array
    {
        $pdo = Database::connection();
        self::assertManager($user, $folderId, $pdo);
        $q = $pdo->prepare('SELECT * FROM folder_shares WHERE active_folder_id = ?');
        $q->execute([$folderId]);
        $row = $q->fetch();
        return $row ? self::serialize($row) : null;
    }

    public static function create(array $user, int $folderId, array $input): array
    {
        $lock = new StorageLock();
        $pdo = Database::connection();
        self::assertManager($user, $folderId, $pdo);
        $level = $input['access_level'] ?? 'view';
        if (!in_array($level, ['view', 'write'], true)) throw new RuntimeException('Choose view or write access.');
        if ($level === 'write' && !self::writeAllowed($user)) throw new RuntimeException('The administrator only allows view links.');
        $options = FileShares::normalizeOptions($input);
        if ($options['delete_after'] !== null) {
            SpaceService::assertNotSpaceRoot($folderId, 'schedule deletion of');
            if ($folderId === Database::rootFolderId() || !Permissions::canDeleteFolder($folderId, $user, $pdo)) {
                throw new RuntimeException('This folder cannot be scheduled for deletion.');
            }
        }
        $q = $pdo->prepare('SELECT * FROM folder_shares WHERE active_folder_id = ?');
        $q->execute([$folderId]);
        $old = $q->fetch();
        $password = $options['clear_password'] ? null : ($options['update_password'] ? $options['password_hash'] : ($old['password_hash'] ?? null));
        $version = (int) ($old['password_version'] ?? 0) + (($options['clear_password'] || $options['update_password']) ? 1 : 0);
        $now = wb_now();
        $values = [$options['expires_at'], $options['delete_after'], $options['max_views'], $password, $version, $level, $now];
        if ($old) {
            $pdo->prepare('UPDATE folder_shares SET expires_at=?, delete_after=?, max_views=?, password_hash=?, password_version=?, access_level=?, updated_at=? WHERE id=?')
                ->execute([...$values, $old['id']]);
        } else {
            $pdo->prepare('INSERT INTO folder_shares (expires_at, delete_after, max_views, password_hash, password_version, access_level, updated_at, folder_id, active_folder_id, token, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$values, $folderId, $folderId, wb_random_token(24), $user['id'], $now]);
        }
        AuditLog::record('share.create', 'file_management', ['actor_user' => $user, 'target_type' => 'folder', 'target_id' => $folderId, 'summary' => 'Saved public folder link (' . $level . ')'], $pdo);
        return self::get($user, $folderId);
    }

    public static function revoke(array $user, int $folderId): void
    {
        $pdo = Database::connection();
        self::assertManager($user, $folderId, $pdo);
        $pdo->prepare('UPDATE folder_shares SET active_folder_id=NULL, revoked_at=?, delete_after=NULL WHERE active_folder_id=?')->execute([wb_now(), $folderId]);
    }

    public static function writeAllowed(array $user): bool
    {
        return in_array($user['role'], ['admin', 'super_admin'], true)
            || (Database::setting('user_link_share_level', 'write') === 'write' && SpaceService::policy()['max_grant_level'] === 'write');
    }

    private static function resolve(string $token): array
    {
        $pdo = Database::connection();
        $q = $pdo->prepare('SELECT s.*, f.name FROM folder_shares s JOIN folders f ON f.id=s.folder_id WHERE s.token=? AND s.revoked_at IS NULL');
        $q->execute([$token]);
        $row = $q->fetch();
        if (!$row || ($row['expires_at'] !== null && $row['expires_at'] <= wb_now()) || ($row['delete_after'] !== null && $row['delete_after'] <= wb_now())) self::unavailable();
        $q = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $q->execute([$row['created_by']]);
        $owner = $q->fetch();
        if (!$owner || !self::canManage($owner, (int) $row['folder_id'], $pdo)) self::unavailable();
        $root = SpaceService::spaceRootIdForFolder((int) $row['folder_id'], $pdo);
        if ($root !== null) {
            $space = SpaceService::findByFolderId($root, $pdo);
            if (!$space || $space['status'] !== 'active' || !SpaceService::policy($pdo)['enabled']) self::unavailable();
        }
        $row['owner'] = $owner;
        return $row;
    }

    private static function unavailable(): never
    {
        throw new RuntimeException('Shared folder unavailable.');
    }

    public static function context(string $token, ?string $password = null, bool $acceptTerms = false): array
    {
        $lock = new StorageLock();
        $row = self::resolve($token);
        $terms = Settings::shareTermsPolicy();
        $key = 'folder:' . $token;
        $session = $_SESSION['folder_share_access'][$key] ?? [];
        $unlocked = empty($row['password_hash']) || (($session['password_version'] ?? -1) === (int) $row['password_version']);
        if (!$unlocked && $password !== null) {
            $buckets = [['scope' => 'folder-share-password', 'identifier' => $token . '|' . Security::clientIp(), 'limit' => 5, 'window' => 900]];
            Security::assertRateLimitAvailable($buckets, 'Too many password attempts. Try again later.');
            if (!password_verify($password, $row['password_hash'])) {
                Security::consumeRateLimit($buckets);
                throw new RuntimeException('Incorrect password.');
            }
            Security::clearRateLimit($buckets);
            $session['password_version'] = (int) $row['password_version'];
            $unlocked = true;
        }
        if ($acceptTerms && $unlocked) $session['terms_version'] = $terms['version'];
        $termsAccepted = !$terms['enabled'] || ($session['terms_version'] ?? 0) === $terms['version'];
        $admitted = ($session['until'] ?? 0) > time() && $unlocked && $termsAccepted;
        if (!$admitted && $row['max_views'] !== null && (int) $row['view_count'] >= (int) $row['max_views']) self::unavailable();
        if ($unlocked && $termsAccepted && !$admitted) {
            $q = Database::connection()->prepare('UPDATE folder_shares SET view_count=view_count+1 WHERE id=? AND (max_views IS NULL OR view_count < max_views)');
            $q->execute([$row['id']]);
            if ($q->rowCount() !== 1) self::unavailable();
            $session['until'] = time() + 600;
            $row['view_count']++;
        }
        $_SESSION['folder_share_access'][$key] = $session;
        return ['row' => $row, 'unlocked' => $unlocked, 'terms_accepted' => $termsAccepted, 'terms_message' => $terms['message']];
    }

    public static function actor(string $token): array
    {
        $context = self::context($token);
        if (!$context['unlocked'] || !$context['terms_accepted']) throw new RuntimeException('Open the folder link and unlock access first.');
        $row = $context['row'];
        $pdo = Database::connection();
        $ids = FileManager::descendantFolderIdsForSpace((int) $row['folder_id'], $pdo);
        // Never inherit into a separately registered personal space.
        foreach ($pdo->query('SELECT folder_id FROM spaces')->fetchAll(PDO::FETCH_COLUMN) as $root) {
            if ((int) $root !== (int) $row['folder_id'] && in_array((int) $root, $ids, true)) {
                $ids = array_values(array_diff($ids, FileManager::descendantFolderIdsForSpace((int) $root, $pdo)));
            }
        }
        $ownerScope = Permissions::scope($row['owner'], $pdo);
        $ids = array_values(array_intersect($ids, $ownerScope['content']));
        $write = $row['access_level'] === 'write' && self::writeAllowed($row['owner']);
        $scope = ['all' => false, 'ancestors' => $ids, 'content' => $ids];
        foreach (['upload', 'edit', 'delete', 'create'] as $permission) {
            $scope[$permission] = $write ? array_values(array_intersect($ids, $ownerScope[$permission])) : [];
        }
        return ['id' => (int) $row['created_by'], 'username' => 'Folder link guest', 'role' => 'folder_guest', 'folder_share_token' => $token, 'folder_share_root' => (int) $row['folder_id'], 'folder_share_scope' => $scope];
    }

    public static function serialize(array $row): array
    {
        $path = '/share/folder.php?token=' . $row['token'];
        return ['folder_id' => (int) $row['folder_id'], 'token' => $row['token'], 'url' => wb_absolute_url($path) ?? wb_url($path),
            'access_level' => $row['access_level'], 'expires_at' => $row['expires_at'], 'delete_after' => $row['delete_after'],
            'max_views' => $row['max_views'] === null ? null : (int) $row['max_views'], 'view_count' => (int) $row['view_count'],
            'remaining_views' => $row['max_views'] === null ? null : max(0, (int) $row['max_views'] - (int) $row['view_count']), 'requires_password' => !empty($row['password_hash'])];
    }

    public static function processDueDeletions(): int
    {
        $lock = new StorageLock();
        $pdo = Database::connection();
        $q = $pdo->prepare('SELECT folder_id, created_by FROM folder_shares WHERE delete_after IS NOT NULL AND delete_after <= ?');
        $q->execute([wb_now()]);
        $deleted = 0;
        foreach ($q->fetchAll() as $row) {
            $owner = $pdo->prepare('SELECT * FROM users WHERE id=?');
            $owner->execute([$row['created_by']]);
            $user = $owner->fetch();
            if ($user && self::canManage($user, (int) $row['folder_id'], $pdo)) {
                FileManager::deleteFolder($user, (int) $row['folder_id']);
                $deleted++;
            }
        }
        return $deleted;
    }
}
