<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Security;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class FileOffloadTest extends DatabaseTestCase
{
    public function testOffloadIsOptInAndConfinedToUploads(): void
    {
        $previous = getenv('WB_X_ACCEL_PREFIX');
        $file = wb_storage_path('uploads/video with spaces.mp4');
        file_put_contents($file, 'video');
        try {
            putenv('WB_X_ACCEL_PREFIX');
            self::assertNull(Security::fileOffloadUri($file));
            putenv('WB_X_ACCEL_PREFIX=/_wb_uploads/');
            self::assertSame('/_wb_uploads/video%20with%20spaces.mp4', Security::fileOffloadUri($file));
            self::assertNull(Security::fileOffloadUri(wb_storage_path('app.sqlite')));
            self::assertNull(Security::fileOffloadUri(wb_storage_path('uploads/../app.sqlite')));
            self::assertNull(Security::fileOffloadUri(wb_storage_path('uploads/missing.mp4')));
            self::assertNull(Security::fileOffloadUri(wb_storage_path('uploads')));
            foreach (['https://example.com/', '/bad/../', "/bad/\r\nInjected/", 'relative/', '/no-trailing-slash'] as $prefix) {
                putenv('WB_X_ACCEL_PREFIX=' . $prefix);
                self::assertNull(Security::fileOffloadUri($file));
            }
        } finally {
            putenv($previous === false ? 'WB_X_ACCEL_PREFIX' : 'WB_X_ACCEL_PREFIX=' . $previous);
        }
    }
}
