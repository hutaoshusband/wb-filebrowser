<?php

declare(strict_types=1);

function wb_now(): string
{
    return gmdate('c');
}

function wb_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wb_detect_base_path(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $projectRoot = str_replace('\\', '/', realpath(WB_ROOT) ?: WB_ROOT);

    if ($scriptName === '' || $scriptFile === '' || !str_starts_with($scriptFile, $projectRoot)) {
        $fallback = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');

        return $fallback === '/' ? '' : $fallback;
    }

    $relative = ltrim(substr($scriptFile, strlen($projectRoot)), '/');
    $relativeSegments = $relative === '' ? [] : explode('/', $relative);
    $scriptSegments = $scriptName === '' ? [] : explode('/', trim($scriptName, '/'));

    if ($relativeSegments === [] || count($scriptSegments) < count($relativeSegments)) {
        $fallback = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');

        return $fallback === '/' ? '' : $fallback;
    }

    $baseSegments = array_slice($scriptSegments, 0, count($scriptSegments) - count($relativeSegments));
    $basePath = '/' . implode('/', $baseSegments);
    $basePath = rtrim(preg_replace('#/+#', '/', $basePath) ?: '/', '/');

    return $basePath === '/' ? '' : $basePath;
}

function wb_url(string $path = ''): string
{
    $basePath = WB_BASE_PATH;

    if ($path === '' || $path === '/') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    $normalized = '/' . ltrim($path, '/');

    return ($basePath === '' ? '' : $basePath) . $normalized;
}

function wb_request_origin(): ?string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return null;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host;
}

function wb_absolute_url(string $path = '', ?string $origin = null): ?string
{
    $origin ??= wb_request_origin();

    if ($origin === null || $origin === '') {
        return null;
    }

    return rtrim($origin, '/') . wb_url($path);
}

function wb_cookie_path(): string
{
    $base = wb_url('/');

    return $base === '' ? '/' : $base;
}

function wb_storage_path(string $relative = ''): string
{
    $path = WB_STORAGE;

    if ($relative === '') {
        return $path;
    }

    return $path . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function wb_request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function wb_is_json_request(): bool
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    return str_contains($contentType, 'application/json');
}

function wb_request_data(): array
{
    if (wb_is_json_request()) {
        $payload = json_decode((string) file_get_contents('php://input'), true);

        return is_array($payload) ? $payload : [];
    }

    return $_POST;
}

function wb_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function wb_error_response(string $message, int $status = 400, array $extra = []): never
{
    wb_json_response([
        'ok' => false,
        'message' => $message,
        'errors' => $extra,
    ], $status);
}

function wb_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function wb_parse_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function wb_random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function wb_json_html(mixed $value): string
{
    return (string) json_encode(
        $value,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
}

function wb_bootstrap_script_tag(array $bootstrap): string
{
    return '<script id="wb-bootstrap" type="application/json">' . wb_json_html($bootstrap) . '</script>';
}

function wb_page_head(string $title): string
{
    return implode("\n", [
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>' . wb_h($title) . '</title>',
        '<link rel="icon" type="image/svg+xml" href="' . wb_h(wb_url('/media/logo.svg')) . '">',
        '<link rel="stylesheet" href="' . wb_h(wb_url('/assets/app.css')) . '">',
    ]);
}

function wb_normalize_name(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;

    return trim($value);
}

function wb_validate_entry_name(string $value, string $kind = 'item'): string
{
    $name = wb_normalize_name($value);

    if ($name === '') {
        throw new InvalidArgumentException(ucfirst($kind) . ' name is required.');
    }

    if (mb_strlen($name) > 255) {
        throw new InvalidArgumentException(ucfirst($kind) . ' name must be 255 characters or fewer.');
    }

    if (preg_match('#[\\\\/]#', $name)) {
        throw new InvalidArgumentException(ucfirst($kind) . ' name cannot contain path separators.');
    }

    if (in_array($name, ['.', '..'], true)) {
        throw new InvalidArgumentException(ucfirst($kind) . ' name is invalid.');
    }

    return $name;
}

function wb_format_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return 'Unknown';
    }

    if ($bytes === 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return sprintf($value >= 10 || $power === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$power]);
}

