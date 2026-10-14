<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use KaziSTM\LaravelScopedBackup\Contracts\DatabaseDumperInterface;
use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;

class DatabaseBackupDriverManager
{
    /** @var array<string, DatabaseBackupDriver> */
    protected array $drivers = [];

    /** @var array<string, Closure> */
    protected array $customCreators = [];

    /**
     * Resolve the database driver instance by driver name.
     */
    public function resolve(string $driver): DatabaseBackupDriver|DatabaseDumperInterface
    {
        $normalized = strtolower(trim($driver));

        if (isset($this->drivers[$normalized])) {
            return $this->drivers[$normalized];
        }

        if (isset($this->customCreators[$normalized])) {
            return $this->drivers[$normalized] = call_user_func($this->customCreators[$normalized]);
        }

        return $this->drivers[$normalized] = match ($normalized) {
            'pgsql', 'postgres', 'postgresql' => new PostgreSqlDriver,
            'mysql' => new MySqlDriver,
            'mariadb' => new MariaDbDriver,
            'sqlite' => new SqliteDriver,
            default => throw new UnsupportedDatabaseDriverException(
                "Database driver [{$driver}] is not supported for backups. Supported drivers: pgsql, mysql, mariadb, sqlite."
            ),
        };
    }

    /**
     * Resolve the database driver for a specific Laravel database connection.
     */
    public function resolveForConnection(?string $connection = null): DatabaseBackupDriver|DatabaseDumperInterface
    {
        $connName = $connection ?? (string) config('scoped-backup.database.connection', config('database.default', 'pgsql'));
        $connConfig = (array) config("database.connections.{$connName}", []);

        $driver = (string) ($connConfig['driver'] ?? '');

        if ($driver === '') {
            throw new UnsupportedDatabaseDriverException(
                "Database connection [{$connName}] has no configured driver."
            );
        }

        if ($driver === 'mysql') {
            if (($connConfig['is_mariadb'] ?? false) === true) {
                return $this->resolve('mariadb');
            }

            try {
                $conn = DB::connection($connName);
                if (method_exists($conn, 'isMaria') && $conn->isMaria()) {
                    return $this->resolve('mariadb');
                }
            } catch (\Throwable) {
                // If connection is unreachable during resolution, proceed with configured driver name
            }
        }

        return $this->resolve($driver);
    }

    /**
     * Register a custom database driver creator callback or pre-built driver instance.
     */
    public function extend(string $driver, Closure|DatabaseDumperInterface $driverOrClosure): self
    {
        $key = strtolower(trim($driver));

        if ($driverOrClosure instanceof Closure) {
            $this->customCreators[$key] = $driverOrClosure;
            unset($this->drivers[$key]);
        } else {
            $this->drivers[$key] = $driverOrClosure;
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function supportedDrivers(): array
    {
        return array_values(array_unique(array_merge(
            ['pgsql', 'postgres', 'postgresql', 'mysql', 'mariadb', 'sqlite'],
            array_keys($this->customCreators),
            array_keys($this->drivers)
        )));
    }
}
