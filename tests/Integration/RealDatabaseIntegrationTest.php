<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Tests\Integration\Concerns\InteractsWithRealDatabases;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class RealDatabaseIntegrationTest extends TestCase
{
    use InteractsWithRealDatabases;

    public function test_sqlite_environment_and_driver_availability(): void
    {
        $this->assertTrue(extension_loaded('pdo_sqlite'), 'PDO SQLite extension must be loaded.');

        $driver = new SqliteDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'SQLite driver must be available.');
        $this->assertNotEmpty($avail['version']);
    }

    public function test_postgresql_environment_connectivity_and_version(): void
    {
        $host = env('DB_PGSQL_HOST');
        $port = (int) (env('DB_PGSQL_PORT') ?: 5432);
        $username = (string) (env('DB_PGSQL_USERNAME') ?: 'postgres');
        $password = env('DB_PGSQL_PASSWORD') !== null ? (string) env('DB_PGSQL_PASSWORD') : '';
        $adminDb = (string) (env('DB_PGSQL_DATABASE') ?: 'postgres');

        if (empty($host)) {
            $this->handleServiceUnavailable('pgsql', null, $port);
            return;
        }

        $this->assertTrue(extension_loaded('pdo_pgsql'), 'PDO PostgreSQL extension must be loaded when DB_PGSQL_HOST is set.');

        $driver = new PostgreSqlDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'PostgreSQL client binary (pg_dump) must be installed and executable: '.($avail['message'] ?? ''));

        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$adminDb};user={$username};password={$password}";
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = (string) $pdo->query('SELECT version()')->fetchColumn();

            $this->assertStringContainsString('PostgreSQL', $version, "PostgreSQL server at {$host}:{$port} must identify as PostgreSQL.");
        } catch (Throwable $e) {
            $this->handleServiceUnavailable('pgsql', $host, $port, $e);
        }
    }

    public function test_mysql_environment_connectivity_and_version(): void
    {
        $host = env('DB_MYSQL_HOST');
        $port = (int) (env('DB_MYSQL_PORT') ?: 3306);
        $username = (string) (env('DB_MYSQL_USERNAME') ?: 'root');
        $password = env('DB_MYSQL_PASSWORD') !== null ? (string) env('DB_MYSQL_PASSWORD') : '';
        $adminDb = (string) (env('DB_MYSQL_DATABASE') ?: 'mysql');

        if (empty($host)) {
            $this->handleServiceUnavailable('mysql', null, $port);
            return;
        }

        $this->assertTrue(extension_loaded('pdo_mysql'), 'PDO MySQL extension must be loaded when DB_MYSQL_HOST is set.');

        $driver = new MySqlDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'MySQL client binary (mysqldump) must be installed and executable: '.($avail['message'] ?? ''));

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$adminDb}";
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = (string) $pdo->query('SELECT version()')->fetchColumn();

            $this->assertNotEmpty($version, "MySQL server at {$host}:{$port} must return a valid version string.");
        } catch (Throwable $e) {
            $this->handleServiceUnavailable('mysql', $host, $port, $e);
        }
    }

    public function test_mariadb_environment_connectivity_and_version(): void
    {
        $host = env('DB_MARIADB_HOST');
        $port = (int) (env('DB_MARIADB_PORT') ?: 3307);
        $username = (string) (env('DB_MARIADB_USERNAME') ?: 'root');
        $password = env('DB_MARIADB_PASSWORD') !== null ? (string) env('DB_MARIADB_PASSWORD') : '';
        $adminDb = (string) (env('DB_MARIADB_DATABASE') ?: 'mysql');

        if (empty($host)) {
            $this->handleServiceUnavailable('mariadb', null, $port);
            return;
        }

        $this->assertTrue(extension_loaded('pdo_mysql'), 'PDO MySQL extension must be loaded when DB_MARIADB_HOST is set.');

        $driver = new MariaDbDriver;
        $avail = $driver->checkAvailability();
        $this->assertTrue($avail['available'], 'MariaDB/MySQL client binary must be installed and executable: '.($avail['message'] ?? ''));

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$adminDb}";
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = (string) $pdo->query('SELECT version()')->fetchColumn();

            $this->assertStringContainsString('MariaDB', $version, "MariaDB server at {$host}:{$port} must identify as MariaDB.");
        } catch (Throwable $e) {
            $this->handleServiceUnavailable('mariadb', $host, $port, $e);
        }
    }
}
