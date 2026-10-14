<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Commands;

use Illuminate\Console\Command;
use KaziSTM\LaravelScopedBackup\Actions\RestoreFullBackupAction;
use KaziSTM\LaravelScopedBackup\Actions\RestoreScopedBackupAction;
use KaziSTM\LaravelScopedBackup\Enums\RestoreMode;
use Throwable;

class BackupRestoreCommand extends Command
{
    protected $signature = 'scoped-backup:restore
                            {target : Backup ID, archive file path, or database dump path}
                            {--type=scoped : Type of backup to restore (scoped or full)}
                            {--connection= : Target database connection for restore}
                            {--dry-run : Simulate restoration without persisting modifications (scoped only)}
                            {--verify-only : Inspect and verify archive without restoring (full only)}
                            {--restore-files : Restore included filesystem assets (full only)}
                            {--force : Force destructive restoration in production}';

    protected $description = 'Safely restore a full database backup or scoped dataset archive';

    public function handle(
        RestoreScopedBackupAction $restoreScoped,
        RestoreFullBackupAction $restoreFull,
    ): int {
        $target = (string) $this->argument('target');
        $type = strtolower((string) ($this->option('type') ?: 'scoped'));
        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $dryRun = (bool) $this->option('dry-run');
        $verifyOnly = (bool) $this->option('verify-only');
        $restoreFiles = (bool) $this->option('restore-files');
        $force = (bool) $this->option('force');

        if ($type === 'full') {
            $this->warn("Initiating FULL database restore from [{$target}]...");

            if (! $verifyOnly && ! $force && config('app.env') === 'production') {
                if (! $this->confirm('WARNING: You are about to restore a database backup in PRODUCTION. Are you absolutely sure?')) {
                    $this->info('Full database restoration cancelled by user.');

                    return self::SUCCESS;
                }

                $force = true;
            }

            try {
                $backupArg = is_numeric($target) ? (int) $target : $target;
                $result = $restoreFull->execute(
                    backup: $backupArg,
                    targetConnection: $connection,
                    force: $force,
                    verifyOnly: $verifyOnly,
                    restoreFiles: $restoreFiles,
                );

                if ($verifyOnly) {
                    $this->info('Full backup pre-flight verification passed successfully.');
                    $this->table(['Key', 'Value'], [
                        ['Verified', 'Yes'],
                        ['Driver', $result['manifest']['driver'] ?? 'pgsql'],
                        ['Database', $result['manifest']['database_name'] ?? 'N/A'],
                        ['TOC Entries', (string) ($result['verification']['toc_entries_count'] ?? 0)],
                    ]);
                } else {
                    $this->info('Full database restore completed successfully.');
                }

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->error("Full restore failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        // Scoped Dataset Restore
        $this->info("Initiating SCOPED dataset restore from [{$target}]".($dryRun ? ' (DRY RUN)' : '').'...');

        try {
            $stats = $restoreScoped->execute(
                archivePath: $target,
                options: [
                    'connection' => $connection,
                    'dry_run' => $dryRun,
                    'mode' => $dryRun ? RestoreMode::DRY_RUN : RestoreMode::TRANSACTIONAL_UPSERT,
                ],
            );

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Records Created', (string) $stats->recordsCreated],
                    ['Records Updated', (string) $stats->recordsUpdated],
                    ['Records Unchanged', (string) $stats->recordsUnchanged],
                    ['Records Skipped', (string) $stats->recordsSkipped],
                    ['Duration', "{$stats->durationSeconds}s"],
                    ['Dry Run Mode', $stats->isDryRun ? 'Yes' : 'No'],
                ]
            );

            if ($stats->hasErrors()) {
                $this->warn('Restore completed with errors:');
                foreach ($stats->errors as $err) {
                    $this->line("  - {$err}");
                }
            } else {
                $this->info('Scoped dataset restore completed successfully.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Scoped restore failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
