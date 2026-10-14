<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\DTO\BackupStats;
use KaziSTM\LaravelScopedBackup\Enums\RestoreMode;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use KaziSTM\LaravelScopedBackup\Services\ScopedExporter;
use KaziSTM\LaravelScopedBackup\Services\ScopedImporter;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use SQLite3;

// Test model with strict guarded attributes
class GuardedModel extends Model
{
    protected $table = 'guarded_models';

    protected $guarded = ['*']; // Completely guarded against standard mass assignment
}

final class AuditRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        if (! Schema::hasTable('guarded_models')) {
            Schema::create('guarded_models', function (Blueprint $table) {
                $table->id();
                $table->string('secret_token');
                $table->string('role');
                $table->timestamps();
            });
        }
    }

    public function test_scoped_importer_force_fills_guarded_attributes(): void
    {
        // 1. Create record with guarded attributes
        $record = new GuardedModel;
        $record->forceFill([
            'id' => 42,
            'secret_token' => 'top_secret_xyz',
            'role' => 'superadmin',
        ]);
        $record->save();

        // 2. Export scoped backup
        $def = ScopedBackupDefinition::make('guarded_scope')
            ->model(GuardedModel::class)
            ->identity('id');

        $action = app(CreateScopedBackupAction::class);
        $result = $action->execute($def);
        $archivePath = Storage::disk('local')->path($result['record']->file_path);

        // 3. Wipe table
        GuardedModel::truncate();
        $this->assertSame(0, GuardedModel::count());

        // 4. Restore and verify that guarded attributes were NOT dropped
        $importer = app(ScopedImporter::class);
        $stats = $importer->import($archivePath, [
            'model' => GuardedModel::class,
            'identity_keys' => ['id'],
            'mode' => RestoreMode::TRANSACTIONAL_UPSERT,
        ]);

        $this->assertSame(1, $stats->recordsCreated);
        $restored = GuardedModel::find(42);
        $this->assertNotNull($restored, 'Record with ID 42 must be restored');
        $this->assertSame('top_secret_xyz', $restored->secret_token);
        $this->assertSame('superadmin', $restored->role);
    }

    public function test_mariadb_detection_when_connection_driver_is_mysql(): void
    {
        config([
            'database.connections.mariadb_test' => [
                'driver' => 'mysql',
                'is_mariadb' => true,
                'database' => 'test_db',
            ],
            'database.connections.standard_mysql' => [
                'driver' => 'mysql',
                'database' => 'test_db',
            ],
        ]);

        $manager = app(DatabaseBackupDriverManager::class);

        $mariaDriver = $manager->resolveForConnection('mariadb_test');
        $this->assertInstanceOf(MariaDbDriver::class, $mariaDriver);

        $mySqlDriver = $manager->resolveForConnection('standard_mysql');
        $this->assertInstanceOf(MySqlDriver::class, $mySqlDriver);
    }

    public function test_sqlite_restore_isolates_and_cleans_stale_wal_and_shm_files(): void
    {
        $tempDir = storage_path('framework/testing/sqlite_wal_test_'.uniqid());
        File::makeDirectory($tempDir, 0700, true, true);

        $sourceDb = $tempDir.'/source.sqlite';
        $targetDb = $tempDir.'/target.sqlite';
        $backupArchive = $tempDir.'/backup.sqlite';

        // 1. Create source db
        $sDb = new SQLite3($sourceDb);
        $sDb->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT);');
        $sDb->exec("INSERT INTO items (title) VALUES ('Fresh Title');");
        $sDb->close();

        // 2. Dump source db to archive
        $driver = new SqliteDriver;
        $driver->dump(['driver' => 'sqlite', 'database' => $sourceDb], $backupArchive);

        // 3. Create target db with stale WAL and SHM files
        $tDb = new SQLite3($targetDb);
        $tDb->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT);');
        $tDb->exec("INSERT INTO items (title) VALUES ('Stale Title');");
        $tDb->close();

        File::put($targetDb.'-wal', 'stale wal bytes');
        File::put($targetDb.'-shm', 'stale shm bytes');

        $this->assertTrue(File::exists($targetDb.'-wal'));
        $this->assertTrue(File::exists($targetDb.'-shm'));

        // 4. Restore into target
        $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $backupArchive);

        // 5. Stale WAL and SHM files must have been cleaned up
        $this->assertFalse(File::exists($targetDb.'-wal'), 'Stale WAL file must be cleaned on restore');
        $this->assertFalse(File::exists($targetDb.'-shm'), 'Stale SHM file must be cleaned on restore');

        // 6. Verify restored data
        $vDb = new SQLite3($targetDb);
        $title = $vDb->querySingle('SELECT title FROM items WHERE id = 1;');
        $vDb->close();

        $this->assertSame('Fresh Title', $title);

        File::deleteDirectory($tempDir);
    }

    public function test_checksum_validator_computes_sha256_from_stream(): void
    {
        $validator = new ChecksumValidator;

        $content = 'Sample content for streaming checksum verification 123456';
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        $hash = $validator->sha256Stream($stream);
        fclose($stream);

        $this->assertSame(hash('sha256', $content), $hash);
    }

    public function test_scoped_exporter_executes_on_model_connection(): void
    {
        $validator = new ChecksumValidator;
        $exporter = new ScopedExporter($validator);

        $tempStaging = storage_path('framework/testing/scoped_exporter_staging_'.uniqid());

        $def = ScopedBackupDefinition::make('exporter_test')
            ->model(GuardedModel::class)
            ->withTransaction(true, 'READ COMMITTED');

        $result = $exporter->export($def, $tempStaging);

        $this->assertInstanceOf(BackupStats::class, $result['stats']);
        $this->assertSame('scoped', $result['manifest']->backupType->value);
        $this->assertSame(GuardedModel::class, $result['manifest']->metadata['root_model']);

        File::deleteDirectory($tempStaging);
    }
}
