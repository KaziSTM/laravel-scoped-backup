<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Enums;

enum BackupStatus: string
{
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
