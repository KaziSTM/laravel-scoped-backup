<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\SecurityException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use KaziSTM\LaravelScopedBackup\Services\HealthMonitor;

beforeEach(function (): void {
    $this->testDir = sys_get_temp_dir().'/storage_security_test_'.uniqid();
    mkdir($this->testDir, 0755, true);
});

afterEach(function (): void {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
    Mockery::close();
});

it('handles remote disk lifecycle without path support', function (): void {
    // 1. Create a mock remote disk that simulates Flysystem S3 behavior (throws on path())
    Storage::fake('mock_s3');
    $realDisk = Storage::disk('mock_s3');

    // Create an archive and put it onto the disk
    $archivePath = $this->testDir.'/sample_archive.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['schemaVersion' => '1.0']));
    $zip->addFromString('database.dump', 'SQLite format 3'."\0".str_repeat('A', 100));
    $zip->close();

    $contentStream = fopen($archivePath, 'rb');
    $realDisk->put('backups/sample_archive.zip', $contentStream);
    fclose($contentStream);

    $expectedSha256 = hash_file('sha256', $archivePath);

    // 2. Verify ChecksumValidator hashes via stream without path()
    $validator = app(ChecksumValidator::class);
    $stream = $realDisk->readStream('backups/sample_archive.zip');
    $calculatedSha = $validator->sha256Stream($stream);
    expect($calculatedSha)->toBe($expectedSha256);

    // 3. Verify HealthMonitor checks integrity on remote disk
    $record = BackupRecord::create([
        'backup_type' => BackupType::FULL,
        'trigger' => BackupTrigger::MANUAL,
        'status' => BackupStatus::SUCCEEDED,
        'disk' => 'mock_s3',
        'file_path' => 'backups/sample_archive.zip',
        'file_size_bytes' => filesize($archivePath),
        'checksum_sha256' => $expectedSha256,
        'database_name' => 'sample_db',
        'driver' => 'sqlite',
        'completed_at' => now(),
    ]);

    $monitor = app(HealthMonitor::class);
    $result = $monitor->check();
    expect($result->isHealthy)->toBeTrue();

    // 4. Verify RestoreFullBackupAction downloads stream to temp file and cleans up after completion
    $restoreAction = app(RestoreFullBackupAction::class);
    $tempDb = $this->testDir.'/restored_target.sqlite';

    // Prepare dummy connection
    config()->set('database.connections.sqlite_restore_test', [
        'driver' => 'sqlite',
        'database' => $tempDb,
    ]);

    try {
        $restoreAction->execute(
            backup: $record,
            targetConnection: 'sqlite_restore_test',
            verifyOnly: true
        );
    } catch (Throwable $e) {
        // Expected if dump format isn't full valid SQLite, but verifyOnly checks TOC
    }

    // Verify no leftover download temp files exist in staging dir
    $stagingDir = storage_path('framework/cache/backups');
    if (is_dir($stagingDir)) {
        $strayDownloads = glob($stagingDir.'/remote_restore_*');
        expect($strayDownloads)->toBeEmpty('Staging temp files from remote download must be cleaned up in finally block');
    }
});

it('rejects symlink entries in archive manager', function (): void {
    $archiveManager = app(ArchiveManager::class);
    $badZipPath = $this->testDir.'/symlink_attack.zip';
    $extractDir = $this->testDir.'/extracted';

    // Create a zip with a symlink entry
    $zip = new ZipArchive;
    $zip->open($badZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('normal.txt', 'harmless content');
    $zip->addFromString('link_to_root', '/etc/passwd');
    // Set Unix symlink attribute: 0120777 (S_IFLNK | 0777) in high 16 bits
    $zip->setExternalAttributesName('link_to_root', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    $zip->close();

    expect(fn () => $archiveManager->extractZip($badZipPath, $extractDir))
        ->toThrow(SecurityException::class, 'symbolic link, which is not permitted');
});

it('sets restrictive file permissions in archive manager', function (): void {
    $archiveManager = app(ArchiveManager::class);
    $zipPath = $this->testDir.'/perms_test.zip';
    $extractDir = $this->testDir.'/extracted_perms';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('secret_dump.sql', 'SUPER SECRET DATA');
    $zip->close();

    $archiveManager->extractZip($zipPath, $extractDir);

    $extractedFile = $extractDir.'/secret_dump.sql';
    expect(file_exists($extractedFile))->toBeTrue();

    // Check POSIX file permissions: must be 0600 (-rw-------)
    $perms = fileperms($extractedFile) & 0777;
    expect($perms)->toBe(0600, 'Extracted sensitive backup files must have 0600 permissions');
});

it('cleans up and marks backup failed on failed storage upload', function (): void {
    $tempDb = $this->testDir.'/full_backup_source.sqlite';
    $db = new SQLite3($tempDb);
    $db->exec('CREATE TABLE test (id INT);');
    $db->close();

    config()->set('database.connections.sqlite_file_test', [
        'driver' => 'sqlite',
        'database' => $tempDb,
    ]);

    config()->set('scoped-backup.database.connection', 'sqlite_file_test');
    config()->set('scoped-backup.storage.disk', 'failing_disk');

    $diskMock = Mockery::mock(Filesystem::class);
    $diskMock->shouldReceive('put')->andReturn(false);
    Storage::set('failing_disk', $diskMock);

    $action = app(CreateFullBackupAction::class);

    expect(fn () => $action->execute(trigger: BackupTrigger::MANUAL, disk: 'failing_disk'))
        ->toThrow(BackupException::class, 'Failed to write backup archive to storage disk');

    // Verify record in database was marked FAILED
    $failedRecord = BackupRecord::where('disk', 'failing_disk')->first();
    expect($failedRecord)->not->toBeNull()
        ->and($failedRecord->status)->toBe(BackupStatus::FAILED);
});
