<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    config([
        'scoped-backup.table_name' => 'database_backups',
        'scoped-backup.storage.disk' => 'local',
        'scoped-backup.storage.path' => 'backups',
        'scoped-backup.health.max_age_hours' => 24,
        'scoped-backup.health.size_deviation_threshold_percent' => 50,
    ]);
});

it('detects fresh backup as healthy', function (): void {
    $path = 'backups/fresh_backup.zip';
    Storage::disk('local')->put($path, 'DUMP_DATA');

    BackupRecord::create([
        'status' => BackupStatus::SUCCEEDED,
        'trigger' => BackupTrigger::SCHEDULED,
        'driver' => 'pgsql',
        'database_name' => 'test_db',
        'disk' => 'local',
        'file_path' => $path,
        'file_size_bytes' => strlen('DUMP_DATA'),
        'checksum_sha256' => hash('sha256', 'DUMP_DATA'),
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2),
    ]);

    $verifier = app(VerifyBackupHealthAction::class);
    $result = $verifier->check();

    expect($result->isHealthy)->toBeTrue()
        ->and($result->hasFailures())->toBeFalse()
        ->and($result->hasWarnings())->toBeFalse();
});

it('detects stale backup as failure', function (): void {
    $path = 'backups/stale_backup.zip';
    Storage::disk('local')->put($path, 'STALE_DATA');

    // Completed 30 hours ago (> 24h threshold)
    BackupRecord::create([
        'status' => BackupStatus::SUCCEEDED,
        'trigger' => BackupTrigger::SCHEDULED,
        'driver' => 'pgsql',
        'database_name' => 'test_db',
        'disk' => 'local',
        'file_path' => $path,
        'file_size_bytes' => strlen('STALE_DATA'),
        'checksum_sha256' => hash('sha256', 'STALE_DATA'),
        'started_at' => now()->subHours(30),
        'completed_at' => now()->subHours(30),
    ]);

    $result = app(VerifyBackupHealthAction::class)->check();

    expect($result->isHealthy)->toBeFalse()
        ->and($result->hasFailures())->toBeTrue();

    $failures = $result->failures();
    expect($failures[0]['check'])->toBe('backup_freshness')
        ->and($failures[0]['message'])->toContain('stale');
});

it('detects missing physical archive as failure', function (): void {
    // Record exists, but physical file is missing from disk
    BackupRecord::create([
        'status' => BackupStatus::SUCCEEDED,
        'trigger' => BackupTrigger::SCHEDULED,
        'driver' => 'pgsql',
        'database_name' => 'test_db',
        'disk' => 'local',
        'file_path' => 'backups/deleted_from_disk.zip',
        'file_size_bytes' => 1000,
        'checksum_sha256' => hash('sha256', 'DELETED'),
        'started_at' => now()->subHours(1),
        'completed_at' => now()->subHours(1),
    ]);

    $result = app(VerifyBackupHealthAction::class)->check();

    expect($result->isHealthy)->toBeFalse()
        ->and($result->hasFailures())->toBeTrue();

    $failures = $result->failures();
    $integrityFailure = collect($failures)->firstWhere('check', 'archive_integrity');
    expect($integrityFailure)->not->toBeNull()
        ->and($integrityFailure['message'])->toContain('missing');
});

it('detects size drop anomaly as warning', function (): void {
    // 3 historical successful backups of ~10,000 bytes
    for ($i = 4; $i >= 2; $i--) {
        $p = "backups/historical_{$i}.zip";
        Storage::disk('local')->put($p, str_repeat('A', 10000));
        BackupRecord::create([
            'status' => BackupStatus::SUCCEEDED,
            'trigger' => BackupTrigger::SCHEDULED,
            'driver' => 'pgsql',
            'database_name' => 'test_db',
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
        'database_name' => 'test_db',
        'disk' => 'local',
        'file_path' => $latestPath,
        'file_size_bytes' => 1000,
        'checksum_sha256' => hash('sha256', str_repeat('A', 1000)),
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2),
    ]);

    $result = app(VerifyBackupHealthAction::class)->check();

    // Size deviation is treated as warning, not outright failure
    expect($result->isHealthy)->toBeTrue()
        ->and($result->hasWarnings())->toBeTrue();

    $warning = collect($result->warnings())->firstWhere('check', 'size_anomaly');
    expect($warning)->not->toBeNull()
        ->and($warning['message'])->toContain('dropped');
});
