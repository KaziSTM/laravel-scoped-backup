<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\PostgreSqlDriver;
use KaziSTM\LaravelScopedBackup\Database\Drivers\SqliteDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use Mockery;

final class DatabaseBackupDriverManagerTest extends TestCase
{
    public function test_resolves_all_built_in_drivers(): void
    {
        $manager = new DatabaseBackupDriverManager;

        $this->assertInstanceOf(PostgreSqlDriver::class, $manager->resolve('pgsql'));
        $this->assertInstanceOf(PostgreSqlDriver::class, $manager->resolve('postgres'));
        $this->assertInstanceOf(PostgreSqlDriver::class, $manager->resolve('postgresql'));
        $this->assertInstanceOf(MySqlDriver::class, $manager->resolve('mysql'));
        $this->assertInstanceOf(MariaDbDriver::class, $manager->resolve('mariadb'));
        $this->assertInstanceOf(SqliteDriver::class, $manager->resolve('sqlite'));
    }

    public function test_rejects_unsupported_driver_with_clear_message(): void
    {
        $manager = new DatabaseBackupDriverManager;

        $this->expectException(UnsupportedDatabaseDriverException::class);
        $this->expectExceptionMessage('Database driver [oracle] is not supported for backups. Supported drivers: pgsql, mysql, mariadb, sqlite.');

        $manager->resolve('oracle');
    }

    public function test_resolves_for_connection(): void
    {
        config([
            'database.connections.custom_sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            'database.connections.custom_mysql' => [
                'driver' => 'mysql',
                'database' => 'company_radar',
            ],
        ]);

        $manager = new DatabaseBackupDriverManager;

        $sqliteDriver = $manager->resolveForConnection('custom_sqlite');
        $this->assertInstanceOf(SqliteDriver::class, $sqliteDriver);

        $mysqlDriver = $manager->resolveForConnection('custom_mysql');
        $this->assertInstanceOf(MySqlDriver::class, $mysqlDriver);
    }

    public function test_custom_driver_extension_via_instance_and_closure(): void
    {
        $manager = new DatabaseBackupDriverManager;

        $mockCustomDriver = Mockery::mock(DatabaseBackupDriver::class);
        $manager->extend('custom_db', $mockCustomDriver);

        $this->assertSame($mockCustomDriver, $manager->resolve('custom_db'));

        $manager->extend('dynamic_db', fn () => $mockCustomDriver);
        $this->assertSame($mockCustomDriver, $manager->resolve('dynamic_db'));

        $this->assertContains('custom_db', $manager->supportedDrivers());
        $this->assertContains('dynamic_db', $manager->supportedDrivers());
    }
}
