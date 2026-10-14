<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\DTO;

class HealthCheckResult
{
    /**
     * @param  array<int, array{check: string, status: string, message: string, context?: array<string, mixed>}>  $checks
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly array $checks = [],
    ) {}

    public function hasWarnings(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['status'] === 'warning') {
                return true;
            }
        }

        return false;
    }

    public function hasFailures(): bool
    {
        return ! $this->isHealthy;
    }

    /**
     * @return array<int, array{check: string, status: string, message: string, context?: array<string, mixed>}>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => $c['status'] === 'failed'));
    }

    /**
     * @return array<int, array{check: string, status: string, message: string, context?: array<string, mixed>}>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => $c['status'] === 'warning'));
    }
}
