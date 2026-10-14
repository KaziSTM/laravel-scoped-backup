<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\RestoreStats;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Enums\RestoreMode;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;

class ScopedImporter
{
    public function __construct(
        protected readonly ArchiveManager $archiveManager,
        protected readonly ChecksumValidator $checksumValidator,
    ) {}

    /**
     * Restore a scoped dataset archive into the database.
     *
     * @param  array<string, mixed>  $options
     */
    public function import(string $archivePath, array $options = []): RestoreStats
    {
        $startTime = microtime(true);
        $stats = new RestoreStats;

        if (! File::exists($archivePath)) {
            throw new RestoreException("Archive file does not exist at [{$archivePath}].");
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $mode = isset($options['mode']) && $options['mode'] instanceof RestoreMode
            ? $options['mode']
            : ($dryRun ? RestoreMode::DRY_RUN : (RestoreMode::tryFrom((string) ($options['mode'] ?? 'transactional_upsert')) ?? RestoreMode::TRANSACTIONAL_UPSERT));

        $stats->isDryRun = ($mode === RestoreMode::DRY_RUN);

        $tempExtractionDir = storage_path('framework/cache/backups/restore_'.uniqid('', true));
        File::makeDirectory($tempExtractionDir, 0755, true, true);

        try {
            // 1. Safely extract archive with Zip Slip protections
            $this->archiveManager->extractZip($archivePath, $tempExtractionDir);

            // 2. Validate Manifest
            $manifestPath = $tempExtractionDir.'/manifest.json';
            if (! File::exists($manifestPath)) {
                throw new BackupVerificationException('Archive does not contain a manifest.json file.');
            }

            $manifest = $this->archiveManager->readManifest($archivePath);

            if ($manifest->backupType !== BackupType::SCOPED) {
                throw new RestoreException("Archive is a {$manifest->backupType->value} backup, not a scoped backup. Use full restore for this archive.");
            }

            // 3. Verify Checksum of records.jsonl if present in manifest
            $recordsFile = $tempExtractionDir.'/records.jsonl';
            if (File::exists($recordsFile) && isset($manifest->checksums['records_sha256'])) {
                $computedSha = $this->checksumValidator->sha256($recordsFile);
                if (! hash_equals($manifest->checksums['records_sha256'], $computedSha)) {
                    throw new BackupVerificationException("Checksum verification failed for records.jsonl. Expected [{$manifest->checksums['records_sha256']}], got [{$computedSha}].");
                }
            }

            // 4. Resolve Target Model
            $modelClass = (string) ($options['model'] ?? $manifest->metadata['root_model'] ?? '');
            if ($modelClass === '' || ! class_exists($modelClass)) {
                throw new RestoreException("Target root model class [{$modelClass}] is invalid or not found.");
            }

            // 5. Resolve Identity Keys
            /** @var array<int, string> $identityKeys */
            $identityKeys = (array) ($options['identity_keys'] ?? $manifest->metadata['identity_keys'] ?? []);

            $connectionName = (string) ($options['connection'] ?? config('database.default'));
            $connection = DB::connection($connectionName);

            // 6. Execute Restoration
            $withoutEvents = (bool) ($options['without_events'] ?? true);

            $restoreExecution = function () use ($recordsFile, $modelClass, $identityKeys, $mode, $stats, $connection): void {
                if (! File::exists($recordsFile)) {
                    return;
                }

                $handle = fopen($recordsFile, 'rb');
                if ($handle === false) {
                    throw new RestoreException('Failed to open records.jsonl for reading.');
                }

                try {
                    while (($line = fgets($handle)) !== false) {
                        $trimmed = trim($line);
                        if ($trimmed === '') {
                            continue;
                        }

                        $recordData = json_decode($trimmed, true);
                        if (! is_array($recordData)) {
                            $stats->addWarning('Skipped invalid JSON record in records.jsonl.');
                            $stats->recordsSkipped++;

                            continue;
                        }

                        $this->restoreSingleRecord($connection, $modelClass, $recordData, $identityKeys, $mode, $stats);
                    }
                } finally {
                    fclose($handle);
                }
            };

            $runRestore = function () use ($restoreExecution, $modelClass, $withoutEvents): void {
                if ($withoutEvents) {
                    $modelClass::withoutEvents(function () use ($restoreExecution) {
                        $restoreExecution();
                    });
                } else {
                    $restoreExecution();
                }
            };

            if ($mode === RestoreMode::TRANSACTIONAL_UPSERT) {
                $connection->transaction(function () use ($runRestore) {
                    $runRestore();
                });
            } else {
                $runRestore();
            }

            if ($mode !== RestoreMode::DRY_RUN) {
                $this->syncPostgresSequence($connection, new $modelClass);
            }

            $stats->durationSeconds = round(microtime(true) - $startTime, 3);

            return $stats;
        } finally {
            if (File::isDirectory($tempExtractionDir)) {
                File::deleteDirectory($tempExtractionDir);
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $identityKeys
     */
    protected function restoreSingleRecord(
        ConnectionInterface $connection,
        string $modelClass,
        array $data,
        array $identityKeys,
        RestoreMode $mode,
        RestoreStats $stats,
    ): void {
        /** @var Model $instance */
        $instance = new $modelClass;
        $instance->setConnection($connection->getName());

        // Extract nested relations if present in data array to prevent column missing errors
        $attributes = $this->filterNonColumnAttributes($instance, $data);

        if (empty($identityKeys)) {
            $keyName = $instance->getKeyName();
            if (is_string($keyName) && $keyName !== '') {
                $identityKeys = [$keyName];
            }
        }

        if (! empty($identityKeys)) {
            $matchCriteria = [];
            foreach ($identityKeys as $key) {
                if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                    $matchCriteria[$key] = $attributes[$key];
                }
            }

            if (count($matchCriteria) === count($identityKeys)) {
                $query = $instance->newQuery();
                foreach ($matchCriteria as $col => $val) {
                    $query->where($col, $val);
                }

                $matchedCount = $query->count();
                if ($matchedCount > 1) {
                    $stats->addWarning("Ambiguous identity match: {$matchedCount} records found on [{$modelClass}] for criteria. Record skipped to prevent data corruption.");
                    $stats->recordsSkipped++;

                    return;
                }

                $existing = $query->first();

                if ($existing !== null) {
                    if ($mode === RestoreMode::DRY_RUN) {
                        $stats->recordsUpdated++;

                        return;
                    }

                    $existing->forceFill($attributes);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $stats->recordsUpdated++;
                    } else {
                        $stats->recordsUnchanged++;
                    }

                    return;
                }
            }
        }

        // New record insertion
        if ($mode === RestoreMode::DRY_RUN) {
            $stats->recordsCreated++;

            return;
        }

        $newInstance = $instance->newInstance([], false);
        $newInstance->setConnection($connection->getName());
        $newInstance->forceFill($attributes);
        $newInstance->save();
        $stats->recordsCreated++;
    }

    /**
     * Remove known nested array relations so forceFill() targets table columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterNonColumnAttributes(Model $model, array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            // Strip out relation arrays (e.g. ['translations' => [...], 'sectors' => [...]])
            // but preserve genuine JSON casted array attributes
            if (is_array($value) && method_exists($model, $key) && ! $model->hasCast($key)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * Synchronize PostgreSQL auto-increment sequence after explicit ID inserts.
     */
    protected function syncPostgresSequence(ConnectionInterface $connection, Model $model): void
    {
        if ($connection->getDriverName() === 'pgsql' && $model->getIncrementing()) {
            $keyName = $model->getKeyName();
            $table = $model->getTable();
            try {
                $connection->statement(
                    "SELECT setval(pg_get_serial_sequence('{$table}', '{$keyName}'), COALESCE(MAX({$keyName}), 1)) FROM {$table}"
                );
            } catch (\Throwable) {
                // Ignore if sequence cannot be resolved or is managed externally
            }
        }
    }
}
