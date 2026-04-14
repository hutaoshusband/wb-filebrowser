<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\AuditLog;
use WbFileBrowser\Database;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class AuditLogTest extends DatabaseTestCase
{
    public function testShouldLogReturnsFalseWhenCategoryIsInvalid(): void
    {
        $this->assertFalse(AuditLog::shouldLog('non_existent_category'));
    }

    public function testShouldLogReturnsFalseWhenAuditIsDisabled(): void
    {
        Database::updateSetting('audit_enabled', '0');
        Database::updateSetting('log_auth_success', '1');

        $this->assertFalse(AuditLog::shouldLog('auth_success'));
    }

    public function testShouldLogReturnsFalseWhenCategoryIsDisabled(): void
    {
        Database::updateSetting('audit_enabled', '1');
        Database::updateSetting('log_auth_success', '0');

        $this->assertFalse(AuditLog::shouldLog('auth_success'));
    }

    public function testShouldLogReturnsTrueWhenAuditAndCategoryAreEnabled(): void
    {
        Database::updateSetting('audit_enabled', '1');
        Database::updateSetting('log_auth_success', '1');

        $this->assertTrue(AuditLog::shouldLog('auth_success'));
    }

    public function testShouldLogReturnsTrueWhenCategorySettingIsMissing(): void
    {
        Database::updateSetting('audit_enabled', '1');

        // Remove the setting to test default value '1'
        Database::connection()->prepare('DELETE FROM settings WHERE key = :key')
            ->execute([':key' => 'log_auth_success']);

        $this->assertTrue(AuditLog::shouldLog('auth_success'));
    }
}
