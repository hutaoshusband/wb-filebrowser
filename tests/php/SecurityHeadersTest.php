<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Security;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testPageHeadersExposeStrictBrowserPolicies(): void
    {
        $headers = Security::pageHeaders();

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        // Cross-origin isolation is required for the multithreaded ffmpeg
        // fallback (SharedArrayBuffer) and is safe under the self-only CSP.
        $this->assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        $this->assertSame('require-corp', $headers['Cross-Origin-Embedder-Policy']);
        // COEP require-corp rejects subresource responses without CORP, so
        // every PHP response must declare it.
        $this->assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
        // The worker-src list must stay parseable (blob: unquoted, 'self' quoted).
        $this->assertStringContainsString("worker-src 'self' blob:", $headers['Content-Security-Policy']);
    }

    public function testApiHeadersExposeStrictBrowserPolicies(): void
    {
        $headers = Security::apiHeaders();

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy']);
    }

    public function testBootstrapScriptTagEscapesExecutableMarkupAndPageHeadIncludesFavicon(): void
    {
        $tag = wb_bootstrap_script_tag([
            'surface' => 'app',
            'payload' => '</script><script>alert(1)</script>',
        ]);
        $head = wb_page_head('wb-filebrowser');

        $this->assertStringContainsString('type="application/json"', $tag);
        $this->assertStringNotContainsString('</script><script>', $tag);
        $this->assertStringContainsString('/media/logo.svg', $head);
    }
}
