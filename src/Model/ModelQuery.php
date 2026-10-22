<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\ModelException;
use Ethernize\Strata\Exception\ModelNotFoundException;
use Ethernize\Strata\Query\CursorPage;
use Ethernize\Strata\Query\Page;
use Ethernize\Strata\Query\QueryBuilder;

/**
 * A query that returns models. It wraps the Strata QueryBuilder (there is no second SQL mechanism), adds the
 * model's scopes (soft deletes), hydrates rows and loads relations in batches.
 *
 * Builder methods are forwarded by name from a fixed list, so every condition is built, validated and bound
 * exactly as in a plain query. Columns are qualified with the table, so joins do not make them ambiguous.
 *
 * @method self where(string|Closure $column, mixed $operator = null, mixed $value = null)
 * @method self orWhere(string|Closure $column, mixed $operator = null, mixed $value = null)
 * @method self whereIn(string $column, array $values)
 * @method self whereNotIn(string $column, array $values)
 * @method self whereNull(string $column)
 * @method self whereNotNull(string $column)
 * @method self whereBetween(string $column, mixed $from, mixed $to)
 * @method self orderBy(string $column, string $direction = 'asc')
 * @method self orderByDesc(string $column)
 * @method self limit(int $limit)
 * @method self offset(int $offset)
 * @method self join(string $table, string|Closure $first, ?string $operator = null, ?string $second = null)
 * @method self leftJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null)
 * @method self rightJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null)
 * @method self groupBy(string ...$columns)
 * @method self having(string $expression, mixed $operator = null, mixed $value = null)
 * @method self orHaving(string $expression, mixed $operator = null, mixed $value = null)
 * @method self select(string ...$columns)
 * @method self distinct(bool $distinct = true)
 * @method int count(string $column = '*')
 * @method bool exists()
 * @method int|float|null sum(string $column)
 * @method float|null avg(string $column)
 * @method mixed min(string $column)
 * @method mixed max(string $column)
 * @method mixed value(string $column)
 * @method list<mixed> pluck(string $column)
 * @method string toSql()
 * @method list<mixed> getBindings()
 */
final class ModelQuery
{
    private const CHAIN = ['where', 'orWhere', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'orderBy', 'orderByDesc', 'limit', 'offset', 'join', 'leftJoin', 'rightJoin', 'groupBy', 'having', 'orHaving', 'select', 'distinct'];
    private const TERMINAL = ['count', 'exists', 'sum', 'avg', 'min', 'max', 'value', 'pluck', 'toSql', 'getBindings'];

    private readonly ModelMetadata $meta;
    private QueryBuilder $builder;

    /** @var array<string, Closure|null> relation path => constraint */
    private array $with = [];

    private string $trashed = 'without';

    /** @var list<Closure(QueryBuilder): mixed> */
    private array $constraints = [];

    /**
     * @param class-string<Model> $class
     */
    public function __construct(private readonly string $class, private readonly Connection $connection)
    {
        $this->meta = ModelMetadata::for($class);
        $this->builder = $connection->table($this->meta->table)->select($this->meta->table . '.*');
    }

    /**
     * Relations to load together with the models, one query per relation: with('category', 'reviews.author'),
     * or with(['reviews' => fn (ModelQuery $q) => $q->where('approved', true)]).
     *
     * @param string|array<int|string, string|Closure> ...$relations
     */
    public function with(string|array ...$relations): self
    {
        $this->with = [...$this->with, ...self::normalizeWith($relations)];

        return $this;
    }

    public function withTrashed(): self
    {
        $this->assertSoftDeletes();
        $this->trashed = 'with';

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->assertSoftDeletes();
        $this->trashed = 'only';

        return $this;
    }

    /**
     * A condition that cannot be bypassed by orWhere() calls made later. Used by relations.
     *
     * @param Closure(QueryBuilder): mixed $constraint
     * @internal
     */
    public function constrainTo(Closure $constraint): self
    {
        $this->constraints[] = $constraint;

        return $this;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (in_array($method, self::CHAIN, true)) {
            $this->builder->{$method}(...$arguments);

            return $this;
        }

        if (in_array($method, self::TERMINAL, true)) {
            return $this->scoped()->{$method}(...$arguments);
        }

        throw new ModelException(sprintf('%s query has no method "%s".', $this->class, $method));
    }

    // Reading

    /**
     * @return list<Model>
     */
    public function get(): array
    {
        $models = $this->hydrateAll($this->scoped()->get());
        self::loadRelations($models, $this->with);

        return $models;
    }

    public function first(): ?Model
    {
        $copy = clone $this;

        return $copy->limit(1)->get()[0] ?? null;
    }

