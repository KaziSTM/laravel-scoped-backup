<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Commands;

use Illuminate\Console\Command;
use KaziSTM\LaravelScopedBackup\Actions\VerifyBackupHealthAction;
use Throwable;

class BackupMonitorCommand extends Command
{
    protected $signature = 'scoped-backup:monitor';

    protected $description = 'Evaluate backup freshness, archive integrity, and historical size health';

    public function handle(VerifyBackupHealthAction $verifier): int
    {
        $this->info('Evaluating backup system health...');

        try {
            $result = $verifier->check();

            $rows = [];
            foreach ($result->checks as $check) {
                $statusFormatted = match ($check['status']) {
                    'passed' => '<info>PASSED</info>',
                    'warning' => '<comment>WARNING</comment>',
                    default => '<error>FAILED</error>',
                };

                $rows[] = [
                    $check['check'],
                    $statusFormatted,
                    $check['message'],
                ];
            }

            $this->table(['Check Name', 'Status', 'Details'], $rows);

            if (! $result->isHealthy) {
                $this->error('Backup health check failed. One or more critical checks failed.');

                return self::FAILURE;
            }

            if ($result->hasWarnings()) {
                $this->warn('Backup health check completed with warnings.');

                return self::SUCCESS;
            }

            $this->info('All backup health checks passed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Health monitor command failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
