<?php

declare(strict_types=1);

namespace WbFileBrowser;

use InvalidArgumentException;
use RuntimeException;

final class FileEncryption
{
    public const FORMAT = 'WBENC001';
    public const MIME = 'application/x-wb-encrypted';

    public static function mode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, ['off', 'optional', 'required'], true)) {
            throw new InvalidArgumentException('Encryption policy must be off, optional or required.');
        }

        return $mode;
    }

    public static function assertUpload(string $format): void
    {
        if (!in_array($format, ['', self::FORMAT], true)) {
            throw new InvalidArgumentException('Unsupported file encryption format.');
        }

        $mode = self::mode(Database::setting('uploads_encryption_mode', 'off'));
        if ($mode === 'required' && $format === '') {
            throw new RuntimeException('The administrator requires local file encryption. Refresh and encrypt your file before uploading.');
        }
        if ($mode === 'off' && $format !== '') {
            throw new RuntimeException('Encrypted uploads are disabled by the administrator.');
        }
        // Ciphertext cannot be inspected by ffprobe. Never waive a content-verification policy.
        if ($format !== '' && Database::setting('video_compression_mode', 'off') === 'required') {
            throw new RuntimeException('Encrypted uploads are incompatible with required server video verification. Ask the administrator to change one of these policies.');
        }
    }

    public static function validateContainer(string $path, int $size): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to inspect encrypted upload.');
        }
        try {
            $header = fread($handle, 64);
        } finally {
            fclose($handle);
        }
        if (!is_string($header) || strlen($header) !== 64 || substr($header, 0, 8) !== self::FORMAT
            || substr($header, 40, 8) !== str_repeat("\0", 8)) {
            throw new RuntimeException('Invalid encrypted file header.');
        }
        $words = unpack('Vlow/Vhigh', substr($header, 32, 8));
        $plainSize = $words['high'] * 4294967296 + $words['low'];
        if ($plainSize > 1099511627776 || $size !== 64 + $plainSize + (int) ceil($plainSize / 1048576) * 16) {
            throw new RuntimeException('Encrypted file size does not match its header.');
        }
    }

    public static function preview(array $file): array
    {
        if (($file['encryption_format'] ?? '') !== '') {
            return array_merge(wb_file_preview_metadata('application/octet-stream', 'wbencrypted'), [
                'preview_mode' => 'download',
                'encryption_format' => (string) $file['encryption_format'],
            ]);
        }

        return wb_file_preview_metadata((string) $file['mime_type'], strtolower(pathinfo((string) $file['original_name'], PATHINFO_EXTENSION)));
    }
}
