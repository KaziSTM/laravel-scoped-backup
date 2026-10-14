<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;

beforeEach(function () {
    $this->tempDir = storage_path('framework/testing/mariadb_driver_tests_'.uniqid('', true));
    File::makeDirectory($this->tempDir, 0755, true, true);
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('capabilities report', function () {
    $driver = new MariaDbDriver;
    $caps = $driver->getCapabilities();

    expect($caps['name'])->toBe('MariaDB')
        ->and($caps['driver'])->toBe('mariadb')
        ->and($caps['archive_format'])->toBe('sql')
        ->and($driver->getArchiveExtension())->toBe('sql')
        ->and($caps['supports_transactions'])->toBeTrue()
        ->and($caps['supports_routines'])->toBeTrue()
        ->and($caps['supports_triggers'])->toBeTrue()
        ->and($caps['supports_events'])->toBeTrue();
});

test('availability reports false when binary missing', function () {
    $driver = new MariaDbDriver(dumpBinary: 'nonexistent_mariadb_dump');
    $avail = $driver->checkAvailability();

    expect($avail['available'])->toBeFalse()
        ->and($avail['message'])->toContain('not found or not executable');
});

test('verify archive succeeds on mariadb dump header', function () {
    $validFile = $this->tempDir.'/valid_mariadb.sql';
    $validDump = <<<'SQL'
-- MariaDB dump 10.19  Distrib 10.11.6-MariaDB, for debian-linux-gnu (x86_64)
-- Host: 127.0.0.1    Database: test_mariadb_db
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `announcements` VALUES (1,'صفقة تزويد بالعتاد');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
-- Dump completed on 2026-10-14 12:00:00
SQL;
    File::put($validFile, $validDump);

    $driver = new MariaDbDriver;
    $meta = $driver->verifyArchive($validFile);

    expect($meta['verified'])->toBeTrue()
        ->and($meta['file_size'])->toBeGreaterThan(0);
});

test('dump rejects unsupported driver', function () {
    $driver = new MariaDbDriver;

    expect(fn () => $driver->dump(['driver' => 'pgsql'], $this->tempDir.'/out.sql'))
        ->toThrow(UnsupportedDatabaseDriverException::class, "Expected 'mysql' or 'mariadb'");
});

test('restore rejects empty target database', function () {
    $validFile = $this->tempDir.'/valid.sql';
    File::put($validFile, "-- MariaDB dump 10.19\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

    $driver = new MariaDbDriver;

    expect(fn () => $driver->restore([
        'driver' => 'mariadb',
        'database' => '',
    ], $validFile))->toThrow(RestoreException::class, 'Target database name is not configured.');
});
