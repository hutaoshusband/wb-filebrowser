<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;

final class Security
{
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
        // Relying on HTTP_CF_CONNECTING_IP or HTTP_X_FORWARDED_FOR unconditionally allows IP spoofing.
        // An attacker can easily spoof these headers to bypass rate limits (e.g., brute-forcing logins).
        // It is highly recommended to configure the web server (e.g., Nginx real_ip_module or Apache mod_remoteip) 
        // to set the true client IP in REMOTE_ADDR natively.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

        http_response_code($status);
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $dispositionType . '; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . $length);
        
        // ADDED TO PREVENT STORED XSS:
        // A rigid CSP strictly prohibits HTML/JS and plugins from executing when viewed inline in the browser.
        header("Content-Security-Policy: default-src 'none'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');

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
}
