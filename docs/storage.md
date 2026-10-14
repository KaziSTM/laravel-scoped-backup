# Storage & Filesystem Architecture

The package leverages Laravel's Flysystem abstraction to support storing backup archives on local disks, network mounts, or cloud object storage.

---

## Supported Storage Drivers

Any disk configured in your application's `config/filesystems.php` can be used as a backup destination:

- **Local & NFS:** `local`, `public`
- **Cloud Object Storage:** Amazon S3, Google Cloud Storage, Cloudflare R2, MinIO, DigitalOcean Spaces
- **Remote Filesystems:** SFTP, FTP

---

## Configuration

In `config/scoped-backup.php`:

```php
'storage' => [
    // Storage disk name from config/filesystems.php
    'disk' => env('BACKUP_STORAGE_DISK', 's3'),

    // Relative directory prefix on the storage disk
    'path' => env('BACKUP_STORAGE_PATH', 'production-backups'),

    // Local temporary staging directory for dump creation and ZIP assembly
    'temp_staging_dir' => storage_path('framework/cache/backups'),
],
```

---

## Staging & Atomic Upload Lifecycle

Backups are never created directly in place on remote storage. The package employs a staged, atomic workflow:

1. **Local Staging:**
   - Raw database dumps and ZIP archives are generated in `storage.temp_staging_dir`.
   - Staging directories are created with restrictive permissions (`0700`) to protect database contents from unprivileged local users.
2. **Pre-upload Verification:**
   - Database TOC checks and SHA-256 checksums are calculated on the staged file before any remote upload occurs.
3. **Streaming Transfer:**
   - The archive is transferred to the destination disk using PHP streams (`fopen('rb')` piped into `Storage::disk()->put($path, $stream)`). This ensures minimal memory usage regardless of archive size.
4. **Immediate Staging Cleanup:**
   - Temporary dumps and staging archives are deleted immediately after the transfer completes, or inside `finally` blocks if any exception occurs.

---

## Remote Cloud Storage Semantics (S3 / R2 / MinIO)

### Physical Path Limitations
Cloud object storage drivers (such as S3) do not have local filesystem paths. Calling `$disk->path($file)` on an S3 disk throws a `BadMethodCallException`.

`laravel-scoped-backup` natively accounts for this limitation:

- **Checksum Validation on Remote Disks:** `HealthMonitor` and `ChecksumValidator` compute cryptographic SHA-256 hashes directly from Flysystem readable streams (`$disk->readStream()`), bypassing local path requirements.
- **Restoration from Remote Disks:** When a restore operation is triggered against a remote archive, `RestoreFullBackupAction` automatically downloads the archive into a temporary local file, executes pre-flight TOC checks and native restores, and cleans up the temporary file upon completion.

---

## Example: S3 Configuration

In `config/filesystems.php`:

```php
'disks' => [
    'backups_s3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BACKUP_BUCKET'),
        'visibility' => 'private',
    ],
],
```

In `.env`:

```dotenv
BACKUP_STORAGE_DISK=backups_s3
BACKUP_STORAGE_PATH=backups/app_production
```
