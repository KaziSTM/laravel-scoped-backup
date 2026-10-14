<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use KaziSTM\LaravelScopedBackup\DTO\RestoreStats;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Events\RestoreFailed;
use KaziSTM\LaravelScopedBackup\Events\RestoreStarting;
use KaziSTM\LaravelScopedBackup\Events\RestoreSuccessful;
use KaziSTM\LaravelScopedBackup\Services\ScopedImporter;
use Throwable;

class RestoreScopedBackupAction
{
    public function __construct(
        protected readonly ScopedImporter $importer,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function execute(string $archivePath, array $options = []): RestoreStats
    {
        Event::dispatch(new RestoreStarting(BackupType::SCOPED, $archivePath, $options));
        Log::info("Starting scoped dataset restoration from [{$archivePath}]...");

        try {
            $stats = $this->importer->import($archivePath, $options);

            Event::dispatch(new RestoreSuccessful(BackupType::SCOPED, $archivePath, (array) $stats));

            Log::info('Scoped dataset restoration completed', [
                'archive' => $archivePath,
                'created' => $stats->recordsCreated,
                'updated' => $stats->recordsUpdated,
                'dry_run' => $stats->isDryRun,
                'duration' => $stats->durationSeconds,
            ]);

            return $stats;
        } catch (Throwable $e) {
            Event::dispatch(new RestoreFailed(BackupType::SCOPED, $archivePath, $e));

            Log::error("Scoped dataset restoration failed: {$e->getMessage()}", [
                'archive' => $archivePath,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
