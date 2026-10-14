<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Events;

use KaziSTM\LaravelScopedBackup\Enums\BackupType;

class RestoreSuccessful
{
    /**
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public readonly BackupType $type,
        public readonly string $archivePath,
        public readonly array $stats = [],
    ) {}
}
