<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Database\Contracts;

use KaziSTM\LaravelScopedBackup\Contracts\DatabaseDumperInterface;

interface DatabaseBackupDriver extends DatabaseDumperInterface
{
    /**
     * Check if the driver and its required utilities/extensions are available on this environment.
     *
     * @return array{available: bool, message: ?string, binary: ?string, version: ?string}
     */
    public function checkAvailability(): array;

    /**
     * Report supported driver capabilities and limitations.
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * Get the file extension for this driver's raw backup artifact.
     */
    public function getArchiveExtension(): string;
}
