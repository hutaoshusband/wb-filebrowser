<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Database;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class ApiErrorHandlingTest extends DatabaseTestCase
{
    public function testDatabaseExceptionsAreLoggedWithoutLeakingDetailsToApiClients(): void
    {
        Database::connection()->exec(
            'CREATE TRIGGER fail_login_attempts_insert
             BEFORE INSERT ON login_attempts
             BEGIN
                 SELECT RAISE(FAIL, "sensitive trigger detail");
             END;'
        );

        $response = $this->runApiRequest('auth.login', [
            'username' => 'superadmin',
            'password' => 'wrong-password',
        ]);

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response['status']);
        $this->assertSame([
            'ok' => false,
            'message' => 'A database error occurred.',
            'errors' => [],
        ], $payload);
        $this->assertStringNotContainsString('sensitive trigger detail', $response['body']);
        $this->assertStringContainsString('sensitive trigger detail', $response['log']);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{status: int, body: string, log: string}
     */
    private function runApiRequest(string $action, array $post = []): array
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'wb-api-request-');
        $resultPath = tempnam(sys_get_temp_dir(), 'wb-api-result-');
        $logPath = tempnam(sys_get_temp_dir(), 'wb-api-log-');

        if ($scriptPath === false || $resultPath === false || $logPath === false) {
            self::fail('Unable to create temporary files for the API request test.');
        }

        $script = sprintf(
            <<<'PHP'
<?php
declare(strict_types=1);

define('WB_ROOT', %s);
define('WB_STORAGE', %s);
define('WB_BASE_PATH', '');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', %s);

$_GET = ['action' => %s];
$_POST = %s;
$_COOKIE = [];
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = '';

ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_contents();

    if ($body === false) {
        $body = '';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    file_put_contents(%s, json_encode([
        'status' => http_response_code() ?: 200,
        'body' => $body,
    ], JSON_THROW_ON_ERROR));
});

require WB_ROOT . '/app/bootstrap.php';
$_SERVER['HTTP_X_CSRF_TOKEN'] = \WbFileBrowser\Security::csrfToken();
require %s;
PHP,
            var_export(WB_ROOT, true),
            var_export(WB_STORAGE, true),
            var_export($logPath, true),
            var_export($action, true),
            var_export($post, true),
            var_export($resultPath, true),
            var_export(WB_ROOT . '/api/index.php', true)
        );

        file_put_contents($scriptPath, $script);

        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath), $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $rawResult = file_get_contents($resultPath);

            if ($rawResult === false) {
                self::fail('Unable to read the API request result payload.');
            }

            $result = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
            $log = file_get_contents($logPath);

            return [
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'log' => $log === false ? '' : $log,
            ];
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
            @unlink($logPath);
        }
    }
}
