<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class RealDatabaseIntegrationTest extends TestCase
{
    public function test_check_database_connectivity_and_binaries(): void
    {
        // Check binaries available in container
        $binaries = [
            'pg_dump' => exec('which pg_dump 2>/dev/null'),
            'pg_restore' => exec('which pg_restore 2>/dev/null'),
            'psql' => exec('which psql 2>/dev/null'),
            'mysqldump' => exec('which mysqldump 2>/dev/null'),
            'mysql' => exec('which mysql 2>/dev/null'),
            'mariadb-dump' => exec('which mariadb-dump 2>/dev/null'),
            'mariadb' => exec('which mariadb 2>/dev/null'),
            'sqlite3' => exec('which sqlite3 2>/dev/null'),
        ];

        echo "\n[CONTAINER BINARIES DETECTED]:\n".json_encode($binaries, JSON_PRETTY_PRINT)."\n";

        // Check PostgreSQL connectivity
        $pgHosts = array_values(array_unique(array_filter([env('DB_PGSQL_HOST'), 'lerd-postgres', '127.0.0.1', 'localhost'])));
        $pgUser = (string) env('DB_PGSQL_USERNAME', 'postgres');
        $pgPass = (string) env('DB_PGSQL_PASSWORD', 'lerd');
        $pgPort = (int) env('DB_PGSQL_PORT', 5432);
        $pgConnected = false;
        $pgError = null;
        foreach ($pgHosts as $host) {
            try {
                $dsn = "pgsql:host={$host};port={$pgPort};dbname=lerd;user={$pgUser};password={$pgPass}";
                $pdo = new PDO($dsn, $pgUser, $pgPass, [PDO::ATTR_TIMEOUT => 2]);
                $version = $pdo->query('SELECT version()')->fetchColumn();
                echo "\n[POSTGRES CONNECTED via {$host}]: {$version}\n";
                $pgConnected = true;
                break;
            } catch (Throwable $e) {
                $pgError = $e->getMessage();
            }
        }
        if (! $pgConnected) {
            echo "\n[POSTGRES CONNECTION FAILED]: {$pgError}\n";
        }

        // Check MySQL connectivity
        $mysqlHosts = array_values(array_unique(array_filter([env('DB_MYSQL_HOST'), 'lerd-mysql', '127.0.0.1', 'localhost'])));
        $mysqlUser = (string) env('DB_MYSQL_USERNAME', 'root');
        $mysqlPass = (string) env('DB_MYSQL_PASSWORD', 'lerd');
        $mysqlPort = (int) env('DB_MYSQL_PORT', 3306);
        $mysqlConnected = false;
        $mysqlError = null;
        foreach ($mysqlHosts as $host) {
            try {
                $dsn = "mysql:host={$host};port={$mysqlPort};dbname=lerd";
                $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [PDO::ATTR_TIMEOUT => 2]);
                $version = $pdo->query('SELECT version()')->fetchColumn();
                echo "\n[MYSQL CONNECTED via {$host}]: {$version}\n";
                $mysqlConnected = true;
                break;
            } catch (Throwable $e) {
                $mysqlError = $e->getMessage();
            }
        }
        if (! $mysqlConnected) {
            echo "\n[MYSQL CONNECTION FAILED]: {$mysqlError}\n";
        }

        $this->assertTrue(true);
    }
}
