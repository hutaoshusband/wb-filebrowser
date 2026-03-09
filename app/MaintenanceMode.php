<?php

declare(strict_types=1);

namespace WbFileBrowser;

use PDO;

final class MaintenanceMode
{
    public const SCOPE_APP_ONLY = 'app_only';
    public const SCOPE_APP_AND_SHARE = 'app_and_share';
    public const SCOPE_ALL_NON_ADMIN = 'all_non_admin';

    public static function defaultMessage(): string
    {
        return 'The file browser is temporarily unavailable while maintenance is in progress. Please try again later.';
    }

    /**
     * @return array{enabled: bool, scope: string, message: string}
     */
    public static function state(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $scope = (string) Database::setting('maintenance_scope', self::SCOPE_APP_ONLY);

        if (!in_array($scope, self::scopes(), true)) {
            $scope = self::SCOPE_APP_ONLY;
        }

        $message = (string) Database::setting('maintenance_message', self::defaultMessage());
        $message = trim($message) === '' ? self::defaultMessage() : $message;

        return [
            'enabled' => wb_parse_bool(Database::setting('maintenance_enabled', '0')),
            'scope' => $scope,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(?array $user, string $surface = 'app', ?PDO $pdo = null): array
    {
        $state = self::state($pdo);

        return [
            ...$state,
            'blocks_current_user' => self::shouldBlock($user, $surface, $pdo, $state),
        ];
    }

    public static function shouldBlock(?array $user, string $surface = 'app', ?PDO $pdo = null, ?array $state = null): bool
    {
        $state ??= self::state($pdo);

        if (!$state['enabled']) {
            return false;
        }

        if (self::isAdmin($user)) {
            return false;
        }

        return self::scopeBlocksSurface((string) $state['scope'], $surface);
    }

    /**
     * @throws MaintenanceModeException
     */
    public static function assertAllowed(?array $user, string $surface = 'app', ?PDO $pdo = null): void
    {
        $payload = self::payload($user, $surface, $pdo);

        if (!$payload['blocks_current_user']) {
            return;
        }

        throw new MaintenanceModeException($payload, (string) $payload['message']);
    }

    /**
     * @throws MaintenanceModeException
     */
    public static function assertActionAllowed(string $action, ?array $user, ?PDO $pdo = null): void
    {
        $surface = self::surfaceForAction($action);

        if ($surface === null) {
            return;
        }

        self::assertAllowed($user, $surface, $pdo);
    }

    /**
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_APP_ONLY,
            self::SCOPE_APP_AND_SHARE,
            self::SCOPE_ALL_NON_ADMIN,
        ];
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true);
    }

    public static function scopeBlocksSurface(string $scope, string $surface): bool
    {
        return match ($scope) {
            self::SCOPE_APP_AND_SHARE => in_array($surface, ['app', 'share'], true),
            self::SCOPE_ALL_NON_ADMIN => $surface !== 'admin',
            default => $surface === 'app',
        };
    }

    private static function surfaceForAction(string $action): ?string
    {
        if ($action === '' || str_starts_with($action, 'install.') || str_starts_with($action, 'admin.')) {
            return null;
        }

        if (in_array($action, ['auth.session', 'auth.login', 'auth.logout'], true)) {
            return null;
        }

        if (str_starts_with($action, 'files.share.')) {
            return null;
        }

        if ($action === 'share.stream') {
            return 'share';
        }

        return 'app';
    }
}
