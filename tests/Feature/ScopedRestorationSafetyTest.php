<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Enums\RestoreMode;
use KaziSTM\LaravelScopedBackup\Services\ScopedImporter;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->testDir = sys_get_temp_dir().'/scoped_safety_test_'.uniqid();
    mkdir($this->testDir, 0755, true);

    $this->createScopedArchive = function (array $records, array $metadata = []): string {
        $archivePath = $this->testDir.'/scoped_backup.zip';
        $jsonlPath = $this->testDir.'/records.jsonl';

        $handle = fopen($jsonlPath, 'wb');
        foreach ($records as $record) {
            fwrite($handle, json_encode($record)."\n");
        }
        fclose($handle);

        $checksum = hash_file('sha256', $jsonlPath);

        $manifest = new BackupManifest(
            schemaVersion: '1.0',
            backupType: BackupType::SCOPED,
            createdAt: now()->toIso8601String(),
            environment: 'testing',
            driver: 'sqlite',
            databaseName: ':memory:',
            counts: ['User' => count($records)],
            checksums: ['records_sha256' => $checksum],
            metadata: array_merge([
                'root_model' => User::class,
                'identity_keys' => ['email'],
            ], $metadata)
        );

        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->addFile($jsonlPath, 'records.jsonl');
        $zip->close();

        return $archivePath;
    };
});

afterEach(function (): void {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

it('suppresses model events by default in scoped importer', function (): void {
    $eventFired = false;
    User::saving(function () use (&$eventFired) {
        $eventFired = true;
    });

    $createArchive = $this->createScopedArchive;
    $archive = $createArchive([
        ['name' => 'Restored User', 'email' => 'restored@example.com', 'password' => 'secret'],
    ]);

    $importer = app(ScopedImporter::class);
    $stats = $importer->import($archive, ['without_events' => true]);

    expect($stats->recordsCreated)->toBe(1)
        ->and($eventFired)->toBeFalse('Model saving event should not fire when without_events is true');

    $this->assertDatabaseHas('users', ['email' => 'restored@example.com']);
});

it('skips ambiguous identity match in scoped importer', function (): void {
    // Create two users with identical names
    User::create(['name' => 'Duplicate Name', 'email' => 'user1@example.com', 'password' => 'secret']);
    User::create(['name' => 'Duplicate Name', 'email' => 'user2@example.com', 'password' => 'secret']);

    // Archive specifies identity key as ['name'] which is ambiguous
    $createArchive = $this->createScopedArchive;
    $archive = $createArchive([
        ['name' => 'Duplicate Name', 'email' => 'updated@example.com'],
    ], ['identity_keys' => ['name']]);

    $importer = app(ScopedImporter::class);
    $stats = $importer->import($archive);

    expect($stats->recordsSkipped)->toBe(1)
        ->and($stats->warnings)->not->toBeEmpty()
        ->and($stats->warnings[0])->toContain('Ambiguous identity match');

    // Neither user should have had their email changed
    $this->assertDatabaseHas('users', ['email' => 'user1@example.com']);
    $this->assertDatabaseHas('users', ['email' => 'user2@example.com']);
});

it('rolls back on transactional error in scoped importer', function (): void {
    User::create(['name' => 'Existing User', 'email' => 'existing@example.com', 'password' => 'secret']);

    // Second record causes a unique constraint violation (or exception)
    $createArchive = $this->createScopedArchive;
    $archive = $createArchive([
        ['name' => 'New User 1', 'email' => 'new1@example.com', 'password' => 'secret'],
        // Invalid data that violates unique constraint or fails
        ['name' => 'Conflicting User', 'email' => 'existing@example.com', 'id' => 999],
    ], ['identity_keys' => ['id']]); // ID 999 is new, so it tries INSERT with existing email

    $importer = app(ScopedImporter::class);

    try {
        $importer->import($archive, ['mode' => RestoreMode::TRANSACTIONAL_UPSERT]);
        test()->fail('Expected unique constraint exception');
    } catch (Throwable $e) {
        // First user should have been rolled back
        $this->assertDatabaseMissing('users', ['email' => 'new1@example.com']);
    }
});
