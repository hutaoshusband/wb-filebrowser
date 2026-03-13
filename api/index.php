<?php

declare(strict_types=1);

use WbFileBrowser\Auth;
use WbFileBrowser\AutomationRunner;
use WbFileBrowser\AuditLog;
use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\FileShares;
use WbFileBrowser\IpBanService;
use WbFileBrowser\Installer;
use WbFileBrowser\MaintenanceMode;
use WbFileBrowser\MaintenanceModeException;
use WbFileBrowser\Permissions;
use WbFileBrowser\Security;
use WbFileBrowser\Settings;

require __DIR__ . '/../app/bootstrap.php';

$action = (string) ($_GET['action'] ?? '');
$installed = Installer::isInstalled();

Security::sendApiHeaders();

if ($installed) {
    try {
        IpBanService::assertCurrentIpAllowed();
    } catch (BlockedAccessException $exception) {
        if (in_array($action, ['files.stream', 'share.stream'], true)) {
            http_response_code(403);
            exit;
        }

        wb_blocked_response($exception, 403);
    }
}

try {
    $requestData = wb_request_data();
    $csrfToken = $requestData['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $currentUser = $installed ? Auth::currentUser() : null;

    if ($installed) {
        MaintenanceMode::assertActionAllowed($action, $currentUser);
    }

    $requireCsrf = static function () use ($action, $csrfToken): void {
        if (wb_request_method() !== 'GET' && !in_array($action, ['auth.logout'], true)) {
            Security::assertCsrfToken(is_string($csrfToken) ? $csrfToken : null);
        }
    };

    switch ($action) {
        case 'install.status':
            wb_json_response([
                'ok' => true,
                'installed' => $installed,
                'checks' => Installer::environmentChecks(),
                'app_version' => Installer::VERSION,
            ]);

        case 'install.run':
            if ($installed) {
                wb_error_response('wb-filebrowser is already installed.', 409);
            }

            $requireCsrf();
            $username = (string) ($requestData['username'] ?? '');
            $password = (string) ($requestData['password'] ?? '');
            $result = Installer::install($username, $password, $requestData);
            Security::regenerateSession();
            $_SESSION['user_id'] = $result['super_admin_id'];
            wb_json_response([
                'ok' => true,
                'redirect' => wb_url('/admin/#/dashboard'),
            ], 201);

        case 'auth.session':
            if (!$installed) {
                wb_json_response([
                    'ok' => true,
                    'installed' => false,
                    'csrf_token' => Security::csrfToken(),
                ]);
            }

            $user = Auth::currentUser();
            $surface = match ((string) ($_GET['surface'] ?? 'app')) {
                'admin' => 'admin',
                'share' => 'share',
                default => 'app',
            };
            wb_json_response([
                'ok' => true,
                'installed' => true,
                'csrf_token' => Security::csrfToken(),
                'user' => $user,
                'public_access' => Permissions::publicAccessEnabled(),
                'scope' => Permissions::scope($user),
                'root_folder_id' => Database::rootFolderId(),
                'app_version' => Database::setting('app_version', Installer::VERSION),
                'storage' => FileManager::storageStats(),
                'diagnostic' => Settings::diagnosticState(),
                'maintenance' => MaintenanceMode::payload($user, $surface),
                'display' => Settings::grouped()['display'],
                'upload_policy' => Settings::uploadPolicy(),
                'help' => [
                    'title' => 'Help',
                    'body' => 'Keep the storage directory inaccessible from the web server. Uploads are checked against the active upload policy before chunks are accepted.',
                ],
            ]);

        case 'auth.login':
            if (!$installed) {
                wb_error_response('Install the application first.', 409);
            }

            $requireCsrf();
            $user = Auth::login((string) ($requestData['username'] ?? ''), (string) ($requestData['password'] ?? ''));
            wb_json_response([
                'ok' => true,
                'user' => $user,
                'csrf_token' => Security::csrfToken(),
            ]);

        case 'auth.logout':
            if ($installed) {
                Auth::logout();
            }

            wb_json_response(['ok' => true]);

        case 'tree.list':
            $pdo = Database::connection();
            $user = Auth::currentUser($pdo);

            if ($user === null && !Permissions::publicAccessEnabled($pdo)) {
                wb_error_response('Please sign in to browse files.', 401);
            }

            $folderId = max(1, (int) ($_GET['folder_id'] ?? Database::rootFolderId()));
            wb_json_response([
                'ok' => true,
                'data' => FileManager::listFolder(
                    $user,
                    $folderId,
                    (string) ($_GET['sort'] ?? 'name'),
                    (string) ($_GET['direction'] ?? 'asc')
                ),
            ]);

        case 'tree.search':
            $pdo = Database::connection();
            $user = Auth::currentUser($pdo);

            if ($user === null && !Permissions::publicAccessEnabled($pdo)) {
                wb_error_response('Please sign in to search files.', 401);
            }

            wb_json_response([
                'ok' => true,
                'data' => FileManager::search(
                    $user,
                    (string) ($_GET['query'] ?? ''),
                    (string) ($_GET['sort'] ?? 'name'),
                    (string) ($_GET['direction'] ?? 'asc')
                ),
            ]);

        case 'tree.folders':
            $pdo = Database::connection();
            $user = Auth::currentUser($pdo);

            if ($user === null && !Permissions::publicAccessEnabled($pdo)) {
                wb_error_response('Please sign in to browse folders.', 401);
            }

            wb_json_response([
                'ok' => true,
                'folders' => FileManager::folderTree($user),
            ]);

        case 'tree.details':
            $pdo = Database::connection();
            $user = Auth::currentUser($pdo);

            if ($user === null && !Permissions::publicAccessEnabled($pdo)) {
                wb_error_response('Please sign in to inspect files.', 401);
            }

            wb_json_response([
                'ok' => true,
                'data' => FileManager::fileDetails($user, (int) ($_GET['id'] ?? 0)),
            ]);

        case 'folders.create':
            $requireCsrf();
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'folder' => FileManager::createFolder(
                    $user,
                    (int) ($requestData['parent_id'] ?? 0),
                    (string) ($requestData['name'] ?? '')
                ),
            ], 201);

        case 'folders.ensure_path':
            $requireCsrf();
            $user = Auth::requireUser();
            $pathSegments = $requestData['path_segments'] ?? [];
            wb_json_response([
                'ok' => true,
                'folder' => FileManager::ensureFolderPath(
                    $user,
                    (int) ($requestData['parent_id'] ?? 0),
                    is_array($pathSegments) ? array_values($pathSegments) : []
                ),
            ], 201);

        case 'folders.rename':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::renameFolder($user, (int) ($requestData['folder_id'] ?? 0), (string) ($requestData['name'] ?? ''));
            wb_json_response(['ok' => true]);

        case 'folders.move':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::moveFolder($user, (int) ($requestData['folder_id'] ?? 0), (int) ($requestData['target_parent_id'] ?? 0));
            wb_json_response(['ok' => true]);

        case 'folders.delete':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::deleteFolder($user, (int) ($requestData['folder_id'] ?? 0));
            wb_json_response(['ok' => true]);

        case 'files.rename':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::renameFile($user, (int) ($requestData['file_id'] ?? 0), (string) ($requestData['name'] ?? ''));
            wb_json_response(['ok' => true]);

        case 'files.move':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::moveFile($user, (int) ($requestData['file_id'] ?? 0), (int) ($requestData['target_folder_id'] ?? 0));
            wb_json_response(['ok' => true]);

        case 'files.delete':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::deleteFile($user, (int) ($requestData['file_id'] ?? 0));
            wb_json_response(['ok' => true]);

        case 'files.notes.save':
            $requireCsrf();
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'item' => FileManager::saveFileDescription(
                    $user,
                    (int) ($requestData['file_id'] ?? 0),
                    (string) ($requestData['description'] ?? '')
                ),
            ]);

        case 'folders.notes.save':
            $requireCsrf();
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'item' => FileManager::saveFolderDescription(
                    $user,
                    (int) ($requestData['folder_id'] ?? 0),
                    (string) ($requestData['description'] ?? '')
                ),
            ]);

        case 'files.share.get':
            $user = Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                'share' => FileShares::get($user, (int) ($_GET['file_id'] ?? 0)),
            ]);

        case 'files.share.create':
            $requireCsrf();
            $user = Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                'share' => FileShares::create($user, (int) ($requestData['file_id'] ?? 0), [
                    'expires_at' => $requestData['expires_at'] ?? null,
                    'max_views' => $requestData['max_views'] ?? null,
                    'password' => $requestData['password'] ?? null,
                    'clear_password' => $requestData['clear_password'] ?? false,
                ]),
            ], 201);

        case 'files.share.revoke':
            $requireCsrf();
            $user = Auth::requireAdmin();
            FileShares::revoke($user, (int) ($requestData['file_id'] ?? 0));
            wb_json_response(['ok' => true]);

        case 'upload.init':
            $requireCsrf();
            $user = Auth::requireUser();
            $uploadRateLimitBuckets = [
                [
                    'scope' => 'upload-init-user',
                    'identifier' => (string) $user['id'],
                    'limit' => 20,
                    'window' => 10 * 60,
                ],
                [
                    'scope' => 'upload-init-ip',
                    'identifier' => Security::clientIp(),
                    'limit' => 60,
                    'window' => 10 * 60,
                ],
            ];
            Security::assertRateLimitAvailable($uploadRateLimitBuckets, 'Too many upload attempts. Please wait a few minutes and try again.');
            Security::consumeRateLimit($uploadRateLimitBuckets);
            wb_json_response([
                'ok' => true,
                'data' => FileManager::uploadInit(
                    $user,
                    (int) ($requestData['folder_id'] ?? 0),
                    (string) ($requestData['original_name'] ?? ''),
                    (int) ($requestData['size'] ?? 0),
                    (string) ($requestData['mime_type'] ?? 'application/octet-stream'),
                    (int) ($requestData['total_chunks'] ?? 1),
                    is_array($requestData['relative_path_segments'] ?? null)
                        ? array_values($requestData['relative_path_segments'])
                        : []
                ),
            ], 201);

        case 'upload.chunk':
            Security::assertCsrfToken(is_string($csrfToken) ? $csrfToken : null);
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'data' => FileManager::uploadChunk(
                    $user,
                    (string) ($_POST['upload_token'] ?? ''),
                    (int) ($_POST['chunk_index'] ?? 0),
                    $_FILES['chunk'] ?? []
                ),
            ]);

        case 'upload.complete':
            $requireCsrf();
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'file' => FileManager::uploadComplete($user, (string) ($requestData['upload_token'] ?? '')),
            ], 201);

        case 'upload.cancel':
            $requireCsrf();
            $user = Auth::requireUser();
            FileManager::uploadCancel($user, (string) ($requestData['upload_token'] ?? ''));
            wb_json_response(['ok' => true]);

        case 'share.stream':
            if (!$installed) {
                http_response_code(404);
                exit;
            }

            try {
                $redirectUrl = FileShares::streamRedirectUrl(
                    (string) ($_GET['token'] ?? ''),
                    (string) ($_GET['grant'] ?? '')
                );

                if ($redirectUrl !== null) {
                    header('Location: ' . $redirectUrl, true, 303);
                    exit;
                }

                $grant = (string) ($_GET['grant'] ?? '');

                if ($grant !== '') {
                    FileShares::streamGranted($grant);
                }

                FileShares::stream((string) ($_GET['token'] ?? ''), (string) ($_GET['disposition'] ?? 'inline'));
            } catch (BlockedAccessException) {
                http_response_code(403);
                exit;
            } catch (\RuntimeException $exception) {
                http_response_code($exception->getMessage() === 'Share password is required.' ? 403 : 404);
                exit;
            }

        case 'files.stream':
            $user = $installed ? Auth::currentUser() : null;

            if (!$installed) {
                http_response_code(404);
                exit;
            }

            if ($user === null && !Permissions::publicAccessEnabled()) {
                http_response_code(401);
                exit;
            }

            FileManager::streamFile($user, (int) ($_GET['id'] ?? 0), (string) ($_GET['disposition'] ?? 'inline'));

        case 'admin.dashboard':
            Auth::requireAdmin();
            $pdo = Database::connection();
            AutomationRunner::syncJobs($pdo);
            $counts = [
                'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'files' => (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
                'folders' => max(0, (int) $pdo->query('SELECT COUNT(*) FROM folders')->fetchColumn() - 1),
            ];
            wb_json_response([
                'ok' => true,
                'stats' => [
                    ...FileManager::storageStats(),
                    ...$counts,
                ],
                'diagnostic' => Settings::diagnosticState(),
                'automation' => [
                    'jobs' => AutomationRunner::jobs($pdo),
                ],
                'upload_policy' => Settings::uploadPolicy($pdo),
                'public_access' => Permissions::publicAccessEnabled($pdo),
            ]);

        case 'admin.users.list':
            Auth::requireAdmin();
            $users = Database::connection()->query(
                'SELECT
                    users.id,
                    users.username,
                    users.role,
                    users.status,
                    users.force_password_reset,
                    users.is_immutable,
                    users.storage_quota_bytes,
                    users.created_at,
                    users.updated_at,
                    users.last_login_at,
                    COALESCE(file_usage.used_bytes, 0) AS storage_used_bytes
                 FROM users
                 LEFT JOIN (
                    SELECT created_by, SUM(size) AS used_bytes
                    FROM files
                    GROUP BY created_by
                 ) AS file_usage ON file_usage.created_by = users.id
                 ORDER BY role DESC, username ASC'
            )->fetchAll();
            wb_json_response([
                'ok' => true,
                'users' => array_map(static function (array $user): array {
                    $user['id'] = (int) $user['id'];
                    $user['force_password_reset'] = (int) $user['force_password_reset'] === 1;
                    $user['is_immutable'] = (int) $user['is_immutable'] === 1;
                    $user['storage_used_bytes'] = (int) ($user['storage_used_bytes'] ?? 0);
                    $user['storage_used_label'] = wb_format_bytes($user['storage_used_bytes']);
                    $user['storage_quota_bytes'] = $user['storage_quota_bytes'] === null ? null : (int) $user['storage_quota_bytes'];
                    $user['storage_quota_label'] = $user['storage_quota_bytes'] === null
                        ? 'Unlimited'
                        : wb_format_bytes($user['storage_quota_bytes']);

                    return $user;
                }, $users),
            ]);

        case 'admin.users.create':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $role = (string) ($requestData['role'] ?? 'user');

            if ($actor['role'] !== 'super_admin' && $role !== 'user') {
                wb_error_response('Only the Super-Admin can create administrators.', 403);
            }

            if (!in_array($role, ['admin', 'user'], true)) {
                wb_error_response('Invalid role.', 422);
            }

            $password = (string) ($requestData['password'] ?? '');

            if (mb_strlen($password) < 12) {
                wb_error_response('Passwords must be at least 12 characters long.', 422);
            }

            $statement = Database::connection()->prepare(
                'INSERT INTO users (username, password_hash, role, status, force_password_reset, is_immutable, created_at, updated_at)
                 VALUES (:username, :password_hash, :role, :status, :force_password_reset, 0, :created_at, :updated_at)'
            );
            $statement->execute([
                ':username' => wb_validate_entry_name((string) ($requestData['username'] ?? ''), 'username'),
                ':password_hash' => Security::hashPassword($password),
                ':role' => $role,
                ':status' => 'active',
                ':force_password_reset' => wb_parse_bool($requestData['force_password_reset'] ?? false) ? 1 : 0,
                ':created_at' => wb_now(),
                ':updated_at' => wb_now(),
            ]);
            $createdUserId = (int) Database::connection()->lastInsertId();
            AuditLog::record('admin.user.create', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'user',
                'target_id' => $createdUserId,
                'target_label' => wb_validate_entry_name((string) ($requestData['username'] ?? ''), 'username'),
                'summary' => 'Created user ' . (string) ($requestData['username'] ?? ''),
                'metadata' => [
                    'role' => $role,
                ],
            ]);
            wb_json_response(['ok' => true], 201);

        case 'admin.users.update':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $targetId = (int) ($requestData['user_id'] ?? 0);
            $pdo = Database::connection();
            $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $targetId]);
            $target = $statement->fetch();

            if (!is_array($target)) {
                wb_error_response('User not found.', 404);
            }

            if ((int) $target['is_immutable'] === 1 && $actor['role'] !== 'super_admin') {
                wb_error_response('Only the Super-Admin can modify this account.', 403);
            }

            if ($actor['role'] !== 'super_admin' && $target['role'] !== 'user') {
                wb_error_response('Admins can only manage standard user accounts.', 403);
            }

            $role = (string) ($requestData['role'] ?? $target['role']);

            if ($actor['role'] !== 'super_admin' && $role !== 'user') {
                wb_error_response('Only the Super-Admin can grant administrator access.', 403);
            }

            $storageQuotaBytes = null;
            $storageQuotaInput = $requestData['storage_quota_bytes'] ?? $target['storage_quota_bytes'];

            if ($role === 'user' && $storageQuotaInput !== null && $storageQuotaInput !== '') {
                $storageQuotaBytes = filter_var($storageQuotaInput, FILTER_VALIDATE_INT);

                if ($storageQuotaBytes === false || $storageQuotaBytes < 1) {
                    wb_error_response('Storage quota must be a whole number of bytes greater than 0, or null for unlimited.', 422);
                }
            }

            $update = $pdo->prepare(
                'UPDATE users
                 SET role = :role,
                     status = :status,
                     force_password_reset = :force_password_reset,
                     storage_quota_bytes = :storage_quota_bytes,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':role' => $role,
                ':status' => in_array((string) ($requestData['status'] ?? $target['status']), ['active', 'suspended'], true) ? $requestData['status'] : $target['status'],
                ':force_password_reset' => wb_parse_bool($requestData['force_password_reset'] ?? $target['force_password_reset']) ? 1 : 0,
                ':storage_quota_bytes' => $role === 'user' ? $storageQuotaBytes : null,
                ':updated_at' => wb_now(),
                ':id' => $targetId,
            ]);
            AuditLog::record('admin.user.update', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'user',
                'target_id' => $targetId,
                'target_label' => (string) $target['username'],
                'summary' => 'Updated user ' . $target['username'],
                'metadata' => [
                    'role' => $role,
                    'status' => (string) ($requestData['status'] ?? $target['status']),
                    'force_password_reset' => wb_parse_bool($requestData['force_password_reset'] ?? $target['force_password_reset']),
                ],
            ], $pdo);
            wb_json_response(['ok' => true]);

        case 'admin.users.password':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $targetId = (int) ($requestData['user_id'] ?? 0);
            $pdo = Database::connection();
            $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $targetId]);
            $target = $statement->fetch();

            if (!is_array($target)) {
                wb_error_response('User not found.', 404);
            }

            if ((int) $target['is_immutable'] === 1 && $actor['role'] !== 'super_admin') {
                wb_error_response('Only the Super-Admin can reset this password.', 403);
            }

            if ($actor['role'] !== 'super_admin' && $target['role'] !== 'user') {
                wb_error_response('Admins can only manage standard user accounts.', 403);
            }

            $password = (string) ($requestData['password'] ?? '');

            if (mb_strlen($password) < 12) {
                wb_error_response('Passwords must be at least 12 characters long.', 422);
            }

            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash, force_password_reset = :force_password_reset, updated_at = :updated_at WHERE id = :id');
            $update->execute([
                ':password_hash' => Security::hashPassword($password),
                ':force_password_reset' => wb_parse_bool($requestData['force_password_reset'] ?? false) ? 1 : 0,
                ':updated_at' => wb_now(),
                ':id' => $targetId,
            ]);
            AuditLog::record('admin.user.password_reset', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'user',
                'target_id' => $targetId,
                'target_label' => (string) $target['username'],
                'summary' => 'Reset password for ' . $target['username'],
            ], $pdo);
            wb_json_response(['ok' => true]);

        case 'admin.permissions.get':
            $actor = Auth::requireAdmin();
            $principalType = (string) ($_GET['principal_type'] ?? 'user');
            $principalId = $principalType === 'guest' ? 0 : (int) ($_GET['principal_id'] ?? 0);
            $matrix = Permissions::matrix($actor, $principalType, $principalId);
            wb_json_response([
                'ok' => true,
                'folders' => $matrix['folders'],
                'permissions' => $matrix['permissions'],
            ]);

        case 'admin.permissions.save':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $principalType = (string) ($requestData['principal_type'] ?? 'user');
            $principalId = $principalType === 'guest' ? 0 : (int) ($requestData['principal_id'] ?? 0);
            $entries = $requestData['entries'] ?? [];
            Permissions::saveMatrix($actor, $principalType, $principalId, is_array($entries) ? $entries : []);
            $activeEntries = is_array($entries)
                ? array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && wb_parse_bool($entry['can_view'] ?? false)))
                : [];
            AuditLog::record('admin.permissions.save', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'permissions',
                'target_id' => $principalId,
                'target_label' => $principalType === 'guest' ? 'Guest permissions' : 'User permissions #' . $principalId,
                'summary' => 'Saved ' . $principalType . ' permissions',
                'metadata' => [
                    'principal_type' => $principalType,
                    'principal_id' => $principalId,
                    'visible_entries' => count($activeEntries),
                ],
            ]);
            wb_json_response(['ok' => true]);

        case 'admin.audit.list':
            Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                ...AuditLog::list([
                    'page' => (int) ($_GET['page'] ?? 1),
                    'query' => (string) ($_GET['query'] ?? ''),
                    'category' => (string) ($_GET['category'] ?? ''),
                ]),
            ]);

        case 'admin.audit.cleanup':
            $requireCsrf();
            $actor = Auth::requireSuperAdmin();
            $days = $requestData['days'] ?? null;
            wb_json_response([
                'ok' => true,
                ...AuditLog::cleanup(
                    (string) ($requestData['mode'] ?? ''),
                    $days === null || $days === '' ? null : (int) $days,
                    $actor
                ),
            ]);

        case 'admin.security.get':
            $user = Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                'settings' => Settings::grouped(),
                'diagnostics' => Settings::diagnosticState(),
                'upload_policy' => Settings::uploadPolicy(),
                'can_manage_settings' => $user['role'] === 'super_admin',
                ...IpBanService::list(),
            ]);

        case 'admin.security.ban':
            $requireCsrf();
            $actor = Auth::requireSuperAdmin();
            $ban = IpBanService::ban(
                $actor,
                (string) ($requestData['ip_address'] ?? ''),
                (string) ($requestData['reason'] ?? ''),
                isset($requestData['expires_at']) ? (string) $requestData['expires_at'] : null
            );
            wb_json_response([
                'ok' => true,
                'ban' => $ban,
                ...IpBanService::list(),
            ], 201);

        case 'admin.security.unban':
            $requireCsrf();
            $actor = Auth::requireSuperAdmin();
            $ban = IpBanService::unban($actor, (int) ($requestData['ban_id'] ?? 0));
            wb_json_response([
                'ok' => true,
                'ban' => $ban,
                ...IpBanService::list(),
            ]);

        case 'admin.settings.get':
            $user = Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                ...Settings::adminPayload(),
                'can_manage_settings' => $user['role'] === 'super_admin',
            ]);

        case 'admin.settings.save':
            $requireCsrf();
            $actor = Auth::requireSuperAdmin();
            $settings = Settings::saveAdminSettings($requestData);
            AuditLog::record('admin.settings.save', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'settings',
                'target_label' => 'Application settings',
                'summary' => 'Saved admin settings',
            ]);
            wb_json_response([
                'ok' => true,
                'settings' => $settings,
                'automation' => [
                    'jobs' => AutomationRunner::jobs(),
                ],
                'diagnostics' => Settings::diagnosticState(),
            ]);

        case 'admin.automation.tick':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $result = AutomationRunner::tick(wb_request_origin());
            AuditLog::record('admin.automation.tick', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'automation',
                'target_label' => 'Due jobs',
                'summary' => 'Ran due automation checks',
                'metadata' => [
                    'locked' => (bool) ($result['locked'] ?? false),
                ],
            ]);
            wb_json_response([
                'ok' => true,
                ...$result,
            ]);

        case 'admin.automation.run':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $jobKey = (string) ($requestData['job_key'] ?? '');
            $result = AutomationRunner::run($jobKey, wb_request_origin());
            AuditLog::record('admin.automation.run', 'admin_actions', [
                'actor_user' => $actor,
                'target_type' => 'automation',
                'target_label' => $jobKey,
                'summary' => 'Ran automation job ' . $jobKey,
            ]);
            wb_json_response([
                'ok' => true,
                ...$result,
            ]);

        case 'admin.diagnostic.update':
            $requireCsrf();
            Auth::requireAdmin();
            $result = AutomationRunner::evaluateStorageShield(wb_request_origin());
            wb_json_response([
                'ok' => true,
                'result' => $result,
                'diagnostic' => Settings::diagnosticState(),
            ]);

        default:
            wb_error_response('Unknown API action.', 404);
    }
} catch (MaintenanceModeException $exception) {
    wb_maintenance_response($exception->payload(), 503);
} catch (BlockedAccessException $exception) {
    if (in_array($action, ['files.stream', 'share.stream'], true)) {
        http_response_code(403);
        exit;
    }

    wb_blocked_response($exception, 403);
} catch (\InvalidArgumentException $exception) {
    wb_error_response($exception->getMessage(), 422);
} catch (\RuntimeException $exception) {
    $status = match ($exception->getMessage()) {
        'Authentication is required.' => 401,
        'Administrator access is required.', 'Super-Admin access is required.', 'Share password is required.' => 403,
        default => 400,
    };
    wb_error_response($exception->getMessage(), $status);
} catch (\PDOException $exception) {
    wb_error_response('A database error occurred.', 500);
} catch (\Throwable $exception) {
    wb_error_response('Unexpected server error.', 500);
}
