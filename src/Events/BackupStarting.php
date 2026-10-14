<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Enums\BackupTrigger;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

class BackupStarting
{
    public function __construct(
        public readonly BackupType $type,
        public readonly BackupTrigger $trigger,
        public readonly BackupRecord $record,
    ) {}
}
