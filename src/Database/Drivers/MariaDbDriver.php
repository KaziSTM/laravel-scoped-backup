<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Database\Drivers;

use Symfony\Component\Process\Process;

class MariaDbDriver extends MySqlDriver
{
    public function getCapabilities(): array
    {
        return [
            'name' => 'MariaDB',
            'driver' => 'mariadb',
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

    public function checkAvailability(): array
    {
        $binary = $this->resolveDumpBinary();

        try {
            $process = new Process([$binary, '--version']);
            $process->run();

            if (! $process->isSuccessful()) {
                return [
                    'available' => false,
                    'message' => "MariaDB/MySQL dump executable not found or not executable at [{$binary}].",
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
                'message' => "Error verifying MariaDB/MySQL dump executable: {$e->getMessage()}",
                'binary' => $binary,
                'version' => null,
            ];
        }
    }

    protected function resolveDumpBinary(): string
    {
        if ($this->dumpBinary !== null) {
            return $this->dumpBinary;
        }

        $configured = (string) config('scoped-backup.database.drivers.mariadb.dump_binary', 'mariadb-dump');

        // Test if configured binary (e.g. mariadb-dump) exists and runs
        $process = new Process([$configured, '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            return $configured;
        }

        // Fallback to mysqldump if enabled
        if ((bool) config('scoped-backup.database.drivers.mariadb.fallback_to_mysql', true)) {
            $mysqlDump = (string) config('scoped-backup.database.drivers.mysql.dump_binary', 'mysqldump');
            $fbProcess = new Process([$mysqlDump, '--version']);
            $fbProcess->run();
            if ($fbProcess->isSuccessful()) {
                return $mysqlDump;
            }
        }

        return $configured;
    }

    protected function resolveRestoreBinary(): string
    {
        if ($this->restoreBinary !== null) {
            return $this->restoreBinary;
        }

        $configured = (string) config('scoped-backup.database.drivers.mariadb.restore_binary', 'mariadb');

        $process = new Process([$configured, '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            return $configured;
        }

        if ((bool) config('scoped-backup.database.drivers.mariadb.fallback_to_mysql', true)) {
            $mysqlClient = (string) config('scoped-backup.database.drivers.mysql.restore_binary', 'mysql');
            $fbProcess = new Process([$mysqlClient, '--version']);
            $fbProcess->run();
            if ($fbProcess->isSuccessful()) {
                return $mysqlClient;
            }
        }

        return $configured;
    }
}
