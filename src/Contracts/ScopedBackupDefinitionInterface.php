<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Contracts;

use Closure;

interface ScopedBackupDefinitionInterface
{
    public function getName(): string;

    public function getModelClass(): string;

    public function getQueryCallback(): ?Closure;

    /**
     * @return array<int, string>
     */
    public function getIncludedRelations(): array;

    /**
     * @return array<int, string>
     */
    public function getIdentityKeys(): array;

    /**
     * @return array<string, array{resolver: Closure, options: array<string, mixed>}>
     */
    public function getAdditionalEntities(): array;

    public function getChunkSize(): int;

    public function shouldUseTransaction(): bool;

    public function getIsolationLevel(): string;

    public function getTransformCallback(): ?Closure;
}
