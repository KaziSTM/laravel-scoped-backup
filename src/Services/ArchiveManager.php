<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use KaziSTM\LaravelScopedBackup\DTO\BackupManifest;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\SecurityException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

class ArchiveManager
{
    /**
     * Maximum uncompressed archive extraction size (5 GB) to prevent Zip Bombs.
     */
    public const MAX_UNCOMPRESSED_BYTES = 5368709120;

    /**
     * @param  array<string, string>  $files  Map of relative zip path => absolute source file path
     * @param  array<string, string>  $directories  Map of relative zip prefix => absolute source directory path
     * @param  array<int, string>  $excludePatterns  Glob patterns to exclude from directories
     */
    public function createZip(
        string $zipPath,
        array $files = [],
        array $directories = [],
        ?BackupManifest $manifest = null,
        array $excludePatterns = [],
    ): void {
        $parentDir = dirname($zipPath);
        if (! File::isDirectory($parentDir)) {
            File::makeDirectory($parentDir, 0755, true, true);
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            throw new BackupException("Failed to open ZIP archive at [{$zipPath}]. Error code: {$openResult}");
        }

        try {
            // 1. Add individual files
            foreach ($files as $entryName => $sourcePath) {
                if (! File::exists($sourcePath)) {
                    throw new BackupException("Source file [{$sourcePath}] does not exist for archive entry [{$entryName}].");
                }
                $zip->addFile($sourcePath, $entryName);
            }

            // 2. Add directories recursively
            foreach ($directories as $relativePrefix => $sourceDir) {
                if (! File::isDirectory($sourceDir)) {
                    continue;
                }

                $prefix = trim($relativePrefix, '/');
                $this->addDirectoryToZip($zip, $sourceDir, $prefix, $excludePatterns);
            }

            // 3. Add manifest
            if ($manifest !== null) {
                $zip->addFromString('manifest.json', $manifest->toJson());
            }
        } finally {
            $zip->close();
        }

        // 4. Verify created archive
        $this->verifyZip($zipPath);
    }

    /**
     * @param  array<int, string>  $excludePatterns
     */
    private function addDirectoryToZip(
        ZipArchive $zip,
        string $sourceDir,
        string $prefix,
        array $excludePatterns,
    ): void {
        $realSourceDir = realpath($sourceDir);
        if ($realSourceDir === false) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realSourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                // Skip symlinks for security
                continue;
            }

            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $relativePath = substr($filePath, strlen($realSourceDir) + 1);
            $normalizedRelative = str_replace('\\', '/', $relativePath);

            if ($this->isExcluded($normalizedRelative, $excludePatterns)) {
                continue;
            }

            $entryName = $prefix !== '' ? "{$prefix}/{$normalizedRelative}" : $normalizedRelative;
            $zip->addFile($filePath, $entryName);
        }
    }

    /**
     * Extract archive with strict Zip Slip path traversal security protection and Zip Bomb limits.
     *
     * @return array<int, string> List of extracted file relative paths
     */
    public function extractZip(string $zipPath, string $destinationDir): array
    {
        if (! File::exists($zipPath)) {
            throw new BackupVerificationException("Archive file does not exist at [{$zipPath}].");
        }

        if (! File::isDirectory($destinationDir)) {
            File::makeDirectory($destinationDir, 0755, true, true);
        }

        $realDestination = realpath($destinationDir);
        if ($realDestination === false) {
            throw new SecurityException("Target extraction directory [{$destinationDir}] cannot be resolved.");
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($openResult !== true) {
            throw new BackupVerificationException("Failed to open archive at [{$zipPath}]. Code: {$openResult}");
        }

        $extractedFiles = [];
        $totalUncompressedBytes = 0;

        try {
            $numFiles = $zip->numFiles;

            // First pass: validate all entries for Zip Slip & cumulative size
            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $entryName = $stat['name'];

                // 1. Zip Slip Protection: Reject path traversal sequences
                if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    throw new SecurityException("Archive entry [{$entryName}] contains illegal path traversal sequence.");
                }

                // Reject symbolic link entries (Unix mode 0120000)
                $opsys = 0;
                $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attr >> 16) & 0170000;
                    if ($mode === 0120000) {
                        throw new SecurityException("Archive entry [{$entryName}] is a symbolic link, which is not permitted.");
                    }
                }

                // 2. Zip Bomb Protection
                $totalUncompressedBytes += $stat['size'];
                if ($totalUncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new SecurityException('Archive exceeds maximum allowed uncompressed size of 5 GB.');
                }
            }

            // Second pass: safely extract
            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $entryName = $stat['name'];
                $normalizedEntry = str_replace('\\', '/', $entryName);
                $targetPath = $realDestination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedEntry);

                if (is_link($targetPath)) {
                    throw new SecurityException("Target path [{$targetPath}] is a symbolic link, which is not permitted.");
                }

                // Ensure target directory exists
                $targetDir = dirname($targetPath);
                if (! File::isDirectory($targetDir)) {
                    File::makeDirectory($targetDir, 0700, true, true);
                }

                $realTargetDir = realpath($targetDir);
                if ($realTargetDir === false || (! str_starts_with($realTargetDir, $realDestination.DIRECTORY_SEPARATOR) && $realTargetDir !== $realDestination)) {
                    throw new SecurityException("Archive entry [{$entryName}] resolves outside target extraction directory.");
                }

                if (str_ends_with($entryName, '/') || str_ends_with($entryName, '\\')) {
                    // Directory entry
                    if (! File::isDirectory($targetPath)) {
                        File::makeDirectory($targetPath, 0700, true, true);
                    }

                    continue;
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new BackupException("Failed to read stream for archive entry [{$entryName}].");
                }

                $outHandle = fopen($targetPath, 'wb');
                if ($outHandle === false) {
                    fclose($stream);
                    throw new BackupException("Failed to open target file [{$targetPath}] for writing.");
                }

                stream_copy_to_stream($stream, $outHandle);
                fclose($outHandle);
                fclose($stream);

                // Tighten permissions on sensitive extracted backup files
                @chmod($targetPath, 0600);

                $extractedFiles[] = $entryName;
            }
        } finally {
            $zip->close();
        }

        return $extractedFiles;
    }

    public function readManifest(string $zipPath): BackupManifest
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($openResult !== true) {
            throw new BackupVerificationException("Failed to open archive at [{$zipPath}] to read manifest.");
        }

        try {
            $manifestIndex = $zip->locateName('manifest.json');
            if ($manifestIndex === false) {
                throw new BackupVerificationException("Archive at [{$zipPath}] does not contain a manifest.json file.");
            }

            $content = $zip->getFromIndex($manifestIndex);
            if ($content === false) {
                throw new BackupVerificationException("Failed to read manifest.json from archive [{$zipPath}].");
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                throw new BackupVerificationException("manifest.json in archive [{$zipPath}] contains invalid JSON.");
            }

            return BackupManifest::fromArray($decoded);
        } finally {
            $zip->close();
        }
    }

    public function verifyZip(string $zipPath): void
    {
        if (! File::exists($zipPath) || File::size($zipPath) === 0) {
            throw new BackupVerificationException("Created archive at [{$zipPath}] does not exist or is empty.");
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::CHECKCONS);

        if ($openResult !== true) {
            throw new BackupVerificationException("Archive integrity verification failed for [{$zipPath}]. Code: {$openResult}");
        }

        $zip->close();
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }

        return false;
    }
}
