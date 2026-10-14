<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use Throwable;

class RestoreFailed
{
    public function __construct(
        public readonly BackupType $type,
        public readonly string $archivePath,
        public readonly Throwable $exception,
    ) {}
}
