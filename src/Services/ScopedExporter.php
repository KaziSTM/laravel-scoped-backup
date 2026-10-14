<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\Contracts\ScopedBackupDefinitionInterface;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\DTO\BackupStats;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;

class ScopedExporter
{
    public function __construct(
        protected readonly ChecksumValidator $checksumValidator,
    ) {}

    /**
     * Export a scoped model/dataset definition into the target directory.
     *
     * @return array{stats: BackupStats, manifest: BackupManifest}
     */
    public function export(ScopedBackupDefinitionInterface $definition, string $stagingDir): array
    {
        $startTime = microtime(true);
        $stats = new BackupStats;

        if (! File::isDirectory($stagingDir)) {
            File::makeDirectory($stagingDir, 0755, true, true);
        }

        $recordsFile = $stagingDir.'/records.jsonl';
        $entitiesDir = $stagingDir.'/entities';

        $exportWorker = function () use ($definition, $recordsFile, $entitiesDir, $stats): void {
            // 1. Export Primary Model Records in JSON Lines Stream
            $this->exportRootRecords($definition, $recordsFile, $stats);

            // 2. Export Supplementary Entities (e.g. Mappings / Auxiliary collections)
            $this->exportAdditionalEntities($definition, $entitiesDir, $stats);
        };

        $modelClass = $definition->getModelClass();
        /** @var Model $instance */
        $instance = new $modelClass;
        $connection = $instance->getConnection();

        // Snapshot Consistency Guarantee:
        // Execute inside a database transaction with configured isolation level if enabled.
        if ($definition->shouldUseTransaction()) {
            $driver = $connection->getDriverName();
            $isolationLevel = $definition->getIsolationLevel();

            $connection->transaction(function () use ($connection, $driver, $isolationLevel, $exportWorker) {
                // Set transaction isolation level as the initial statement in the active transaction
                if (in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
                    try {
                        $connection->statement("SET TRANSACTION ISOLATION LEVEL {$isolationLevel}");
                    } catch (\Throwable) {
                        // Fall back to default transaction level if setting fails or unsupported
                    }
                }

                $exportWorker();
            });
        } else {
            $exportWorker();
        }

        // 3. Compute Checksums
        $checksums = [];
        if (File::exists($recordsFile)) {
            $checksums['records_sha256'] = $this->checksumValidator->sha256($recordsFile);
        }

        if (File::isDirectory($entitiesDir)) {
            foreach (File::files($entitiesDir) as $entityFile) {
                $checksums['entities_'.basename($entityFile->getPathname(), '.json').'_sha256'] = $this->checksumValidator->sha256($entityFile->getPathname());
            }
        }

        // 4. Create and Write Manifest
        $manifest = new BackupManifest(
            schemaVersion: '1.0',
            backupType: BackupType::SCOPED,
            createdAt: now()->toIso8601String(),
            environment: (string) config('app.env', 'production'),
            driver: $connection->getDriverName(),
            databaseName: (string) $connection->getDatabaseName(),
            counts: $stats->entityCounts,
            checksums: $checksums,
            metadata: [
                'scope_name' => $definition->getName(),
                'root_model' => $definition->getModelClass(),
                'identity_keys' => $definition->getIdentityKeys(),
                'included_relations' => $definition->getIncludedRelations(),
                'transaction_enabled' => $definition->shouldUseTransaction(),
                'isolation_level' => $definition->getIsolationLevel(),
                'records_count' => $stats->recordsExported,
                'laravel_version' => app()->version(),
            ],
        );

        File::put($stagingDir.'/manifest.json', $manifest->toJson());

        $stats->durationSeconds = round(microtime(true) - $startTime, 3);

        return [
            'stats' => $stats,
            'manifest' => $manifest,
        ];
    }

    protected function exportRootRecords(
        ScopedBackupDefinitionInterface $definition,
        string $recordsFile,
        BackupStats $stats,
    ): void {
        $handle = fopen($recordsFile, 'wb');
        if ($handle === false) {
            throw new BackupException("Unable to open [{$recordsFile}] for writing.");
        }

        try {
            $modelClass = $definition->getModelClass();
            /** @var Model $instance */
            $instance = new $modelClass;
            $query = $instance->newQuery();

            // Apply query constraints
            if ($definition->getQueryCallback() !== null) {
                ($definition->getQueryCallback())($query);
            }

            // Eager load explicitly declared relations
            if (! empty($definition->getIncludedRelations())) {
                $query->with($definition->getIncludedRelations());
            }

            // Deterministic chunk ordering by primary key
            $keyName = $instance->getKeyName();
            if (is_string($keyName) && $keyName !== '') {
                $query->orderBy($instance->qualifyColumn($keyName), 'asc');
            }

            $transform = $definition->getTransformCallback();
            $chunkSize = $definition->getChunkSize();

            $query->chunk($chunkSize, function ($records) use ($handle, $stats, $transform, $definition) {
                foreach ($records as $record) {
                    $item = $record->getAttributes();
                    foreach ($record->getRelations() as $relName => $relVal) {
                        if ($relVal instanceof Model) {
                            $item[$relName] = $relVal->getAttributes();
                        } elseif ($relVal instanceof Collection) {
                            $item[$relName] = $relVal->map(fn ($r) => $r instanceof Model ? $r->getAttributes() : $r)->all();
                        }
                    }

                    if ($transform !== null) {
                        $item = $transform($record, $item);
                    }

                    $line = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    fwrite($handle, ($line ?: '{}')."\n");

                    $stats->incrementEntity($definition->getName(), 1);
                }
            });
        } finally {
            fclose($handle);
        }
    }

    protected function exportAdditionalEntities(
        ScopedBackupDefinitionInterface $definition,
        string $entitiesDir,
        BackupStats $stats,
    ): void {
        $entities = $definition->getAdditionalEntities();
        if (empty($entities)) {
            return;
        }

        if (! File::isDirectory($entitiesDir)) {
            File::makeDirectory($entitiesDir, 0755, true, true);
        }

        foreach ($entities as $key => $config) {
            $resolver = $config['resolver'];
            $data = $resolver($definition);

            if ($data instanceof Collection) {
                $data = $data->toArray();
            }

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            File::put("{$entitiesDir}/{$key}.json", $json ?: '{}');

            $count = is_array($data) ? count($data) : 1;
            $stats->recordAdditionalEntity("additional_{$key}", $count);
        }
    }
}
