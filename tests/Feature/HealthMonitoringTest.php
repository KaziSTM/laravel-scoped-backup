<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;

final class HealthMonitoringTest extends TestCase
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
            'scoped-backup.health.max_age_hours' => 24,
            'scoped-backup.health.size_deviation_threshold_percent' => 50,
        ]);
    }

    public function test_detects_fresh_backup_as_healthy(): void
    {
        $path = 'backups/fresh_backup.zip';
        Storage::disk('local')->put($path, 'DUMP_DATA');

        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'company_radar',
            'disk' => 'local',
            'file_path' => $path,
            'file_size_bytes' => strlen('DUMP_DATA'),
            'checksum_sha256' => hash('sha256', 'DUMP_DATA'),
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);

        $verifier = app(VerifyBackupHealthAction::class);
        $result = $verifier->check();

        $this->assertTrue($result->isHealthy);
        $this->assertFalse($result->hasFailures());
        $this->assertFalse($result->hasWarnings());
    }

    public function test_detects_stale_backup_as_failure(): void
    {
        $path = 'backups/stale_backup.zip';
        Storage::disk('local')->put($path, 'STALE_DATA');

        // Completed 30 hours ago (> 24h threshold)
        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'company_radar',
            'disk' => 'local',
            'file_path' => $path,
            'file_size_bytes' => strlen('STALE_DATA'),
            'checksum_sha256' => hash('sha256', 'STALE_DATA'),
            'started_at' => now()->subHours(30),
            'completed_at' => now()->subHours(30),
        ]);

        $result = app(VerifyBackupHealthAction::class)->check();

        $this->assertFalse($result->isHealthy);
        $this->assertTrue($result->hasFailures());

        $failures = $result->failures();
        $this->assertSame('backup_freshness', $failures[0]['check']);
        $this->assertStringContainsString('stale', $failures[0]['message']);
    }

    public function test_detects_missing_physical_archive_as_failure(): void
    {
        // Record exists, but physical file is missing from disk
        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'company_radar',
            'disk' => 'local',
            'file_path' => 'backups/deleted_from_disk.zip',
            'file_size_bytes' => 1000,
            'checksum_sha256' => hash('sha256', 'DELETED'),
            'started_at' => now()->subHours(1),
            'completed_at' => now()->subHours(1),
        ]);

        $result = app(VerifyBackupHealthAction::class)->check();

        $this->assertFalse($result->isHealthy);
        $this->assertTrue($result->hasFailures());

        $failures = $result->failures();
        $integrityFailure = collect($failures)->firstWhere('check', 'archive_integrity');
        $this->assertNotNull($integrityFailure);
        $this->assertStringContainsString('missing', $integrityFailure['message']);
    }

    public function test_detects_size_drop_anomaly_as_warning(): void
    {
        // 3 historical successful backups of ~10,000 bytes
        for ($i = 4; $i >= 2; $i--) {
            $p = "backups/historical_{$i}.zip";
            Storage::disk('local')->put($p, str_repeat('A', 10000));
            BackupRecord::create([
                'status' => BackupStatus::SUCCEEDED,
                'trigger' => BackupTrigger::SCHEDULED,
                'driver' => 'pgsql',
                'database_name' => 'company_radar',
                'disk' => 'local',
                'file_path' => $p,
                'file_size_bytes' => 10000,
                'checksum_sha256' => hash('sha256', str_repeat('A', 10000)),
                'started_at' => now()->subDays($i),
                'completed_at' => now()->subDays($i),
            ]);
        }

        // Latest backup suddenly drops to 1,000 bytes (90% drop, > 50% threshold)
        $latestPath = 'backups/latest_drop.zip';
        Storage::disk('local')->put($latestPath, str_repeat('A', 1000));
        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'company_radar',
            'disk' => 'local',
            'file_path' => $latestPath,
            'file_size_bytes' => 1000,
            'checksum_sha256' => hash('sha256', str_repeat('A', 1000)),
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);

        $result = app(VerifyBackupHealthAction::class)->check();

        // Size deviation is treated as warning, not outright failure
        $this->assertTrue($result->isHealthy);
        $this->assertTrue($result->hasWarnings());

        $warning = collect($result->warnings())->firstWhere('check', 'size_anomaly');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('dropped', $warning['message']);
    }
}
