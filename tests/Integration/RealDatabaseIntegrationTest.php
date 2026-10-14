<?php

declare(strict_types=1);

use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;

it('verifies sqlite environment and driver availability', function (): void {
    expect(extension_loaded('pdo_sqlite'))->toBeTrue('PDO SQLite extension must be loaded.');

    $driver = new SqliteDriver;
    $avail = $driver->checkAvailability();
    expect($avail['available'])->toBeTrue('SQLite driver must be available.')
        ->and($avail['version'])->not->toBeEmpty();
});

it('verifies postgresql environment connectivity and version', function (): void {
    $host = env('DB_PGSQL_HOST');
    $port = (int) (env('DB_PGSQL_PORT') ?: 5432);
    $username = (string) (env('DB_PGSQL_USERNAME') ?: 'postgres');
    $password = env('DB_PGSQL_PASSWORD') !== null ? (string) env('DB_PGSQL_PASSWORD') : '';
    $adminDb = (string) (env('DB_PGSQL_DATABASE') ?: 'postgres');

    if (empty($host)) {
        handleServiceUnavailable('pgsql', null, $port);
        return;
    }

    expect(extension_loaded('pdo_pgsql'))->toBeTrue('PDO PostgreSQL extension must be loaded when DB_PGSQL_HOST is set.');

    $driver = new PostgreSqlDriver;
    $avail = $driver->checkAvailability();
    expect($avail['available'])->toBeTrue('PostgreSQL client binary (pg_dump) must be installed and executable: '.($avail['message'] ?? ''));

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$adminDb};user={$username};password={$password}";
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $pdo->query('SELECT version()')->fetchColumn();
    } catch (Throwable $e) {
        handleServiceUnavailable('pgsql', $host, $port, $e);
        return;
    }

    expect($version)->toContain('PostgreSQL');
});

it('verifies mysql environment connectivity and version', function (): void {
    $host = env('DB_MYSQL_HOST');
    $port = (int) (env('DB_MYSQL_PORT') ?: 3306);
    $username = (string) (env('DB_MYSQL_USERNAME') ?: 'root');
    $password = env('DB_MYSQL_PASSWORD') !== null ? (string) env('DB_MYSQL_PASSWORD') : '';
    $adminDb = (string) (env('DB_MYSQL_DATABASE') ?: 'mysql');

    if (empty($host)) {
        handleServiceUnavailable('mysql', null, $port);
        return;
    }

    expect(extension_loaded('pdo_mysql'))->toBeTrue('PDO MySQL extension must be loaded when DB_MYSQL_HOST is set.');

    $driver = new MySqlDriver;
    $avail = $driver->checkAvailability();
    expect($avail['available'])->toBeTrue('MySQL client binary (mysqldump) must be installed and executable: '.($avail['message'] ?? ''));

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$adminDb}";
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $pdo->query('SELECT version()')->fetchColumn();
    } catch (Throwable $e) {
        handleServiceUnavailable('mysql', $host, $port, $e);
        return;
    }

    expect($version)->not->toBeEmpty();
});

it('verifies mariadb environment connectivity and version', function (): void {
    $host = env('DB_MARIADB_HOST');
    $port = (int) (env('DB_MARIADB_PORT') ?: 3307);
    $username = (string) (env('DB_MARIADB_USERNAME') ?: 'root');
    $password = env('DB_MARIADB_PASSWORD') !== null ? (string) env('DB_MARIADB_PASSWORD') : '';
    $adminDb = (string) (env('DB_MARIADB_DATABASE') ?: 'mysql');

    if (empty($host)) {
        handleServiceUnavailable('mariadb', null, $port);
        return;
    }

    expect(extension_loaded('pdo_mysql'))->toBeTrue('PDO MySQL extension must be loaded when DB_MARIADB_HOST is set.');

    $driver = new MariaDbDriver;
    $avail = $driver->checkAvailability();
    expect($avail['available'])->toBeTrue('MariaDB/MySQL client binary must be installed and executable: '.($avail['message'] ?? ''));

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$adminDb}";
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $pdo->query('SELECT version()')->fetchColumn();
    } catch (Throwable $e) {
        handleServiceUnavailable('mariadb', $host, $port, $e);
        return;
    }

    expect($version)->toContain('MariaDB');
});
