<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use KaziSTM\LaravelScopedBackup\DTO\HealthCheckResult;
use KaziSTM\LaravelScopedBackup\Events\UnhealthyBackupDetected;
use KaziSTM\LaravelScopedBackup\Services\HealthMonitor;

class VerifyBackupHealthAction
{
    public function __construct(
        protected readonly HealthMonitor $monitor,
    ) {}

    public function check(): HealthCheckResult
    {
        $result = $this->monitor->check();

        if (! $result->isHealthy || $result->hasWarnings()) {
            Event::dispatch(new UnhealthyBackupDetected($result));

            foreach ($result->failures() as $failure) {
                Log::error("Backup Health Alert [{$failure['check']}]: {$failure['message']}", $failure['context'] ?? []);
            }

            foreach ($result->warnings() as $warning) {
                Log::warning("Backup Health Warning [{$warning['check']}]: {$warning['message']}", $warning['context'] ?? []);
            }
        }

        return $result;
    }
}
