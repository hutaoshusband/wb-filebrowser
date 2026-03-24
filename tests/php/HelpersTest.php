<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversFunction('wb_json_html')]
#[CoversFunction('wb_relative_time')]
#[CoversFunction('wb_validate_entry_name')]
#[CoversFunction('wb_parse_bool')]
class HelpersTest extends TestCase
{
    #[DataProvider('provideParseBoolData')]
    public function testWbParseBool(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, wb_parse_bool($value));
    }

    public static function provideParseBoolData(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];

        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'int 2' => [2, false];
        yield 'int -1' => [-1, false];

        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'string yes' => ['yes', true];
        yield 'string no' => ['no', false];
        yield 'string on' => ['on', true];
        yield 'string off' => ['off', false];

        yield 'string TRUE uppercase' => ['TRUE', true];
        yield 'string Yes mixed case' => ['Yes', true];
        yield 'string ON mixed case' => ['ON', true];
        yield 'string spaces' => ['  true  ', true];
        yield 'string spaces with 1' => [' 1 ', true];
        yield 'string empty' => ['', false];
        yield 'string random' => ['random_string', false];

        yield 'null' => [null, false];
        yield 'float 1.0' => [1.0, true];
        yield 'float 0.0' => [0.0, false];

        yield 'object with toString returning true' => [new class {
            public function __toString(): string
            {
                return 'true';
            }
        }, true];
    }
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
