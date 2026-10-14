<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use ZipArchive;

final class ScopedRestoreTest extends TestCase
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
        ]);
    }

    public function test_scoped_restore_dry_run_simulates_without_persisting(): void
    {
        // 1. Create and export user
        $user = User::create(['name' => 'Original Name', 'email' => 'restore_test@test.dz', 'password' => 'secret']);

        $definition = ScopedBackupDefinition::make('users_dataset')
            ->model(User::class, fn ($q) => $q->where('email', 'restore_test@test.dz'))
            ->identity('email');

        $result = app(CreateScopedBackupAction::class)->execute($definition);
        $archivePath = Storage::disk('local')->path($result['record']->file_path);

        // 2. Wipe database
        User::truncate();
        $this->assertSame(0, User::count());

        // 3. Dry-run restore
        $restoreAction = app(RestoreScopedBackupAction::class);
        $stats = $restoreAction->execute($archivePath, ['dry_run' => true]);

        $this->assertTrue($stats->isDryRun);
        $this->assertSame(1, $stats->recordsCreated);
        $this->assertSame(0, $stats->recordsUpdated);

        // Crucial guarantee: database must remain empty after dry run
        $this->assertSame(0, User::count());
    }

    public function test_scoped_restore_upserts_and_matches_natural_identity_keys(): void
    {
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

        $this->assertNull(User::where('email', 'alice@test.dz')->first());
        $this->assertSame('Bob Mutated Name', User::where('email', 'bob@test.dz')->value('name'));

        // 3. Execute live restore
        $stats = app(RestoreScopedBackupAction::class)->execute($archivePath);

        $this->assertSame(1, $stats->recordsCreated); // Alice re-created
        $this->assertSame(1, $stats->recordsUpdated); // Bob updated back to 'Bob Before'
        $this->assertFalse($stats->isDryRun);

        // 4. Verify database state
        $this->assertNotNull(User::where('email', 'alice@test.dz')->first());
        $this->assertSame('Bob Before', User::where('email', 'bob@test.dz')->value('name'));

        // Records outside the backup (Charlie) are preserved and untouched
        $this->assertNotNull(User::where('email', 'charlie@test.dz')->first());
    }

    public function test_scoped_restore_rejects_archive_with_tampered_checksum(): void
    {
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

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('Checksum verification failed for records.jsonl');

        app(RestoreScopedBackupAction::class)->execute($archivePath);
    }
}
