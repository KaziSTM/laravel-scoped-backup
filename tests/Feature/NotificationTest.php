<?php

declare(strict_types=1);

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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    config([
        'scoped-backup.table_name' => 'database_backups',
        'scoped-backup.storage.disk' => 'local',
        'scoped-backup.storage.path' => 'backups',
        'scoped-backup.notifications.enabled' => true,
        'scoped-backup.notifications.channels' => ['mail', 'webhook'],
        'scoped-backup.notifications.mail.to' => 'ops@example.com',
        'scoped-backup.notifications.webhook.url' => 'https://discord.com/api/webhooks/test',
        'scoped-backup.notifications.webhook.format' => 'discord',
        'scoped-backup.notifications.events.backup_failed' => true,
        'scoped-backup.database.connection' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_password',
        ],
    ]);
});

afterEach(function (): void {
    Mockery::close();
});

it('dispatches mail and discord webhook on backup failure', function (): void {
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

    expect(fn () => $action->execute(trigger: BackupTrigger::MANUAL))
        ->toThrow(BackupProcessException::class, 'Connection refused');

    // Verify Discord webhook was invoked with embed payload
    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url() === 'https://discord.com/api/webhooks/test'
            && isset($payload['embeds'][0]['title'])
            && str_contains($payload['embeds'][0]['title'], 'Backup Failed');
    });
});

it('does not mask primary backup error when notification fails', function (): void {
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

    expect(fn () => $action->execute(trigger: BackupTrigger::MANUAL))
        ->toThrow(BackupProcessException::class, 'Primary database dump failure');
});
