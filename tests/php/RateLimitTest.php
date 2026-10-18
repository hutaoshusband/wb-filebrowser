<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Auth;
use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\FileManager;
use WbFileBrowser\Security;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class RateLimitTest extends DatabaseTestCase
{
    public function testFailedLoginsAreRateLimited(): void
    {
        for ($attempt = 0; $attempt < 4; $attempt += 1) {
            try {
                Auth::login('superadmin', 'wrong-password');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid username or password.', $exception->getMessage());
            }
        }

        try {
            Auth::login('superadmin', 'wrong-password');
            self::fail('Expected the login surface to be blocked.');
        } catch (BlockedAccessException $exception) {
            $payload = $exception->payload();
            $this->assertSame('You have been blocked.', $exception->getMessage());
            $this->assertSame('auth_login', $payload['source']);
            $this->assertFalse($payload['blocked_permanently']);
            $this->assertNotEmpty($payload['blocked_until']);
            $this->assertGreaterThan(0, $payload['retry_after_seconds']);
        }
    }

    public function testBulkUploadInitAllowsFiftyFiles(): void
    {
        $buckets = FileManager::uploadInitRateLimitBuckets('1', '127.0.0.1');

        for ($file = 1; $file <= 50; $file += 1) {
            Security::assertRateLimitAvailable($buckets, FileManager::MSG_RATE_LIMIT_UPLOAD);
            Security::consumeRateLimit($buckets);
        }

        self::assertNull(Security::rateLimitBlockInfo($buckets));

        $shareBuckets = FileManager::folderShareUploadRateLimitBuckets('127.0.0.1');

        for ($file = 1; $file <= 50; $file += 1) {
            Security::assertRateLimitAvailable($shareBuckets, FileManager::MSG_RATE_LIMIT_UPLOAD);
            Security::consumeRateLimit($shareBuckets);
        }

        self::assertNull(Security::rateLimitBlockInfo($shareBuckets));
    }
}
