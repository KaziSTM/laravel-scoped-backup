<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

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
use KaziSTM\LaravelScopedBackup\Tests\TestCase;

final class ScopedBackupTest extends TestCase
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

    public function test_definition_rejects_nonexistent_or_invalid_model_class(): void
    {
        $definition = ScopedBackupDefinition::make('test');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Target model class [NonExistentModel] does not exist');

        $definition->model('NonExistentModel');
    }

    public function test_exports_scoped_models_to_chunked_jsonl_and_zip_archive(): void
    {
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

        $this->assertInstanceOf(BackupRecord::class, $record);
        $this->assertSame(BackupStatus::SUCCEEDED, $record->status);
        $this->assertSame(2, $stats->recordsExported);
        $this->assertNotNull($record->file_path);
        $this->assertStringStartsWith('backups/scoped_internal_users_', $record->file_path);

        Storage::disk('local')->assertExists($record->file_path);

        // Verify ZIP archive contents
        $zipFullPath = Storage::disk('local')->path($record->file_path);
        $archiveManager = app(ArchiveManager::class);

        $tempExtract = storage_path('framework/testing/scoped_verify_'.uniqid());
        $archiveManager->extractZip($zipFullPath, $tempExtract);

        $this->assertFileExists($tempExtract.'/manifest.json');
        $this->assertFileExists($tempExtract.'/records.jsonl');
        $this->assertFileExists($tempExtract.'/entities/department_metadata.json');

        // Verify Manifest
        $manifest = $archiveManager->readManifest($zipFullPath);
        $this->assertSame(BackupType::SCOPED, $manifest->backupType);
        $this->assertSame('internal_users', $manifest->metadata['scope_name']);
        $this->assertSame(['email'], $manifest->metadata['identity_keys']);
        $this->assertSame(2, $manifest->counts['internal_users']);
        $this->assertSame(2, $manifest->counts['additional_department_metadata']);

        // Verify JSONL Content lines
        $lines = file($tempExtract.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        $decodedLine1 = json_decode($lines[0], true);
        $decodedLine2 = json_decode($lines[1], true);

        $emails = [$decodedLine1['email'], $decodedLine2['email']];
        $this->assertContains('alice@test.dz', $emails);
        $this->assertContains('bob@test.dz', $emails);
        $this->assertNotContains('charlie@external.com', $emails);

        // Cleanup
        File::deleteDirectory($tempExtract);
    }
}
