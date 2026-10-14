<?php

declare(strict_types=1);

use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;

test('resolves all built in drivers', function () {
    $manager = new DatabaseBackupDriverManager;

    expect($manager->resolve('pgsql'))->toBeInstanceOf(PostgreSqlDriver::class)
        ->and($manager->resolve('postgres'))->toBeInstanceOf(PostgreSqlDriver::class)
        ->and($manager->resolve('postgresql'))->toBeInstanceOf(PostgreSqlDriver::class)
        ->and($manager->resolve('mysql'))->toBeInstanceOf(MySqlDriver::class)
        ->and($manager->resolve('mariadb'))->toBeInstanceOf(MariaDbDriver::class)
        ->and($manager->resolve('sqlite'))->toBeInstanceOf(SqliteDriver::class);
});

test('rejects unsupported driver with clear message', function () {
    $manager = new DatabaseBackupDriverManager;

    expect(fn () => $manager->resolve('oracle'))
        ->toThrow(UnsupportedDatabaseDriverException::class, 'Database driver [oracle] is not supported for backups. Supported drivers: pgsql, mysql, mariadb, sqlite.');
});

test('resolves for connection', function () {
    config([
        'database.connections.custom_sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
        'database.connections.custom_mysql' => [
            'driver' => 'mysql',
            'database' => 'test_custom_db',
        ],
    ]);

    $manager = new DatabaseBackupDriverManager;

    expect($manager->resolveForConnection('custom_sqlite'))->toBeInstanceOf(SqliteDriver::class)
        ->and($manager->resolveForConnection('custom_mysql'))->toBeInstanceOf(MySqlDriver::class);
});

test('custom driver extension via instance and closure', function () {
    $manager = new DatabaseBackupDriverManager;

    $mockCustomDriver = Mockery::mock(DatabaseBackupDriver::class);
    $manager->extend('custom_db', $mockCustomDriver);

    expect($manager->resolve('custom_db'))->toBe($mockCustomDriver);

    $manager->extend('dynamic_db', fn () => $mockCustomDriver);
    expect($manager->resolve('dynamic_db'))->toBe($mockCustomDriver)
        ->and($manager->supportedDrivers())->toContain('custom_db', 'dynamic_db');
});
