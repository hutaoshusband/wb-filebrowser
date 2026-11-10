<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;

final class FileFallbackTest extends TestCase
{
    public function testSourceIconsPreserveTextPreview(): void
    {
        $metadata = \wb_file_preview_metadata('text/plain', 'py');
        self::assertSame('text', $metadata['preview_mode']);
        self::assertStringContainsString('/python.svg?', $metadata['file_icon_url']);
        self::assertNull($metadata['fallback_icon_url']);
    }

    public function testBinaryAndArchiveIcons(): void
    {
        foreach ([
            ['DLL', 'application/x-msdownload', 'dll'],
            ['exe', '', 'exe'],
            ['elf', '', 'linux'],
            ['', 'application/x-pie-executable', 'linux'],
            ['so', '', 'linux'],
            ['zip', '', 'zip'],
            ['', 'application/vnd.rar', 'rar'],
            ['7z', '', '7z'],
            ['zst', '', 'archive'],
            ['cpio', '', 'archive'],
            ['flatpak', '', 'flatpak'],
            ['deb', '', 'deb'],
            ['jar', '', 'jar'],
            ['py', '', 'python'],
            ['tsx', '', 'typescript'],
            ['rs', '', 'rust'],
            ['yaml', '', 'yaml'],
            ['i64', '', 'ida'],
            ['IDB', '', 'ida'],
            ['db', '', 'database'],
            ['sqlite3', '', 'database'],
            ['', 'application/vnd.sqlite3', 'database'],
            ['firefly', '', 'firefly'],
            ['.FIREFLY', 'application/octet-stream', 'firefly'],
            ['unknown', '', 'binary'],
        ] as [$extension, $mime, $icon]) {
            $result = \wb_file_fallback_metadata($extension, $mime);
            self::assertSame($icon, $result['icon'], $extension . ':' . $mime);
            self::assertFileExists(dirname(__DIR__, 2) . '/media/file-fallbacks/' . $icon . '.svg');
        }
    }
}
