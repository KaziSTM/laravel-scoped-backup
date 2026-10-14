<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Unit;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Exceptions\SecurityException;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use ZipArchive;

final class ArchiveManagerTest extends TestCase
{
    private string $tempDir;

    private ArchiveManager $archiveManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/archive_tests_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true, true);
        $this->archiveManager = new ArchiveManager;
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_creates_and_reads_valid_zip_archive_with_manifest(): void
    {
        $dummyFile = $this->tempDir.'/dump.txt';
        File::put($dummyFile, 'DATABASE_DUMP_PAYLOAD');

        $manifest = new BackupManifest(
            schemaVersion: '1.0',
            backupType: BackupType::FULL,
            createdAt: now()->toIso8601String(),
            environment: 'testing',
            driver: 'pgsql',
            databaseName: 'test_db',
            counts: ['toc_entries' => 10],
            checksums: ['dump_sha256' => hash('sha256', 'DATABASE_DUMP_PAYLOAD')],
        );

        $zipPath = $this->tempDir.'/backup.zip';
        $this->archiveManager->createZip(
            zipPath: $zipPath,
            files: ['database.dump' => $dummyFile],
            manifest: $manifest,
        );

        $this->assertFileExists($zipPath);

        // Read manifest back
        $readManifest = $this->archiveManager->readManifest($zipPath);
        $this->assertSame('1.0', $readManifest->schemaVersion);
        $this->assertSame(BackupType::FULL, $readManifest->backupType);
        $this->assertSame('test_db', $readManifest->databaseName);
        $this->assertSame(10, $readManifest->counts['toc_entries']);

        // Extract and verify files
        $extractDir = $this->tempDir.'/extracted';
        $extractedFiles = $this->archiveManager->extractZip($zipPath, $extractDir);

        $this->assertContains('database.dump', $extractedFiles);
        $this->assertContains('manifest.json', $extractedFiles);
        $this->assertSame('DATABASE_DUMP_PAYLOAD', File::get($extractDir.'/database.dump'));
    }

    public function test_extract_rejects_zip_slip_path_traversal(): void
    {
        $maliciousZip = $this->tempDir.'/malicious.zip';
        $zip = new ZipArchive;
        $zip->open($maliciousZip, ZipArchive::CREATE);
        $zip->addFromString('../evil.txt', 'MALICIOUS_CONTENT');
        $zip->close();

        $extractDir = $this->tempDir.'/extracted_safe';

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('illegal path traversal sequence');

        $this->archiveManager->extractZip($maliciousZip, $extractDir);
    }
}
