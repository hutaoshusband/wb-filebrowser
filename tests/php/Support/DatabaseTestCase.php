<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests\Support;

use PHPUnit\Framework\TestCase;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\Installer;
use WbFileBrowser\Security;

abstract class DatabaseTestCase extends TestCase
{
    protected array $installResult = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSessionState();
        $this->resetStorage();
        Database::disconnect();
        Installer::ensureRuntimeDirectories();
        $this->installResult = Installer::install('superadmin', 'SuperSecurePass123!');
        Database::disconnect();
    }

    protected function tearDown(): void
    {
        Database::disconnect();
        $this->resetSessionState();
        $this->resetStorage();
        parent::tearDown();
    }

    protected function superAdmin(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, username, role, status, force_password_reset, is_immutable, storage_quota_bytes, created_at, updated_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $this->installResult['super_admin_id']]);

        return $statement->fetch() ?: [];
    }

    protected function createUser(string $username = 'member', string $role = 'user'): array
    {
        $now = wb_now();
        $statement = Database::connection()->prepare(
            'INSERT INTO users (username, password_hash, role, status, force_password_reset, is_immutable, storage_quota_bytes, created_at, updated_at)
             VALUES (:username, :password_hash, :role, :status, 0, 0, NULL, :created_at, :updated_at)'
        );
        $statement->execute([
            ':username' => $username,
            ':password_hash' => Security::hashPassword('AnotherSecurePass123!'),
            ':role' => $role,
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = (int) Database::connection()->lastInsertId();
        $fetch = Database::connection()->prepare(
            'SELECT id, username, role, status, force_password_reset, is_immutable, storage_quota_bytes, created_at, updated_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $fetch->execute([':id' => $id]);

        return $fetch->fetch() ?: [];
    }

    protected function reloadUser(int $id): array
    {
        $fetch = Database::connection()->prepare(
            'SELECT id, username, role, status, force_password_reset, is_immutable, storage_quota_bytes, created_at, updated_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $fetch->execute([':id' => $id]);

        return $fetch->fetch() ?: [];
    }

    protected function setUserStorageQuota(int $userId, ?int $quotaBytes): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET storage_quota_bytes = :storage_quota_bytes, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':storage_quota_bytes' => $quotaBytes,
            ':updated_at' => wb_now(),
            ':id' => $userId,
        ]);
    }

    protected function createFolder(string $name, ?int $parentId = null, ?array $actor = null): array
    {
        return FileManager::createFolder(
            $actor ?? $this->superAdmin(),
            $parentId ?? Database::rootFolderId(),
            $name
        );
    }

    protected function createFile(
        string $name = 'sample.txt',
        string $contents = 'hello world',
        string $mimeType = 'text/plain',
        ?int $folderId = null,
        ?array $actor = null
    ): array {
        $folderId ??= Database::rootFolderId();
        $actor ??= $this->superAdmin();
        $diskName = wb_random_token(16);
        $diskExtension = 'blob';
        $directory = wb_storage_path('uploads/' . substr($diskName, 0, 2) . '/' . substr($diskName, 2, 2));

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . $diskName . '.' . $diskExtension;
        file_put_contents($path, $contents);

        $now = wb_now();
        $statement = Database::connection()->prepare(
            'INSERT INTO files (folder_id, original_name, disk_name, disk_extension, mime_type, size, checksum, created_by, created_at, updated_at)
             VALUES (:folder_id, :original_name, :disk_name, :disk_extension, :mime_type, :size, :checksum, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            ':folder_id' => $folderId,
            ':original_name' => $name,
            ':disk_name' => $diskName,
            ':disk_extension' => $diskExtension,
            ':mime_type' => $mimeType,
            ':size' => filesize($path) ?: 0,
            ':checksum' => hash('sha256', $contents),
            ':created_by' => (int) $actor['id'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $id = (int) Database::connection()->lastInsertId();
        $fetch = Database::connection()->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $fetch->execute([':id' => $id]);

        return $fetch->fetch() ?: [];
    }

    protected function setJobNextRun(string $jobKey, string $nextRunAt): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE automation_jobs SET next_run_at = :next_run_at WHERE job_key = :job_key'
        );
        $statement->execute([
            ':job_key' => $jobKey,
            ':next_run_at' => $nextRunAt,
        ]);
    }

    protected function createStaleChunkWorkspace(string $token = 'feedface', int $hoursAgo = 48): string
    {
        $directory = wb_storage_path('chunks/' . $token);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $createdAt = gmdate('c', time() - ($hoursAgo * 3600));
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'meta.json', json_encode([
            'token' => $token,
            'folder_id' => Database::rootFolderId(),
            'user_id' => $this->installResult['super_admin_id'],
            'original_name' => 'stale.txt',
            'mime_type' => 'text/plain',
            'size' => 4,
            'total_chunks' => 1,
            'created_at' => $createdAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        touch($directory, time() - ($hoursAgo * 3600));

        return $directory;
    }

    protected function executeConcurrentIsolatedPhp(string $code, int $workerCount): array
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wb-isolated-workers-' . bin2hex(random_bytes(8));

        if (!mkdir($workspace, 0775, true) && !is_dir($workspace)) {
            self::fail('Unable to create an isolated worker workspace.');
        }

        $startPath = $workspace . DIRECTORY_SEPARATOR . 'start.flag';
        $processes = [];

        try {
            for ($index = 0; $index < max(1, $workerCount); $index++) {
                $scriptPath = $workspace . DIRECTORY_SEPARATOR . 'worker-' . $index . '.php';
                $script = $this->isolatedPhpScript(sprintf(
                    <<<'PHP'
while (!is_file(%s)) {
    usleep(1000);
}

%s
PHP,
                    var_export($startPath, true),
                    $code
                ));
                file_put_contents($scriptPath, $script);

                $process = proc_open(
                    [PHP_BINARY, $scriptPath],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    WB_ROOT
                );

                if (!is_resource($process)) {
                    self::fail('Unable to start an isolated PHP worker.');
                }

                fclose($pipes[0]);
                $processes[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                ];
            }

            file_put_contents($startPath, 'go');

            $results = [];

            foreach ($processes as $process) {
                $stdout = stream_get_contents($process['pipes'][1]);
                $stderr = stream_get_contents($process['pipes'][2]);
                fclose($process['pipes'][1]);
                fclose($process['pipes'][2]);

                $results[] = [
                    'exit_code' => proc_close($process['process']),
                    'stdout' => $stdout === false ? '' : $stdout,
                    'stderr' => $stderr === false ? '' : $stderr,
                ];
            }

            return $results;
        } finally {
            $this->deleteDirectory($workspace);
        }
    }

    private function isolatedPhpScript(string $code): string
    {
        return sprintf(
            <<<'PHP'
<?php
declare(strict_types=1);

define('WB_ROOT', %s);
define('WB_STORAGE', %s);
define('WB_BASE_PATH', %s);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require %s;

%s
PHP,
            var_export(WB_ROOT, true),
            var_export(WB_STORAGE, true),
            var_export(WB_BASE_PATH, true),
            var_export(WB_ROOT . '/app/bootstrap.php', true),
            $code
        );
    }

    private function resetStorage(): void
    {
        if (is_dir(WB_STORAGE)) {
            $this->deleteDirectory(WB_STORAGE);
        }
    }

    private function resetSessionState(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        $_SESSION = [];

        if (session_name() !== '' && isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
