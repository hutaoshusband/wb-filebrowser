<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

#[CoversFunction('wb_json_html')]
#[CoversFunction('wb_relative_time')]
#[CoversFunction('wb_validate_entry_name')]
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

    public function testRelativeTimeReturnsUnknownForNull(): void
    {
        $this->assertSame('Unknown', wb_relative_time(null));
    }

    public function testRelativeTimeReturnsUnknownForEmptyString(): void
    {
        $this->assertSame('Unknown', wb_relative_time(''));
    }

    public function testRelativeTimeReturnsOriginalStringForInvalidDate(): void
    {
        $this->assertSame('invalid-date-string', wb_relative_time('invalid-date-string'));
    }

    public function testWbValidateEntryNameReturnsValidName(): void
    {
        $this->assertSame('valid_name.txt', wb_validate_entry_name('valid_name.txt'));
        $this->assertSame('spaces allowed', wb_validate_entry_name(' spaces allowed '));
    }

    public function testWbValidateEntryNameThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name is required.');
        wb_validate_entry_name('   ');
    }

    public function testWbValidateEntryNameThrowsOnTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name must be 255 characters or fewer.');
        wb_validate_entry_name(str_repeat('a', 256));
    }

    public function testWbValidateEntryNameThrowsOnPathSeparators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name cannot contain path separators.');
        wb_validate_entry_name('folder/file.txt');
    }

    public function testWbValidateEntryNameThrowsOnBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name cannot contain path separators.');
        wb_validate_entry_name('folder\\file.txt');
    }

    public function testWbValidateEntryNameThrowsOnInvalidDots(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name is invalid.');
        wb_validate_entry_name('.');
    }

    public function testWbValidateEntryNameThrowsOnInvalidDoubleDots(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item name is invalid.');
        wb_validate_entry_name('..');
    }

    public function testWbValidateEntryNameUsesCustomKindInException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Folder name is required.');
        wb_validate_entry_name('', 'folder');
    }
}
