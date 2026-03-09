<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use RuntimeException;
use WbFileBrowser\Auth;
use WbFileBrowser\AuditLog;
use WbFileBrowser\BlockedAccessException;
use WbFileBrowser\Database;
use WbFileBrowser\FileManager;
use WbFileBrowser\FileShares;
use WbFileBrowser\IpBanService;
use WbFileBrowser\Settings;
use WbFileBrowser\Tests\Support\DatabaseTestCase;

final class SecurityAuditTest extends DatabaseTestCase
{
    public function testAuthEventsAreLoggedWhenAuditLoggingIsEnabled(): void
    {
        $this->enableAudit([
            'log_auth_success' => true,
            'log_auth_failure' => true,
        ]);

        try {
            Auth::login('superadmin', 'wrong-password');
            self::fail('Expected failed login to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invalid username or password.', $exception->getMessage());
        }

        $user = Auth::login('superadmin', 'SuperSecurePass123!');
        $this->assertSame('superadmin', $user['username']);
        Auth::logout();

        $events = $this->auditEventTypes();
        $this->assertContains('auth.login.failure', $events);
        $this->assertContains('auth.login.success', $events);
        $this->assertContains('auth.logout', $events);
    }

    public function testFileLifecycleAndShareEventsAreLogged(): void
    {
        $this->enableAudit([
            'log_file_uploads' => true,
            'log_file_management' => true,
            'log_deletions' => true,
            'log_admin_actions' => true,
        ]);

        $actor = $this->superAdmin();
        $folder = FileManager::createFolder($actor, Database::rootFolderId(), 'Projects');
        FileManager::renameFolder($actor, (int) $folder['id'], 'Projects 2026');
        $movedFolder = $this->createFolder('Archive');

        $init = FileManager::uploadInit($actor, (int) $folder['id'], 'report.txt', 11, 'text/plain', 1);
        file_put_contents(wb_storage_path('chunks/' . $init['upload_token'] . '/0.part'), 'hello world');
        $uploaded = FileManager::uploadComplete($actor, (string) $init['upload_token']);
        FileManager::renameFile($actor, (int) $uploaded['id'], 'report-final.txt');
        FileManager::moveFile($actor, (int) $uploaded['id'], (int) $movedFolder['id']);
        FileManager::deleteFile($actor, (int) $uploaded['id']);

        $sharedFile = $this->createFile('shared.txt', 'shared body', 'text/plain', Database::rootFolderId(), $actor);
        FileShares::create($actor, (int) $sharedFile['id']);
        FileShares::revoke($actor, (int) $sharedFile['id']);

        $events = $this->auditEventTypes();
        $this->assertContains('folder.create', $events);
        $this->assertContains('folder.rename', $events);
        $this->assertContains('file.upload', $events);
        $this->assertContains('file.rename', $events);
        $this->assertContains('file.move', $events);
        $this->assertContains('file.delete', $events);
        $this->assertContains('share.create', $events);
        $this->assertContains('share.revoke', $events);
    }

