<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Commands;

use Illuminate\Console\Command;
use KaziSTM\LaravelScopedBackup\Actions\CreateScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;
use Throwable;

class BackupScopedCommand extends Command
{
    protected $signature = 'scoped-backup:scoped
                            {model : Fully qualified Eloquent model class to snapshot}
                            {--name= : Name identifier for the scoped dataset}
                            {--relations=* : Explicit relationships to include}
                            {--identity=* : Natural identity key(s) for record matching}
                            {--chunk=100 : Chunk size for streaming export}
                            {--disk= : Filesystem storage disk}
                            {--path= : Relative path within the storage disk}
                            {--retention= : Retention limit for scoped backups}';

    protected $description = 'Create a scoped JSON Lines model or dataset snapshot';

    public function handle(CreateScopedBackupAction $action): int
    {
        $model = (string) $this->argument('model');
        $name = (string) ($this->option('name') ?: class_basename($model));
        $relations = (array) $this->option('relations');
        $identity = (array) $this->option('identity');
        $chunk = (int) $this->option('chunk');
        $disk = $this->option('disk') ? (string) $this->option('disk') : null;
        $path = $this->option('path') ? (string) $this->option('path') : null;
        $retention = $this->option('retention') !== null ? (int) $this->option('retention') : null;

        $this->info("Creating scoped backup for [{$model}] (Scope: {$name})...");

        try {
            $definition = ScopedBackupDefinition::make($name)
                ->model($model)
                ->chunkSize($chunk);

            if (! empty($relations)) {
                $definition->include($relations);
            }

            if (! empty($identity)) {
                $definition->identity($identity);
            }

            $result = $action->execute(
                definition: $definition,
                trigger: BackupTrigger::MANUAL,
                disk: $disk,
                path: $path,
                retentionLimit: $retention,
            );

            $backup = $result['record'];
            $stats = $result['stats'];

            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Backup ID', (string) $backup->id],
                    ['Scope Name', $name],
                    ['Model', $model],
                    ['Records Exported', (string) $stats->recordsExported],
                    ['Archive Path', (string) $backup->file_path],
                    ['Size', number_format($backup->file_size_bytes ?? 0).' bytes'],
                    ['SHA-256', (string) $backup->checksum_sha256],
                    ['Duration', "{$backup->duration_seconds}s"],
                ]
            );

            $this->info('Scoped backup completed successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Scoped backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
