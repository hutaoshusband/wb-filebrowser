<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class Security
{
    private const RATE_LIMIT_RETENTION_SECONDS = 172800;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.save_path', wb_storage_path('sessions'));

        session_name('WBFILEBROWSERSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => wb_cookie_path(),
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = wb_random_token(24);
        }
    }

    public static function sendPageHeaders(): void
    {
        foreach (self::pageHeaders() as $headerName => $headerValue) {
            header($headerName . ': ' . $headerValue);
        }
    }

    public static function sendApiHeaders(): void
    {
        foreach (self::apiHeaders() as $headerName => $headerValue) {
            header($headerName . ': ' . $headerValue);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function pageHeaders(): array
    {
        return self::commonHeaders() + [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; connect-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function apiHeaders(): array
    {
        return self::commonHeaders() + [
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; object-src 'none'",
        ];
    }

    public static function csrfToken(): string
    {
        self::startSession();

        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    public static function assertCsrfToken(?string $token): void
    {
        self::startSession();

        if ($token === null || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
            throw new RuntimeException('The security token is invalid. Refresh the page and try again.');
        }
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function regenerateSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSession();
        }

        session_regenerate_id(true);
        $_SESSION['csrf_token'] = wb_random_token(24);
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * @param array<int, array{scope: string, identifier: string|int, limit: int, window: int}> $buckets
     * @param array{source?: string, message?: string}|null $blockedContext
     */
    public static function assertRateLimitAvailable(array $buckets, string $message, ?PDO $pdo = null, ?array $blockedContext = null): void
    {
        $pdo ??= Database::connection();
        self::pruneRateLimitRows($pdo);
        $blocked = self::rateLimitBlockInfo($buckets, $pdo);

        if ($blocked === null) {
            return;
        }

        if ($blockedContext !== null) {
            throw BlockedAccessException::temporary(
                (string) ($blockedContext['source'] ?? 'rate_limit'),
                (int) $blocked['retry_after_seconds'],
                (string) ($blockedContext['message'] ?? 'You have been blocked.')
            );
        }

        throw new RuntimeException($message);
    }

    /**
     * @param array<int, array{scope: string, identifier: string|int, limit: int, window: int}> $buckets
     */
    public static function consumeRateLimit(array $buckets, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        self::pruneRateLimitRows($pdo);
        $now = time();
        $select = $pdo->prepare(
            'SELECT hits, window_started_at
             FROM rate_limits
             WHERE bucket_key = :bucket_key
             LIMIT 1'
        );
        $insert = $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, scope, bucket_identifier, hits, window_started_at, updated_at)
             VALUES (:bucket_key, :scope, :bucket_identifier, :hits, :window_started_at, :updated_at)'
        );
        $update = $pdo->prepare(
            'UPDATE rate_limits
             SET hits = :hits, window_started_at = :window_started_at, updated_at = :updated_at
             WHERE bucket_key = :bucket_key'
        );

        foreach ($buckets as $bucket) {
            $scope = (string) $bucket['scope'];
            $identifier = (string) $bucket['identifier'];
            $window = (int) $bucket['window'];
            $bucketKey = self::rateLimitBucketKey($scope, $identifier);
            $select->execute([':bucket_key' => $bucketKey]);
            $row = $select->fetch();
            $windowStartedAt = wb_now();
            $hits = 1;

            if (is_array($row)) {
                $windowStartedAtUnix = strtotime((string) $row['window_started_at']) ?: 0;

                if ($windowStartedAtUnix > 0 && ($now - $windowStartedAtUnix) < $window) {
                    $windowStartedAt = (string) $row['window_started_at'];
                    $hits = (int) $row['hits'] + 1;
                }

                $update->execute([
                    ':bucket_key' => $bucketKey,
                    ':hits' => $hits,
                    ':window_started_at' => $windowStartedAt,
                    ':updated_at' => wb_now(),
                ]);
                continue;
            }

            $insert->execute([
                ':bucket_key' => $bucketKey,
                ':scope' => $scope,
                ':bucket_identifier' => $identifier,
                ':hits' => $hits,
                ':window_started_at' => $windowStartedAt,
                ':updated_at' => wb_now(),
            ]);
        }
    }

    /**
     * @param array<int, array{scope: string, identifier: string|int, limit: int, window: int}> $buckets
     */
    public static function clearRateLimit(array $buckets, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $statement = $pdo->prepare('DELETE FROM rate_limits WHERE bucket_key = :bucket_key');

        foreach ($buckets as $bucket) {
            $statement->execute([
                ':bucket_key' => self::rateLimitBucketKey((string) $bucket['scope'], (string) $bucket['identifier']),
            ]);
        }
    }

    /**
     * @param array<int, array{scope: string, identifier: string|int, limit: int, window: int}> $buckets
     * @return array{retry_after_seconds: int, blocked_until: string}|null
     */
    public static function rateLimitBlockInfo(array $buckets, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        self::pruneRateLimitRows($pdo);
        $now = time();
        $activeBlock = null;

        foreach ($buckets as $bucket) {
            $window = (int) $bucket['window'];
            $state = self::loadRateLimitState(
                $pdo,
                (string) $bucket['scope'],
                (string) $bucket['identifier'],
                $window,
                $now
            );

            if ($state['hits'] < (int) $bucket['limit']) {
                continue;
            }

            $retryAfterSeconds = max(1, $window - max(0, $now - $state['window_started_at_unix']));

            if ($activeBlock === null || $retryAfterSeconds > $activeBlock['retry_after_seconds']) {
                $activeBlock = [
                    'retry_after_seconds' => $retryAfterSeconds,
                    'blocked_until' => gmdate('c', $now + $retryAfterSeconds),
                ];
            }
        }

        return $activeBlock;
    }

    public static function signPayload(array $payload): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $body, self::signingSecret());

        return $body . '.' . $signature;
    }

    public static function verifySignedPayload(string $token): array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException('Shared file not found.');
        }

        [$body, $signature] = $parts;
        $expected = hash_hmac('sha256', $body, self::signingSecret());

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Shared file not found.');
        }

        $json = base64_decode(strtr($body, '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        if (!is_array($payload)) {
            throw new RuntimeException('Shared file not found.');
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($expiresAt <= time()) {
            throw new RuntimeException('Shared file not found.');
        }

        return $payload;
    }

    public static function sendFile(string $path, string $mimeType, string $downloadName, string $disposition = 'inline'): never
    {
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }

        $size = filesize($path) ?: 0;
        $downloadName = str_replace(["\r", "\n"], '', $downloadName);
        $dispositionType = $disposition === 'attachment' ? 'attachment' : 'inline';
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
        $start = 0;
        $end = $size > 0 ? $size - 1 : 0;
        $status = 200;

        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
            $requestedStart = $matches[1] === '' ? null : (int) $matches[1];
            $requestedEnd = $matches[2] === '' ? null : (int) $matches[2];

            if ($requestedStart === null && $requestedEnd !== null) {
                $requestedStart = max(0, $size - $requestedEnd);
                $requestedEnd = $size - 1;
            }

            if ($requestedStart !== null) {
                $start = max(0, min($requestedStart, max(0, $size - 1)));
            }

            if ($requestedEnd !== null) {
                $end = max($start, min($requestedEnd, max(0, $size - 1)));
            }

            $status = 206;
        }

        $length = ($end - $start) + 1;

        self::sendCommonHeaders();
        http_response_code($status);
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $dispositionType . '; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . $length);
        header("Content-Security-Policy: default-src 'none'; media-src 'self' blob:; img-src 'self' data: blob:; style-src 'unsafe-inline'; sandbox");

        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            http_response_code(500);
            exit;
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $remaining = $length;

        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));

            if ($chunk === false) {
                break;
            }

            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }

        fclose($handle);
        exit;
    }

    private static function sendCommonHeaders(): void
    {
        foreach (self::commonHeaders() as $headerName => $headerValue) {
            header($headerName . ': ' . $headerValue);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function commonHeaders(): array
    {
        $headers = [
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        ];

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * @return array{hits: int, window_started_at_unix: int}
     */
    private static function loadRateLimitState(PDO $pdo, string $scope, string $identifier, int $window, int $now): array
    {
        $statement = $pdo->prepare(
            'SELECT hits, window_started_at
             FROM rate_limits
             WHERE bucket_key = :bucket_key
             LIMIT 1'
        );
        $statement->execute([
            ':bucket_key' => self::rateLimitBucketKey($scope, $identifier),
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return ['hits' => 0, 'window_started_at_unix' => 0];
        }

        $windowStartedAt = strtotime((string) $row['window_started_at']) ?: 0;

        if ($windowStartedAt <= 0 || ($now - $windowStartedAt) >= $window) {
            return ['hits' => 0, 'window_started_at_unix' => 0];
        }

        return [
            'hits' => (int) $row['hits'],
            'window_started_at_unix' => $windowStartedAt,
        ];
    }

    private static function pruneRateLimitRows(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'DELETE FROM rate_limits
             WHERE updated_at < :cutoff'
        );
        $statement->execute([
            ':cutoff' => gmdate('c', time() - self::RATE_LIMIT_RETENTION_SECONDS),
        ]);
    }

    private static function rateLimitBucketKey(string $scope, string $identifier): string
    {
        return hash('sha256', $scope . '|' . $identifier);
    }

    private static function signingSecret(): string
    {
        if (Installer::isInstalled()) {
            try {
                $secret = Database::setting('app_secret');

                if ($secret !== null && $secret !== '') {
                    return $secret;
                }
            } catch (\Throwable) {
            }
        }

        return hash('sha256', WB_ROOT . '|wb-filebrowser');
    }
}
