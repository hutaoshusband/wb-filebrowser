<?php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use WbFileBrowser\{Auth, FileManager, FolderShares, IpBanService, MaintenanceMode, Security, StorageLock};

Security::sendApiHeaders();
try {
    IpBanService::assertCurrentIpAllowed();
    MaintenanceMode::assertAllowed(Auth::currentUser(), 'share');
    $data = wb_request_data();
    $token = (string) ($_GET['token'] ?? '');
    $action = (string) ($_GET['action'] ?? '');
    $lock = new StorageLock();
    $actor = FolderShares::actor($token);
    if ($action === 'files.stream') {
        unset($lock);
        FileManager::streamFile($actor, (int) ($_GET['id'] ?? 0), ($_GET['disposition'] ?? '') === 'attachment' ? 'attachment' : 'inline');
    }
    switch ($action) {
        case 'auth.session':
            wb_json_response([
                'ok' => true, 'installed' => true, 'csrf_token' => Security::csrfToken(),
                'user' => null, 'public_access' => true, 'root_folder_id' => $actor['folder_share_root'],
                'home_folder_id' => $actor['folder_share_root'], 'navigation_roots' => [], 'space' => null,
                'can_create_link_shares' => false, 'can_create_write_links' => false,
                'app_version' => \WbFileBrowser\Installer::VERSION,
                'display' => \WbFileBrowser\Settings::grouped()['display'],
                'upload_policy' => \WbFileBrowser\Settings::uploadPolicy(),
                'help' => ['title' => 'Shared folder', 'body' => 'Browse this folder and its subfolders. Unavailable actions are disabled by the link permissions.'],
            ]);
        case 'tree.list':
            wb_json_response(['ok' => true, 'data' => FileManager::listFolder($actor, (int) ($_GET['folder_id'] ?? $actor['folder_share_root']), (string) ($_GET['sort'] ?? 'name'), (string) ($_GET['direction'] ?? 'asc'))]);
        case 'tree.search':
            wb_json_response(['ok' => true, 'data' => FileManager::search($actor, (string) ($_GET['query'] ?? ''), (string) ($_GET['sort'] ?? 'name'), (string) ($_GET['direction'] ?? 'asc'))]);
        case 'tree.folders':
            wb_json_response(['ok' => true, 'folders' => FileManager::folderTree($actor)]);
        case 'tree.details':
            wb_json_response(['ok' => true, 'data' => FileManager::fileDetails($actor, (int) ($_GET['id'] ?? 0))]);
    }
    if (wb_request_method() !== 'POST') throw new RuntimeException('Use POST for changes.');
    Security::assertCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['csrf_token'] ?? null);
    $folderId = (int) ($data['folder_id'] ?? $actor['folder_share_root']);
    $id = (int) ($data['file_id'] ?? $data['folder_id'] ?? 0);
    $name = (string) ($data['name'] ?? '');
    if (in_array($action, ['folders.rename', 'folders.delete', 'folders.move', 'folders.notes.save'], true) && $id === $actor['folder_share_root']) throw new RuntimeException('The shared root cannot be changed through its link.');
    $result = null;
    switch ($action) {
        case 'folders.create': wb_json_response(['ok' => true, 'folder' => FileManager::createFolder($actor, (int) ($data['parent_id'] ?? 0), $name)], 201);
        case 'folders.rename': FileManager::renameFolder($actor, $id, $name); break;
        case 'folders.delete': FileManager::deleteFolder($actor, $id); break;
        case 'files.rename': FileManager::renameFile($actor, $id, $name); break;
        case 'files.delete': FileManager::deleteFile($actor, $id); break;
        case 'folders.move': FileManager::moveFolder($actor, $id, (int) ($data['target_parent_id'] ?? 0)); break;
        case 'files.move': FileManager::moveFile($actor, $id, (int) ($data['target_folder_id'] ?? 0)); break;
        case 'folders.notes.save': wb_json_response(['ok' => true, 'item' => FileManager::saveFolderDescription($actor, $id, (string) ($data['description'] ?? ''))]);
        case 'files.notes.save': wb_json_response(['ok' => true, 'item' => FileManager::saveFileDescription($actor, $id, (string) ($data['description'] ?? ''))]);
        case 'folders.ensure_path':
            $parentId = (int) ($data['parent_id'] ?? 0);
            if (!\WbFileBrowser\Permissions::canCreateFoldersIn($parentId, $actor)) throw new RuntimeException('You cannot create folders here.');
            $folder = FileManager::ensureFolderPath($actor, $parentId, is_array($data['path_segments'] ?? null) ? $data['path_segments'] : []);
            if (!\WbFileBrowser\Permissions::canViewFolderContents((int) $folder['id'], $actor)) throw new RuntimeException('Folder unavailable.');
            wb_json_response(['ok' => true, 'folder' => $folder]);
        case 'client.log': wb_json_response(['ok' => true]);
        case 'upload.init':
            $buckets = [['scope' => 'folder-share-upload', 'identifier' => Security::clientIp(), 'limit' => 60, 'window' => 600]];
            Security::assertRateLimitAvailable($buckets, FileManager::MSG_RATE_LIMIT_UPLOAD);
            Security::consumeRateLimit($buckets);
            $result = FileManager::uploadInit($actor, $folderId, (string) ($data['original_name'] ?? ''), (int) ($data['size'] ?? 0), (string) ($data['mime_type'] ?? 'application/octet-stream'), (int) ($data['total_chunks'] ?? 1), is_array($data['relative_path_segments'] ?? null) ? $data['relative_path_segments'] : [], (string) ($data['encryption_format'] ?? ''));
            break;
        case 'upload.chunk': $result = FileManager::uploadChunk($actor, (string) ($_POST['upload_token'] ?? ''), (int) ($_POST['chunk_index'] ?? 0), $_FILES['chunk'] ?? []); break;
        case 'upload.complete': wb_json_response(['ok' => true, 'file' => FileManager::uploadComplete($actor, (string) ($data['upload_token'] ?? ''))], 201);
        case 'upload.cancel': FileManager::uploadCancel($actor, (string) ($data['upload_token'] ?? '')); break;
        default: throw new RuntimeException('Unknown folder action.');
    }
    wb_json_response(['ok' => true, 'data' => $result]);
} catch (Throwable $e) {
    wb_error_response($e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to access this folder.', 403);
}
