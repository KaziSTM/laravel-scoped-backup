<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Enums;

enum BackupType: string
{
    case FULL = 'full';
    case SCOPED = 'scoped';
}
