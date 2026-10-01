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
    }

    public function testApiHeadersExposeStrictBrowserPolicies(): void
    {
        $headers = Security::apiHeaders();

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy']);
    }

    public function testEmbedHeadersAllowFramingAndCrossOriginMedia(): void
    {
        $headers = Security::embedHeaders();

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString('frame-ancestors *', $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('cross-origin', $headers['Cross-Origin-Resource-Policy']);
        $this->assertSame('noindex, nofollow, noarchive', $headers['X-Robots-Tag']);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $headers['Cache-Control']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testPageAndApiHeadersStillForbidFraming(): void
    {
        $this->assertStringContainsString("frame-ancestors 'none'", Security::pageHeaders()['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", Security::apiHeaders()['Content-Security-Policy']);
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
