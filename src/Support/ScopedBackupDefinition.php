<?php

declare(strict_types=1);

namespace KaziSTM\LaravelScopedBackup\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use KaziSTM\LaravelScopedBackup\Contracts\ScopedBackupDefinitionInterface;
use KaziSTM\LaravelScopedBackup\Exceptions\BackupException;

class ScopedBackupDefinition implements ScopedBackupDefinitionInterface
{
    /** @var class-string<Model> */
    protected string $modelClass = '';

    protected ?Closure $queryCallback = null;

    /** @var array<int, string> */
    protected array $includedRelations = [];

    /** @var array<int, string> */
    protected array $identityKeys = [];

    /** @var array<string, array{resolver: Closure, options: array<string, mixed>}> */
    protected array $additionalEntities = [];

    protected int $chunkSize = 100;

    protected bool $useTransaction = true;

    protected string $isolationLevel = 'REPEATABLE READ';

    protected ?Closure $transformCallback = null;

    public function __construct(
        protected readonly string $name,
    ) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function model(string $modelClass, ?Closure $queryCallback = null): self
    {
        if (! class_exists($modelClass)) {
            throw new BackupException("Target model class [{$modelClass}] does not exist.");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new BackupException("Target class [{$modelClass}] must extend Illuminate\\Database\\Eloquent\\Model.");
        }

        $this->modelClass = $modelClass;
        $this->queryCallback = $queryCallback;

        return $this;
    }

    public function rootModel(string $modelClass, ?Closure $queryCallback = null): self
    {
        return $this->model($modelClass, $queryCallback);
    }

    public function query(Closure $queryCallback): self
    {
        $this->queryCallback = $queryCallback;

        return $this;
    }

    /**
     * @param  string|array<int, string>  $relations
     */
    public function include(string|array $relations): self
    {
        $items = is_array($relations) ? $relations : [$relations];

        foreach ($items as $relation) {
            if (! in_array($relation, $this->includedRelations, true)) {
                $this->includedRelations[] = $relation;
            }
        }

        return $this;
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function withRelations(array $relations): self
    {
        return $this->include($relations);
    }

    /**
     * Declare stable identity/natural key(s) used for matching existing records upon restore.
     *
     * @param  string|array<int, string>  $keys
     */
    public function identity(string|array $keys): self
    {
        $this->identityKeys = is_array($keys) ? $keys : [$keys];

        return $this;
    }

    /**
     * Alias for identity()
     *
     * @param  string|array<int, string>  $keys
     */
    public function uniqueBy(string|array $keys): self
    {
        return $this->identity($keys);
    }

    /**
     * Add supplementary entity queries to include in the scoped dataset (e.g. taxonomy mappings or configs).
     *
     * @param  array<string, mixed>  $options
     */
    public function addEntity(string $key, Closure $resolver, array $options = []): self
    {
        $this->additionalEntities[$key] = [
            'resolver' => $resolver,
            'options' => $options,
        ];

        return $this;
    }

    public function chunkSize(int $size): self
    {
        if ($size < 1) {
            $size = 1;
        }

        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Configure snapshot transaction and isolation level.
     */
    public function withTransaction(bool $enabled = true, string $isolationLevel = 'REPEATABLE READ'): self
    {
        $this->useTransaction = $enabled;
        $this->isolationLevel = strtoupper($isolationLevel);

        return $this;
    }

    public function transform(Closure $callback): self
    {
        $this->transformCallback = $callback;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModelClass(): string
    {
        if ($this->modelClass === '') {
            throw new BackupException("Scoped backup definition [{$this->name}] must declare a target model class.");
        }

        return $this->modelClass;
    }

    public function getQueryCallback(): ?Closure
    {
        return $this->queryCallback;
    }

    /**
     * @return array<int, string>
     */
    public function getIncludedRelations(): array
    {
        return $this->includedRelations;
    }

    /**
     * @return array<int, string>
     */
    public function getIdentityKeys(): array
    {
        return $this->identityKeys;
    }

    /**
     * @return array<string, array{resolver: Closure, options: array<string, mixed>}>
     */
    public function getAdditionalEntities(): array
    {
        return $this->additionalEntities;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function shouldUseTransaction(): bool
    {
        return $this->useTransaction;
    }

    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }

    public function getTransformCallback(): ?Closure
    {
        return $this->transformCallback;
    }
}
