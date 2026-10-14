<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Feature;

use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Enums\RestoreMode;
use KaziSTM\LaravelScopedBackup\Services\ScopedImporter;
use KaziSTM\LaravelScopedBackup\Tests\Fixtures\User;
use KaziSTM\LaravelScopedBackup\Tests\TestCase;
use Throwable;
use ZipArchive;

class ScopedRestorationSafetyTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir().'/scoped_safety_test_'.uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    protected function createScopedArchive(array $records, array $metadata = []): string
    {
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
    }

    public function test_scoped_importer_suppresses_model_events_by_default(): void
    {
        $eventFired = false;
        User::saving(function () use (&$eventFired) {
            $eventFired = true;
        });

        $archive = $this->createScopedArchive([
            ['name' => 'Restored User', 'email' => 'restored@example.com', 'password' => 'secret'],
        ]);

        $importer = app(ScopedImporter::class);
        $stats = $importer->import($archive, ['without_events' => true]);

        $this->assertEquals(1, $stats->recordsCreated);
        $this->assertFalse($eventFired, 'Model saving event should not fire when without_events is true');

        $this->assertDatabaseHas('users', ['email' => 'restored@example.com']);
    }

    public function test_scoped_importer_skips_ambiguous_identity_match(): void
    {
        // Create two users with identical names
        User::create(['name' => 'Duplicate Name', 'email' => 'user1@example.com', 'password' => 'secret']);
        User::create(['name' => 'Duplicate Name', 'email' => 'user2@example.com', 'password' => 'secret']);

        // Archive specifies identity key as ['name'] which is ambiguous
        $archive = $this->createScopedArchive([
            ['name' => 'Duplicate Name', 'email' => 'updated@example.com'],
        ], ['identity_keys' => ['name']]);

        $importer = app(ScopedImporter::class);
        $stats = $importer->import($archive);

        $this->assertEquals(1, $stats->recordsSkipped);
        $this->assertNotEmpty($stats->warnings);
        $this->assertStringContainsString('Ambiguous identity match', $stats->warnings[0]);

        // Neither user should have had their email changed
        $this->assertDatabaseHas('users', ['email' => 'user1@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'user2@example.com']);
    }

    public function test_scoped_importer_rolls_back_on_transactional_error(): void
    {
        User::create(['name' => 'Existing User', 'email' => 'existing@example.com', 'password' => 'secret']);

        // Second record causes a unique constraint violation (or exception)
        $archive = $this->createScopedArchive([
            ['name' => 'New User 1', 'email' => 'new1@example.com', 'password' => 'secret'],
            // Invalid data that violates unique constraint or fails
            ['name' => 'Conflicting User', 'email' => 'existing@example.com', 'id' => 999],
        ], ['identity_keys' => ['id']]); // ID 999 is new, so it tries INSERT with existing email

        $importer = app(ScopedImporter::class);

        try {
            $importer->import($archive, ['mode' => RestoreMode::TRANSACTIONAL_UPSERT]);
            $this->fail('Expected unique constraint exception');
        } catch (Throwable $e) {
            // First user should have been rolled back
            $this->assertDatabaseMissing('users', ['email' => 'new1@example.com']);
        }
    }
}
