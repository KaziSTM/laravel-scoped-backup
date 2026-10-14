<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\DTO;

class BackupStats
{
    public int $recordsExported = 0;

    public int $relationsExported = 0;

    /** @var array<string, int> */
    public array $entityCounts = [];

    public float $durationSeconds = 0.0;

    public int $fileSizeBytes = 0;

    public int $additionalEntitiesCount = 0;

    public function recordCountFor(string $entity): int
    {
        return $this->entityCounts[$entity] ?? 0;
    }

    public function incrementRootRecord(string $entity, int $count = 1): void
    {
        $this->entityCounts[$entity] = ($this->entityCounts[$entity] ?? 0) + $count;
        $this->recordsExported += $count;
    }

    public function recordAdditionalEntity(string $entity, int $count = 1): void
    {
        $this->entityCounts[$entity] = ($this->entityCounts[$entity] ?? 0) + $count;
        $this->additionalEntitiesCount += $count;
    }

    public function incrementEntity(string $entity, int $count = 1): void
    {
        $this->incrementRootRecord($entity, $count);
    }
}
