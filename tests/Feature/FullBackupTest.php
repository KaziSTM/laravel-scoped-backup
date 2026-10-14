<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\PruneBackupsAction;
use KaziSTM\LaravelScopedBackup\Contracts\DatabaseDumperInterface;
use KaziSTM\LaravelScopedBackup\Dumpers\DatabaseDumperManager;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Events\BackupFailed;
use KaziSTM\LaravelScopedBackup\Events\BackupSuccessful;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('s3');

    config([
        'scoped-backup.table_name' => 'database_backups',
        'scoped-backup.storage.disk' => 'local',
        'scoped-backup.storage.path' => 'backups',
        'scoped-backup.retention.limit' => 7,
        'scoped-backup.database.connection' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_password',
        ],
    ]);
});

afterEach(function (): void {
    Mockery::close();
});

it('creates full backup and generates zip manifest successfully', function (): void {
    Event::fake([BackupSuccessful::class, BackupFailed::class]);

    $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
    $mockDumper->shouldReceive('dump')
        ->once()
        ->withArgs(function (array $config, string $outputPath) {
            File::put($outputPath, 'VALID_PGSQL_DUMP_STREAM');

            return $config['database'] === 'test_db';
        })
        ->andReturn([
            'format' => 'custom',
            'compression' => true,
            'driver' => 'pgsql',
            'database' => 'test_db',
        ]);

    $mockDumper->shouldReceive('verifyArchive')
        ->once()
        ->andReturn([
            'verified' => true,
            'toc_entries_count' => 140,
        ]);

    $dumperManager = new DatabaseDumperManager;
    $dumperManager->extend('pgsql', $mockDumper);

    $action = new CreateFullBackupAction(
        $dumperManager,
        new ArchiveManager,
        new ChecksumValidator,
        new BackupLockManager,
        app(PruneBackupsAction::class),
    );

    $record = $action->execute(trigger: BackupTrigger::SCHEDULED);

    expect($record)->toBeInstanceOf(BackupRecord::class)
        ->and($record->status)->toBe(BackupStatus::SUCCEEDED)
        ->and($record->trigger)->toBe(BackupTrigger::SCHEDULED)
        ->and($record->driver)->toBe('pgsql')
        ->and($record->database_name)->toBe('test_db')
        ->and($record->disk)->toBe('local')
        ->and($record->file_path)->not->toBeNull()
        ->and($record->file_path)->toEndWith('.zip')
        ->and($record->file_size_bytes)->toBeGreaterThan(0)
        ->and($record->checksum_sha256)->not->toBeNull()
        ->and($record->error_message)->toBeNull()
        ->and($record->pruned_at)->toBeNull();

    // Assert Storage ZIP exists
    Storage::disk('local')->assertExists($record->file_path);

    // Verify ZIP Manifest
    $fullPath = Storage::disk('local')->path($record->file_path);
    $manifest = (new ArchiveManager)->readManifest($fullPath);

    expect($manifest->schemaVersion)->toBe('1.0')
        ->and($manifest->backupType)->toBe(BackupType::FULL)
        ->and($manifest->databaseName)->toBe('test_db')
        ->and($manifest->counts['toc_entries'])->toBe(140)
        ->and($manifest->checksums['database_dump_sha256'])->toBe(hash('sha256', 'VALID_PGSQL_DUMP_STREAM'));

    // Assert event was dispatched
    Event::assertDispatched(BackupSuccessful::class);
});

it('records failure and cleans staging when dumper process fails', function (): void {
    Event::fake([BackupFailed::class]);

    $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
    $mockDumper->shouldReceive('dump')
        ->once()
        ->andThrow(new BackupProcessException('pg_dump process failed: network timeout'));

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
        ->toThrow(BackupProcessException::class, 'network timeout');

    $failedRecord = BackupRecord::latest('id')->first();
    expect($failedRecord)->not->toBeNull()
        ->and($failedRecord->status)->toBe(BackupStatus::FAILED)
        ->and((string) $failedRecord->error_message)->toContain('network timeout');

    // Zero files published to storage
    expect(Storage::disk('local')->allFiles('backups'))->toBeEmpty();
    Event::assertDispatched(BackupFailed::class);
});

it('prevents publishing when pre-publication verification fails', function (): void {
    $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
    $mockDumper->shouldReceive('dump')
        ->once()
        ->withArgs(function ($config, $outputPath) {
            File::put($outputPath, 'TRUNCATED_DUMP_BYTES');

            return true;
        })
        ->andReturn([]);

    $mockDumper->shouldReceive('verifyArchive')
        ->once()
        ->andThrow(new BackupVerificationException('pg_restore verification produced empty Table of Contents.'));

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
        ->toThrow(BackupVerificationException::class, 'empty Table of Contents');

    $failedRecord = BackupRecord::latest('id')->first();
    expect($failedRecord->status)->toBe(BackupStatus::FAILED);
    expect(Storage::disk('local')->allFiles('backups'))->toBeEmpty();
});

it('soft prunes older archives according to retention policy', function (): void {
    // Create 10 historical successful backup records with physical files
    for ($i = 10; $i >= 1; $i--) {
        $path = "backups/backup_day_{$i}.zip";
        Storage::disk('local')->put($path, "ZIP_PAYLOAD_{$i}");

        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'test_db',
            'disk' => 'local',
            'file_path' => $path,
            'file_size_bytes' => 100,
            'checksum_sha256' => hash('sha256', "DAY_{$i}"),
            'started_at' => now()->subDays($i),
            'completed_at' => now()->subDays($i)->addSeconds(2),
        ]);
    }

    expect(BackupRecord::unpruned()->count())->toBe(10)
        ->and(Storage::disk('local')->allFiles('backups'))->toHaveCount(10);

    $pruner = app(PruneBackupsAction::class);
    $prunedCount = $pruner->execute(retentionLimit: 7);

    expect($prunedCount)->toBe(3)
        ->and(BackupRecord::unpruned()->count())->toBe(7)
        ->and(BackupRecord::whereNotNull('pruned_at')->count())->toBe(3);

    // 7 newest exist on disk
    for ($i = 1; $i <= 7; $i++) {
        Storage::disk('local')->assertExists("backups/backup_day_{$i}.zip");
    }

    // 3 oldest removed from disk
    for ($i = 8; $i <= 10; $i++) {
        Storage::disk('local')->assertMissing("backups/backup_day_{$i}.zip");
    }
});

it('prevents simultaneous full backup using concurrency lock', function (): void {
    $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
    $dumperManager = new DatabaseDumperManager;
    $dumperManager->extend('pgsql', $mockDumper);

    // Pre-acquire execution lock
    $lock = Cache::lock(BackupLockManager::EXECUTION_LOCK_KEY, 600);
    $lock->get();

    $action = new CreateFullBackupAction(
        $dumperManager,
        new ArchiveManager,
        new ChecksumValidator,
        new BackupLockManager,
        app(PruneBackupsAction::class),
    );

    try {
        expect(fn () => $action->execute())
            ->toThrow(BackupException::class, 'currently in progress');
    } finally {
        $lock->release();
    }
});
