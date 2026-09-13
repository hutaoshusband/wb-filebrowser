<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;

/** Serializes ledger changes with physical cleanup on the shared storage volume. */
final class StorageLock
{
    private static $handle = null;
    private static int $depth = 0;

    public function __construct()
    {
        if (self::$depth === 0) {
            $handle = fopen(wb_storage_path('.storage.lock'), 'c');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the storage lock.');
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException('Unable to lock storage.');
            }
            self::$handle = $handle;
        }
        self::$depth++;
    }

    public function __destruct()
    {
        if (--self::$depth === 0) {
            flock(self::$handle, LOCK_UN);
            fclose(self::$handle);
            self::$handle = null;
        }
    }
}
