<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use SQLite3;

final class SqliteDriverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/sqlite_driver_tests_'.uniqid('', true));
        File::makeDirectory($this->tempDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_availability_reports_available_with_sqlite_version(): void
    {
        $driver = new SqliteDriver;
        $avail = $driver->checkAvailability();

        $this->assertTrue($avail['available']);
        $this->assertNull($avail['message']);
        $this->assertSame('ext-sqlite3', $avail['binary']);
        $this->assertNotEmpty($avail['version']);
    }

    public function test_capabilities_report(): void
    {
        $driver = new SqliteDriver;
        $caps = $driver->getCapabilities();

        $this->assertSame('SQLite', $caps['name']);
        $this->assertSame('sqlite', $caps['driver']);
        $this->assertSame('sqlite', $caps['archive_format']);
        $this->assertSame('sqlite', $driver->getArchiveExtension());
        $this->assertTrue($caps['supports_transactions']);
        $this->assertTrue($caps['supports_wal']);
        $this->assertFalse($caps['supports_in_memory']);
        $this->assertFalse($caps['requires_external_binaries']);
    }

    public function test_backup_rejects_in_memory_database(): void
    {
        $driver = new SqliteDriver;

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage("In-memory SQLite databases (':memory:') cannot be backed up to disk.");

        $driver->dump([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], $this->tempDir.'/out.sqlite');
    }

    public function test_backup_rejects_nonexistent_database_file(): void
    {
        $driver = new SqliteDriver;

        $this->expectException(BackupProcessException::class);
        $this->expectExceptionMessage('SQLite database file not found');

        $driver->dump([
            'driver' => 'sqlite',
            'database' => $this->tempDir.'/nonexistent.sqlite',
        ], $this->tempDir.'/out.sqlite');
    }

    public function test_backup_and_validation_with_rich_schema_unicode_and_wal(): void
    {
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

        $this->assertTrue(File::exists($outputPath));
        $this->assertSame('sqlite', $meta['driver']);
        $this->assertSame('ok', $meta['integrity_check']);
        $this->assertSame(2, $meta['tables_count']); // products, audit_log
        $this->assertSame(1, $meta['views_count']);  // v_expensive_products
        $this->assertSame(1, $meta['triggers_count']); // trg_products_insert

        // Validate archive verification independently
        $verifyMeta = $driver->verifyArchive($outputPath);
        $this->assertTrue($verifyMeta['verified']);
        $this->assertSame('ok', $verifyMeta['integrity_check']);
        $this->assertGreaterThan(0, $verifyMeta['file_size']);
    }

    public function test_verify_archive_rejects_corrupted_file(): void
    {
        $corruptPath = $this->tempDir.'/corrupt.sqlite';
        File::put($corruptPath, 'THIS_IS_NOT_A_VALID_SQLITE_DATABASE');

        $driver = new SqliteDriver;

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('not a valid SQLite 3 database file');

        $driver->verifyArchive($corruptPath);
    }

    public function test_restore_into_isolated_target_preserves_all_data_and_schema(): void
    {
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

        $this->assertTrue($restoreMeta['restored']);
        $this->assertSame($targetDbPath, $restoreMeta['target_database']);

        // Inspect restored target
        $restoredDb = new SQLite3($targetDbPath);
        $userCount = $restoredDb->querySingle('SELECT count(*) FROM users;');
        $bio = $restoredDb->querySingle('SELECT bio FROM users WHERE id = 1;');
        $integrity = $restoredDb->querySingle('PRAGMA integrity_check;');
        $restoredDb->close();

        $this->assertSame(1, $userCount);
        $this->assertSame('Algeria tender radar 🇩🇿 مرحبا', $bio);
        $this->assertSame('ok', $integrity);
    }

    public function test_restore_preserves_original_target_when_restore_fails(): void
    {
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
            $this->fail('Expected BackupVerificationException or RestoreException was not thrown.');
        } catch (\Throwable $e) {
            $this->assertTrue(
                $e instanceof BackupVerificationException || $e instanceof RestoreException
            );
        }

        // Target must remain untouched and completely functional
        $targetDb = new SQLite3($targetDbPath);
        $note = $targetDb->querySingle('SELECT note FROM critical_data WHERE id = 1;');
        $targetDb->close();

        $this->assertSame('DO_NOT_LOSE_THIS', $note);
    }
}