function wb_relative_time(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return 'Unknown';
    }

    $timestamp = strtotime($isoDate);

    if ($timestamp === false) {
        return $isoDate;
    }

    $delta = time() - $timestamp;
    $future = $delta < 0;
    $delta = abs($delta);

    $units = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
    ];

    foreach ($units as $seconds => $label) {
        if ($delta >= $seconds) {
            $value = (int) floor($delta / $seconds);
            $suffix = $value === 1 ? $label : $label . 's';

            return $future ? "in {$value} {$suffix}" : "{$value} {$suffix} ago";
        }
    }

    return $future ? 'in a moment' : 'just now';
}

/**
 * @return array<int, string>
 */
function wb_text_preview_extensions(): array
{
    static $extensions = ['json', 'md', 'markdown', 'xml', 'yml', 'yaml', 'js', 'ts', 'php', 'css', 'html', 'sql'];

    return $extensions;
}

function wb_file_extension_badge(string $extension): string
{
    $badge = strtoupper(ltrim(trim($extension), '.'));

    if ($badge === '') {
        return 'FILE';
    }

    return substr($badge, 0, 10);
}

/**
 * @return array{variant: string, icon: string, label: string}
 */
function wb_file_fallback_metadata(string $extension, string $mimeType = ''): array
{
    $extension = strtolower(ltrim(trim($extension), '.'));
    $mimeType = strtolower(trim($mimeType));

    if ($extension === 'jar' || $mimeType === 'application/java-archive') {
        return [
            'variant' => 'jar',
            'icon' => 'jar',
            'label' => 'Java archive',
        ];
    }

    if ($extension === 'exe' || in_array($mimeType, ['application/vnd.microsoft.portable-executable', 'application/x-msdownload'], true)) {
        return [
            'variant' => 'exe',
            'icon' => 'exe',
            'label' => 'Windows executable',
        ];
    }

    if (in_array($extension, ['msi', 'bat', 'cmd', 'com', 'scr', 'ps1'], true)) {
        return [
            'variant' => 'executable',
            'icon' => 'executable',
            'label' => 'Executable file',
        ];
    }

    if (
        in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'], true)
        || in_array($mimeType, ['application/zip', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/gzip', 'application/x-tar'], true)
    ) {
        return [
            'variant' => 'archive',
            'icon' => 'archive',
            'label' => 'Archive file',
        ];
    }

    if (
        in_array($extension, ['apk', 'dmg', 'pkg', 'iso', 'deb', 'rpm', 'appimage'], true)
        || in_array($mimeType, ['application/vnd.android.package-archive', 'application/x-iso9660-image'], true)
    ) {
        return [
            'variant' => 'package',
            'icon' => 'package',
            'label' => 'Installable package',
        ];
    }

    return [
        'variant' => 'binary',
        'icon' => 'binary',
        'label' => 'Binary file',
    ];
}

/**
 * @return array{
 *   preview_mode: string,
 *   fallback_variant: ?string,
 *   fallback_icon_url: ?string,
 *   fallback_label: ?string
 * }
 */
function wb_file_preview_metadata(string $mimeType, string $extension): array
{
    $mimeType = strtolower(trim($mimeType));
    $extension = strtolower(ltrim(trim($extension), '.'));

    if (str_starts_with($mimeType, 'image/')) {
        return [
            'preview_mode' => 'image',
            'fallback_variant' => null,
            'fallback_icon_url' => null,
            'fallback_label' => null,
        ];
    }

    if ($mimeType === 'application/pdf') {
        return [
            'preview_mode' => 'pdf',
            'fallback_variant' => null,
            'fallback_icon_url' => null,
            'fallback_label' => null,
        ];
    }

    if (str_starts_with($mimeType, 'video/')) {
        return [
            'preview_mode' => 'video',
            'fallback_variant' => null,
            'fallback_icon_url' => null,
            'fallback_label' => null,
        ];
    }

    if (str_starts_with($mimeType, 'audio/')) {
        return [
            'preview_mode' => 'audio',
            'fallback_variant' => null,
            'fallback_icon_url' => null,
            'fallback_label' => null,
        ];
    }

    if (str_starts_with($mimeType, 'text/') || in_array($extension, wb_text_preview_extensions(), true)) {
        return [
            'preview_mode' => 'text',
            'fallback_variant' => null,
            'fallback_icon_url' => null,
            'fallback_label' => null,
        ];
    }

    $fallback = wb_file_fallback_metadata($extension, $mimeType);

    return [
        'preview_mode' => 'download',
        'fallback_variant' => $fallback['variant'],
        'fallback_icon_url' => wb_url('/media/file-fallbacks/' . $fallback['icon'] . '.svg'),
        'fallback_label' => $fallback['label'],
    ];
}