    public function testFileAndShareStreamingLogsAvoidDuplicateInlineShareEvents(): void
    {
        $this->enableAudit([
            'log_file_views' => true,
            'log_file_downloads' => true,
        ]);

        $file = $this->createFile('manual.txt', 'alpha beta', 'text/plain');
        $this->runIsolatedPhp(sprintf(
            '$user = WbFileBrowser\Database::connection()->query("SELECT id, username, role, status, force_password_reset, is_immutable, created_at, updated_at, last_login_at FROM users LIMIT 1")->fetch(); WbFileBrowser\FileManager::streamFile($user, %d, "inline");',
            (int) $file['id']
        ));
        $this->runIsolatedPhp(sprintf(
            '$user = WbFileBrowser\Database::connection()->query("SELECT id, username, role, status, force_password_reset, is_immutable, created_at, updated_at, last_login_at FROM users LIMIT 1")->fetch(); WbFileBrowser\FileManager::streamFile($user, %d, "attachment");',
            (int) $file['id']
        ));

        $share = FileShares::create($this->superAdmin(), (int) $file['id']);
        $payload = FileShares::viewPayload($share['token']);
        parse_str((string) parse_url((string) $payload['file']['preview_url'], PHP_URL_QUERY), $inlineQuery);
        parse_str((string) parse_url((string) $payload['file']['download_url'], PHP_URL_QUERY), $attachmentQuery);

        $this->runIsolatedPhp(sprintf(
            'WbFileBrowser\FileShares::streamGranted(%s);',
            var_export((string) ($inlineQuery['grant'] ?? ''), true)
        ));
        $this->runIsolatedPhp(sprintf(
            'WbFileBrowser\FileShares::streamGranted(%s);',
            var_export((string) ($attachmentQuery['grant'] ?? ''), true)
        ));

        $events = $this->auditEventTypes();
        $this->assertSame(1, $this->countAuditEvents('file.view'));
        $this->assertSame(1, $this->countAuditEvents('file.download'));
        $this->assertSame(1, $this->countAuditEvents('share.view'));
        $this->assertSame(1, $this->countAuditEvents('share.download'));
        $this->assertContains('share.view', $events);
        $this->assertContains('share.download', $events);
    }

    public function testPasswordProtectedShareDoesNotLogViewsBeforeUnlock(): void
    {
        $this->enableAudit([
            'log_file_views' => true,
            'log_file_downloads' => true,
            'log_security_actions' => true,
        ]);

        $file = $this->createFile('vault.txt', 'classified');
        $share = FileShares::create($this->superAdmin(), (int) $file['id'], [
            'password' => 'Secret 123',
        ]);

        try {
            FileShares::viewPayload($share['token']);
            self::fail('Expected locked share to reject direct viewing.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Share password is required.', $exception->getMessage());
        }

        $this->assertSame(0, $this->countAuditEvents('share.view'));
        $this->assertFalse(FileShares::unlock($share['token'], 'nope'));
        $this->assertSame(0, $this->countAuditEvents('share.view'));
        $this->assertTrue(FileShares::unlock($share['token'], 'Secret 123'));
        FileShares::viewPayload($share['token']);
        $this->assertSame(1, $this->countAuditEvents('share.view'));
    }

    public function testIpBansCanBeCheckedExpiredAndLifted(): void
    {
        $this->enableAudit([
            'log_security_actions' => true,
        ]);

        $actor = $this->superAdmin();
        $ban = IpBanService::ban($actor, '203.0.113.42', 'Abuse');
        $previousIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        try {
            IpBanService::assertCurrentIpAllowed();
            self::fail('Expected the banned IP to be rejected.');
        } catch (BlockedAccessException $exception) {
            $this->assertSame('ip_ban', $exception->payload()['source']);
            $this->assertTrue($exception->payload()['blocked_permanently']);
        } finally {
            $_SERVER['REMOTE_ADDR'] = $previousIp ?? '127.0.0.1';
        }

        $lifted = IpBanService::unban($actor, (int) $ban['id']);
        $this->assertSame('manual', $lifted['revoked_reason']);

        $expiringBan = IpBanService::ban($actor, '198.51.100.7', 'Temporary', gmdate('c', time() + 300));
        Database::connection()->prepare('UPDATE ip_bans SET expires_at = :expires_at, updated_at = :updated_at WHERE id = :id')->execute([
            ':expires_at' => gmdate('c', time() - 60),
            ':updated_at' => wb_now(),
            ':id' => $expiringBan['id'],
        ]);
        $list = IpBanService::list();
        $expired = current(array_filter($list['ban_history'], static fn (array $row): bool => (int) $row['id'] === (int) $expiringBan['id']));

        $this->assertIsArray($expired);
        $this->assertSame('expired', $expired['revoked_reason']);
        $this->assertContains('security.ip_ban.create', $this->auditEventTypes());
        $this->assertContains('security.ip_ban.unban', $this->auditEventTypes());
        $this->assertContains('security.ip_ban.expire', $this->auditEventTypes());
    }

