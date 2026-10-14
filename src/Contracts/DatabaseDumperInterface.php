<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Contracts;

interface DatabaseDumperInterface
{
    /**
     * Execute database dump into the specified output path.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> Metadata about the dump operation
     */
    public function dump(array $config, string $outputPath): array;

    /**
     * Verify that the archive exists, is non-empty, and can be inspected by archive tooling.
     *
     * @return array<string, mixed> Metadata extracted from archive verification (e.g. TOC summary)
     */
    public function verifyArchive(string $archivePath): array;

    /**
     * Verify and restore an archive into the specified database connection.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> Metadata about the restore operation
     */
    public function restore(array $config, string $archivePath): array;
}
