<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Models\BackupRecord;

class BackupSuccessful
{
    public function __construct(
        public readonly BackupRecord $record,
    ) {}
}
