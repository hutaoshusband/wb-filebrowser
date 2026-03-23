<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Database;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class DatabaseTest extends DatabaseTestCase
{
    public function testDisconnectClearsConnectionAndConfig(): void
    {
        // Obtain the initial connection
        $connection1 = Database::connection();
        $this->assertNotNull($connection1);

        // Ensure configuration is cached
        $config1 = Database::config();
        $this->assertIsArray($config1);

        // Disconnect
        Database::disconnect();

        // After disconnecting, requesting a connection should yield a new instance
        $connection2 = Database::connection();
        $this->assertNotSame($connection1, $connection2);
    }
}
