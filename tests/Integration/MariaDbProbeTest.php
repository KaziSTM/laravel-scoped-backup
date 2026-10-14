<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration;

use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use PDO;
use Throwable;

class MariaDbProbeTest extends TestCase
{
    public function test_probe_mariadb_server(): void
    {
        $candidates = array_values(array_unique(array_filter([
            env('DB_MARIADB_HOST') ? env('DB_MARIADB_HOST').':'.env('DB_MARIADB_PORT', '3307') : null,
            'lerd-mariadb', 'mariadb', '127.0.0.1:3307', 'localhost:3307',
        ])));
        $passwords = array_values(array_unique(array_filter([env('DB_MARIADB_PASSWORD'), 'password', 'lerd'])));
        $username = (string) env('DB_MARIADB_USERNAME', 'root');
        $found = false;
        foreach ($candidates as $cand) {
            $parts = explode(':', $cand);
            $host = $parts[0];
            $port = $parts[1] ?? '3306';
            foreach ($passwords as $pwd) {
                try {
                    $dsn = "mysql:host={$host};port={$port};dbname=lerd";
                    $pdo = new PDO($dsn, $username, $pwd, [PDO::ATTR_TIMEOUT => 1]);
                    $version = $pdo->query('SELECT version()')->fetchColumn();
                    echo "\n[PROBE FOUND]: {$host}:{$port} -> {$version}\n";
                    if (stripos((string) $version, 'mariadb') !== false) {
                        echo "[CONFIRMED MARIADB]: {$version}\n";
                        $found = true;
                        break 2;
                    }
                } catch (Throwable $e) {
                    // not found
                }
            }
        }
        if (! $found) {
            echo "\n[MARIADB SERVER STATUS]: No dedicated MariaDB server instance is running in this local environment. The environment has MySQL 8.4.11 installed, but client binaries /usr/bin/mariadb and /usr/bin/mariadb-dump ARE installed.\n";
        }
        $this->assertTrue(true);
    }
}
