<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;

beforeEach(function (): void {
    $this->tempDir = storage_path('framework/testing/sqlite_driver_tests_'.uniqid('', true));
    File::makeDirectory($this->tempDir, 0755, true, true);
});

afterEach(function (): void {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('reports available with sqlite version', function (): void {
    $driver = new SqliteDriver;
    $avail = $driver->checkAvailability();

    expect($avail['available'])->toBeTrue()
        ->and($avail['message'])->toBeNull()
        ->and($avail['binary'])->toBe('ext-sqlite3')
        ->and($avail['version'])->not->toBeEmpty();
});

it('reports sqlite capabilities', function (): void {
    $driver = new SqliteDriver;
    $caps = $driver->getCapabilities();

    expect($caps['name'])->toBe('SQLite')
        ->and($caps['driver'])->toBe('sqlite')
        ->and($caps['archive_format'])->toBe('sqlite')
        ->and($driver->getArchiveExtension())->toBe('sqlite')
        ->and($caps['supports_transactions'])->toBeTrue()
        ->and($caps['supports_wal'])->toBeTrue()
        ->and($caps['supports_in_memory'])->toBeFalse()
        ->and($caps['requires_external_binaries'])->toBeFalse();
});

it('rejects in-memory database on backup', function (): void {
    $driver = new SqliteDriver;

    expect(fn () => $driver->dump([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], $this->tempDir.'/out.sqlite'))
        ->toThrow(BackupException::class, "In-memory SQLite databases (':memory:') cannot be backed up to disk.");
});

it('rejects nonexistent database file on backup', function (): void {
    $driver = new SqliteDriver;

    expect(fn () => $driver->dump([
        'driver' => 'sqlite',
        'database' => $this->tempDir.'/nonexistent.sqlite',
    ], $this->tempDir.'/out.sqlite'))
        ->toThrow(BackupProcessException::class, 'SQLite database file not found');
});

it('performs backup and validation with rich schema, unicode, and WAL', function (): void {
    $sourceDbPath = $this->tempDir.'/source.sqlite';
    $db = new SQLite3($sourceDbPath);
    $db->exec('PRAGMA journal_mode=WAL;');

    // Tables, indexes, triggers, views
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, binary_payload BLOB, price REAL);');
    $db->exec('CREATE INDEX idx_products_name ON products(name);');
    $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, action TEXT, created_at TEXT);');
    $db->exec('CREATE TRIGGER trg_products_insert AFTER INSERT ON products BEGIN INSERT INTO audit_log(action, created_at) VALUES ("INSERT: " || new.name, datetime("now")); END;');
    $db->exec('CREATE VIEW v_expensive_products AS SELECT * FROM products WHERE price > 500;');

    // Insert Unicode data: Arabic, French, Japanese, emojis, and binary data
    $stmt = $db->prepare('INSERT INTO products (name, binary_payload, price) VALUES (:name, :bin, :price);');
    $stmt->bindValue(':name', 'صفقة الجزائر — Marché Public №42 🚀 日本語テスト');
    $stmt->bindValue(':bin', random_bytes(256), SQLITE3_BLOB);
    $stmt->bindValue(':price', 1250.75);
    $stmt->execute();
    $db->close();

    $outputPath = $this->tempDir.'/backup.sqlite';
    $driver = new SqliteDriver;
    $meta = $driver->dump([
        'driver' => 'sqlite',
        'database' => $sourceDbPath,
    ], $outputPath);

    expect(File::exists($outputPath))->toBeTrue()
        ->and($meta['driver'])->toBe('sqlite')
        ->and($meta['integrity_check'])->toBe('ok')
        ->and($meta['tables_count'])->toBe(2)
        ->and($meta['views_count'])->toBe(1)
        ->and($meta['triggers_count'])->toBe(1);

    // Validate archive verification independently
    $verifyMeta = $driver->verifyArchive($outputPath);
    expect($verifyMeta['verified'])->toBeTrue()
        ->and($verifyMeta['integrity_check'])->toBe('ok')
        ->and($verifyMeta['file_size'])->toBeGreaterThan(0);
});

it('rejects corrupted file on verifyArchive', function (): void {
    $corruptPath = $this->tempDir.'/corrupt.sqlite';
    File::put($corruptPath, 'THIS_IS_NOT_A_VALID_SQLITE_DATABASE');

    $driver = new SqliteDriver;

    expect(fn () => $driver->verifyArchive($corruptPath))
        ->toThrow(BackupVerificationException::class, 'not a valid SQLite 3 database file');
});

it('restores into isolated target preserving all data and schema', function (): void {
    $sourceDbPath = $this->tempDir.'/source.sqlite';
    $db = new SQLite3($sourceDbPath);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, bio TEXT);');
    $db->exec('INSERT INTO users (email, bio) VALUES ("kazi@example.com", "Algeria tender radar 🇩🇿 مرحبا");');
    $db->close();

    $backupPath = $this->tempDir.'/backup.sqlite';
    $driver = new SqliteDriver;
    $driver->dump(['driver' => 'sqlite', 'database' => $sourceDbPath], $backupPath);

    // Restore into completely fresh isolated target
    $targetDbPath = $this->tempDir.'/target_restored.sqlite';
    $restoreMeta = $driver->restore([
        'driver' => 'sqlite',
        'database' => $targetDbPath,
    ], $backupPath);

    expect($restoreMeta['restored'])->toBeTrue()
        ->and($restoreMeta['target_database'])->toBe($targetDbPath);

    // Inspect restored target
    $restoredDb = new SQLite3($targetDbPath);
    $userCount = $restoredDb->querySingle('SELECT count(*) FROM users;');
    $bio = $restoredDb->querySingle('SELECT bio FROM users WHERE id = 1;');
    $integrity = $restoredDb->querySingle('PRAGMA integrity_check;');
    $restoredDb->close();

    expect($userCount)->toBe(1)
        ->and($bio)->toBe('Algeria tender radar 🇩🇿 مرحبا')
        ->and($integrity)->toBe('ok');
});

it('preserves original target when restore fails', function (): void {
    $targetDbPath = $this->tempDir.'/critical_target.sqlite';
    $db = new SQLite3($targetDbPath);
    $db->exec('CREATE TABLE critical_data (id INTEGER PRIMARY KEY, note TEXT);');
    $db->exec('INSERT INTO critical_data (note) VALUES ("DO_NOT_LOSE_THIS");');
    $db->close();

    $corruptBackup = $this->tempDir.'/corrupted_backup.sqlite';
    File::put($corruptBackup, 'CORRUPTED_FILE');

    $driver = new SqliteDriver;

    try {
        $driver->restore([
            'driver' => 'sqlite',
            'database' => $targetDbPath,
        ], $corruptBackup);
        test()->fail('Expected BackupVerificationException or RestoreException was not thrown.');
    } catch (\Throwable $e) {
        expect($e instanceof BackupVerificationException || $e instanceof RestoreException)->toBeTrue();
    }

    // Target must remain untouched and completely functional
    $targetDb = new SQLite3($targetDbPath);
    $note = $targetDb->querySingle('SELECT note FROM critical_data WHERE id = 1;');
    $targetDb->close();

    expect($note)->toBe('DO_NOT_LOSE_THIS');
});
