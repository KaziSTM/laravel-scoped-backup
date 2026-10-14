<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

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
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use Mockery;

final class FullBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'company_radar_testing',
                'username' => 'postgres',
                'password' => 'supersecretpass',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_successful_full_backup_creation_and_zip_manifest_generation(): void
    {
        Event::fake([BackupSuccessful::class, BackupFailed::class]);

        $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
        $mockDumper->shouldReceive('dump')
            ->once()
            ->withArgs(function (array $config, string $outputPath) {
                File::put($outputPath, 'VALID_PGSQL_DUMP_STREAM');

                return $config['database'] === 'company_radar_testing';
            })
            ->andReturn([
                'format' => 'custom',
                'compression' => true,
                'driver' => 'pgsql',
                'database' => 'company_radar_testing',
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

        $this->assertInstanceOf(BackupRecord::class, $record);
        $this->assertSame(BackupStatus::SUCCEEDED, $record->status);
        $this->assertSame(BackupTrigger::SCHEDULED, $record->trigger);
        $this->assertSame('pgsql', $record->driver);
        $this->assertSame('company_radar_testing', $record->database_name);
        $this->assertSame('local', $record->disk);
        $this->assertNotNull($record->file_path);
        $this->assertStringEndsWith('.zip', $record->file_path);
        $this->assertGreaterThan(0, $record->file_size_bytes);
        $this->assertNotNull($record->checksum_sha256);
        $this->assertNull($record->error_message);
        $this->assertNull($record->pruned_at);

        // Assert Storage ZIP exists
        Storage::disk('local')->assertExists($record->file_path);

        // Verify ZIP Manifest
        $fullPath = Storage::disk('local')->path($record->file_path);
        $manifest = (new ArchiveManager)->readManifest($fullPath);

        $this->assertSame('1.0', $manifest->schemaVersion);
        $this->assertSame(BackupType::FULL, $manifest->backupType);
        $this->assertSame('company_radar_testing', $manifest->databaseName);
        $this->assertSame(140, $manifest->counts['toc_entries']);
        $this->assertSame(hash('sha256', 'VALID_PGSQL_DUMP_STREAM'), $manifest->checksums['database_dump_sha256']);

        // Assert event was dispatched
        Event::assertDispatched(BackupSuccessful::class);
    }

    public function test_failed_dumper_process_records_failure_and_cleans_staging(): void
    {
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

        try {
            $action->execute(trigger: BackupTrigger::MANUAL);
            $this->fail('Expected BackupProcessException was not thrown.');
        } catch (BackupProcessException $e) {
            $this->assertStringContainsString('network timeout', $e->getMessage());
        }

        $failedRecord = BackupRecord::latest('id')->first();
        $this->assertNotNull($failedRecord);
        $this->assertSame(BackupStatus::FAILED, $failedRecord->status);
        $this->assertStringContainsString('network timeout', (string) $failedRecord->error_message);

        // Zero files published to storage
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
        Event::assertDispatched(BackupFailed::class);
    }

    public function test_pre_publication_verification_failure_prevents_publishing(): void
    {
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

        try {
            $action->execute(trigger: BackupTrigger::MANUAL);
            $this->fail('Expected BackupVerificationException was not thrown.');
        } catch (BackupVerificationException $e) {
            $this->assertStringContainsString('empty Table of Contents', $e->getMessage());
        }

        $failedRecord = BackupRecord::latest('id')->first();
        $this->assertSame(BackupStatus::FAILED, $failedRecord->status);
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
    }

    public function test_retention_pruning_soft_prunes_older_archives(): void
    {
        // Create 10 historical successful backup records with physical files
        for ($i = 10; $i >= 1; $i--) {
            $path = "backups/backup_day_{$i}.zip";
            Storage::disk('local')->put($path, "ZIP_PAYLOAD_{$i}");

            BackupRecord::create([
                'status' => BackupStatus::SUCCEEDED,
                'trigger' => BackupTrigger::SCHEDULED,
                'driver' => 'pgsql',
                'database_name' => 'company_radar_testing',
                'disk' => 'local',
                'file_path' => $path,
                'file_size_bytes' => 100,
                'checksum_sha256' => hash('sha256', "DAY_{$i}"),
                'started_at' => now()->subDays($i),
                'completed_at' => now()->subDays($i)->addSeconds(2),
            ]);
        }

        $this->assertSame(10, BackupRecord::unpruned()->count());
        $this->assertCount(10, Storage::disk('local')->allFiles('backups'));

        $pruner = app(PruneBackupsAction::class);
        $prunedCount = $pruner->execute(retentionLimit: 7);

        $this->assertSame(3, $prunedCount);
        $this->assertSame(7, BackupRecord::unpruned()->count());
        $this->assertSame(3, BackupRecord::whereNotNull('pruned_at')->count());

        // 7 newest exist on disk
        for ($i = 1; $i <= 7; $i++) {
            Storage::disk('local')->assertExists("backups/backup_day_{$i}.zip");
        }

        // 3 oldest removed from disk
        for ($i = 8; $i <= 10; $i++) {
            Storage::disk('local')->assertMissing("backups/backup_day_{$i}.zip");
        }
    }

    public function test_concurrency_lock_prevents_simultaneous_full_backup(): void
    {
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
            $action->execute();
            $this->fail('Expected BackupException was not thrown on concurrent run.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('currently in progress', $e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
