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

class PostgreSqlDriver implements DatabaseBackupDriver
{
    public function __construct(
        protected readonly ?string $dumpBinary = null,
        protected readonly ?string $restoreBinary = null,
        protected readonly ?int $timeout = null,
    ) {}

    public function checkAvailability(): array
    {
        $binary = $this->dumpBinary ?? (string) config('scoped-backup.database.drivers.pgsql.dump_binary', config('scoped-backup.database.dumpers.pgsql.dump_binary', 'pg_dump'));

        try {
            $process = new Process([$binary, '--version']);
            $process->run();

            if (! $process->isSuccessful()) {
                return [
                    'available' => false,
                    'message' => "pg_dump executable not found or not executable at [{$binary}].",
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
                'message' => "Error verifying pg_dump executable: {$e->getMessage()}",
                'binary' => $binary,
                'version' => null,
            ];
        }
    }

    public function getCapabilities(): array
    {
        return [
            'name' => 'PostgreSQL',
            'driver' => 'pgsql',
            'archive_format' => 'custom',
            'extension' => 'dump',
            'supports_transactions' => true,
            'supports_routines' => true,
            'supports_triggers' => true,
            'supports_views' => true,
            'supports_compression' => true,
            'requires_external_binaries' => true,
        ];
    }

    public function getArchiveExtension(): string
    {
        return 'dump';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function dump(array $config, string $outputPath): array
    {
        $driver = (string) ($config['driver'] ?? '');
        if (! in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            throw new UnsupportedDatabaseDriverException(
                "Database driver [{$driver}] is not supported for backups. Only PostgreSQL ('pgsql') is supported."
            );
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? 'postgres');
        $password = (string) ($config['password'] ?? '');

        if ($database === '') {
            throw new BackupProcessException('Database name is not configured for connection.');
        }

        $binary = $this->dumpBinary ?? (string) config('scoped-backup.database.drivers.pgsql.dump_binary', config('scoped-backup.database.dumpers.pgsql.dump_binary', 'pg_dump'));
        $timeout = $this->timeout ?? (int) config('scoped-backup.database.timeout', 600);

        // Explicit command array preventing shell string interpolation
        $command = [
            $binary,
            '-h', $host,
            '-p', $port,
            '-U', $username,
            '-d', $database,
            '-F', 'c',
            '-f', $outputPath,
        ];

        // Credentials passed strictly via environment, never via CLI arguments
        $environment = [
            'PGPASSWORD' => $password,
        ];

        $process = new Process($command, null, $environment, null, (float) $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $this->sanitizeOutput($process->getErrorOutput(), $password);
            $exitCode = $process->getExitCode();

            throw new BackupProcessException(
                "pg_dump process failed with exit code {$exitCode}: {$errorOutput}"
            );
        }

        return [
            'format' => 'custom',
            'compression' => true,
            'driver' => 'pgsql',
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

        $binary = $this->restoreBinary ?? (string) config('scoped-backup.database.drivers.pgsql.restore_binary', config('scoped-backup.database.dumpers.pgsql.restore_binary', 'pg_restore'));

        $command = [
            $binary,
            '--list',
            $archivePath,
        ];

        $process = new Process($command, null, [], null, 60.0);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $this->sanitizeOutput($process->getErrorOutput());
            $exitCode = $process->getExitCode();

            throw new BackupVerificationException(
                "pg_restore verification failed with exit code {$exitCode}: {$errorOutput}"
            );
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            throw new BackupVerificationException(
                'pg_restore verification produced an empty Table of Contents.'
            );
        }

        $entryCount = substr_count($output, "\n") + 1;

        return [
            'verified' => true,
            'driver' => 'pgsql',
            'toc_entries_count' => $entryCount,
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
        if (! in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            throw new UnsupportedDatabaseDriverException("Cannot restore into driver [{$driver}]. Only PostgreSQL is supported.");
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? 'postgres');
        $password = (string) ($config['password'] ?? '');

        if ($database === '') {
            throw new RestoreException('Target database name is not configured.');
        }

        $binary = $this->restoreBinary ?? (string) config('scoped-backup.database.drivers.pgsql.restore_binary', config('scoped-backup.database.dumpers.pgsql.restore_binary', 'pg_restore'));
        $timeout = $this->timeout ?? (int) config('scoped-backup.database.timeout', 600);

        $command = [
            $binary,
            '-h', $host,
            '-p', $port,
            '-U', $username,
            '-d', $database,
            '--clean',
            '--if-exists',
            $archivePath,
        ];

        $environment = [
            'PGPASSWORD' => $password,
        ];

        $process = new Process($command, null, $environment, null, (float) $timeout);
        $process->run();

        // Note: pg_restore may return exit code 0 or 1 with non-fatal warnings
        if (! $process->isSuccessful() && $process->getExitCode() > 1) {
            $errorOutput = $this->sanitizeOutput($process->getErrorOutput(), $password);
            throw new RestoreException("pg_restore failed with exit code {$process->getExitCode()}: {$errorOutput}");
        }

        return [
            'restored' => true,
            'target_database' => $database,
            'target_host' => $host,
        ];
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
