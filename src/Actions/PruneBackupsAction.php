<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\BackupLockManager;

class PruneBackupsAction
{
    public function __construct(
        protected readonly BackupLockManager $lockManager,
    ) {}

    /**
     * Prune expired backups according to retention policy.
     * Retains the most recent N successful, unpruned backups.
     *
     * @param  int|null  $retentionLimit  Override the configured retention limit
     * @param  BackupType|null  $type  Optionally filter by backup type
     * @return int Number of backups pruned
     */
    public function execute(?int $retentionLimit = null, ?BackupType $type = null): int
    {
        $limit = $retentionLimit ?? (int) config('scoped-backup.retention.limit', 7);
        if ($limit < 1) {
            $limit = 1;
        }

        $lock = $this->lockManager->acquirePruneLock(60);

        return $lock->get(function () use ($limit, $type): int {
            $query = BackupRecord::successful()
                ->unpruned()
                ->latestCompleted();

            $successfulBackups = $query->get();

            if ($type !== null) {
                $successfulBackups = $successfulBackups->filter(fn (BackupRecord $b) => $b->getBackupType() === $type)->values();
            }

            if ($successfulBackups->count() <= $limit) {
                return 0;
            }

            // Retention safeguard: Never delete the last remaining successful backup
            if ((bool) config('scoped-backup.retention.protect_last_successful', true) && $successfulBackups->count() <= 1) {
                return 0;
            }

            $toPrune = $successfulBackups->slice($limit);
            $prunedCount = 0;

            foreach ($toPrune as $backup) {
                $diskName = $backup->disk ?: (string) config('scoped-backup.storage.disk', 'local');
                $disk = Storage::disk($diskName);
                $filePath = $backup->file_path;

                if ($filePath !== null && $filePath !== '') {
                    $basePath = trim((string) config('scoped-backup.storage.path', 'backups'), '/');
                    $normalized = ltrim($filePath, '/');

                    // Strict path validation: reject directory traversal and absolute filesystem paths
                    if (str_contains($normalized, '..') || str_starts_with($filePath, '/') || str_starts_with($filePath, '\\')) {
                        Log::warning("Skipped deleting artifact for unsafe backup path [{$filePath}].");
                        $backup->update(['pruned_at' => now()]);
                        $prunedCount++;

                        continue;
                    }

                    if ($disk->exists($normalized)) {
                        $disk->delete($normalized);
                    }
                }

                $backup->update([
                    'pruned_at' => now(),
                ]);

                $prunedCount++;

                Log::info("Pruned expired backup [ID: {$backup->id}] artifact [{$backup->file_path}] (Disk: {$diskName})");
            }

            return $prunedCount;
        }) ?? 0;
    }
}
