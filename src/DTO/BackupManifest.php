<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\DTO;

use KaziSTM\LaravelScopedBackup\Enums\BackupType;

final class BackupManifest
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $checksums
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly BackupType $backupType,
        public readonly string $createdAt,
        public readonly string $environment,
        public readonly string $driver,
        public readonly string $databaseName,
        public readonly array $counts = [],
        public readonly array $checksums = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            schemaVersion: (string) ($data['schema_version'] ?? '1.0'),
            backupType: BackupType::tryFrom((string) ($data['backup_type'] ?? 'full')) ?? BackupType::FULL,
            createdAt: (string) ($data['created_at'] ?? now()->toIso8601String()),
            environment: (string) ($data['environment'] ?? 'production'),
            driver: (string) ($data['driver'] ?? 'pgsql'),
            databaseName: (string) ($data['database_name'] ?? ''),
            counts: (array) ($data['counts'] ?? []),
            checksums: (array) ($data['checksums'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'backup_type' => $this->backupType->value,
            'created_at' => $this->createdAt,
            'environment' => $this->environment,
            'driver' => $this->driver,
            'database_name' => $this->databaseName,
            'counts' => $this->counts,
            'checksums' => $this->checksums,
            'metadata' => $this->metadata,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
