<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    config([
        'scoped-backup.table_name' => 'database_backups',
        'scoped-backup.storage.disk' => 'local',
        'scoped-backup.storage.path' => 'backups',
    ]);
});

it('rejects nonexistent or invalid model class in definition', function (): void {
    $definition = ScopedBackupDefinition::make('test');

    expect(fn () => $definition->model('NonExistentModel'))
        ->toThrow(BackupException::class, 'Target model class [NonExistentModel] does not exist');
});

it('exports scoped models to chunked jsonl and zip archive', function (): void {
    // Create sample users
    User::create(['name' => 'Alice Admin', 'email' => 'alice@test.dz', 'password' => 'secret']);
    User::create(['name' => 'Bob Staff', 'email' => 'bob@test.dz', 'password' => 'secret']);
    User::create(['name' => 'Charlie External', 'email' => 'charlie@external.com', 'password' => 'secret']);

    // Scope to only users with @test.dz email
    $definition = ScopedBackupDefinition::make('internal_users')
        ->model(User::class, fn ($q) => $q->where('email', 'like', '%@test.dz'))
        ->identity('email')
        ->chunkSize(2)
        ->addEntity('department_metadata', function () {
            return ['dept' => 'Engineering', 'site' => 'Algiers'];
        });

    $action = app(CreateScopedBackupAction::class);
    $result = $action->execute($definition);

    $record = $result['record'];
    $stats = $result['stats'];

    expect($record)->toBeInstanceOf(BackupRecord::class)
        ->and($record->status)->toBe(BackupStatus::SUCCEEDED)
        ->and($stats->recordsExported)->toBe(2)
        ->and($record->file_path)->not->toBeNull()
        ->and($record->file_path)->toStartWith('backups/scoped_internal_users_');

    Storage::disk('local')->assertExists($record->file_path);

    // Verify ZIP archive contents
    $zipFullPath = Storage::disk('local')->path($record->file_path);
    $archiveManager = app(ArchiveManager::class);

    $tempExtract = storage_path('framework/testing/scoped_verify_'.uniqid());
    try {
        $archiveManager->extractZip($zipFullPath, $tempExtract);

        expect(File::exists($tempExtract.'/manifest.json'))->toBeTrue()
            ->and(File::exists($tempExtract.'/records.jsonl'))->toBeTrue()
            ->and(File::exists($tempExtract.'/entities/department_metadata.json'))->toBeTrue();

        // Verify Manifest
        $manifest = $archiveManager->readManifest($zipFullPath);
        expect($manifest->backupType)->toBe(BackupType::SCOPED)
            ->and($manifest->metadata['scope_name'])->toBe('internal_users')
            ->and($manifest->metadata['identity_keys'])->toBe(['email'])
            ->and($manifest->counts['internal_users'])->toBe(2)
            ->and($manifest->counts['additional_department_metadata'])->toBe(2);

        // Verify JSONL Content lines
        $lines = file($tempExtract.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toHaveCount(2);

        $decodedLine1 = json_decode($lines[0], true);
        $decodedLine2 = json_decode($lines[1], true);

        $emails = [$decodedLine1['email'], $decodedLine2['email']];
        expect($emails)->toContain('alice@test.dz')
            ->and($emails)->toContain('bob@test.dz')
            ->and($emails)->not->toContain('charlie@external.com');
    } finally {
        if (File::isDirectory($tempExtract)) {
            File::deleteDirectory($tempExtract);
        }
    }
});
