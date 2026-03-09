<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;

final class FilePreviewMetadataTest extends TestCase
{
    public function testJarFallbackWinsOverZipMime(): void
    {
        $metadata = wb_file_preview_metadata('application/zip', 'jar');

        $this->assertSame('download', $metadata['preview_mode']);
        $this->assertSame('jar', $metadata['fallback_variant']);
        $this->assertSame('Java archive', $metadata['fallback_label']);
        $this->assertStringContainsString('/media/file-fallbacks/jar.svg', (string) $metadata['fallback_icon_url']);
    }

    public function testExeFallbackUsesDedicatedVariant(): void
    {
        $metadata = wb_file_preview_metadata('application/vnd.microsoft.portable-executable', 'exe');

        $this->assertSame('download', $metadata['preview_mode']);
        $this->assertSame('exe', $metadata['fallback_variant']);
        $this->assertSame('Windows executable', $metadata['fallback_label']);
        $this->assertStringContainsString('/media/file-fallbacks/exe.svg', (string) $metadata['fallback_icon_url']);
    }

    public function testArchivePackageAndGenericFallbackFamiliesResolve(): void
    {
        $archive = wb_file_preview_metadata('application/octet-stream', 'zip');
        $package = wb_file_preview_metadata('application/octet-stream', 'appimage');
        $generic = wb_file_preview_metadata('application/octet-stream', 'bin');

        $this->assertSame('archive', $archive['fallback_variant']);
        $this->assertSame('Archive file', $archive['fallback_label']);
        $this->assertSame('package', $package['fallback_variant']);
        $this->assertSame('Installable package', $package['fallback_label']);
        $this->assertSame('binary', $generic['fallback_variant']);
        $this->assertSame('Binary file', $generic['fallback_label']);
    }

    public function testPreviewableTypesDoNotExposeFallbackMetadata(): void
    {
        $text = wb_file_preview_metadata('text/plain', 'txt');
        $pdf = wb_file_preview_metadata('application/pdf', 'pdf');

        $this->assertSame('text', $text['preview_mode']);
        $this->assertNull($text['fallback_variant']);
        $this->assertNull($text['fallback_icon_url']);
        $this->assertNull($text['fallback_label']);
        $this->assertSame('pdf', $pdf['preview_mode']);
        $this->assertNull($pdf['fallback_variant']);
    }
}
