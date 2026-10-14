<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Contracts\DatabaseDumperInterface;
use KaziSTM\LaravelScopedBackup\Dumpers\DatabaseDumperManager;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use Mockery;

final class CommandsTest extends TestCase
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

    public function test_scoped_backup_full_command_runs_successfully(): void
    {
        $mockDumper = Mockery::mock(DatabaseDumperInterface::class);
        $mockDumper->shouldReceive('dump')
            ->once()
            ->withArgs(function ($config, $outputPath) {
                File::put($outputPath, 'DUMP_FOR_COMMAND_TEST');

                return true;
            })
            ->andReturn([
                'format' => 'custom',
                'driver' => 'pgsql',
                'database' => 'company_radar',
            ]);

        $mockDumper->shouldReceive('verifyArchive')
            ->once()
            ->andReturn([
                'verified' => true,
                'toc_entries_count' => 110,
            ]);

        $dumperManager = app(DatabaseDumperManager::class);
        $dumperManager->extend('pgsql', $mockDumper);

        $this->artisan('scoped-backup:full', ['--trigger' => 'manual'])
            ->expectsOutputToContain('Initiating manual full backup...')
            ->expectsOutputToContain('Full backup completed and verified successfully.')
            ->assertExitCode(0);

        $record = BackupRecord::latest('id')->first();
        $this->assertNotNull($record);
        $this->assertSame(BackupStatus::SUCCEEDED, $record->status);
    }

    public function test_scoped_backup_scoped_command_runs_successfully(): void
    {
        User::create(['name' => 'CLI User', 'email' => 'cli@test.dz', 'password' => 'secret']);

        $this->artisan('scoped-backup:scoped', [
            'model' => User::class,
            '--name' => 'cli_users',
            '--identity' => ['email'],
        ])
            ->expectsOutputToContain('Creating scoped backup for ['.User::class.']')
            ->expectsOutputToContain('Scoped backup completed successfully.')
            ->assertExitCode(0);

        $record = BackupRecord::latest('id')->first();
        $this->assertNotNull($record);
        $this->assertSame(BackupStatus::SUCCEEDED, $record->status);
    }

    public function test_scoped_backup_prune_command_runs_successfully(): void
    {
        $this->artisan('scoped-backup:prune', ['--retention' => 7])
            ->expectsOutputToContain('Pruning expired backups...')
            ->expectsOutputToContain('Pruning complete.')
            ->assertExitCode(0);
    }

    public function test_scoped_backup_monitor_command_runs_and_reports_health(): void
    {
        // 1. When no backups exist -> reports failed
        $this->artisan('scoped-backup:monitor')
            ->expectsOutputToContain('backup_freshness')
            ->assertExitCode(1);

        // 2. Create fresh valid backup
        $path = 'backups/fresh.zip';
        Storage::disk('local')->put($path, 'DUMP');

        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'company_radar',
            'disk' => 'local',
            'file_path' => $path,
            'file_size_bytes' => 4,
            'checksum_sha256' => hash('sha256', 'DUMP'),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->artisan('scoped-backup:monitor')
            ->expectsOutputToContain('All backup health checks passed.')
            ->assertExitCode(0);
    }
}
