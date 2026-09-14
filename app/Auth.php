<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;
use RuntimeException;

final class Auth
{
    public static function currentUser(?PDO $pdo = null): ?array
    {
        Security::startSession();

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0 || !Installer::isInstalled()) {
            return null;
        }

        $pdo ??= Database::connection();
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, role, status, force_password_reset, is_immutable, created_at, updated_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        $statement->closeCursor();

        if (!is_array($user) || $user['status'] !== 'active'
            || !hash_equals(hash('sha256', (string) $user['password_hash']), (string) ($_SESSION['credential_version'] ?? ''))) {
            unset($_SESSION['user_id'], $_SESSION['credential_version']);

            return null;
        }

        unset($user['password_hash']);

        return $user;
    }

    public static function login(string $username, string $password): array
    {
        $pdo = Database::connection();
        $username = wb_normalize_name($username);
        $ip = Security::clientIp();
        $rateLimitBuckets = self::loginRateLimitBuckets($username, $ip);
        Security::reserveRateLimit(
            $rateLimitBuckets,
            'Too many failed login attempts. Please wait a few minutes and try again.',
            $pdo,
            ['source' => 'auth_login']
        );

        $statement = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute([':username' => $username]);
        $user = $statement->fetch();
        $statement->closeCursor();

        if (!is_array($user) || $user['status'] !== 'active' || !password_verify($password, (string) $user['password_hash'])) {
            self::recordAttempt($pdo, $username, $ip, false);
            AuditLog::record('auth.login.failure', 'auth_failure', [
                'actor_username' => $username,
                'ip_address' => $ip,
                'target_type' => 'auth',
                'target_label' => $username,
                'summary' => 'Failed login for ' . $username,
            ], $pdo);

            if (Security::rateLimitBlockInfo($rateLimitBuckets, $pdo) !== null) {
                AuditLog::record('auth.login.lockout', 'security_actions', [
                    'actor_username' => $username,
                    'ip_address' => $ip,
                    'target_type' => 'auth',
                    'target_label' => $username,
                    'summary' => 'Blocked login attempts for ' . $username,
                ], $pdo);
                Security::assertRateLimitAvailable(
                    $rateLimitBuckets,
                    'Too many failed login attempts. Please wait a few minutes and try again.',
                    $pdo,
                    ['source' => 'auth_login']
                );
            }

            throw new RuntimeException('Invalid username or password.');
        }

        MaintenanceMode::assertAllowed($user, 'app', $pdo);

        self::recordAttempt($pdo, $username, $ip, true);
        Security::clearRateLimit($rateLimitBuckets, $pdo);
        Security::regenerateSession();
        self::establishSession($user);

        $update = $pdo->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':last_login_at' => wb_now(),
            ':updated_at' => wb_now(),
            ':id' => $user['id'],
        ]);

        $currentUser = self::currentUser($pdo) ?? throw new RuntimeException('Unable to load the authenticated user.');
        AuditLog::record('auth.login.success', 'auth_success', [
            'actor_user' => $currentUser,
            'ip_address' => $ip,
            'target_type' => 'auth',
            'target_id' => (int) $currentUser['id'],
            'target_label' => (string) $currentUser['username'],
            'summary' => 'Logged in as ' . $currentUser['username'],
        ], $pdo);

        return $currentUser;
    }

    public static function establishSession(array $user): void
    {
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['credential_version'] = hash('sha256', (string) $user['password_hash']);
    }

    public static function changePassword(string $currentPassword, string $newPassword): array
    {
        $pdo = Database::connection();
        $user = self::requireUser($pdo);
        Security::reserveRateLimit([['scope' => 'password-change', 'identifier' => (string) $user['id'], 'limit' => 5, 'window' => 600]], 'Too many password change attempts. Please try again later.', $pdo);
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute([':id' => $user['id']]);
        $oldHash = (string) $statement->fetchColumn();
        $statement->closeCursor();
        if (!password_verify($currentPassword, $oldHash)) {
            throw new RuntimeException('The current password is incorrect.');
        }
        if (mb_strlen($newPassword) < 12 || password_verify($newPassword, $oldHash)) {
            throw new RuntimeException('Choose a different password with at least 12 characters.');
        }
        $newHash = Security::hashPassword($newPassword);
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash, force_password_reset = 0, updated_at = :now WHERE id = :id AND password_hash = :old');
        $update->execute([':hash' => $newHash, ':now' => wb_now(), ':id' => $user['id'], ':old' => $oldHash]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Your credentials changed. Please sign in again.');
        }
        Security::regenerateSession();
        self::establishSession($user + ['password_hash' => $newHash]);
        return self::currentUser($pdo) ?? throw new RuntimeException('Authentication is required.');
    }

    public static function logout(): void
    {
        Security::startSession();
        $user = self::currentUser();

        if ($user !== null) {
            AuditLog::record('auth.logout', 'auth_success', [
                'actor_user' => $user,
                'target_type' => 'auth',
                'target_id' => (int) $user['id'],
                'target_label' => (string) $user['username'],
                'summary' => 'Logged out ' . $user['username'],
            ]);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }

        session_destroy();
    }

    public static function requireUser(?PDO $pdo = null): array
    {
        $user = self::currentUser($pdo);

        if ($user === null) {
            throw new RuntimeException('Authentication is required.');
        }

        return $user;
    }

    public static function requireAdmin(?PDO $pdo = null): array
    {
        $user = self::requireUser($pdo);

        if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
            throw new RuntimeException('Administrator access is required.');
        }

        return $user;
    }

    public static function requireSuperAdmin(?PDO $pdo = null): array
    {
        $user = self::requireUser($pdo);

        if ($user['role'] !== 'super_admin') {
            throw new RuntimeException('Super-Admin access is required.');
        }

        return $user;
    }

    /**
     * @return array<int, array{scope: string, identifier: string, limit: int, window: int}>
     */
    private static function loginRateLimitBuckets(string $username, string $ip): array
    {
        return [
            [
                'scope' => 'login-user-ip',
                'identifier' => $username . '|' . $ip,
                'limit' => 5,
                'window' => 10 * 60,
            ],
            [
                'scope' => 'login-user',
                'identifier' => $username,
                'limit' => 10,
                'window' => 15 * 60,
            ],
            [
                'scope' => 'login-ip',
                'identifier' => $ip,
                'limit' => 25,
                'window' => 15 * 60,
            ],
        ];
    }

    private static function recordAttempt(PDO $pdo, string $username, string $ip, bool $success): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
             VALUES (:username, :ip_address, :success, :attempted_at)'
        );
        $statement->execute([
            ':username' => $username,
            ':ip_address' => $ip,
            ':success' => $success ? 1 : 0,
            ':attempted_at' => wb_now(),
        ]);
    }
}
