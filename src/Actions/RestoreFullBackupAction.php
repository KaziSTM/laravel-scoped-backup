<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Actions;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use KaziSTM\LaravelScopedBackup\Database\DatabaseBackupDriverManager;
use KaziSTM\LaravelScopedBackup\Enums\BackupType;
use KaziSTM\LaravelScopedBackup\Events\RestoreFailed;
use KaziSTM\LaravelScopedBackup\Events\RestoreStarting;
use KaziSTM\LaravelScopedBackup\Events\RestoreSuccessful;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupVerificationException;
use KaziSTM\LaravelScopedBackup\Exceptions\RestoreException;
use KaziSTM\LaravelScopedBackup\Exceptions\SecurityException;
use KaziSTM\LaravelScopedBackup\Models\BackupRecord;
use KaziSTM\LaravelScopedBackup\Services\ArchiveManager;
use KaziSTM\LaravelScopedBackup\Services\ChecksumValidator;
use Throwable;

class RestoreFullBackupAction
{
    public function __construct(
        protected readonly DatabaseBackupDriverManager $dumperManager,
        protected readonly ArchiveManager $archiveManager,
        protected readonly ChecksumValidator $checksumValidator,
    ) {}

    /**
     * Restore a full database backup archive.
     *
     * @param  int|string|BackupRecord  $backup  Backup ID, physical path, or BackupRecord model
     * @param  string|null  $targetConnection  Target database connection (defaults to configured recovery connection)
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function execute(
        int|string|BackupRecord $backup,
        ?string $targetConnection = null,
        bool $force = false,
        bool $verifyOnly = false,
        bool $restoreFiles = false,
    ): array {
        $env = (string) config('app.env', 'production');

        // Safety gate: Require explicit --force when running in production environment
        if ($env === 'production' && ! $force && ! $verifyOnly) {
            throw new SecurityException(
                'Destructive full database restore into production is blocked. Restore into an isolated recovery database (e.g. createdb <app>_recovery and --connection=pgsql_recovery) or supply --force explicitly.'
            );
        }

        $temporaryFiles = [];

        // Resolve archive physical path (handles both local and remote object storage)
        $archivePath = $this->resolveArchivePath($backup, $temporaryFiles);

        Event::dispatch(new RestoreStarting(BackupType::FULL, $archivePath, [
            'target_connection' => $targetConnection,
            'verify_only' => $verifyOnly,
        ]));

        $tempDir = storage_path('framework/cache/backups/full_restore_'.uniqid('', true));
        File::makeDirectory($tempDir, 0755, true, true);

        try {
            // 1. Extract ZIP archive with Zip Slip protections
            $this->archiveManager->extractZip($archivePath, $tempDir);

            // 2. Validate Manifest
            $manifest = $this->archiveManager->readManifest($archivePath);
            if ($manifest->backupType !== BackupType::FULL) {
                throw new RestoreException("Archive is a {$manifest->backupType->value} backup, not a full backup.");
            }

            $dumpFile = $tempDir.'/database.dump';
            if (! File::exists($dumpFile)) {
                $candidates = ['database.dump', 'database.sql', 'database.sqlite'];
                $found = false;
                foreach ($candidates as $candidate) {
                    if (File::exists($tempDir.'/'.$candidate)) {
                        $dumpFile = $tempDir.'/'.$candidate;
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw new RestoreException('Full backup archive is missing required database dump file.');
                }
            }

            // 3. Verify dump checksum if recorded in manifest
            if (! empty($manifest->checksums['database_dump_sha256'])) {
                $computedSha = $this->checksumValidator->sha256($dumpFile);
                if (! hash_equals($manifest->checksums['database_dump_sha256'], $computedSha)) {
                    throw new BackupVerificationException(
                        "Database dump checksum mismatch. Expected [{$manifest->checksums['database_dump_sha256']}], got [{$computedSha}]."
                    );
                }
            }

            // 4. Resolve Database Dumper
            $driver = $manifest->driver ?: 'pgsql';
            $dumper = $this->dumperManager->resolve($driver);

            // 5. Pre-flight verification using native dumper TOC tools
            $verifyMeta = $dumper->verifyArchive($dumpFile);

            if ($verifyOnly) {
                Log::info("Full backup archive verification passed for [{$archivePath}].");

                return [
                    'verified' => true,
                    'manifest' => $manifest->toArray(),
                    'verification' => $verifyMeta,
                ];
            }

            // 6. Live Restore into Target Connection
            $connName = $targetConnection ?? (string) config('scoped-backup.database.connection', config('database.default', 'pgsql'));
            $connConfig = (array) config("database.connections.{$connName}", []);

            if (empty($connConfig)) {
                throw new RestoreException("Target database connection [{$connName}] is not configured.");
            }

            Log::warning("Executing full database restore into connection [{$connName}] from [{$archivePath}]...");
            $restoreMeta = $dumper->restore($connConfig, $dumpFile);

            // 7. Optional Filesystem Restoration
            $restoredFileCount = 0;
            if ($restoreFiles && File::isDirectory($tempDir.'/files')) {
                $targetStorageDir = storage_path('app');
                $filesIterator = File::allFiles($tempDir.'/files');
                foreach ($filesIterator as $file) {
                    $relative = substr($file->getPathname(), strlen($tempDir.'/files') + 1);
                    $dest = $targetStorageDir.'/'.$relative;
                    $destDir = dirname($dest);
                    if (! File::isDirectory($destDir)) {
                        File::makeDirectory($destDir, 0755, true, true);
                    }
                    File::copy($file->getPathname(), $dest);
                    $restoredFileCount++;
                }
            }

            $result = array_merge($restoreMeta, [
                'archive' => $archivePath,
                'target_connection' => $connName,
                'restored_files_count' => $restoredFileCount,
                'manifest' => $manifest->toArray(),
            ]);

            Event::dispatch(new RestoreSuccessful(BackupType::FULL, $archivePath, $result));

            return $result;
        } catch (Throwable $e) {
            Event::dispatch(new RestoreFailed(BackupType::FULL, $archivePath, $e));

            Log::error("Full database restore failed: {$e->getMessage()}", [
                'archive' => $archivePath,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }

            foreach ($temporaryFiles as $tempFile) {
                if (File::exists($tempFile)) {
                    File::delete($tempFile);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $temporaryFiles
     */
    protected function resolveArchivePath(int|string|BackupRecord $backup, array &$temporaryFiles): string
    {
        if ($backup instanceof BackupRecord) {
            return $this->getPhysicalArchivePath(Storage::disk($backup->disk), (string) $backup->file_path, $temporaryFiles);
        }

        if (is_int($backup)) {
            $modelClass = config('scoped-backup.model', BackupRecord::class);
            /** @var BackupRecord|null $record */
            $record = $modelClass::find($backup);
            if ($record === null) {
                throw new RestoreException("Backup record [ID: {$backup}] was not found.");
            }

            return $this->getPhysicalArchivePath(Storage::disk($record->disk), (string) $record->file_path, $temporaryFiles);
        }

        // Direct path string on local filesystem
        if (File::exists($backup)) {
            return $backup;
        }

        // Check relative to storage disk
        $defaultDisk = Storage::disk((string) config('scoped-backup.storage.disk', 'local'));

        return $this->getPhysicalArchivePath($defaultDisk, $backup, $temporaryFiles);
    }

    /**
     * Resolve a physical local path for a storage artifact, downloading from remote object stores if necessary.
     *
     * @param  Filesystem  $disk
     * @param  array<int, string>  $temporaryFiles
     */
    protected function getPhysicalArchivePath($disk, string $filePath, array &$temporaryFiles): string
    {
        try {
            $path = $disk->path($filePath);
            if (File::exists($path)) {
                return $path;
            }
        } catch (Throwable) {
            // Driver does not support local physical paths (e.g. S3 or custom remote adapter)
        }

        if ($disk->exists($filePath)) {
            $tempDownload = storage_path('framework/cache/backups/dl_'.uniqid('', true).'.zip');
            $dir = dirname($tempDownload);
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0700, true, true);
            }

            $stream = $disk->readStream($filePath);
            if ($stream === false) {
                throw new RestoreException("Failed to open stream for [{$filePath}] from storage disk.");
            }

            $out = fopen($tempDownload, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            $temporaryFiles[] = $tempDownload;

            return $tempDownload;
        }

        throw new RestoreException("Backup archive file could not be located on storage disk at [{$filePath}].");
    }
}
