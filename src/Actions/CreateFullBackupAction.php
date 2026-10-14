<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Database\Contracts\DatabaseBackupDriver;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Events\BackupFailed;
use KaziSTM\LaravelScopedBackup\Events\BackupStarting;
use KaziSTM\LaravelScopedBackup\Events\BackupSuccessful;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupProcessException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use Throwable;

class CreateFullBackupAction
{
    public function __construct(
        protected readonly DatabaseBackupDriverManager $dumperManager,
        protected readonly ArchiveManager $archiveManager,
        protected readonly ChecksumValidator $checksumValidator,
        protected readonly BackupLockManager $lockManager,
        protected readonly PruneBackupsAction $pruner,
    ) {}

    /**
     * Create a verified full database and optional filesystem backup packaged into a ZIP archive with manifest.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws Throwable
     */
    public function execute(
        BackupTrigger $trigger = BackupTrigger::MANUAL,
        ?string $connection = null,
        ?string $disk = null,
        ?string $path = null,
        ?int $retentionLimit = null,
        ?bool $includeFiles = null,
        array $options = [],
    ): BackupRecord {
        $startTime = microtime(true);

        // 1. Acquire execution concurrency lock
        $lock = $this->lockManager->acquireExecutionLock(
            (int) config('scoped-backup.database.timeout', 600)
        );

        $connName = $connection ?? (string) config('scoped-backup.database.connection', config('database.default', 'pgsql'));
        $connConfig = (array) config("database.connections.{$connName}", []);

        $driver = (string) ($connConfig['driver'] ?? '');
        $databaseName = (string) ($connConfig['database'] ?? '');
        $host = (string) ($connConfig['host'] ?? '127.0.0.1');
        $diskName = $disk ?? (string) config('scoped-backup.storage.disk', 'local');
        $basePath = trim($path ?? (string) config('scoped-backup.storage.path', 'backups'), '/');
        $shouldIncludeFiles = $includeFiles ?? (bool) config('scoped-backup.filesystem.enabled', false);

        // 2. Resolve database dumper / driver
        $dumper = $this->dumperManager->resolve($driver);

        if ($dumper instanceof DatabaseBackupDriver) {
            $avail = $dumper->checkAvailability();
            if (! $avail['available']) {
                throw new BackupProcessException(
                    "Database driver [{$driver}] is not available: {$avail['message']}"
                );
            }
        }

        // 3. Create tracking record in RUNNING status
        $modelClass = config('scoped-backup.model', BackupRecord::class);
        /** @var BackupRecord $backup */
        $backup = $modelClass::create([
            'status' => BackupStatus::RUNNING,
            'trigger' => $trigger,
            'driver' => $driver,
            'database_name' => $databaseName,
            'host' => $host,
            'disk' => $diskName,
            'started_at' => now(),
            'metadata' => [
                'backup_type' => BackupType::FULL->value,
                'include_files' => $shouldIncludeFiles,
            ],
        ]);

        Event::dispatch(new BackupStarting(BackupType::FULL, $trigger, $backup));

        $stagingDir = (string) config('scoped-backup.storage.temp_staging_dir', storage_path('framework/cache/backups'));
        if (! File::isDirectory($stagingDir)) {
            File::makeDirectory($stagingDir, 0755, true, true);
        }

        $uniqueId = uniqid('', true);
        $ext = ($dumper instanceof DatabaseBackupDriver)
            ? $dumper->getArchiveExtension()
            : 'dump';
        $stagingDumpFile = $stagingDir."/dump_{$uniqueId}.{$ext}";
        $stagingZipFile = $stagingDir."/pkg_{$uniqueId}.zip";

        try {
            Log::info("Starting {$trigger->value} full backup for [{$databaseName}] on [{$host}]...");

            // 4. Execute database dump into temporary staging file
            $dumpMeta = $dumper->dump($connConfig, $stagingDumpFile);

            // 5. Verify database archive integrity and TOC
            $verifyMeta = $dumper->verifyArchive($stagingDumpFile);

            // 6. Compute dump checksum
            $dumpSha256 = $this->checksumValidator->sha256($stagingDumpFile);

            // 7. Prepare filesystem inclusions if requested
            $directoryInclusions = [];
            $fileCount = 0;

            if ($shouldIncludeFiles) {
                $includePaths = (array) config('scoped-backup.filesystem.include_paths', []);
                foreach ($includePaths as $sourcePath) {
                    if (File::isDirectory($sourcePath)) {
                        $directoryInclusions['files/'.basename($sourcePath)] = $sourcePath;
                    }
                }
            }

            // 8. Generate manifest
            $manifest = new BackupManifest(
                schemaVersion: '1.0',
                backupType: BackupType::FULL,
                createdAt: now()->toIso8601String(),
                environment: (string) config('app.env', 'production'),
                driver: $driver,
                databaseName: $databaseName,
                counts: [
                    'toc_entries' => (int) ($verifyMeta['toc_entries_count'] ?? 0),
                    'files' => $fileCount,
                ],
                checksums: [
                    'database_dump_sha256' => $dumpSha256,
                ],
                metadata: array_merge($dumpMeta, $verifyMeta, [
                    'include_files' => $shouldIncludeFiles,
                    'laravel_version' => app()->version(),
                ]),
            );

            // 9. Package into ZIP archive
            $this->archiveManager->createZip(
                zipPath: $stagingZipFile,
                files: [
                    'database.dump' => $stagingDumpFile,
                ],
                directories: $directoryInclusions,
                manifest: $manifest,
                excludePatterns: (array) config('scoped-backup.filesystem.exclude_paths', []),
            );

            // 10. Compute final archive metrics
            $zipSizeBytes = File::size($stagingZipFile);
            $zipSha256 = $this->checksumValidator->sha256($stagingZipFile);

            // 11. Stream archive to destination disk atomically
            $timestamp = now()->format('Y-m-d_His');
            $uniqueSuffix = substr(bin2hex(random_bytes(4)), 0, 8);
            $cleanDbName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $databaseName) ?: 'database';
            $filename = "{$cleanDbName}_{$timestamp}_{$uniqueSuffix}.zip";
            $targetPath = $basePath !== '' ? "{$basePath}/{$filename}" : $filename;

            $stream = fopen($stagingZipFile, 'rb');
            if ($stream === false) {
                throw new BackupException("Failed to open staging ZIP archive at [{$stagingZipFile}] for reading.");
            }

            $uploaded = Storage::disk($diskName)->put($targetPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($uploaded === false) {
                throw new BackupException("Failed to write backup archive to storage disk [{$diskName}] at [{$targetPath}].");
            }

            // Clean up staging files
            File::delete($stagingDumpFile);
            File::delete($stagingZipFile);

            $duration = round(microtime(true) - $startTime, 3);

            // 12. Mark record as SUCCEEDED
            $backup->update([
                'status' => BackupStatus::SUCCEEDED,
                'file_path' => $targetPath,
                'file_size_bytes' => $zipSizeBytes,
                'checksum_sha256' => $zipSha256,
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'metadata' => array_merge(
                    $backup->metadata ?? [],
                    $dumpMeta,
                    $verifyMeta,
                    $manifest->toArray(),
                    [
                        'dump_sha256' => $dumpSha256,
                    ]
                ),
            ]);

            Event::dispatch(new BackupSuccessful($backup));

            Log::info("Full backup succeeded for [{$databaseName}]", [
                'backup_id' => $backup->id,
                'disk' => $diskName,
                'path' => $targetPath,
                'size_bytes' => $zipSizeBytes,
                'duration_seconds' => $duration,
            ]);

            // 13. Enforce retention policy
            $this->pruner->execute($retentionLimit, BackupType::FULL);

            return $backup;
        } catch (Throwable $e) {
            if (File::exists($stagingDumpFile)) {
                File::delete($stagingDumpFile);
            }
            if (File::exists($stagingZipFile)) {
                File::delete($stagingZipFile);
            }

            $duration = round(microtime(true) - $startTime, 3);

            $backup->update([
                'status' => BackupStatus::FAILED,
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Event::dispatch(new BackupFailed($backup, $e));

            Log::error("Full backup failed for [{$databaseName}]: {$e->getMessage()}", [
                'backup_id' => $backup->id,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
