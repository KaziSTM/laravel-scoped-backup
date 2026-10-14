<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\PruneBackupsAction;
use KaziSTM\LaravelScopedBackup\Contracts\DatabaseDumperInterface;
use KaziSTM\LaravelScopedBackup\Dumpers\DatabaseDumperManager;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use Mockery;

final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        config([
            'scoped-backup.table_name' => 'database_backups',
            'scoped-backup.storage.disk' => 'local',
            'scoped-backup.storage.path' => 'backups',
            'scoped-backup.notifications.enabled' => true,
            'scoped-backup.notifications.channels' => ['mail', 'webhook'],
            'scoped-backup.notifications.mail.to' => 'ops@companyradar.dz',
            'scoped-backup.notifications.webhook.url' => 'https://discord.com/api/webhooks/test',
            'scoped-backup.notifications.webhook.format' => 'discord',
            'scoped-backup.notifications.events.backup_failed' => true,
            'scoped-backup.database.connection' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'company_radar',
                'username' => 'postgres',
                'password' => 'secret',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dispatches_mail_and_discord_webhook_on_backup_failure(): void
    {
        Mail::fake();
        Http::fake([
            'https://discord.com/api/webhooks/test' => Http::response(['status' => 'ok'], 200),
        ]);

        $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
        $mockDumper->shouldReceive('dump')
            ->once()
            ->andThrow(new BackupProcessException('Connection refused by PostgreSQL server'));

        $dumperManager = new DatabaseDumperManager;
        $dumperManager->extend('pgsql', $mockDumper);

        $action = new CreateFullBackupAction(
            $dumperManager,
            new ArchiveManager,
            new ChecksumValidator,
            new BackupLockManager,
            app(PruneBackupsAction::class),
        );

        try {
            $action->execute(trigger: BackupTrigger::MANUAL);
            $this->fail('Expected BackupProcessException was not thrown.');
        } catch (BackupProcessException $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        // Verify Discord webhook was invoked with embed payload
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://discord.com/api/webhooks/test'
                && isset($payload['embeds'][0]['title'])
                && str_contains($payload['embeds'][0]['title'], 'Backup Failed');
        });
    }

    public function test_notification_failure_does_not_mask_primary_backup_error(): void
    {
        // Simulate webhook network failure (500 internal server error)
        Http::fake([
            'https://discord.com/api/webhooks/test' => Http::response('Discord Service Unavailable', 500),
        ]);

        $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
        $mockDumper->shouldReceive('dump')
            ->once()
            ->andThrow(new BackupProcessException('Primary database dump failure'));

        $dumperManager = new DatabaseDumperManager;
        $dumperManager->extend('pgsql', $mockDumper);

        $action = new CreateFullBackupAction(
            $dumperManager,
            new ArchiveManager,
            new ChecksumValidator,
            new BackupLockManager,
            app(PruneBackupsAction::class),
        );

        try {
            $action->execute(trigger: BackupTrigger::MANUAL);
            $this->fail('Expected BackupProcessException was not thrown.');
        } catch (BackupProcessException $e) {
            // The primary error is faithfully delivered and not masked by webhook error
            $this->assertSame('Primary database dump failure', $e->getMessage());
        }
    }
}
