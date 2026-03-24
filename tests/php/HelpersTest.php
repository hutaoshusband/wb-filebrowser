<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

#[CoversFunction('wb_json_html')]
class HelpersTest extends TestCase
{
    public function testWbJsonHtmlEscapesUnsafeCharacters(): void
    {
        $input = ['unsafe' => '<script>alert("xss & stuff \'")</script>'];
        $expected = '{"unsafe":"\u003Cscript\u003Ealert(\u0022xss \u0026 stuff \u0027\u0022)\u003C/script\u003E"}';
        $actual = wb_json_html($input);

        $this->assertEquals($expected, $actual);
    }

    public function testWbJsonHtmlRetainsSlashes(): void
    {
        $input = ['url' => 'https://example.com/path/to/resource'];
        $expected = '{"url":"https://example.com/path/to/resource"}';
        $actual = wb_json_html($input);

        $this->assertEquals($expected, $actual);
    }

    public function testWbJsonHtmlRetainsUnicode(): void
    {
        $input = ['emoji' => '🚀', 'cyrillic' => 'привет'];
        $expected = '{"emoji":"🚀","cyrillic":"привет"}';
        $actual = wb_json_html($input);

        $this->assertEquals($expected, $actual);
    }
}
