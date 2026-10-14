<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use Throwable;

class BackupFailed
{
    public function __construct(
        public readonly BackupRecord $record,
        public readonly Throwable $exception,
    ) {}
}
