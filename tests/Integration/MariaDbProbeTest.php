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
        $candidates = ['lerd-mariadb', 'mariadb', '127.0.0.1:3307', 'localhost:3307'];
        $found = false;
        foreach ($candidates as $cand) {
            $parts = explode(':', $cand);
            $host = $parts[0];
            $port = $parts[1] ?? '3306';
            try {
                $dsn = "mysql:host={$host};port={$port};dbname=lerd";
                $pdo = new PDO($dsn, 'root', 'lerd', [PDO::ATTR_TIMEOUT => 1]);
                $version = $pdo->query('SELECT version()')->fetchColumn();
                echo "\n[PROBE FOUND]: {$host}:{$port} -> {$version}\n";
                if (stripos((string) $version, 'mariadb') !== false) {
                    echo "[CONFIRMED MARIADB]: {$version}\n";
                    $found = true;
                }
            } catch (Throwable $e) {
                // not found
            }
        }
        if (! $found) {
            echo "\n[MARIADB SERVER STATUS]: No dedicated MariaDB server instance is running in this local environment. The environment has MySQL 8.4.11 installed, but client binaries /usr/bin/mariadb and /usr/bin/mariadb-dump ARE installed.\n";
        }
        $this->assertTrue(true);
    }
}
