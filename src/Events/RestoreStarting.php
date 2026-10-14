<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Enums\BackupType;

class RestoreStarting
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly BackupType $type,
        public readonly string $archivePath,
        public readonly array $context = [],
    ) {}
}
