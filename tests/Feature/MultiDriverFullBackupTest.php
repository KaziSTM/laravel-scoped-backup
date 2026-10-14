<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use SQLite3;

final class MultiDriverFullBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    private string $sourceDbFile;

    private string $restoreDbFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tempDir = storage_path('framework/testing/multi_driver_tests_'.uniqid('', true));
        File::makeDirectory($this->tempDir, 0755, true, true);

        $this->sourceDbFile = $this->tempDir.'/source_app.sqlite';
        $this->restoreDbFile = $this->tempDir.'/restored_app.sqlite';

        // Setup real SQLite database with data and schema
        $db = new SQLite3($this->sourceDbFile);
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT, country TEXT);');
        $db->exec('INSERT INTO companies (name, country) VALUES ("Sonatrach", "Algeria");');
        $db->exec('INSERT INTO companies (name, country) VALUES ("Cosider", "Algeria");');
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
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_full_backup_action_executes_successfully_for_sqlite(): void
    {
        /** @var CreateFullBackupAction $action */
        $action = app(CreateFullBackupAction::class);

        $backup = $action->execute(
            trigger: BackupTrigger::MANUAL,
            connection: 'test_sqlite_source',
            disk: 'local',
            path: 'backups',
            includeFiles: false,
        );

        $this->assertInstanceOf(BackupRecord::class, $backup);
        $this->assertSame(BackupStatus::SUCCEEDED, $backup->status);
        $this->assertSame('sqlite', $backup->driver);
        $this->assertSame($this->sourceDbFile, $backup->database_name);
        $this->assertNotNull($backup->file_path);
        $this->assertNotNull($backup->checksum_sha256);
        $this->assertGreaterThan(0, $backup->file_size_bytes);

        // Verify physical ZIP exists on configured disk
        Storage::disk('local')->assertExists($backup->file_path);

        // Verify metadata recorded
        $this->assertTrue($backup->metadata['verified']);
        $this->assertSame('ok', $backup->metadata['integrity_check']);
        $this->assertSame(1, $backup->metadata['tables_count']);
    }

    public function test_restore_full_backup_action_restores_sqlite_into_isolated_connection(): void
    {
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

        $this->assertTrue($result['restored']);
        $this->assertSame($this->restoreDbFile, $result['target_database']);

        // Verify target database file was created and contains identical data
        $this->assertTrue(File::exists($this->restoreDbFile));
        $restoredDb = new SQLite3($this->restoreDbFile);
        $count = $restoredDb->querySingle('SELECT count(*) FROM companies;');
        $firstName = $restoredDb->querySingle('SELECT name FROM companies WHERE id = 1;');
        $restoredDb->close();

        $this->assertSame(2, $count);
        $this->assertSame('Sonatrach', $firstName);
    }

    public function test_full_backup_rejects_in_memory_database_with_clear_error(): void
    {
        /** @var CreateFullBackupAction $action */
        $action = app(CreateFullBackupAction::class);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage("In-memory SQLite databases (':memory:') cannot be backed up to disk.");

        $action->execute(
            trigger: BackupTrigger::MANUAL,
            connection: 'test_sqlite_memory',
        );
    }
}
