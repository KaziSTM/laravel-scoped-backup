<?php

declare(strict_types=1);

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

// Test model with strict guarded attributes
if (! class_exists('GuardedModel')) {
    class GuardedModel extends Model
    {
        protected $table = 'guarded_models';

        protected $guarded = ['*']; // Completely guarded against standard mass assignment
    }
}

beforeEach(function (): void {
    Storage::fake('local');

    if (! Schema::hasTable('guarded_models')) {
        Schema::create('guarded_models', function (Blueprint $table) {
            $table->id();
            $table->string('secret_token');
            $table->string('role');
            $table->timestamps();
        });
    }
});

it('force fills guarded attributes in scoped importer', function (): void {
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
    expect(GuardedModel::count())->toBe(0);

    // 4. Restore and verify that guarded attributes were NOT dropped
    $importer = app(ScopedImporter::class);
    $stats = $importer->import($archivePath, [
        'model' => GuardedModel::class,
        'identity_keys' => ['id'],
        'mode' => RestoreMode::TRANSACTIONAL_UPSERT,
    ]);

    expect($stats->recordsCreated)->toBe(1);
    $restored = GuardedModel::find(42);
    expect($restored)->not->toBeNull('Record with ID 42 must be restored')
        ->and($restored->secret_token)->toBe('top_secret_xyz')
        ->and($restored->role)->toBe('superadmin');
});

it('detects mariadb when connection driver is mysql', function (): void {
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
    expect($mariaDriver)->toBeInstanceOf(MariaDbDriver::class);

    $mySqlDriver = $manager->resolveForConnection('standard_mysql');
    expect($mySqlDriver)->toBeInstanceOf(MySqlDriver::class);
});

it('isolates and cleans stale WAL and SHM files on sqlite restore', function (): void {
    $tempDir = storage_path('framework/testing/sqlite_wal_test_'.uniqid());
    File::makeDirectory($tempDir, 0700, true, true);

    try {
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

        expect(File::exists($targetDb.'-wal'))->toBeTrue()
            ->and(File::exists($targetDb.'-shm'))->toBeTrue();

        // 4. Restore into target
        $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $backupArchive);

        // 5. Stale WAL and SHM files must have been cleaned up
        expect(File::exists($targetDb.'-wal'))->toBeFalse('Stale WAL file must be cleaned on restore')
            ->and(File::exists($targetDb.'-shm'))->toBeFalse('Stale SHM file must be cleaned on restore');

        // 6. Verify restored data
        $vDb = new SQLite3($targetDb);
        $title = $vDb->querySingle('SELECT title FROM items WHERE id = 1;');
        $vDb->close();

        expect($title)->toBe('Fresh Title');
    } finally {
        if (File::isDirectory($tempDir)) {
            File::deleteDirectory($tempDir);
        }
    }
});

it('computes sha256 from stream in ChecksumValidator', function (): void {
    $validator = new ChecksumValidator;

    $content = 'Sample content for streaming checksum verification 123456';
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $content);
    rewind($stream);

    $hash = $validator->sha256Stream($stream);
    fclose($stream);

    expect($hash)->toBe(hash('sha256', $content));
});

it('executes scoped exporter on model connection', function (): void {
    $validator = new ChecksumValidator;
    $exporter = new ScopedExporter($validator);

    $tempStaging = storage_path('framework/testing/scoped_exporter_staging_'.uniqid());

    try {
        $def = ScopedBackupDefinition::make('exporter_test')
            ->model(GuardedModel::class)
            ->withTransaction(true, 'READ COMMITTED');

        $result = $exporter->export($def, $tempStaging);

        expect($result['stats'])->toBeInstanceOf(BackupStats::class)
            ->and($result['manifest']->backupType->value)->toBe('scoped')
            ->and($result['manifest']->metadata['root_model'])->toBe(GuardedModel::class);
    } finally {
        if (File::isDirectory($tempStaging)) {
            File::deleteDirectory($tempStaging);
        }
    }
});
