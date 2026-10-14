<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\DTO\HealthCheckResult;

class UnhealthyBackupDetected
{
    public function __construct(
        public readonly HealthCheckResult $result,
    ) {}
}
