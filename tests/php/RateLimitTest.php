<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Auth;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class RateLimitTest extends DatabaseTestCase
{
    public function testFailedLoginsAreRateLimited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt += 1) {
            try {
                Auth::login('superadmin', 'wrong-password');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid username or password.', $exception->getMessage());
            }
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many failed login attempts');

        Auth::login('superadmin', 'wrong-password');
    }
}