    public function testAuditCleanupDeleteAllDoesNotRetainASelfAuditEntry(): void
    {
        $this->enableAudit([
            'log_admin_actions' => true,
        ]);

        $this->insertAuditRow('file.view', 'file_views', gmdate('c', time() - 86400));
        $this->insertAuditRow('file.download', 'file_downloads', gmdate('c', time() - 3600));

        $result = AuditLog::cleanup('all');

        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame(0, $result['remaining_count']);
        $this->assertSame([], $this->auditEventTypes());
    }

    public function testAuditCleanupSupportsOlderThanAndKeepLastDaysModes(): void
    {
        $this->enableAudit([
            'log_admin_actions' => false,
        ]);

        $this->insertAuditRow('old.entry', 'file_views', gmdate('c', time() - (50 * 86400)));
        $this->insertAuditRow('mid.entry', 'file_views', gmdate('c', time() - (40 * 86400)));
        $this->insertAuditRow('new.entry', 'file_views', gmdate('c', time() - (34 * 86400)));

        $keepLast = AuditLog::cleanup('keep_last_days', 7);

        $this->assertSame(1, $keepLast['deleted_count']);
        $this->assertSame(2, $keepLast['remaining_count']);
        $this->assertSame(['mid.entry', 'new.entry'], $this->auditEventTypes());
    }

    public function testAuditCleanupOlderThanDaysUsesWallClockCutoffAndCanLogTheAction(): void
    {
        $this->enableAudit([
            'log_admin_actions' => true,
        ]);

        $this->insertAuditRow('old.entry', 'file_views', gmdate('c', time() - (50 * 86400)));
        $this->insertAuditRow('mid.entry', 'file_views', gmdate('c', time() - (40 * 86400)));
        $this->insertAuditRow('new.entry', 'file_views', gmdate('c', time() - (34 * 86400)));

        $result = AuditLog::cleanup('older_than_days', 7);

        $this->assertSame(3, $result['deleted_count']);
        $this->assertSame(1, $result['remaining_count']);
        $this->assertSame(1, $this->countAuditEvents('admin.audit.cleanup'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enableAudit(array $overrides = []): void
    {
        Settings::saveAdminSettings([
            'security' => array_merge(Settings::defaultGrouped()['security'], [
                'audit_enabled' => true,
            ], $overrides),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function auditEventTypes(): array
    {
        return Database::connection()->query('SELECT event_type FROM audit_logs ORDER BY id ASC')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    private function countAuditEvents(string $eventType): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM audit_logs WHERE event_type = :event_type');
        $statement->execute([':event_type' => $eventType]);

        return (int) $statement->fetchColumn();
    }

    private function insertAuditRow(string $eventType, string $category, string $createdAt): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (
                event_type,
                category,
                actor_user_id,
                actor_username,
                ip_address,
                target_type,
                target_id,
                target_label,
                metadata_json,
                created_at
             ) VALUES (
                :event_type,
                :category,
                NULL,
                NULL,
                :ip_address,
                :target_type,
                NULL,
                :target_label,
                :metadata_json,
                :created_at
             )'
        );
        $statement->execute([
            ':event_type' => $eventType,
            ':category' => $category,
            ':ip_address' => '127.0.0.1',
            ':target_type' => 'file',
            ':target_label' => 'Synthetic entry',
            ':metadata_json' => '{}',
            ':created_at' => $createdAt,
        ]);
    }

    private function runIsolatedPhp(string $code): void
    {
        $bootstrapCode = sprintf(
            'define("WB_ROOT", %s); define("WB_STORAGE", %s); define("WB_BASE_PATH", ""); $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["HTTPS"] = "off"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; require %s; %s',
            var_export(WB_ROOT, true),
            var_export(WB_STORAGE, true),
            var_export(WB_ROOT . '/app/bootstrap.php', true),
            $code
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($bootstrapCode), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
