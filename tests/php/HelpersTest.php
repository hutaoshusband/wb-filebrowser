<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversFunction('wb_json_html')]
#[CoversFunction('wb_relative_time')]
#[CoversFunction('wb_validate_entry_name')]
#[CoversFunction('wb_normalize_name')]
#[CoversFunction('wb_parse_bool')]
#[CoversFunction('wb_format_bytes')]
#[CoversFunction('wb_detect_base_path')]
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

    #[DataProvider('provideNormalizeNameData')]
    public function testWbNormalizeName(string $input, string $expected): void
    {
        $this->assertSame($expected, wb_normalize_name($input));
    }

    public static function provideNormalizeNameData(): iterable
    {
        yield 'normal string' => ['hello world', 'hello world'];
        yield 'surrounding spaces' => ['  hello world  ', 'hello world'];
        yield 'empty string' => ['', ''];
        yield 'string with only spaces' => ['   ', ''];
        yield 'string with null bytes' => ["hello\x00world", 'helloworld'];
        yield 'string with unit separator' => ["hello\x1Fworld", 'helloworld'];
        yield 'string with DEL character' => ["hello\x7Fworld", 'helloworld'];
        yield 'string with start of heading' => ["hello\x01world", 'helloworld'];
        yield 'string with combination of control chars' => ["\x00hello\x01 \x1Fworld\x7F", 'hello world'];
        yield 'string with whitespace stripped by regex (newline, carriage return, tab)' => ["hello\n\r\tworld", "helloworld"];
        yield 'surrounding and internal control chars' => [" \x00 hello \x7F world \x1F ", 'hello  world'];
    }

    #[DataProvider('provideFormatBytesData')]
    public function testWbFormatBytes(?int $bytes, string $expected): void
    {
        $this->assertSame($expected, wb_format_bytes($bytes));
    }

    public static function provideFormatBytesData(): iterable
    {
        yield 'null' => [null, 'Unknown'];
        yield 'negative integer' => [-1, 'Unknown'];
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [500, '500 B'];
        yield 'exact KB' => [1024, '1.0 KB'];
        yield 'KB with decimal' => [1536, '1.5 KB'];
        yield 'exact MB' => [1048576, '1.0 MB'];
        yield 'MB with decimal' => [1572864, '1.5 MB'];
        yield 'exact GB' => [1073741824, '1.0 GB'];
        yield 'GB with decimal' => [1610612736, '1.5 GB'];
        yield 'exact TB' => [1099511627776, '1.0 TB'];
        yield 'TB with decimal' => [1649267441664, '1.5 TB'];
        yield '10 TB' => [10995116277760, '10 TB'];
        yield '100 TB' => [109951162777600, '100 TB'];
    }

    #[DataProvider('provideDetectBasePathData')]
    public function testWbDetectBasePath(
        ?string $scriptName,
        ?string $scriptFilename,
        string $expected
    ): void {
        $_SERVER['WB_TESTING_BASE_PATH'] = '1';

        $originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if ($scriptName !== null) {
            $_SERVER['SCRIPT_NAME'] = $scriptName;
        } else {
            unset($_SERVER['SCRIPT_NAME']);
        }

        if ($scriptFilename !== null) {
            $_SERVER['SCRIPT_FILENAME'] = str_replace('{WB_ROOT}', WB_ROOT, $scriptFilename);
        } else {
            unset($_SERVER['SCRIPT_FILENAME']);
        }

        try {
            $this->assertSame($expected, wb_detect_base_path());
        } finally {
            unset($_SERVER['WB_TESTING_BASE_PATH']);

            if ($originalScriptName !== null) {
                $_SERVER['SCRIPT_NAME'] = $originalScriptName;
            } else {
                unset($_SERVER['SCRIPT_NAME']);
            }

            if ($originalScriptFilename !== null) {
                $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
            } else {
                unset($_SERVER['SCRIPT_FILENAME']);
            }
        }
    }

    public static function provideDetectBasePathData(): iterable
    {
        yield 'root path' => ['/index.php', '{WB_ROOT}/index.php', ''];
        yield 'subdirectory base path' => ['/base/subdir/index.php', '{WB_ROOT}/subdir/index.php', '/base'];
        yield 'deep subdirectory base path' => ['/a/b/subdir/index.php', '{WB_ROOT}/subdir/index.php', '/a/b'];

        yield 'script file not inside project root' => ['/fallback/index.php', '/var/www/other/index.php', '/fallback'];

        yield 'windows paths' => ['/base/win/index.php', '{WB_ROOT}\\win\\index.php', '/base'];
        yield 'windows paths fallback' => ['/win/index.php', 'C:\\outside\\win\\index.php', '/win'];

        yield 'missing script name' => [null, '{WB_ROOT}/index.php', ''];
        yield 'missing script filename' => ['/index.php', null, ''];

        yield 'empty string script name' => ['', '{WB_ROOT}/index.php', ''];

        yield 'index.php without slash script name' => ['index.php', '{WB_ROOT}/index.php', ''];

        yield 'script file shorter than project root' => ['/short/index.php', '/short/index.php', '/short'];

        yield 'relative segments mismatch' => ['/mismatch/index.php', '{WB_ROOT}/different/different2/index.php', '/mismatch'];
    }
}
