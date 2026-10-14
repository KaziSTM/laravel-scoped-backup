<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Tests\Integration\Concerns;

use Throwable;

trait InteractsWithRealDatabases
{
    /**
     * Determine whether the tests are executing inside a continuous integration environment.
     */
    protected function isCi(): bool
    {
        $ci = env('CI') ?? getenv('CI');

        return ! empty($ci) && filter_var($ci, FILTER_VALIDATE_BOOLEAN) !== false;
    }

    /**
     * Handle an unavailable database service differently for CI and local development.
     *
     * In CI environments, required integration database services MUST be reachable; failing
     * immediately prevents silent regression skips. In local environments, missing services
     * gracefully skip with a helpful explanation.
     */
    protected function handleServiceUnavailable(string $service, ?string $host, int|string $port, ?Throwable $e = null): void
    {
        $details = $e ? " ({$e->getMessage()})" : '';
        $hostDisplay = $host ?: 'unconfigured';
        $message = "Database service [{$service}] is unavailable at {$hostDisplay}:{$port}{$details}.";

        if ($this->isCi()) {
            $this->fail("CI Environment Failure: {$message} Real database integration services must be running in CI.");
        }

        $envVar = 'DB_'.strtoupper($service).'_HOST';
        $this->markTestSkipped("Local Environment: {$message} Configure {$envVar} to run real database integration tests.");
    }
}
