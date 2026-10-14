<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\DTO;

class RestoreStats
{
    public int $recordsCreated = 0;

    public int $recordsUpdated = 0;

    public int $recordsUnchanged = 0;

    public int $recordsSkipped = 0;

    public int $relationsProcessed = 0;

    /** @var array<string, int> */
    public array $entityCounts = [];

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public float $durationSeconds = 0.0;

    public bool $isDryRun = false;

    public function totalProcessed(): int
    {
        return $this->recordsCreated + $this->recordsUpdated + $this->recordsUnchanged + $this->recordsSkipped;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
