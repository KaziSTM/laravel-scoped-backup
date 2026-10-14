<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Enums;

enum BackupTrigger: string
{
    case MANUAL = 'manual';
    case SCHEDULED = 'scheduled';
    case AUTOMATED_JOB = 'automated_job';
}
