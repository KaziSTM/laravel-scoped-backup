# Compatibility Matrix & Environment Support

This document details declared compatibility versus actually verified and integration-tested runtime environments.

---

## PHP Version Compatibility

| PHP Version | Declared Support | Verification Status | Notes |
| :--- | :--- | :--- | :--- |
| **PHP 8.2** | Yes (`^8.2`) | Declared | Minimum supported PHP version. |
| **PHP 8.3** | Yes (`^8.3`) | Declared | Compatible with typed class constants. |
| **PHP 8.4** | Yes (`^8.4`) | Declared | Compatible with property hooks and asymmetric visibility. |
| **PHP 8.5** | Yes (`^8.5`) | **Actively Verified** | Current development & container test environment (PHP 8.5.11). |

---

## Laravel Framework Compatibility

| Laravel Version | Declared Support | Test Framework Version | Verification Status |
| :--- | :--- | :--- | :--- |
| **Laravel 11.x** | Yes (`^11.0`) | Orchestra Testbench `^9.0` | **Actively Verified** |
| **Laravel 12.x** | Yes (`^12.0`) | Orchestra Testbench `^10.0` | **Actively Verified** |
| **Laravel 13.x** | Yes (`^13.0`) | Live Application Testing | **Actively Verified** |

---

## Database Engine Compatibility

| Database Engine | Minimum Version | Tested Versions | Known Caveats & Considerations |
| :--- | :--- | :--- | :--- |
| **PostgreSQL** | 12.0+ | 14, 15, 16, 17 | Requires client utilities `pg_dump` and `pg_restore`. Version of `pg_dump` must be equal to or newer than the PostgreSQL server version. |
| **MySQL** | 5.7+ | 8.0, 8.4 LTS | Requires client utilities `mysqldump` and `mysql`. Uses `--single-transaction` and `--quick`. Cloud databases without `PROCESS` or `SUPER` privileges may require disabling routines or events. |
| **MariaDB** | 10.3+ | 10.6, 10.11 LTS | Supports `mariadb-dump` with automatic fallback to `mysqldump`. Supports automatic MariaDB engine detection even when Laravel driver is `'mysql'`. |
| **SQLite** | 3.27+ | 3.45+ | Requires PHP extension `ext-sqlite3`. In-memory databases (`:memory:`) cannot be backed up to disk. Supports WAL mode online backups. |

---

## Operating System Compatibility

- **Linux (Ubuntu, Debian, Alpine, RHEL):** Fully supported and verified. Standard production deployment target.
- **macOS:** Fully supported via Homebrew-installed utilities (`brew install postgresql`, `brew install mysql-client`, `brew install mariadb`).
- **Windows:** Supported when database CLI binaries (`pg_dump.exe`, `mysqldump.exe`) are present on `PATH` or configured explicitly in `config/scoped-backup.php`. Note that Windows backslash paths are automatically normalized to forward slashes in ZIP archive manifests.
