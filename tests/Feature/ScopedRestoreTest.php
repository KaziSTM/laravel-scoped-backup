<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
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

it('simulates scoped restore without persisting on dry run', function (): void {
    // 1. Create and export user
    $user = User::create(['name' => 'Original Name', 'email' => 'restore_test@test.dz', 'password' => 'secret']);

    $definition = ScopedBackupDefinition::make('users_dataset')
        ->model(User::class, fn ($q) => $q->where('email', 'restore_test@test.dz'))
        ->identity('email');

    $result = app(CreateScopedBackupAction::class)->execute($definition);
    $archivePath = Storage::disk('local')->path($result['record']->file_path);

    // 2. Wipe database
    User::truncate();
    expect(User::count())->toBe(0);

    // 3. Dry-run restore
    $restoreAction = app(RestoreScopedBackupAction::class);
    $stats = $restoreAction->execute($archivePath, ['dry_run' => true]);

    expect($stats->isDryRun)->toBeTrue()
        ->and($stats->recordsCreated)->toBe(1)
        ->and($stats->recordsUpdated)->toBe(0);

    // Crucial guarantee: database must remain empty after dry run
    expect(User::count())->toBe(0);
});

it('upserts and matches natural identity keys on scoped restore', function (): void {
    // 1. Create and export users
    User::create(['name' => 'Alice Before', 'email' => 'alice@test.dz', 'password' => 'pass1']);
    User::create(['name' => 'Bob Before', 'email' => 'bob@test.dz', 'password' => 'pass2']);

    $definition = ScopedBackupDefinition::make('users_export')
        ->model(User::class)
        ->identity('email');

    $result = app(CreateScopedBackupAction::class)->execute($definition);
    $archivePath = Storage::disk('local')->path($result['record']->file_path);

    // 2. Modify Bob, delete Alice, add Charlie
    User::where('email', 'bob@test.dz')->update(['name' => 'Bob Mutated Name']);
    User::where('email', 'alice@test.dz')->delete();
    User::create(['name' => 'Charlie Unrelated', 'email' => 'charlie@test.dz', 'password' => 'pass3']);

    expect(User::where('email', 'alice@test.dz')->first())->toBeNull()
        ->and(User::where('email', 'bob@test.dz')->value('name'))->toBe('Bob Mutated Name');

    // 3. Execute live restore
    $stats = app(RestoreScopedBackupAction::class)->execute($archivePath);

    expect($stats->recordsCreated)->toBe(1) // Alice re-created
        ->and($stats->recordsUpdated)->toBe(1) // Bob updated back to 'Bob Before'
        ->and($stats->isDryRun)->toBeFalse();

    // 4. Verify database state
    expect(User::where('email', 'alice@test.dz')->first())->not->toBeNull()
        ->and(User::where('email', 'bob@test.dz')->value('name'))->toBe('Bob Before');

    // Records outside the backup (Charlie) are preserved and untouched
    expect(User::where('email', 'charlie@test.dz')->first())->not->toBeNull();
});

it('rejects archive with tampered checksum on scoped restore', function (): void {
    User::create(['name' => 'Tamper Test', 'email' => 'tamper@test.dz', 'password' => 'pass']);

    $definition = ScopedBackupDefinition::make('tamper_export')
        ->model(User::class)
        ->identity('email');

    $result = app(CreateScopedBackupAction::class)->execute($definition);
    $archivePath = Storage::disk('local')->path($result['record']->file_path);

    // Tamper with records.jsonl inside the ZIP archive
    $zip = new ZipArchive;
    $zip->open($archivePath);
    $zip->addFromString('records.jsonl', 'TAMPERED_CONTENT_CORRUPTED');
    $zip->close();

    expect(fn () => app(RestoreScopedBackupAction::class)->execute($archivePath))
        ->toThrow(BackupVerificationException::class, 'Checksum verification failed for records.jsonl');
});
