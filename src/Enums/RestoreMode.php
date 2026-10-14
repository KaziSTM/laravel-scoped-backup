<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Enums;

enum RestoreMode: string
{
    case TRANSACTIONAL_UPSERT = 'transactional_upsert';
    case WIPE_AND_REPLACE = 'wipe_and_replace';
    case DRY_RUN = 'dry_run';
}
