<?php

declare(strict_types=1);

use WbFileBrowser\Auth;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Installer;
use WbFileBrowser\Permissions;
use WbFileBrowser\Security;

require __DIR__ . '/../app/bootstrap.php';

$action = (string) ($_GET['action'] ?? '');
$installed = Installer::isInstalled();

try {
    $requestData = wb_request_data();
    $csrfToken = $requestData['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

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
            $result = Installer::install($username, $password);
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
                'diagnostic' => [
                    'exposed' => wb_parse_bool(Database::setting('diagnostic_exposed', '0')),
                    'checked_at' => Database::setting('diagnostic_checked_at', ''),
                    'message' => Database::setting('diagnostic_message', ''),
                    'probe_path' => Database::setting('probe_relative_path', ''),
                    'probe_url' => wb_url('/storage/' . Database::setting('probe_relative_path', '')),
                ],
                'help' => [
                    'title' => 'Help',
                    'body' => 'Keep the storage directory inaccessible from the web server. If the admin warning says your storage path is exposed, add an equivalent deny rule in Nginx or IIS before uploading real files.',
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

        case 'upload.init':
            $requireCsrf();
            $user = Auth::requireUser();
            wb_json_response([
                'ok' => true,
                'data' => FileManager::uploadInit(
                    $user,
                    (int) ($requestData['folder_id'] ?? 0),
                    (string) ($requestData['original_name'] ?? ''),
                    (int) ($requestData['size'] ?? 0),
                    (string) ($requestData['mime_type'] ?? 'application/octet-stream'),
                    (int) ($requestData['total_chunks'] ?? 1)
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
                'diagnostic' => [
                    'exposed' => wb_parse_bool(Database::setting('diagnostic_exposed', '0')),
                    'checked_at' => Database::setting('diagnostic_checked_at', ''),
                    'message' => Database::setting('diagnostic_message', ''),
                    'probe_url' => wb_url('/storage/' . Database::setting('probe_relative_path', '')),
                ],
                'public_access' => Permissions::publicAccessEnabled($pdo),
            ]);

        case 'admin.users.list':
            Auth::requireAdmin();
            $users = Database::connection()->query(
                'SELECT id, username, role, status, force_password_reset, is_immutable, created_at, updated_at, last_login_at
                 FROM users ORDER BY role DESC, username ASC'
            )->fetchAll();
            wb_json_response([
                'ok' => true,
                'users' => array_map(static function (array $user): array {
                    $user['id'] = (int) $user['id'];
                    $user['force_password_reset'] = (int) $user['force_password_reset'] === 1;
                    $user['is_immutable'] = (int) $user['is_immutable'] === 1;

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

            $update = $pdo->prepare(
                'UPDATE users SET role = :role, status = :status, force_password_reset = :force_password_reset, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                ':role' => $role,
                ':status' => in_array((string) ($requestData['status'] ?? $target['status']), ['active', 'suspended'], true) ? $requestData['status'] : $target['status'],
                ':force_password_reset' => wb_parse_bool($requestData['force_password_reset'] ?? $target['force_password_reset']) ? 1 : 0,
                ':updated_at' => wb_now(),
                ':id' => $targetId,
            ]);
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
            wb_json_response(['ok' => true]);

        case 'admin.permissions.get':
            $actor = Auth::requireAdmin();
            $principalType = (string) ($_GET['principal_type'] ?? 'user');
            $principalId = $principalType === 'guest' ? 0 : (int) ($_GET['principal_id'] ?? 0);
            $pdo = Database::connection();

            if ($principalType === 'user') {
                $principalStatement = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                $principalStatement->execute([':id' => $principalId]);
                $principalRole = $principalStatement->fetchColumn();

                if ($principalRole === false) {
                    wb_error_response('User not found.', 404);
                }

                if ($actor['role'] !== 'super_admin' && $principalRole !== 'user') {
                    wb_error_response('Admins can only manage standard user permissions.', 403);
                }
            }

            $tree = FileManager::folderTree(Auth::currentUser());
            $statement = $pdo->prepare(
                'SELECT folder_id, can_view, can_upload
                 FROM folder_permissions
                 WHERE principal_type = :principal_type AND principal_id = :principal_id'
            );
            $statement->execute([
                ':principal_type' => $principalType,
                ':principal_id' => $principalId,
            ]);
            wb_json_response([
                'ok' => true,
                'folders' => $tree,
                'permissions' => $statement->fetchAll(),
            ]);

        case 'admin.permissions.save':
            $requireCsrf();
            $actor = Auth::requireAdmin();
            $principalType = (string) ($requestData['principal_type'] ?? 'user');
            $principalId = $principalType === 'guest' ? 0 : (int) ($requestData['principal_id'] ?? 0);
            $entries = $requestData['entries'] ?? [];

            if (!is_array($entries)) {
                wb_error_response('Permission entries must be an array.', 422);
            }

            $pdo = Database::connection();

            if ($principalType === 'user') {
                $principalStatement = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                $principalStatement->execute([':id' => $principalId]);
                $principalRole = $principalStatement->fetchColumn();

                if ($principalRole === false) {
                    wb_error_response('User not found.', 404);
                }

                if ($actor['role'] !== 'super_admin' && $principalRole !== 'user') {
                    wb_error_response('Admins can only manage standard user permissions.', 403);
                }
            }

            $pdo->beginTransaction();
            $deleteStatement = $pdo->prepare('DELETE FROM folder_permissions WHERE principal_type = :principal_type AND principal_id = :principal_id');
            $deleteStatement->execute([
                ':principal_type' => $principalType,
                ':principal_id' => $principalId,
            ]);

            $insertStatement = $pdo->prepare(
                'INSERT INTO folder_permissions (folder_id, principal_type, principal_id, can_view, can_upload, created_at, updated_at)
                 VALUES (:folder_id, :principal_type, :principal_id, :can_view, :can_upload, :created_at, :updated_at)'
            );

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $canView = wb_parse_bool($entry['can_view'] ?? false);
                $canUpload = wb_parse_bool($entry['can_upload'] ?? false);

                if (!$canView && !$canUpload) {
                    continue;
                }

                $insertStatement->execute([
                    ':folder_id' => (int) ($entry['folder_id'] ?? 0),
                    ':principal_type' => $principalType,
                    ':principal_id' => $principalId,
                    ':can_view' => $canView ? 1 : 0,
                    ':can_upload' => $canUpload ? 1 : 0,
                    ':created_at' => wb_now(),
                    ':updated_at' => wb_now(),
                ]);
            }

            $pdo->commit();
            wb_json_response(['ok' => true]);

        case 'admin.settings.get':
            $user = Auth::requireAdmin();
            wb_json_response([
                'ok' => true,
                'settings' => [
                    'public_access' => Permissions::publicAccessEnabled(),
                    'app_version' => Database::setting('app_version', Installer::VERSION),
                ],
                'can_manage_settings' => $user['role'] === 'super_admin',
            ]);

        case 'admin.settings.save':
            $requireCsrf();
            Auth::requireSuperAdmin();
            Database::updateSetting('public_access', wb_parse_bool($requestData['public_access'] ?? false) ? '1' : '0');
            wb_json_response(['ok' => true]);

        case 'admin.diagnostic.update':
            $requireCsrf();
            Auth::requireAdmin();
            $exposed = wb_parse_bool($requestData['exposed'] ?? false);
            Database::updateSetting('diagnostic_exposed', $exposed ? '1' : '0');
            Database::updateSetting('diagnostic_checked_at', wb_now());
            Database::updateSetting(
                'diagnostic_message',
                $exposed
                    ? 'Your server served a file directly from /storage/. Add equivalent deny rules for your web server before uploading sensitive files.'
                    : 'The latest probe could not be fetched directly. Storage appears shielded from public access.'
            );
            wb_json_response(['ok' => true]);

        default:
            wb_error_response('Unknown API action.', 404);
    }
} catch (\InvalidArgumentException $exception) {
    wb_error_response($exception->getMessage(), 422);
} catch (\RuntimeException $exception) {
    $status = match ($exception->getMessage()) {
        'Authentication is required.' => 401,
        'Administrator access is required.', 'Super-Admin access is required.' => 403,
        default => 400,
    };
    wb_error_response($exception->getMessage(), $status);
} catch (\PDOException $exception) {
    wb_error_response('A database error occurred.', 500, ['detail' => $exception->getMessage()]);
} catch (\Throwable $exception) {
    wb_error_response('Unexpected server error.', 500, ['detail' => $exception->getMessage()]);
}
