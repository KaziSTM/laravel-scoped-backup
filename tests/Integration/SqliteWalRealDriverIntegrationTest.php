<?php

declare(strict_types=1);

use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;

beforeEach(function (): void {
    $this->testDir = sys_get_temp_dir().'/sqlite_wal_test_'.uniqid('', true);
    if (! is_dir($this->testDir)) {
        mkdir($this->testDir, 0755, true);
    }
});

afterEach(function (): void {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        $files = glob($this->testDir.'/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->testDir);
    }
});

it('dumps and restores sqlite in WAL mode with active sidecars', function (): void {
    $driver = new SqliteDriver(busyTimeout: 3000);

    $sourceDb = $this->testDir.'/source.sqlite';
    $targetDb = $this->testDir.'/target.sqlite';
    $dumpFile = $this->testDir.'/backup.sqlite';

    // 1. Initialize source in WAL mode with uncheckpointed frames
    $src = new SQLite3($sourceDb);
    $src->exec('PRAGMA journal_mode = WAL;');
    $src->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT);');
    $src->exec("INSERT INTO users (name, email) VALUES ('Source Admin', 'admin@source.test');");
    $src->close();

    // Write another row leaving uncheckpointed WAL frames
    $src2 = new SQLite3($sourceDb);
    $src2->exec("INSERT INTO users (name, email) VALUES ('Source Alice', 'alice@source.test');");
    $src2->close();

    expect(file_exists($sourceDb))->toBeTrue();

    // 2. Dump source using SqliteDriver
    $dumpResult = $driver->dump(['driver' => 'sqlite', 'database' => $sourceDb], $dumpFile);
    expect($dumpResult['format'])->toBe('sqlite')
        ->and(file_exists($dumpFile))->toBeTrue();

    // 3. Initialize target database in WAL mode with its own conflicting data
    $dst = new SQLite3($targetDb);
    $dst->exec('PRAGMA journal_mode = WAL;');
    $dst->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT);');
    $dst->exec("INSERT INTO users (name, email) VALUES ('Old Target User', 'target@old.test');");
    // Ensure sidecars exist
    $dst->exec("INSERT INTO users (name, email) VALUES ('Old Target User 2', 'target2@old.test');");
    $dst->close();

    // 4. Restore dump into target database
    $restoreResult = $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $dumpFile);
    expect($restoreResult['restored'])->toBeTrue();

    // 5. Open target database with a fresh connection and verify consistency
    $check = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
    $integrity = $check->querySingle('PRAGMA integrity_check;');
    expect($integrity)->toBe('ok');

    $quick = $check->querySingle('PRAGMA quick_check;');
    expect($quick)->toBe('ok');

    $rows = [];
    $res = $check->query('SELECT * FROM users ORDER BY id ASC;');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $check->close();

    // Both rows from source must exist, no old target rows or stale frames
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Source Admin')
        ->and($rows[1]['name'])->toBe('Source Alice');
});

it('preserves original sqlite database on rollback during staged failure', function (): void {
    $driver = new SqliteDriver(busyTimeout: 1000);

    $targetDb = $this->testDir.'/target_rollback.sqlite';
    $corruptArchive = $this->testDir.'/corrupt.sqlite';

    // Write original target data
    $db = new SQLite3($targetDb);
    $db->exec('CREATE TABLE canary (id INTEGER PRIMARY KEY, val TEXT);');
    $db->exec("INSERT INTO canary (val) VALUES ('original_data_must_survive');");
    $db->close();

    // Create invalid archive
    file_put_contents($corruptArchive, 'NOT A REAL SQLITE FILE');

    try {
        $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $corruptArchive);
        test()->fail('Expected restore to fail on corrupt archive');
    } catch (Throwable $e) {
        expect($e instanceof RestoreException || $e instanceof BackupVerificationException)->toBeTrue();
    }

    // Verify original target database is 100% intact
    $check = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
    $val = $check->querySingle('SELECT val FROM canary WHERE id = 1;');
    $check->close();

    expect($val)->toBe('original_data_must_survive');
});

it('detects exclusive lock and times out on sqlite restore', function (): void {
    $driver = new SqliteDriver(busyTimeout: 300);

    $targetDb = $this->testDir.'/locked_target.sqlite';
    $validArchive = $this->testDir.'/valid_archive.sqlite';

    // Prepare valid archive
    $arc = new SQLite3($validArchive);
    $arc->exec('CREATE TABLE t (id INT);');
    $arc->close();

    // Prepare target with an active EXCLUSIVE transaction holding a lock
    $lockingConnection = new SQLite3($targetDb);
    $lockingConnection->exec('CREATE TABLE t (id INT);');
    $lockingConnection->exec('BEGIN EXCLUSIVE TRANSACTION;');
    $lockingConnection->exec('INSERT INTO t VALUES (1);');

    try {
        expect(fn () => $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $validArchive))
            ->toThrow(RestoreException::class, 'locked by an active transaction or connection');
    } finally {
        $lockingConnection->exec('ROLLBACK;');
        $lockingConnection->close();
    }
});

