# Scoped Model & Dataset Backups

Scoped backups provide portable, deterministic snapshots of declared Eloquent models, relationships, and auxiliary entities.

Unlike full database dumps that capture physical tables and engine-specific binary state, scoped backups export domain records as streaming JSON Lines (`records.jsonl`). This allows safe cross-environment imports, tenant migration, and seeded dataset versioning.

---

## Scoped Backup Definition Builder

Scoped backups are defined using the fluent `ScopedBackupDefinition` builder.

```php
use App\Models\Company;
use KaziSTM\LaravelScopedBackup\Support\ScopedBackupDefinition;

$definition = ScopedBackupDefinition::make('active_algerian_companies')
    ->model(Company::class, function ($query) {
        $query->where('country', 'DZ')->where('status', 'active');
    })
    ->include([
        'contacts',
        'addresses',
        'industryClassifications',
    ])
    ->identity(['tax_identification_number'])
    ->chunkSize(100)
    ->withTransaction(true, 'REPEATABLE READ')
    ->addEntity('industry_sectors_reference', function () {
        return \App\Models\Sector::all()->toArray();
    });
```

---

## Method Reference

### `model(string $modelClass, ?Closure $queryCallback = null)`
Declares the root Eloquent model class and an optional query constraint callback.
- **Validation:** Throws `BackupException` if the class does not exist or does not extend `Illuminate\Database\Eloquent\Model`.
- **Query Scoping:** The query callback receives standard Eloquent builder instances, allowing `where`, `whereIn`, or custom model scopes.

### `include(string|array $relations)` (Alias: `withRelations`)
Explicitly declares relationship names to eager load and serialize with each root model.
- Eager loading prevents N+1 query performance degradation during chunked export.
- Supports nested relationship dot-notation (e.g. `'contents.translations'`).

### `identity(string|array $keys)` (Alias: `uniqueBy`)
Declares the stable natural key or composite identity attributes used to match existing records during restoration (e.g. `email`, `slug`, `['tenant_id', 'code']`).

### `addEntity(string $key, Closure $resolver, array $options = [])`
Exports supplementary non-root entity data (e.g. reference lookup tables, global taxonomies, or config mappings) into separate JSON files inside `entities/<key>.json`.

### `chunkSize(int $size)`
Configures the number of records fetched and processed per memory-managed query chunk (default: `100`).

### `withTransaction(bool $enabled = true, string $isolationLevel = 'REPEATABLE READ')`
Encloses the entire export process within a database transaction at the declared isolation level (default: `REPEATABLE READ`) to guarantee snapshot consistency during concurrent application writes.

### `transform(Closure $callback)`
Allows inspecting or mutating the serialized record array before writing to JSONL:

```php
$definition->transform(function ($model, array $attributes) {
    unset($attributes['sensitive_token']);
    return $attributes;
});
```

---

## Archive Structure

A scoped backup archive packages records and supplementary metadata:

```text
scoped_active_algerian_companies_2026-10-14_140000_12345678.zip
├── manifest.json            # Scope definition metadata, counts, and checksums
├── records.jsonl            # Primary model records (one JSON object per line)
└── entities/                # Supplementary collections (optional)
    └── industry_sectors_reference.json
```

### Records Representation (`records.jsonl`)

Each line is a self-contained JSON object containing raw model attributes and nested relationships:

```json
{"id":1,"name":"Sonatrach","country":"DZ","tax_identification_number":"000016001234567","contacts":[{"id":10,"company_id":1,"email":"contact@sonatrach.dz"}]}
{"id":2,"name":"Cosider","country":"DZ","tax_identification_number":"000016007654321","contacts":[{"id":11,"company_id":2,"email":"info@cosider.dz"}]}
```

---

## Attribute & Data Type Handling

1. **Raw Attribute Preservation:**
   Records are extracted using Eloquent's `getAttributes()`. This guarantees that hidden attributes (such as `password`, `remember_token`, or encrypted columns) are preserved in the backup archive unless explicitly removed via a `transform()` callback.
2. **Date & Cast Formatting:**
   Database-level values are extracted directly from the model attribute dictionary, avoiding unintended timezone transformations or cast mutator side effects.
3. **JSON Columns:**
   Native JSON columns are preserved and correctly handled during restore via `forceFill()`.

---

## Important Limitations: Scoped Snapshots vs Full Dumps

- **Root Model Restoration:** `ScopedExporter` packages root model records and serializes declared relations into `records.jsonl` and supplementary collections into `entities/`. During restoration, `ScopedImporter` imports and upserts root model records into the target model table. Child relationships and supplementary entity arrays are preserved in the archive for inspection or custom batch restoration, but are not automatically cascade-inserted by `ScopedImporter`.
- **Not a Schema Backup:** Scoped backups export records, not table DDL, foreign keys, or database views. Target database schemas must already exist before restoration.
- **Relational Integrity:** Ensure all mandatory foreign key targets are either included in the scope or already exist in the target database.
- **Memory Considerations:** Use reasonable chunk sizes (`50` to `500`) when models contain large text or embedded binary blobs.
