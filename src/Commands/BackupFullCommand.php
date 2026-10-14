<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Commands;

use Illuminate\Console\Command;
use KaziSTM\LaravelScopedBackup\Actions\CreateFullBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use Throwable;

class BackupFullCommand extends Command
{
    protected $signature = 'scoped-backup:full
                            {--trigger=manual : The backup trigger type (manual or scheduled)}
                            {--connection= : The database connection to backup}
                            {--disk= : Filesystem storage disk}
                            {--path= : Relative path within the storage disk}
                            {--retention= : Number of successful backups to retain}
                            {--include-files : Include configured filesystem paths}';

    protected $description = 'Create a verified full database and filesystem ZIP backup with retention tracking';

    public function handle(CreateFullBackupAction $createBackup): int
    {
        $triggerInput = (string) ($this->option('trigger') ?: 'manual');
        $trigger = BackupTrigger::tryFrom($triggerInput) ?? BackupTrigger::MANUAL;

        $connection = $this->option('connection');
        $disk = $this->option('disk');
        $path = $this->option('path');
        $retention = $this->option('retention') !== null ? (int) $this->option('retention') : null;
        $includeFiles = $this->option('include-files') ? true : null;

        $this->info("Initiating {$trigger->value} full backup...");

        try {
            $backup = $createBackup->execute(
                trigger: $trigger,
                connection: $connection !== null ? (string) $connection : null,
                disk: $disk !== null ? (string) $disk : null,
                path: $path !== null ? (string) $path : null,
                retentionLimit: $retention,
                includeFiles: $includeFiles,
            );

            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Backup ID', (string) $backup->id],
                    ['Status', $backup->status->value],
                    ['Trigger', $backup->trigger->value],
                    ['Database', $backup->database_name],
                    ['Driver', $backup->driver],
                    ['Host', (string) $backup->host],
                    ['Disk', $backup->disk],
                    ['File Path', (string) $backup->file_path],
                    ['Size', number_format($backup->file_size_bytes ?? 0).' bytes'],
                    ['SHA-256', (string) $backup->checksum_sha256],
                    ['Duration', "{$backup->duration_seconds}s"],
                    ['Completed At', (string) $backup->completed_at?->toIso8601String()],
                ]
            );

            $this->info('Full backup completed and verified successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Full backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
