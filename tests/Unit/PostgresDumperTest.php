<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Dumpers\PostgresDumper;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\UnsupportedDatabaseDriverException;

beforeEach(function (): void {
    $this->tempDir = storage_path('framework/testing/pkg_dumper_tests_'.uniqid());
    File::makeDirectory($this->tempDir, 0755, true, true);
});

afterEach(function (): void {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('rejects non-pgsql driver on dump', function (): void {
    $dumper = new PostgresDumper;

    expect(fn () => $dumper->dump([
        'driver' => 'mysql',
        'database' => 'test_db',
    ], $this->tempDir.'/out.dump'))
        ->toThrow(UnsupportedDatabaseDriverException::class, "Database driver [mysql] is not supported for backups. Only PostgreSQL ('pgsql') is supported.");
});

it('rejects missing database name on dump', function (): void {
    $dumper = new PostgresDumper;

    expect(fn () => $dumper->dump([
        'driver' => 'pgsql',
        'database' => '',
    ], $this->tempDir.'/out.dump'))
        ->toThrow(BackupProcessException::class, 'Database name is not configured');
});

it('rejects nonexistent file on verifyArchive', function (): void {
    $dumper = new PostgresDumper;

    expect(fn () => $dumper->verifyArchive($this->tempDir.'/nonexistent.dump'))
        ->toThrow(BackupVerificationException::class, 'does not exist or is empty');
});

it('rejects empty file on verifyArchive', function (): void {
    $dumper = new PostgresDumper;
    $emptyFile = $this->tempDir.'/empty.dump';
    File::put($emptyFile, '');

    expect(fn () => $dumper->verifyArchive($emptyFile))
        ->toThrow(BackupVerificationException::class, 'does not exist or is empty');
});
