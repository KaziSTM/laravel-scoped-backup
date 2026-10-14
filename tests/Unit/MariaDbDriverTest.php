<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MariaDbDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;

final class MariaDbDriverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/mariadb_driver_tests_'.uniqid('', true));
        File::makeDirectory($this->tempDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_capabilities_report(): void
    {
        $driver = new MariaDbDriver;
        $caps = $driver->getCapabilities();

        $this->assertSame('MariaDB', $caps['name']);
        $this->assertSame('mariadb', $caps['driver']);
        $this->assertSame('sql', $caps['archive_format']);
        $this->assertSame('sql', $driver->getArchiveExtension());
        $this->assertTrue($caps['supports_transactions']);
        $this->assertTrue($caps['supports_routines']);
        $this->assertTrue($caps['supports_triggers']);
        $this->assertTrue($caps['supports_events']);
    }

    public function test_availability_reports_availability(): void
    {
        $driver = new MariaDbDriver(dumpBinary: 'nonexistent_mariadb_dump');
        $avail = $driver->checkAvailability();

        $this->assertFalse($avail['available']);
        $this->assertStringContainsString('not found or not executable', $avail['message']);
    }

    public function test_verify_archive_succeeds_on_mariadb_dump_header(): void
    {
        $validFile = $this->tempDir.'/valid_mariadb.sql';
        $validDump = <<<'SQL'
-- MariaDB dump 10.19  Distrib 10.11.6-MariaDB, for debian-linux-gnu (x86_64)
-- Host: 127.0.0.1    Database: company_radar
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

        $this->assertTrue($meta['verified']);
        $this->assertGreaterThan(0, $meta['file_size']);
    }

    public function test_dump_rejects_unsupported_driver(): void
    {
        $driver = new MariaDbDriver;

        $this->expectException(UnsupportedDatabaseDriverException::class);
        $this->expectExceptionMessage("Expected 'mysql' or 'mariadb'");

        $driver->dump(['driver' => 'pgsql'], $this->tempDir.'/out.sql');
    }

    public function test_restore_rejects_empty_target_database(): void
    {
        $validFile = $this->tempDir.'/valid.sql';
        File::put($validFile, "-- MariaDB dump 10.19\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

        $driver = new MariaDbDriver;

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('Target database name is not configured.');

        $driver->restore([
            'driver' => 'mariadb',
            'database' => '',
        ], $validFile);
    }
}
