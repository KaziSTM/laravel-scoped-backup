<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Dumpers\PostgresDumper;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;

final class PostgresDumperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/pkg_dumper_tests_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_dump_rejects_non_pgsql_driver(): void
    {
        $dumper = new PostgresDumper;

        $this->expectException(UnsupportedDatabaseDriverException::class);
        $this->expectExceptionMessage("Database driver [mysql] is not supported for backups. Only PostgreSQL ('pgsql') is supported.");

        $dumper->dump([
            'driver' => 'mysql',
            'database' => 'test_db',
        ], $this->tempDir.'/out.dump');
    }

    public function test_dump_rejects_missing_database_name(): void
    {
        $dumper = new PostgresDumper;

        $this->expectException(BackupProcessException::class);
        $this->expectExceptionMessage('Database name is not configured');

        $dumper->dump([
            'driver' => 'pgsql',
            'database' => '',
        ], $this->tempDir.'/out.dump');
    }

    public function test_verify_archive_rejects_nonexistent_file(): void
    {
        $dumper = new PostgresDumper;

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('does not exist or is empty');

        $dumper->verifyArchive($this->tempDir.'/nonexistent.dump');
    }

    public function test_verify_archive_rejects_empty_file(): void
    {
        $dumper = new PostgresDumper;
        $emptyFile = $this->tempDir.'/empty.dump';
        File::put($emptyFile, '');

        $this->expectException(BackupVerificationException::class);
        $this->expectExceptionMessage('does not exist or is empty');

        $dumper->verifyArchive($emptyFile);
    }
}