    public function find(int|string $id): ?Model
    {
        return $this->whereKey($id)->first();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->class);
    }

    /**
     * @param list<int|string> $ids
     * @return list<Model>
     */
    public function findMany(array $ids): array
    {
        return $ids === [] ? [] : (clone $this)->whereIn($this->meta->table . '.' . $this->meta->key, $ids)->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->class);
    }

    /**
     * @return Page<Model>
     */
    public function paginate(int $perPage = 15, int $page = 1): Page
    {
        return $this->pageOfModels($this->scoped()->paginate($perPage, $page));
    }

    /**
     * @return Page<Model>
     */
    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        return $this->pageOfModels($this->scoped()->simplePaginate($perPage, $page));
    }

    /**
     * @return CursorPage<Model>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, ?string $column = null, string $direction = 'asc'): CursorPage
    {
        $page = $this->scoped()->cursorPaginate($perPage, $cursor, $column ?? $this->meta->table . '.' . $this->meta->key, $direction);
        $models = $this->hydrateAll($page->items);
        self::loadRelations($models, $this->with);

        return new CursorPage($models, $page->perPage, $page->nextCursor);
    }

    // Writing

    /**
     * Builds a model from the #[Fillable] values and inserts it.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Model
    {
        $model = $this->newModel();
        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * A new, unsaved model that will use this query's connection.
     */
    public function newModel(): Model
    {
        return $this->meta->instantiate()->useConnection($this->connection);
    }

    /**
     * Updates every matching row with #[Fillable] values. Needs a where(), like the builder's update().
     * It does not load the models: version columns are not checked and no property is touched.
     *
     * @param array<string, mixed> $attributes
     * @return int number of rows changed
     */
    public function update(array $attributes): int
    {
        $denied = array_values(array_filter(array_keys($attributes), fn (mixed $name): bool => !is_string($name) || !($this->meta->columns[$name]['fillable'] ?? false)));

        if ($denied !== []) {
            throw new \Ethernize\Strata\Exception\MassAssignmentException($this->class, array_map('strval', $denied));
        }

        $values = [];

        foreach ($attributes as $name => $value) {
            $column = $this->meta->columns[$name];
            $values[$name] = $this->meta->toStorage($column, $this->meta->fromInput($name, $column, $value));
        }

        if ($this->meta->timestamps) {
            $values['updated_at'] = $this->meta->toStorage($this->meta->columns['updated_at'], ModelMetadata::now());
        }

        return $this->scoped()->update($values);
    }

    /**
     * Deletes every matching row (soft deletes mark them). Needs a where().
     *
     * @return int number of rows affected
     */
    public function delete(): int
    {
        if ($this->meta->softDeletes) {
            return $this->scoped()->update(['deleted_at' => $this->meta->toStorage($this->meta->columns['deleted_at'], ModelMetadata::now())]);
        }

        return $this->scoped()->delete();
    }

    // Internals

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Model>
     * @internal
     */
    public function hydrateAll(array $rows): array
    {
        $models = [];

        foreach ($rows as $row) {
            $model = $this->meta->instantiate();
            $model->fillFromRow($row, $this->connection);
            $models[] = $model;
        }

        return $models;
    }

    /**
     * The rows of the query as the database returned them, with scopes applied.
     *
     * @return list<array<string, mixed>>
     * @internal
     */
    public function rows(): array
    {
        return $this->scoped()->get();
    }

    /**
     * @param list<Model> $models
     * @param array<string, Closure|null> $with
     * @internal
     */
    public static function loadRelations(array $models, array $with): void
    {
        if ($models === [] || $with === []) {
            return;
        }

        $groups = [];

        foreach ($with as $path => $constraint) {
            [$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
            $groups[$first] ??= ['constraint' => null, 'nested' => []];

            if ($rest === null) {
                $groups[$first]['constraint'] = $constraint;
            } else {
                $groups[$first]['nested'][$rest] = $constraint;
            }
        }

        foreach ($groups as $name => $group) {
            $models[0]->relationFor((string) $name)->eagerLoad((string) $name, $models, $group['constraint'], $group['nested']);
        }
    }

    /**
     * @param array<int, array<int|string, string|Closure>|string> $relations
     * @return array<string, Closure|null>
     * @internal
     */
    public static function normalizeWith(array $relations): array
    {
        $with = [];

        foreach ($relations as $argument) {
            foreach ((array) $argument as $key => $value) {
                if (is_int($key)) {
                    $with[self::relationPath((string) $value)] = null;
                } elseif ($value instanceof Closure) {
                    $with[self::relationPath($key)] = $value;
                } else {
                    throw new ModelException('with() takes relation names, or relation => closure.');
                }
            }
        }

        return $with;
    }

    private static function relationPath(string $path): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new ModelException('A relation name may only contain letters, digits, underscores and dots (for nesting).');
        }

        return $path;
    }

    private function whereKey(int|string $id): self
    {
        $copy = clone $this;
        $copy->builder->where($this->meta->table . '.' . $this->meta->key, $id);

        return $copy;
    }

    /**
     * The builder with the model's scopes applied outside everything the caller added.
     */
    private function scoped(): QueryBuilder
    {
        $builder = clone $this->builder;
        $table = $this->meta->table;
        $trashed = $this->meta->softDeletes ? $this->trashed : 'with';

        if ($this->constraints !== [] || $trashed !== 'with') {
            $builder->constrain(function (QueryBuilder $b) use ($table, $trashed): void {
                foreach ($this->constraints as $constraint) {
                    $constraint($b);
                }

                if ($trashed === 'without') {
                    $b->whereNull($table . '.deleted_at');
                } elseif ($trashed === 'only') {
                    $b->whereNotNull($table . '.deleted_at');
                }
            });
        }

        return $builder;
    }

    /**
     * @param Page<array<string, mixed>> $page
     * @return Page<Model>
     */
    private function pageOfModels(Page $page): Page
    {
        $models = $this->hydrateAll($page->items);
        self::loadRelations($models, $this->with);

        return new Page($models, $page->perPage, $page->currentPage, $page->total, $page->hasMore);
    }

    private function assertSoftDeletes(): void
    {
        if (!$this->meta->softDeletes) {
            throw new ModelException(sprintf('%s does not use soft deletes.', $this->class));
        }
    }

    public function __clone()
    {
        $this->builder = clone $this->builder;
    }
}
