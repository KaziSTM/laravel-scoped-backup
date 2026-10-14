<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;

beforeEach(function (): void {
    $this->tempDir = storage_path('framework/testing/mysql_driver_tests_'.uniqid('', true));
    File::makeDirectory($this->tempDir, 0755, true, true);
});

afterEach(function (): void {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('reports availability and capabilities', function (): void {
    $driver = new MySqlDriver(dumpBinary: 'mysqldump');
    $caps = $driver->getCapabilities();

    expect($caps['name'])->toBe('MySQL')
        ->and($caps['driver'])->toBe('mysql')
        ->and($caps['archive_format'])->toBe('sql')
        ->and($driver->getArchiveExtension())->toBe('sql')
        ->and($caps['supports_transactions'])->toBeTrue()
        ->and($caps['supports_routines'])->toBeTrue()
        ->and($caps['supports_triggers'])->toBeTrue()
        ->and($caps['supports_events'])->toBeTrue();
});

it('reports false availability when binary is missing', function (): void {
    $driver = new MySqlDriver(dumpBinary: 'nonexistent_mysqldump_bin');
    $avail = $driver->checkAvailability();

    expect($avail['available'])->toBeFalse()
        ->and($avail['message'])->not->toBeNull()
        ->and($avail['message'])->toContain('mysqldump executable not found');
});

it('rejects unsupported driver on dump', function (): void {
    $driver = new MySqlDriver;

    expect(fn () => $driver->dump(['driver' => 'pgsql'], $this->tempDir.'/out.sql'))
        ->toThrow(UnsupportedDatabaseDriverException::class, "Expected 'mysql' or 'mariadb'");
});

it('rejects empty database name on dump', function (): void {
    $driver = new MySqlDriver;

    expect(fn () => $driver->dump(['driver' => 'mysql', 'database' => ''], $this->tempDir.'/out.sql'))
        ->toThrow(BackupProcessException::class, 'Database name is not configured for connection.');
});

it('rejects corrupted sql dump on verifyArchive', function (): void {
    $corruptFile = $this->tempDir.'/corrupt.sql';
    File::put($corruptFile, 'INVALID_SQL_DUMP_CONTENT');

    $driver = new MySqlDriver;

    expect(fn () => $driver->verifyArchive($corruptFile))
        ->toThrow(BackupVerificationException::class, 'archive does not contain a valid MySQL/MariaDB dump header');
});

it('rejects truncated sql dump on verifyArchive', function (): void {
    $truncatedFile = $this->tempDir.'/truncated.sql';
    File::put($truncatedFile, "-- MySQL dump 10.13  Distrib 8.0\n--\nCREATE TABLE foo (id INT);");

    $driver = new MySqlDriver;

    expect(fn () => $driver->verifyArchive($truncatedFile))
        ->toThrow(BackupVerificationException::class, 'archive appears truncated or incomplete');
});

it('succeeds on valid mysql dump on verifyArchive', function (): void {
    $validFile = $this->tempDir.'/valid_mysql.sql';
    $validDump = <<<'SQL'
-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
-- Host: localhost    Database: test_db
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES (1,'admin@example.com');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
-- Dump completed on 2026-10-14 12:00:00
SQL;
    File::put($validFile, $validDump);

    $driver = new MySqlDriver;
    $meta = $driver->verifyArchive($validFile);

    expect($meta['verified'])->toBeTrue()
        ->and($meta['driver'])->toBe('mysql')
        ->and($meta['file_size'])->toBeGreaterThan(0);
});

it('rejects missing or empty archive on restore', function (): void {
    $driver = new MySqlDriver;

    expect(fn () => $driver->restore([
        'driver' => 'mysql',
        'database' => 'test_db',
    ], $this->tempDir.'/nonexistent.sql'))
        ->toThrow(RestoreException::class, 'does not exist or is empty');
});

it('rejects unsupported driver on restore', function (): void {
    $validFile = $this->tempDir.'/valid.sql';
    File::put($validFile, "-- MySQL dump 10.13\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

    $driver = new MySqlDriver;

    expect(fn () => $driver->restore([
        'driver' => 'sqlite',
        'database' => 'test_db',
    ], $validFile))
        ->toThrow(UnsupportedDatabaseDriverException::class, 'Only MySQL and MariaDB are supported');
});

it('rejects empty target database on restore', function (): void {
    $validFile = $this->tempDir.'/valid.sql';
    File::put($validFile, "-- MySQL dump 10.13\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

    $driver = new MySqlDriver;

    expect(fn () => $driver->restore([
        'driver' => 'mysql',
        'database' => '',
    ], $validFile))
        ->toThrow(RestoreException::class, 'Target database name is not configured.');
});
