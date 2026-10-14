<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Contracts\ScopedBackupDefinitionInterface;
use KaziSTM\LaravelScopedBackup\DTO\BackupStats;
use KaziSTM\LaravelScopedBackup\Enums\BackupStatus;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Events\BackupFailed;
use KaziSTM\LaravelScopedBackup\Events\BackupStarting;
use KaziSTM\LaravelScopedBackup\Events\BackupSuccessful;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use KaziSTM\LaravelScopedBackup\Services\ScopedExporter;
use Throwable;

class CreateScopedBackupAction
{
    public function __construct(
        protected readonly ScopedExporter $exporter,
        protected readonly ArchiveManager $archiveManager,
        protected readonly ChecksumValidator $checksumValidator,
        protected readonly BackupLockManager $lockManager,
        protected readonly PruneBackupsAction $pruner,
    ) {}

    /**
     * Create a verified portable scoped dataset backup archive.
     *
     * @param  array<string, mixed>  $options
     * @return array{record: BackupRecord, stats: BackupStats}
     *
     * @throws Throwable
     */
    public function execute(
        ScopedBackupDefinitionInterface $definition,
        BackupTrigger $trigger = BackupTrigger::MANUAL,
        ?string $disk = null,
        ?string $path = null,
        ?int $retentionLimit = null,
        array $options = [],
    ): array {
        $startTime = microtime(true);

        // 1. Acquire scope concurrency lock
        $lock = $this->lockManager->acquireScopedLock($definition->getName(), 600);

        $diskName = $disk ?? (string) config('scoped-backup.storage.disk', 'local');
        $basePath = trim($path ?? (string) config('scoped-backup.storage.path', 'backups'), '/');
        $modelClass = $definition->getModelClass();
        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;
        $connection = $modelInstance->getConnection();
        $connName = $modelInstance->getConnectionName() ?: config('database.default');
        $driver = $connection->getDriverName();
        $databaseName = (string) $connection->getDatabaseName();
        $host = (string) (config("database.connections.{$connName}.host") ?? '127.0.0.1');

        // 2. Create tracking record in RUNNING status
        $recordModelClass = config('scoped-backup.model', BackupRecord::class);
        /** @var BackupRecord $backup */
        $backup = $recordModelClass::create([
            'status' => BackupStatus::RUNNING,
            'trigger' => $trigger,
            'driver' => $driver,
            'database_name' => $databaseName,
            'host' => $host,
            'disk' => $diskName,
            'started_at' => now(),
            'metadata' => [
                'backup_type' => BackupType::SCOPED->value,
                'scope_name' => $definition->getName(),
                'root_model' => $definition->getModelClass(),
            ],
        ]);

        Event::dispatch(new BackupStarting(BackupType::SCOPED, $trigger, $backup));

        $stagingBase = (string) config('scoped-backup.storage.temp_staging_dir', storage_path('framework/cache/backups'));
        $uniqueId = uniqid('', true);
        $stagingDir = "{$stagingBase}/scoped_{$uniqueId}";
        $stagingZipFile = "{$stagingBase}/scoped_pkg_{$uniqueId}.zip";

        try {
            Log::info("Starting {$trigger->value} scoped backup for definition [{$definition->getName()}]...");

            // 3. Export records into staging directory
            $exportResult = $this->exporter->export($definition, $stagingDir);
            $stats = $exportResult['stats'];
            $manifest = $exportResult['manifest'];

            // 4. Package directory into ZIP archive
            $filesToZip = [];
            if (File::exists("{$stagingDir}/records.jsonl")) {
                $filesToZip['records.jsonl'] = "{$stagingDir}/records.jsonl";
            }

            $directoriesToZip = [];
            if (File::isDirectory("{$stagingDir}/entities")) {
                $directoriesToZip['entities'] = "{$stagingDir}/entities";
            }

            $this->archiveManager->createZip(
                zipPath: $stagingZipFile,
                files: $filesToZip,
                directories: $directoriesToZip,
                manifest: $manifest,
            );

            // 5. Measure archive metrics
            $zipSizeBytes = File::size($stagingZipFile);
            $zipSha256 = $this->checksumValidator->sha256($stagingZipFile);

            $stats->fileSizeBytes = $zipSizeBytes;
            $stats->checksumSha256 = $zipSha256;

            // 6. Stream to target storage disk atomically
            $timestamp = now()->format('Y-m-d_His');
            $uniqueSuffix = substr(bin2hex(random_bytes(4)), 0, 8);
            $cleanScope = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $definition->getName()) ?: 'scoped';
            $filename = "scoped_{$cleanScope}_{$timestamp}_{$uniqueSuffix}.zip";
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

            // Clean up staging directory and zip
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
            if (File::exists($stagingZipFile)) {
                File::delete($stagingZipFile);
            }

            $duration = round(microtime(true) - $startTime, 3);
            $stats->durationSeconds = $duration;

            // 7. Mark record as SUCCEEDED
            $backup->update([
                'status' => BackupStatus::SUCCEEDED,
                'file_path' => $targetPath,
                'file_size_bytes' => $zipSizeBytes,
                'checksum_sha256' => $zipSha256,
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'metadata' => array_merge($backup->metadata ?? [], $manifest->toArray(), [
                    'records_count' => $stats->recordsExported,
                ]),
            ]);

            Event::dispatch(new BackupSuccessful($backup));

            Log::info("Scoped backup succeeded for [{$definition->getName()}]", [
                'backup_id' => $backup->id,
                'disk' => $diskName,
                'path' => $targetPath,
                'records_exported' => $stats->recordsExported,
                'size_bytes' => $zipSizeBytes,
                'duration_seconds' => $duration,
            ]);

            // 8. Enforce retention policy for scoped backups
            $this->pruner->execute($retentionLimit, BackupType::SCOPED);

            return [
                'record' => $backup,
                'stats' => $stats,
            ];
        } catch (Throwable $e) {
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
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

            Log::error("Scoped backup failed for [{$definition->getName()}]: {$e->getMessage()}", [
                'backup_id' => $backup->id,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
