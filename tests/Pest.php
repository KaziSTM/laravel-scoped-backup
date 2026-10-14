<?php

declare(strict_types=1);

use KaziSTM\LaravelScopedBackup\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Integration', 'Unit');

/**
 * Determine whether the tests are executing inside a continuous integration environment.
 */
function isCi(): bool
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
function handleServiceUnavailable(string $service, ?string $host, int|string $port, ?Throwable $e = null): void
{
    $details = $e ? " ({$e->getMessage()})" : '';
    $hostDisplay = $host ?: 'unconfigured';
    $message = "Database service [{$service}] is unavailable at {$hostDisplay}:{$port}{$details}.";

    if (isCi()) {
        test()->fail("CI Environment Failure: {$message} Real database integration services must be running in CI.");
    }

    $envVar = 'DB_'.strtoupper($service).'_HOST';
    test()->markTestSkipped("Local Environment: {$message} Configure {$envVar} to run real database integration tests.");
}

/**
 * Guard against dropping application or production databases.
 * Validates that a database name strictly matches the disposable test database prefix.
 */
function assertSafeTestDatabaseName(string $name): void
{
    if (! str_starts_with($name, 'test_scoped_pkg_')) {
        throw new InvalidArgumentException("Refusing to drop database [{$name}]: does not match disposable test prefix 'test_scoped_pkg_'.");
    }
}
