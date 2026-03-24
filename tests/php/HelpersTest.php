<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
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
}
