<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Commands;

use Illuminate\Console\Command;
use KaziSTM\LaravelScopedBackup\Actions\PruneBackupsAction;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use Throwable;

class BackupPruneCommand extends Command
{
    protected $signature = 'scoped-backup:prune
                            {--retention= : Override retention count limit}
                            {--type= : Filter by backup type (full or scoped)}';

    protected $description = 'Prune expired backup archives according to retention policy';

    public function handle(PruneBackupsAction $pruner): int
    {
        $retention = $this->option('retention') !== null ? (int) $this->option('retention') : null;
        $typeInput = $this->option('type') ? (string) $this->option('type') : null;
        $type = $typeInput !== null ? BackupType::tryFrom($typeInput) : null;

        $this->info('Pruning expired backups...');

        try {
            $count = $pruner->execute(
                retentionLimit: $retention,
                type: $type,
            );

            $this->info("Pruning complete. Pruned {$count} expired backup(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Pruning failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
