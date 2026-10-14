<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    $this->tempDir = storage_path('framework/testing/multi_driver_tests_'.uniqid('', true));
    File::makeDirectory($this->tempDir, 0755, true, true);

    $this->sourceDbFile = $this->tempDir.'/source_app.sqlite';
    $this->restoreDbFile = $this->tempDir.'/restored_app.sqlite';

    // Setup real SQLite database with data and schema
    $db = new SQLite3($this->sourceDbFile);
    $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT, country TEXT);');
    $db->exec('INSERT INTO companies (name, country) VALUES ("Company A", "Country A");');
    $db->exec('INSERT INTO companies (name, country) VALUES ("Company B", "Country B");');
    $db->close();

    config([
        'scoped-backup.storage.disk' => 'local',
        'scoped-backup.storage.path' => 'backups',
        'scoped-backup.storage.temp_staging_dir' => $this->tempDir.'/staging',
        'database.connections.test_sqlite_source' => [
            'driver' => 'sqlite',
            'database' => $this->sourceDbFile,
        ],
        'database.connections.test_sqlite_target' => [
            'driver' => 'sqlite',
            'database' => $this->restoreDbFile,
        ],
        'database.connections.test_sqlite_memory' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ]);
});

afterEach(function (): void {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('executes full backup action successfully for sqlite', function (): void {
    /** @var CreateFullBackupAction $action */
    $action = app(CreateFullBackupAction::class);

    $backup = $action->execute(
        trigger: BackupTrigger::MANUAL,
        connection: 'test_sqlite_source',
        disk: 'local',
        path: 'backups',
        includeFiles: false,
    );

    expect($backup)->toBeInstanceOf(BackupRecord::class)
        ->and($backup->status)->toBe(BackupStatus::SUCCEEDED)
        ->and($backup->driver)->toBe('sqlite')
        ->and($backup->database_name)->toBe($this->sourceDbFile)
        ->and($backup->file_path)->not->toBeNull()
        ->and($backup->checksum_sha256)->not->toBeNull()
        ->and($backup->file_size_bytes)->toBeGreaterThan(0);

    // Verify physical ZIP exists on configured disk
    Storage::disk('local')->assertExists($backup->file_path);

    // Verify metadata recorded
    expect($backup->metadata['verified'])->toBeTrue()
        ->and($backup->metadata['integrity_check'])->toBe('ok')
        ->and($backup->metadata['tables_count'])->toBe(1);
});

it('restores sqlite into isolated connection via restore full backup action', function (): void {
    /** @var CreateFullBackupAction $createAction */
    $createAction = app(CreateFullBackupAction::class);
    $backup = $createAction->execute(
        trigger: BackupTrigger::MANUAL,
        connection: 'test_sqlite_source',
        disk: 'local',
    );

    /** @var RestoreFullBackupAction $restoreAction */
    $restoreAction = app(RestoreFullBackupAction::class);
    $result = $restoreAction->execute(
        backup: $backup,
        targetConnection: 'test_sqlite_target',
        force: true,
    );

    expect($result['restored'])->toBeTrue()
        ->and($result['target_database'])->toBe($this->restoreDbFile);

    // Verify target database file was created and contains identical data
    expect(File::exists($this->restoreDbFile))->toBeTrue();
    $restoredDb = new SQLite3($this->restoreDbFile);
    $count = $restoredDb->querySingle('SELECT count(*) FROM companies;');
    $firstName = $restoredDb->querySingle('SELECT name FROM companies WHERE id = 1;');
    $restoredDb->close();

    expect($count)->toBe(2)
        ->and($firstName)->toBe('Company A');
});

it('rejects in-memory database with clear error on full backup', function (): void {
    /** @var CreateFullBackupAction $action */
    $action = app(CreateFullBackupAction::class);

    expect(fn () => $action->execute(
        trigger: BackupTrigger::MANUAL,
        connection: 'test_sqlite_memory',
    ))->toThrow(BackupException::class, "In-memory SQLite databases (':memory:') cannot be backed up to disk.");
});