it('restores original WAL and data when post-replacement verification fails', function (): void {
    $targetDb = $this->testDir.'/post_swap_target.sqlite';
    $validArchive = $this->testDir.'/valid_archive_2.sqlite';

    // 1. Target database with WAL mode and initial canary row
    $target = new SQLite3($targetDb);
    $target->exec('PRAGMA journal_mode = WAL;');
    $target->exec('CREATE TABLE test_data (id INT, text TEXT);');
    $target->exec("INSERT INTO test_data VALUES (1, 'initial_target_canary');");
    $target->close();

    // 2. Prepare valid archive
    $arc = new SQLite3($validArchive);
    $arc->exec('CREATE TABLE test_data (id INT, text TEXT);');
    $arc->exec("INSERT INTO test_data VALUES (999, 'new_restored_data');");
    $arc->close();

    // 3. Subclass SqliteDriver to simulate a failure AFTER replacement begins (during post-verification)
    $faultyDriver = new class extends SqliteDriver
    {
        protected int $verifyCount = 0;

        public function verifyArchive(string $archivePath): array
        {
            $this->verifyCount++;
            if ($this->verifyCount >= 3) {
                throw new BackupVerificationException(
                    'Simulated hardware or validation failure immediately after atomic swap'
                );
            }

            return parent::verifyArchive($archivePath);
        }
    };

    try {
        expect(fn () => $faultyDriver->restore(['driver' => 'sqlite', 'database' => $targetDb], $validArchive))
            ->toThrow(RestoreException::class, 'Simulated hardware or validation failure');
    } finally {
        // 4. Verify original target database was fully recovered from rollback snapshot
        $check = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
        $canaryText = $check->querySingle('SELECT text FROM test_data WHERE id = 1;');
        $check->close();

        expect($canaryText)->toBe('initial_target_canary');

        // Verify staged temporary files and rollback files were cleaned up
        $stagedFiles = glob($this->testDir.'/*.restoring_*');
        $rollbackFiles = glob($this->testDir.'/*.pre_restore_bak_*');
        expect($stagedFiles)->toBeEmpty('Staged temporary files must be cleaned up on rollback')
            ->and($rollbackFiles)->toBeEmpty('Rollback files must be cleaned up after recovery');
    }
});

it('requires reconnect for long running sqlite connection after atomic swap', function (): void {
    $driver = new SqliteDriver(busyTimeout: 2000);
    $targetDb = $this->testDir.'/conn_swap_target.sqlite';
    $archivePath = $this->testDir.'/conn_swap_archive.sqlite';

    // 1. Initialize target database with initial data
    $target = new SQLite3($targetDb);
    $target->exec('CREATE TABLE records (id INT PRIMARY KEY, val TEXT);');
    $target->exec("INSERT INTO records VALUES (1, 'pre_restore_value');");
    $target->close();

    // 2. Open a long-running read connection holding an active file descriptor
    $longRunningConn = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
    $valBefore = $longRunningConn->querySingle('SELECT val FROM records WHERE id = 1;');
    expect($valBefore)->toBe('pre_restore_value');

    // 3. Prepare replacement archive with updated data
    $arc = new SQLite3($archivePath);
    $arc->exec('CREATE TABLE records (id INT PRIMARY KEY, val TEXT);');
    $arc->exec("INSERT INTO records VALUES (1, 'post_restore_value');");
    $arc->close();

    // 4. Execute atomic restore
    $result = $driver->restore(['driver' => 'sqlite', 'database' => $targetDb], $archivePath);
    expect($result['restored'])->toBeTrue();

    // 5. Existing open connection still references the old unlinked inode on POSIX
    $valOnOldHandle = $longRunningConn->querySingle('SELECT val FROM records WHERE id = 1;');
    expect($valOnOldHandle)->toBe('pre_restore_value', 'Existing connection holds open file descriptor on old inode');

    // 6. A newly opened connection opens the new inode and sees the restored database
    $freshConn = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
    $valOnFreshConn = $freshConn->querySingle('SELECT val FROM records WHERE id = 1;');
    expect($valOnFreshConn)->toBe('post_restore_value', 'New connection opens newly swapped inode');
    $freshConn->close();

    // 7. Closing and reconnecting the original handle immediately switches to restored data
    $longRunningConn->close();
    $reconnected = new SQLite3($targetDb, SQLITE3_OPEN_READONLY);
    $valAfterReconnect = $reconnected->querySingle('SELECT val FROM records WHERE id = 1;');
    expect($valAfterReconnect)->toBe('post_restore_value', 'Reconnected handle reads restored database');
    $reconnected->close();
});
