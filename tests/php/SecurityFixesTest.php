<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Auth;
use WbFileBrowser\AutomationRunner;
use WbFileBrowser\Database;
use WbFileBrowser\Installer;
use WbFileBrowser\Security;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class SecurityFixesTest extends DatabaseTestCase
{
    public function testPasswordResetRevokesEveryExistingSession(): void
    {
        Auth::login('superadmin', 'SuperSecurePass123!');
        $first = $_SESSION;
        Auth::login('superadmin', 'SuperSecurePass123!');
        $second = $_SESSION;
        $this->assertArrayNotHasKey('password_hash', Auth::currentUser());
        $response = $this->request('admin.users.password', 'POST', [
            'user_id' => $this->installResult['super_admin_id'],
            'password' => 'ReplacementSecure123!',
        ]);
        $this->assertSame(200, $response['status'], $response['body']);
        foreach ([$first, $second] as $session) {
            $_SESSION = $session;
            $this->assertNull(Auth::currentUser());
        }
        $this->assertSame('superadmin', Auth::login('superadmin', 'ReplacementSecure123!')['username']);
    }

    public function testForcedPasswordChangeBlocksReadAndWriteAndAllowsRecovery(): void
    {
        $user = $this->createUser();
        Database::connection()->exec('UPDATE users SET force_password_reset = 1 WHERE id = ' . (int) $user['id']);
        Auth::login('member', 'AnotherSecurePass123!');
        $old = $_SESSION;
        foreach (['tree.list', 'files.stream', 'admin.dashboard'] as $action) {
            $this->assertSame(403, $this->request($action, 'GET')['status']);
        }
        $this->assertSame(403, $this->request('folders.create', 'POST', ['parent_id' => 1, 'name' => 'blocked'])['status']);
        $session = $this->request('auth.session', 'GET');
        $this->assertSame(200, $session['status']);
        $this->assertArrayNotHasKey('scope', json_decode($session['body'], true));
        $this->assertArrayNotHasKey('storage', json_decode($session['body'], true));
        $bad = $this->request('auth.password', 'POST', ['current_password' => 'wrong', 'password' => 'ReplacementSecure123!']);
        $this->assertSame(400, $bad['status']);
        $changed = Auth::changePassword('AnotherSecurePass123!', 'ReplacementSecure123!');
        $this->assertSame(0, (int) $changed['force_password_reset']);
        $this->assertSame($user['id'], Auth::currentUser()['id']);
        $_SESSION = $old;
        $this->assertNull(Auth::currentUser());
    }

    public function testPasswordChangeApiClearsRequirementAndRevokesOldSession(): void
    {
        Auth::login('superadmin', 'SuperSecurePass123!');
        Database::connection()->exec('UPDATE users SET force_password_reset = 1');
        $response = $this->request('auth.password', 'POST', ['current_password' => 'SuperSecurePass123!', 'password' => 'ReplacementSecure123!']);
        $this->assertSame(200, $response['status'], $response['body']);
        $body = json_decode($response['body'], true);
        $this->assertSame(0, (int) $body['user']['force_password_reset']);
        $this->assertNotEmpty($body['csrf_token']);
        $this->assertNull(Auth::currentUser());
        $this->assertSame('superadmin', Auth::login('superadmin', 'ReplacementSecure123!')['username']);
    }

    public function testConcurrentLoginsStopBeforeCheckingExtraPasswords(): void
    {
        $results = $this->executeConcurrentIsolatedPhp(<<<'PHP'
try {
    \WbFileBrowser\Auth::login('superadmin', 'wrong-password');
} catch (\RuntimeException $e) {
    echo $e instanceof \WbFileBrowser\BlockedAccessException ? 'blocked' : $e->getMessage();
}
PHP, 8);
        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertContains($result['stdout'], ['blocked', 'Invalid username or password.']);
        }
        $this->assertSame(5, (int) Database::connection()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testMutationsRejectGetAndMissingCsrf(): void
    {
        Auth::login('superadmin', 'SuperSecurePass123!');
        foreach (['auth.logout', 'folders.create', 'admin.users.password', 'upload.chunk'] as $action) {
            $this->assertSame(405, $this->request($action, 'GET')['status']);
            $this->assertSame(400, $this->request($action, 'POST', [], false)['status']);
        }
        $this->assertSame(200, $this->request('tree.list', 'GET')['status']);
        $created = $this->request('folders.create', 'POST', ['parent_id' => Database::rootFolderId(), 'name' => 'Allowed']);
        $this->assertSame(201, $created['status'], $created['body']);
    }

    public function testOversizedJsonRejectedBeforeAuthentication(): void
    {
        $response = $this->request('auth.login', 'POST', [], false, "\$_SERVER['CONTENT_TYPE'] = 'application/json'; \$_SERVER['CONTENT_LENGTH'] = 1048577;");
        $this->assertSame(413, $response['status']);
    }

    public function testParallelReservationsCannotExceedLimit(): void
    {
        $results = $this->executeConcurrentIsolatedPhp(<<<'PHP'
try {
    \WbFileBrowser\Security::reserveRateLimit([['scope' => 'parallel', 'identifier' => 'same', 'limit' => 5, 'window' => 600]], 'blocked');
    echo 'allowed';
} catch (\RuntimeException $e) {
    echo $e->getMessage();
}
PHP, 10);
        $allowed = 0;
        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertContains($result['stdout'], ['allowed', 'blocked']);
            $allowed += $result['stdout'] === 'allowed' ? 1 : 0;
        }
        $this->assertSame(5, $allowed);
        $this->assertSame(5, (int) Database::connection()->query("SELECT hits FROM rate_limits WHERE scope = 'parallel'")->fetchColumn());
    }

    public function testParallelCounterIncrementsAreNotLost(): void
    {
        $results = $this->executeConcurrentIsolatedPhp(<<<'PHP'
for ($i = 0; $i < 10; $i++) {
    \WbFileBrowser\Security::consumeRateLimit([['scope' => 'increments', 'identifier' => 'same', 'limit' => 100, 'window' => 600]]);
}
PHP, 6);
        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $result['stderr']);
        }
        $this->assertSame(60, (int) Database::connection()->query("SELECT hits FROM rate_limits WHERE scope = 'increments'")->fetchColumn());
    }

    public function testOnlyOneConcurrentInstallerCreatesAnAdministrator(): void
    {
        Database::disconnect();
        unlink(Installer::lockFilePath());
        unlink(Installer::databasePath());
        $results = $this->executeConcurrentIsolatedPhp(<<<'PHP'
try {
    \WbFileBrowser\Installer::install('admin' . bin2hex(random_bytes(4)), 'SuperSecurePass123!');
    echo 'installed';
} catch (\RuntimeException $e) {
    echo $e->getMessage();
}
PHP, 4);
        $successes = 0;
        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertContains($result['stdout'], ['installed', 'wb-filebrowser is already installed.']);
            $successes += $result['stdout'] === 'installed' ? 1 : 0;
        }
        clearstatcache();
        $this->assertSame(1, $successes);
        $this->assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn());
    }

    public function testMissingInstallationMarkerDoesNotPermitSecondAdmin(): void
    {
        $pdo = Database::connection();
        unlink(Installer::lockFilePath());
        try {
            Installer::install('secondadmin', 'SuperSecurePass123!');
            self::fail('A second administrator must not be created.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testRequestHostCannotEnableNetworkProbe(): void
    {
        $previous = getenv('WB_PUBLIC_ORIGIN');
        putenv('WB_PUBLIC_ORIGIN');
        try {
            $_SERVER['HTTP_HOST'] = '127.0.0.1:9';
            $result = AutomationRunner::evaluateStorageShield('http://127.0.0.1:9');
            $this->assertSame('error', $result['state']);
            $this->assertStringContainsString('WB_PUBLIC_ORIGIN', $result['message']);
        } finally {
            $_SERVER['HTTP_HOST'] = 'localhost';
            putenv($previous === false ? 'WB_PUBLIC_ORIGIN' : 'WB_PUBLIC_ORIGIN=' . $previous);
        }
    }

    public function testJsonReadIsBoundedEvenWithoutContentLength(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = tempnam(sys_get_temp_dir(), 'wb-json-');
        file_put_contents($router, '<?php require ' . var_export(WB_ROOT . '/app/helpers.php', true) . '; unset($_SERVER["CONTENT_LENGTH"]); wb_json_response(["bytes" => strlen(wb_request_data()["data"] ?? "")]);');
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, WB_ROOT);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $ready = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if (is_resource($ready)) {
                    fclose($ready);
                    break;
                }
                usleep(20000);
            }
            foreach ([1048500 => 200, 1048600 => 413] as $bytes => $expected) {
                $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['data' => str_repeat('x', $bytes)]), 'ignore_errors' => true, 'timeout' => 5]]);
                $body = file_get_contents('http://' . $address . '/', false, $context);
                $this->assertStringContainsString(' ' . $expected . ' ', $http_response_header[0]);
                if ($expected === 200) {
                    $this->assertSame($bytes, json_decode($body, true)['bytes']);
                }
            }
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            unlink($router);
        }
    }

    private function request(string $action, string $method, array $post = [], bool $csrf = true, string $extra = ''): array
    {
        $code = '$_SESSION = ' . var_export($_SESSION, true) . ';' .
            '$_GET = ' . var_export(['action' => $action], true) . ';' .
            '$_POST = ' . var_export($post, true) . ';' .
            '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' .
            ($csrf ? '$_SERVER["HTTP_X_CSRF_TOKEN"] = \WbFileBrowser\Security::csrfToken();' : '') . $extra . <<<'PHP'
ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body]);
});
require WB_ROOT . '/api/index.php';
PHP;
        $result = $this->executeConcurrentIsolatedPhp($code, 1)[0];
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }
}
