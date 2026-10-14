<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Exceptions\SecurityException;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;


beforeEach(function () {
    $this->tempDir = storage_path('framework/testing/archive_tests_'.uniqid());
    File::makeDirectory($this->tempDir, 0755, true, true);
    $this->archiveManager = new ArchiveManager;
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('creates and reads valid zip archive with manifest', function () {
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

    expect(File::exists($zipPath))->toBeTrue();

    // Read manifest back
    $readManifest = $this->archiveManager->readManifest($zipPath);
    expect($readManifest->schemaVersion)->toBe('1.0')
        ->and($readManifest->backupType)->toBe(BackupType::FULL)
        ->and($readManifest->databaseName)->toBe('test_db')
        ->and($readManifest->counts['toc_entries'])->toBe(10);

    // Extract and verify files
    $extractDir = $this->tempDir.'/extracted';
    $extractedFiles = $this->archiveManager->extractZip($zipPath, $extractDir);

    expect($extractedFiles)->toContain('database.dump', 'manifest.json')
        ->and(File::get($extractDir.'/database.dump'))->toBe('DATABASE_DUMP_PAYLOAD');
});

test('extract rejects zip slip path traversal', function () {
    $maliciousZip = $this->tempDir.'/malicious.zip';
    $zip = new ZipArchive;
    $zip->open($maliciousZip, ZipArchive::CREATE);
    $zip->addFromString('../evil.txt', 'MALICIOUS_CONTENT');
    $zip->close();

    $extractDir = $this->tempDir.'/extracted_safe';

    expect(fn () => $this->archiveManager->extractZip($maliciousZip, $extractDir))
        ->toThrow(SecurityException::class, 'illegal path traversal sequence');
});
