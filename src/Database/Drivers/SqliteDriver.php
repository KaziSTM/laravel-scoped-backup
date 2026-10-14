<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Database\Drivers;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use SQLite3;
use Throwable;

class SqliteDriver implements DatabaseBackupDriver
{
    public function __construct(
        protected readonly ?int $busyTimeout = null,
    ) {}

    protected function getBusyTimeout(): int
    {
        return $this->busyTimeout ?? (int) config('scoped-backup.database.drivers.sqlite.busy_timeout', 5000);
    }

    public function checkAvailability(): array
    {
        if (! class_exists(SQLite3::class)) {
            return [
                'available' => false,
                'message' => 'PHP SQLite3 extension is not loaded.',
                'binary' => null,
                'version' => null,
            ];
        }

        $version = SQLite3::version()['versionString'] ?? 'unknown';

        return [
            'available' => true,
            'message' => null,
            'binary' => 'ext-sqlite3',
            'version' => $version,
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'name' => 'SQLite',
            'driver' => 'sqlite',
            'archive_format' => 'sqlite',
            'extension' => 'sqlite',
            'supports_transactions' => true,
            'supports_wal' => true,
            'supports_in_memory' => false,
            'requires_external_binaries' => false,
        ];
    }

    public function getArchiveExtension(): string
    {
        return 'sqlite';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function dump(array $config, string $outputPath): array
    {
        $driver = (string) ($config['driver'] ?? '');
        if ($driver !== 'sqlite') {
            throw new UnsupportedDatabaseDriverException(
                "Database driver [{$driver}] is not supported by SqliteDriver. Expected 'sqlite'."
            );
        }

        $database = (string) ($config['database'] ?? '');

        if ($database === ':memory:') {
            throw new BackupException("In-memory SQLite databases (':memory:') cannot be backed up to disk.");
        }

        if ($database === '') {
            throw new BackupProcessException('SQLite database path is not configured.');
        }

        if (! File::exists($database)) {
            throw new BackupProcessException("SQLite database file not found at [{$database}].");
        }

        $outputDir = dirname($outputPath);
        if (! File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true, true);
        }

        if (File::exists($outputPath)) {
            File::delete($outputPath);
        }

        try {
            // Open source in readonly mode to prevent write locks during initial read
            $source = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $source->busyTimeout($this->getBusyTimeout());

            // Open destination in readwrite/create mode
            $destination = new SQLite3($outputPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $destination->busyTimeout($this->getBusyTimeout());

            // Execute native C-level online backup API, properly merging active WAL pages
            $backupResult = $source->backup($destination);

            $destination->close();
            $source->close();

            if ($backupResult === false) {
                throw new BackupProcessException("SQLite native backup API failed for database [{$database}].");
            }

            // Verify integrity of the newly produced snapshot
            $verifyMeta = $this->verifyArchive($outputPath);

            return array_merge([
                'format' => 'sqlite',
                'driver' => 'sqlite',
                'database' => $database,
                'file_size' => File::size($outputPath),
            ], $verifyMeta);
        } catch (Throwable $e) {
            if ($e instanceof BackupProcessException || $e instanceof BackupException) {
                throw $e;
            }

            throw new BackupProcessException("SQLite backup failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyArchive(string $archivePath): array
    {
        if (! File::exists($archivePath) || File::size($archivePath) === 0) {
            throw new BackupVerificationException(
                "Backup archive at [{$archivePath}] does not exist or is empty."
            );
        }

        // Validate 16-byte SQLite header magic
        $stream = fopen($archivePath, 'rb');
        if ($stream === false) {
            throw new BackupVerificationException("Unable to read archive file at [{$archivePath}].");
        }

        $header = (string) fread($stream, 16);
        fclose($stream);

        if (! str_starts_with($header, "SQLite format 3\000")) {
            throw new BackupVerificationException(
                "SQLite backup archive [{$archivePath}] is not a valid SQLite 3 database file."
            );
        }

        try {
            $db = new SQLite3($archivePath, SQLITE3_OPEN_READONLY);
            $db->busyTimeout($this->getBusyTimeout());

            $integrity = (string) $db->querySingle('PRAGMA integrity_check;');
            if ($integrity !== 'ok') {
                $db->close();
                throw new BackupVerificationException("SQLite integrity check failed: {$integrity}");
            }

            $quick = (string) $db->querySingle('PRAGMA quick_check;');
            if ($quick !== 'ok') {
                $db->close();
                throw new BackupVerificationException("SQLite quick check failed: {$quick}");
            }

            $tablesCount = (int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';");
            $viewsCount = (int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='view';");
            $indexesCount = (int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%';");
            $triggersCount = (int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='trigger';");

            $db->close();

            return [
                'verified' => true,
                'driver' => 'sqlite',
                'integrity_check' => 'ok',
                'tables_count' => $tablesCount,
                'views_count' => $viewsCount,
                'indexes_count' => $indexesCount,
                'triggers_count' => $triggersCount,
                'file_size' => File::size($archivePath),
            ];
        } catch (Throwable $e) {
            if ($e instanceof BackupVerificationException) {
                throw $e;
            }

            throw new BackupVerificationException("SQLite archive verification failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function restore(array $config, string $archivePath): array
    {
        if (! File::exists($archivePath) || File::size($archivePath) === 0) {
            throw new RestoreException("Archive file at [{$archivePath}] does not exist or is empty.");
        }

        $driver = (string) ($config['driver'] ?? '');
        if ($driver !== 'sqlite') {
            throw new UnsupportedDatabaseDriverException("Cannot restore into driver [{$driver}]. Expected 'sqlite'.");
        }

        $targetDatabase = (string) ($config['database'] ?? '');

        if ($targetDatabase === ':memory:') {
            throw new RestoreException("Cannot restore into an in-memory SQLite database (':memory:').");
        }

        if ($targetDatabase === '') {
            throw new RestoreException('Target SQLite database path is not configured.');
        }

        // Validate archive integrity before attempting restore
        $this->verifyArchive($archivePath);

        $targetDir = dirname($targetDatabase);
        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true, true);
        }

        $busyTimeout = $this->getBusyTimeout();

        // 1. If target database exists, attempt a clean WAL checkpoint before taking rollback snapshot
        if (File::exists($targetDatabase)) {
            try {
                $checkDb = new SQLite3($targetDatabase, SQLITE3_OPEN_READWRITE);
                $checkDb->busyTimeout($busyTimeout);
                // Flush pending WAL frames into main file
                $checkDb->exec('PRAGMA wal_checkpoint(TRUNCATE);');
                $checkDb->close();
            } catch (Throwable $e) {
                // If the target database is exclusively locked, fail early before altering files
                if (stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false) {
                    throw new RestoreException(
                        "Target SQLite database [{$targetDatabase}] is locked by an active transaction or connection. Restore timed out after {$busyTimeout}ms.",
                        0,
                        $e
                    );
                }
            }
        }

        // 2. Stage restoration into an isolated temporary file to ensure atomic replacement
        $stagedDatabase = $targetDatabase.'.restoring_'.uniqid('', true);
        if (File::exists($stagedDatabase)) {
            File::delete($stagedDatabase);
        }

        $rollbackFile = null;
        $rollbackWalFile = null;

        try {
            $src = new SQLite3($archivePath, SQLITE3_OPEN_READONLY);
            $src->busyTimeout($busyTimeout);

            $dst = new SQLite3($stagedDatabase, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $dst->busyTimeout($busyTimeout);

            $result = $src->backup($dst);

            $dst->close();
            $src->close();

            if ($result === false) {
                throw new RestoreException("SQLite online restore failed for staged file [{$stagedDatabase}].");
            }

            // Verify integrity of the staged database prior to swapping
            $this->verifyArchive($stagedDatabase);

            // 3. Create rollback backup of existing target database
            if (File::exists($targetDatabase)) {
                $rollbackFile = $targetDatabase.'.pre_restore_bak_'.uniqid('', true);
                if (! copy($targetDatabase, $rollbackFile)) {
                    throw new RestoreException("Failed to create rollback backup for [{$targetDatabase}].");
                }

                if (File::exists($targetDatabase.'-wal')) {
                    $rollbackWalFile = $rollbackFile.'-wal';
                    copy($targetDatabase.'-wal', $rollbackWalFile);
                }
            }

            // 4. Atomically swap staged database into target location
            if (! rename($stagedDatabase, $targetDatabase)) {
                // Fallback to copy if rename across filesystem boundaries fails
                if (! copy($stagedDatabase, $targetDatabase)) {
                    throw new RestoreException("Failed to replace target database [{$targetDatabase}] with staged restore.");
                }
                File::delete($stagedDatabase);
            }

            // 5. Clean up old sidecars so restored database starts clean
            // Note: Never restore or reuse -shm files; SQLite regenerates shared memory on new connections.
            if (File::exists($targetDatabase.'-wal')) {
                File::delete($targetDatabase.'-wal');
            }
            if (File::exists($targetDatabase.'-shm')) {
                File::delete($targetDatabase.'-shm');
            }

            // 6. Post-restore verification on final target database
            $this->verifyArchive($targetDatabase);

            // Clean up rollback files after successful restoration
            if ($rollbackFile !== null && File::exists($rollbackFile)) {
                File::delete($rollbackFile);
            }
            if ($rollbackWalFile !== null && File::exists($rollbackWalFile)) {
                File::delete($rollbackWalFile);
            }

            return [
                'restored' => true,
                'driver' => 'sqlite',
                'target_database' => $targetDatabase,
            ];
        } catch (Throwable $e) {
            // Clean up staged temporary file if still exists
            if (File::exists($stagedDatabase)) {
                File::delete($stagedDatabase);
            }

            // Safe rollback to original database state
            if ($rollbackFile !== null && File::exists($rollbackFile)) {
                copy($rollbackFile, $targetDatabase);
                File::delete($rollbackFile);

                if ($rollbackWalFile !== null && File::exists($rollbackWalFile)) {
                    copy($rollbackWalFile, $targetDatabase.'-wal');
                    File::delete($rollbackWalFile);
                }

                // Remove potentially stale -shm so SQLite rebuilds it cleanly from restored WAL
                if (File::exists($targetDatabase.'-shm')) {
                    File::delete($targetDatabase.'-shm');
                }
            }

            if ($e instanceof RestoreException) {
                throw $e;
            }

            throw new RestoreException("SQLite restore failed: {$e->getMessage()}", 0, $e);
        }
    }
}
