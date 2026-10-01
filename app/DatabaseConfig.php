<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;

final class DatabaseConfig
{
    public static function path(): string
    {
        return wb_storage_path('config.php');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $driver = DatabasePlatform::normalizeDriver((string) ($input['driver'] ?? 'sqlite'));
        $base = [
            'driver' => $driver,
            'path' => '',
            'host' => '',
            'port' => DatabasePlatform::defaultPort($driver),
            'name' => '',
            'username' => '',
            'password' => '',
        ];

        if ($driver === 'sqlite') {
            $base['path'] = self::normalizePath((string) ($input['path'] ?? wb_storage_path('app.sqlite')));

            return $base;
        }

        $base['host'] = trim((string) ($input['host'] ?? ''));
        $base['port'] = self::normalizePort($driver, $input['port'] ?? null);
        $base['name'] = trim((string) ($input['name'] ?? ''));
        $base['username'] = trim((string) ($input['username'] ?? ''));
        $base['password'] = (string) ($input['password'] ?? '');

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        if (!self::exists()) {
            return null;
        }

        $config = require self::path();

        if (!is_array($config)) {
            throw new RuntimeException('The database configuration file is invalid.');
        }

        return self::normalize($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function write(array $config): void
    {
        $normalized = self::normalize($config);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($normalized, true) . ";\n";

        if (!is_dir(wb_storage_path()) && !mkdir(wb_storage_path(), 0775, true) && !is_dir(wb_storage_path())) {
            throw new RuntimeException('Unable to prepare the storage directory for database configuration.');
        }

        file_put_contents(self::path(), $contents);
        @chmod(self::path(), 0640);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function loadInstalled(): ?array
    {
        $config = self::read();

        if (is_array($config)) {
            return $config;
        }

        if (!is_file(wb_storage_path('installed.lock')) || !is_file(wb_storage_path('app.sqlite'))) {
            return null;
        }

        $legacy = self::normalize([
            'driver' => 'sqlite',
            'path' => wb_storage_path('app.sqlite'),
        ]);
        self::write($legacy);

        return $legacy;
    }

    private static function normalizePort(string $driver, mixed $value): ?int
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $default = DatabasePlatform::defaultPort($driver);

        if ($value === null || $value === '') {
            return $default;
        }

        $port = filter_var($value, FILTER_VALIDATE_INT);

        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('Database port must be between 1 and 65535.');
        }

        return $port;
    }

    private static function normalizePath(string $path): string
    {
        $value = trim($path);

        if ($value === '') {
            throw new RuntimeException('SQLite database path is required.');
        }

        if (preg_match('#^storage[\\\\/]#i', $value) === 1) {
            $relative = preg_replace('#^storage[\\\\/]#i', '', $value) ?? $value;
            $value = rtrim(WB_STORAGE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
        }

        $prefix = '';
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);

        if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1) {
            $prefix = substr($normalized, 0, 2);
            $normalized = substr($normalized, 2);
        } elseif (str_starts_with($normalized, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
            $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        } elseif (str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        } else {
            $prefix = rtrim(WB_ROOT, DIRECTORY_SEPARATOR);
        }

        $segments = array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, $normalized),
            static fn (string $segment): bool => $segment !== ''
        ));
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $segment;
        }

        $suffix = implode(DIRECTORY_SEPARATOR, $resolved);

        if ($prefix === DIRECTORY_SEPARATOR || $prefix === DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR) {
            return $prefix . $suffix;
        }

        if (preg_match('/^[A-Za-z]:$/', $prefix) === 1) {
            return $prefix . DIRECTORY_SEPARATOR . $suffix;
        }

        return $suffix === '' ? $prefix : $prefix . DIRECTORY_SEPARATOR . $suffix;
    }
}
