<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Database\Drivers;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use Symfony\Component\Process\Process;

class MySqlDriver implements DatabaseBackupDriver
{
    public function __construct(
        protected readonly ?string $dumpBinary = null,
        protected readonly ?string $restoreBinary = null,
        protected readonly ?int $timeout = null,
    ) {}

    public function checkAvailability(): array
    {
        $binary = $this->resolveDumpBinary();

        try {
            $process = new Process([$binary, '--version']);
            $process->run();

            if (! $process->isSuccessful()) {
                return [
                    'available' => false,
                    'message' => "mysqldump executable not found or not executable at [{$binary}].",
                    'binary' => $binary,
                    'version' => null,
                ];
            }

            return [
                'available' => true,
                'message' => null,
                'binary' => $binary,
                'version' => trim($process->getOutput()),
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'message' => "Error verifying mysqldump executable: {$e->getMessage()}",
                'binary' => $binary,
                'version' => null,
            ];
        }
    }

    public function getCapabilities(): array
    {
        return [
            'name' => 'MySQL',
            'driver' => 'mysql',
            'archive_format' => 'sql',
            'extension' => 'sql',
            'supports_transactions' => true,
            'supports_routines' => true,
            'supports_triggers' => true,
            'supports_events' => true,
            'supports_views' => true,
            'supports_compression' => false,
            'requires_external_binaries' => true,
        ];
    }

    public function getArchiveExtension(): string
    {
        return 'sql';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function dump(array $config, string $outputPath): array
    {
        $driver = (string) ($config['driver'] ?? '');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new UnsupportedDatabaseDriverException(
                "Database driver [{$driver}] is not supported by MySqlDriver. Expected 'mysql' or 'mariadb'."
            );
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? 'root');
        $password = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        if ($database === '') {
            throw new BackupProcessException('Database name is not configured for connection.');
        }

        $binary = $this->resolveDumpBinary();
        $timeout = $this->timeout ?? (int) config('scoped-backup.database.timeout', 600);

        // Build command with transaction consistency and object support
        $command = [
            $binary,
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            '--single-transaction', // Consistent InnoDB snapshot without table locks
            '--quick',              // Row-by-row streaming to handle large datasets
            '--skip-lock-tables',
        ];

        $driverKey = $this instanceof MariaDbDriver ? 'mariadb' : 'mysql';

        if ((bool) config("scoped-backup.database.drivers.{$driverKey}.routines", true)) {
            $command[] = '--routines';
        }
        if ((bool) config("scoped-backup.database.drivers.{$driverKey}.triggers", true)) {
            $command[] = '--triggers';
        }
        if ((bool) config("scoped-backup.database.drivers.{$driverKey}.events", true)) {
            $command[] = '--events';
        }

        $command[] = '--default-character-set='.$charset;
        $command[] = '--result-file='.$outputPath;
        $command[] = $database;

        // Credentials passed strictly via environment variable
        $environment = [
            'MYSQL_PWD' => $password,
        ];

        $process = new Process($command, null, $environment, null, (float) $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $this->sanitizeOutput($process->getErrorOutput(), $password);
            $exitCode = $process->getExitCode();

            throw new BackupProcessException(
                "mysqldump process failed with exit code {$exitCode}: {$errorOutput}"
            );
        }

        return [
            'format' => 'sql',
            'compression' => false,
            'driver' => $driver,
            'database' => $database,
            'host' => $host,
            'port' => $port,
        ];
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

        // Validate header content
        $stream = fopen($archivePath, 'rb');
        if ($stream === false) {
            throw new BackupVerificationException("Unable to read archive file at [{$archivePath}].");
        }

        $headerChunk = (string) fread($stream, 4096);
        fclose($stream);

        $hasValidHeader = str_contains($headerChunk, 'MySQL dump')
            || str_contains($headerChunk, 'MariaDB dump')
            || str_contains($headerChunk, '/*!40101')
            || str_contains($headerChunk, 'CREATE TABLE')
            || str_contains($headerChunk, 'INSERT INTO');

        if (! $hasValidHeader) {
            throw new BackupVerificationException(
                'SQL dump verification failed: archive does not contain a valid MySQL/MariaDB dump header.'
            );
        }

        // Validate footer completion marker if available
        $fileSize = File::size($archivePath);
        $tailSize = min($fileSize, 4096);
        $tailStream = fopen($archivePath, 'rb');
        if ($tailStream !== false) {
            fseek($tailStream, max(0, $fileSize - $tailSize));
            $tailChunk = (string) fread($tailStream, $tailSize);
            fclose($tailStream);

            // If mysqldump aborted midway, standard trailer will be missing
            $hasValidTrailer = str_contains($tailChunk, 'Dump completed on')
                || str_contains($tailChunk, 'SET COLLATION_CONNECTION')
                || str_contains($tailChunk, 'SET SQL_NOTES');

            if (! $hasValidTrailer) {
                throw new BackupVerificationException(
                    'SQL dump verification failed: archive appears truncated or incomplete.'
                );
            }
        }

        return [
            'verified' => true,
            'driver' => 'mysql',
            'file_size' => $fileSize,
        ];
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
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new UnsupportedDatabaseDriverException("Cannot restore into driver [{$driver}]. Only MySQL and MariaDB are supported by MySqlDriver.");
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? 'root');
        $password = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        if ($database === '') {
            throw new RestoreException('Target database name is not configured.');
        }

        $binary = $this->resolveRestoreBinary();
        $timeout = $this->timeout ?? (int) config('scoped-backup.database.timeout', 600);

        $command = [
            $binary,
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            '--default-character-set='.$charset,
            $database,
        ];

        $environment = [
            'MYSQL_PWD' => $password,
        ];

        $process = new Process($command, null, $environment, null, (float) $timeout);

        $archiveStream = fopen($archivePath, 'rb');
        if ($archiveStream === false) {
            throw new RestoreException("Failed to open archive file [{$archivePath}] for streaming restore.");
        }

        $process->setInput($archiveStream);
        $process->run();

        if (is_resource($archiveStream)) {
            fclose($archiveStream);
        }

        if (! $process->isSuccessful()) {
            $errorOutput = $this->sanitizeOutput($process->getErrorOutput(), $password);
            throw new RestoreException("mysql restore failed with exit code {$process->getExitCode()}: {$errorOutput}");
        }

        return [
            'restored' => true,
            'target_database' => $database,
            'target_host' => $host,
        ];
    }

    protected function resolveDumpBinary(): string
    {
        return $this->dumpBinary
            ?? (string) config('scoped-backup.database.drivers.mysql.dump_binary', 'mysqldump');
    }

    protected function resolveRestoreBinary(): string
    {
        return $this->restoreBinary
            ?? (string) config('scoped-backup.database.drivers.mysql.restore_binary', 'mysql');
    }

    protected function sanitizeOutput(string $output, ?string $password = null): string
    {
        $sanitized = trim($output);

        if ($password !== null && $password !== '') {
            $sanitized = str_replace($password, '[REDACTED]', $sanitized);
        }

        return $sanitized;
    }
}
