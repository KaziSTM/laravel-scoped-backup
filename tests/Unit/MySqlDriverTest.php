<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Database\Drivers\MySqlDriver;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;

final class MySqlDriverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/mysql_driver_tests_'.uniqid('', true));
        File::makeDirectory($this->tempDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_availability_and_capabilities(): void
    {
        $driver = new MySqlDriver(dumpBinary: 'mysqldump');
        $caps = $driver->getCapabilities();

        $this->assertSame('MySQL', $caps['name']);
        $this->assertSame('mysql', $caps['driver']);
        $this->assertSame('sql', $caps['archive_format']);
        $this->assertSame('sql', $driver->getArchiveExtension());
        $this->assertTrue($caps['supports_transactions']);
        $this->assertTrue($caps['supports_routines']);
        $this->assertTrue($caps['supports_triggers']);
        $this->assertTrue($caps['supports_events']);
    }

    public function test_availability_reports_false_when_binary_missing(): void
    {
        $driver = new MySqlDriver(dumpBinary: 'nonexistent_mysqldump_bin');
        $avail = $driver->checkAvailability();

        $this->assertFalse($avail['available']);
        $this->assertNotNull($avail['message']);
        $this->assertStringContainsString('mysqldump executable not found', $avail['message']);
    }

    public function test_dump_rejects_unsupported_driver(): void
    {
        $driver = new MySqlDriver;

        $this->expectException(UnsupportedDatabaseDriverException::class);
        $this->expectExceptionMessage("Expected 'mysql' or 'mariadb'");

        $driver->dump(['driver' => 'pgsql'], $this->tempDir.'/out.sql');
    }

    public function test_dump_rejects_empty_database_name(): void
    {
        $driver = new MySqlDriver;

        $this->expectException(BackupProcessException::class);
        $this->expectExceptionMessage('Database name is not configured for connection.');

        $driver->dump(['driver' => 'mysql', 'database' => ''], $this->tempDir.'/out.sql');
    }

    public function test_verify_archive_rejects_corrupted_sql_dump(): void
    {
        $corruptFile = $this->tempDir.'/corrupt.sql';
        File::put($corruptFile, 'INVALID_SQL_DUMP_CONTENT');

        $driver = new MySqlDriver;

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('archive does not contain a valid MySQL/MariaDB dump header');

        $driver->verifyArchive($corruptFile);
    }

    public function test_verify_archive_rejects_truncated_sql_dump(): void
    {
        $truncatedFile = $this->tempDir.'/truncated.sql';
        File::put($truncatedFile, "-- MySQL dump 10.13  Distrib 8.0\n--\nCREATE TABLE foo (id INT);");

        $driver = new MySqlDriver;

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('archive appears truncated or incomplete');

        $driver->verifyArchive($truncatedFile);
    }

    public function test_verify_archive_succeeds_on_valid_mysql_dump(): void
    {
        $validFile = $this->tempDir.'/valid_mysql.sql';
        $validDump = <<<'SQL'
-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
-- Host: 127.0.0.1    Database: company_radar
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

        $this->assertTrue($meta['verified']);
        $this->assertSame('mysql', $meta['driver']);
        $this->assertGreaterThan(0, $meta['file_size']);
    }

    public function test_restore_rejects_missing_or_empty_archive(): void
    {
        $driver = new MySqlDriver;

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('does not exist or is empty');

        $driver->restore([
            'driver' => 'mysql',
            'database' => 'test_db',
        ], $this->tempDir.'/nonexistent.sql');
    }

    public function test_restore_rejects_unsupported_driver(): void
    {
        $validFile = $this->tempDir.'/valid.sql';
        File::put($validFile, "-- MySQL dump 10.13\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

        $driver = new MySqlDriver;

        $this->expectException(UnsupportedDatabaseDriverException::class);
        $this->expectExceptionMessage('Only MySQL and MariaDB are supported');

        $driver->restore([
            'driver' => 'sqlite',
            'database' => 'test_db',
        ], $validFile);
    }

    public function test_restore_rejects_empty_target_database(): void
    {
        $validFile = $this->tempDir.'/valid.sql';
        File::put($validFile, "-- MySQL dump 10.13\nCREATE TABLE t (id INT);\n-- Dump completed on 2026-10-14\n");

        $driver = new MySqlDriver;

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('Target database name is not configured.');

        $driver->restore([
            'driver' => 'mysql',
            'database' => '',
        ], $validFile);
    }
}
