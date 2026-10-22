<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\MassAssignmentException;
use Ethernize\Strata\Exception\ModelException;
use Ethernize\Strata\Exception\ModelNotFoundException;
use Ethernize\Strata\Exception\StaleModelException;
use Ethernize\Strata\Model\Relation\BelongsTo;
use Ethernize\Strata\Model\Relation\BelongsToMany;
use Ethernize\Strata\Model\Relation\HasMany;
use Ethernize\Strata\Model\Relation\HasOne;
use Ethernize\Strata\Model\Relation\Relation;
use JsonSerializable;
use ReflectionMethod;

/**
 * Base class of models: typed objects over the rows of one table, built on the same QueryBuilder as
 * everything else in Strata. What a model is, is declared, not guessed:
 *
 *   #[Table('products', softDeletes: true)]
 *   final class Product extends Model
 *   {
 *       public int $id;
 *       #[Fillable, Expose] public string $name = '';
 *       #[Fillable, Expose] public int $price_cents = 0;
 *       public ?DateTimeImmutable $deleted_at = null;
 *   }
 *
 *   $product = Product::find(1);
 *   $products = Product::query()->where('active', true)->orderBy('name')->paginate(20);
 *   $product = Product::create(['name' => 'A', 'price_cents' => 12990]);
 *   $product->price_cents = 9990;
 *   $product->save();            // UPDATE of the changed columns only
 *
 * Rules that keep it predictable:
 *  - the columns are the public typed properties; the declared type is the cast;
 *  - mass assignment only reaches #[Fillable] properties and refuses the rest;
 *  - toArray() and JSON show only #[Expose] properties;
 *  - relations are loaded with with() or load(); reading one that was not loaded fails instead of querying;
 *  - a model has no constructor of its own and keeps no state besides its values.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements JsonSerializable
{
    private ?Connection $connection = null;
    private bool $exists = false;

    /** @var array<string, bool|int|float|string|null> the values as last read from or written to the database */
    private array $original = [];

    /** @var array<string, Model|list<Model>|null> */
    private array $relations = [];

    // Starting a query

    public static function query(): ModelQuery
    {
        return new ModelQuery(static::class, ModelContext::connection(static::meta()->connection));
    }

    /**
     * A query on a given connection, for code that does not use the application's default one.
     */
    public static function on(Connection $connection): ModelQuery
    {
        return new ModelQuery(static::class, $connection);
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * @throws ModelNotFoundException
     */
    public static function findOrFail(int|string $id): static
    {
        return static::query()->findOrFail($id);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Builds a model from the #[Fillable] values and inserts it.
     *
     * @param array<string, mixed> $attributes
     * @throws MassAssignmentException
     */
    public static function create(array $attributes): static
    {
        return static::query()->create($attributes);
    }

    /**
     * Runs $callback in a transaction on this model's connection (a savepoint when nested).
     *
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        return ModelContext::connection(static::meta()->connection)->transaction($callback);
    }

    // State

    /**
     * Assigns #[Fillable] properties from an array, converting each value to the declared type.
     * A name that is not #[Fillable] (or not a property at all) makes the whole call fail before anything
     * is assigned.
     *
     * @param array<string, mixed> $attributes
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): static
    {
        $meta = static::meta();
        $denied = [];

        foreach ($attributes as $name => $_) {
            if (!is_string($name) || !($meta->columns[$name]['fillable'] ?? false)) {
                $denied[] = (string) $name;
            }
        }

        if ($denied !== []) {
            throw new MassAssignmentException(static::class, $denied);
        }

        $values = [];

        foreach ($attributes as $name => $value) {
            $values[$name] = $meta->fromInput($name, $meta->columns[$name], $value);
        }

        foreach ($values as $name => $value) {
            $this->{$name} = $value;
        }

        return $this;
    }

    /**
     * Fills and saves in one step.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    /**
     * Inserts the model when it is new, or updates the columns that changed since it was read.
     */
    public function save(): bool
    {
        return $this->exists ? $this->performUpdate() : $this->performInsert();
    }

    /**
     * Soft deletes (sets deleted_at) when the model uses them, otherwise deletes the row.
     */
    public function delete(): bool
    {
        $meta = static::meta();
        $this->assertPersisted('delete');

        if (!$meta->softDeletes) {
            return $this->forceDelete();
        }

        $this->deleted_at = ModelMetadata::now();

        if ($meta->timestamps) {
            $this->updated_at = $this->deleted_at;
        }

        return $this->performUpdate();
    }

    /**
     * Removes the row for good, even when the model uses soft deletes.
     */
    public function forceDelete(): bool
    {
        $meta = static::meta();
        $this->assertPersisted('forceDelete');
        $this->connection()->table($meta->table)->where($meta->key, $this->getKey())->delete();
        $this->exists = false;

        return true;
    }

    public function restore(): bool
    {
        $meta = static::meta();

        if (!$meta->softDeletes) {
            throw new ModelException(sprintf('%s does not use soft deletes.', static::class));
        }

        $this->assertPersisted('restore');
        $this->deleted_at = null;

        return $this->performUpdate();
    }

    public function trashed(): bool
    {
        return static::meta()->softDeletes && ($this->deleted_at ?? null) !== null;
    }

    /**
     * Reads the row again, replacing the values and the loaded relations.
     */
    public function refresh(): static
    {
        $meta = static::meta();
        $this->assertPersisted('refresh');
        $row = $this->connection()->table($meta->table)->where($meta->key, $this->getKey())->first();

        if ($row === null) {
            throw new ModelNotFoundException(static::class);
        }

        $this->fillFromRow($row, $this->connection());
        $this->relations = [];

        return $this;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function getKey(): int|string|null
    {
        $key = static::meta()->key;

        return get_object_vars($this)[$key] ?? null;
    }

    /**
     * Whether the model (or one property of it) differs from what the database holds.
     */
    public function isDirty(?string $property = null): bool
    {
        $dirty = $this->dirty();

        return $property === null ? $dirty !== [] : array_key_exists($property, $dirty);
    }

    /**
     * The changed properties as they would be stored.
     *
     * @return array<string, bool|int|float|string|null>
     */
    public function dirty(): array
    {
        if (!$this->exists) {
            return $this->storable();
        }

        return array_filter(
            $this->storable(),
            fn (mixed $value, string $name): bool => !array_key_exists($name, $this->original) || $this->original[$name] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    // Relations

    /**
     * Loads relations for this model with one query each, nested with dots ('reviews.author').
     *
     * @param string|array<int|string, string|Closure> ...$relations
     */
    public function load(string|array ...$relations): static
    {
        ModelQuery::loadRelations([$this], ModelQuery::normalizeWith($relations));

        return $this;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * @return Model|list<Model>|null
     */
    public function getRelation(string $name): Model|array|null
    {
        if (!array_key_exists($name, $this->relations)) {
            throw new ModelException(sprintf('%s::%s() has not been loaded. Use with(\'%s\') in the query, or load(\'%s\') on the model.', static::class, $name, $name, $name));
        }

        return $this->relations[$name];
    }

    /**
     * @param Model|list<Model>|null $value
     * @internal
     */
    public function setRelation(string $name, Model|array|null $value): void
    {
        $this->relations[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if ($this->isRelationMethod($name)) {
            if (!static::meta()->lazyLoading) {
                throw new ModelException(sprintf(
                    'The relation "%s" of %s was not loaded, and models do not load relations by themselves. Use with(\'%s\') in the query or load(\'%s\') on the model.',
                    $name,
                    static::class,
                    $name,
                    $name,
                ));
            }

            $this->load($name);

            return $this->relations[$name];
        }

        throw new ModelException(sprintf('%s has no property or relation "%s".', static::class, $name));
    }

    public function __isset(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo($this, $related, $foreignKey ?? self::snake($related) . '_id', $ownerKey ?? ModelMetadata::for($related)->key);
    }

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey ?? self::snake(static::class) . '_id', $localKey ?? static::meta()->key);
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey ?? self::snake(static::class) . '_id', $localKey ?? static::meta()->key);
    }

    /**
     * @param string $pivot table that links the two models
     */
    protected function belongsToMany(string $related, string $pivot, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): BelongsToMany
    {
        return new BelongsToMany(
            $this,
            $related,
            $pivot,
            $foreignPivotKey ?? self::snake(static::class) . '_id',
            $relatedPivotKey ?? self::snake($related) . '_id',
            static::meta()->key,
            ModelMetadata::for($related)->key,
        );
    }

    // Output

    /**
     * The #[Expose] properties, plus loaded relations declared with #[Expose]. Nothing else is ever shown.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = static::meta();
        $vars = get_object_vars($this);
        $out = [];

        foreach ($meta->columns as $name => $column) {
            if ($column['expose'] && array_key_exists($name, $vars)) {
                $out[$name] = $meta->toOutput($column, $vars[$name]);
            }
        }

        foreach ($meta->exposedRelations as $name) {
            if (!array_key_exists($name, $this->relations)) {
                continue;
            }

            $value = $this->relations[$name];
            $out[$name] = $value instanceof Model ? $value->toArray() : (is_array($value) ? array_map(static fn (Model $model): array => $model->toArray(), $value) : null);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // Internals shared with ModelQuery and the relations

    /**
     * @internal
     */
    public static function meta(): ModelMetadata
    {
        return ModelMetadata::for(static::class);
    }

    /**
     * Replaces the model's values with a row read from the database.
     *
     * @param array<string, mixed> $row
     * @internal
     */
    public function fillFromRow(array $row, Connection $connection): void
    {
        $meta = static::meta();

        foreach ($meta->columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                $this->{$name} = $meta->fromStorage($name, $column, $row[$name]);
            }
        }

        $this->connection = $connection;
        $this->exists = true;
        $this->original = $this->storable();
    }

    /**
     * @internal
     */
    public function connection(): Connection
    {
        return $this->connection ??= ModelContext::connection(static::meta()->connection);
    }

    /**
     * @internal
     */
    public function useConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    private function performInsert(): bool
    {
        $meta = static::meta();
        $now = ModelMetadata::now();
        $vars = get_object_vars($this);

        if ($meta->timestamps) {
            $this->created_at ??= $now;
            $this->updated_at ??= $now;
        }

        if ($meta->version !== null && !array_key_exists($meta->version, $vars)) {
            $this->{$meta->version} = 1;
        }

        $values = $this->storable();
        $generated = $meta->incrementing && !array_key_exists($meta->key, $values);

        if ($values === []) {
            throw new ModelException(sprintf('%s has no values to insert.', static::class));
        }

        if (!$meta->incrementing && !array_key_exists($meta->key, $values)) {
            throw new ModelException(sprintf('%s::$%s must be set before saving: the database does not generate it.', static::class, $meta->key));
        }

        $builder = $this->connection()->table($meta->table);

        if ($generated) {
            $id = $builder->insertGetId($values, $meta->key);
            $this->{$meta->key} = $meta->fromStorage($meta->key, $meta->columns[$meta->key], $id);
        } else {
            $builder->insert($values);
        }

        $this->exists = true;
        $this->original = $this->storable();

        return true;
    }

    private function performUpdate(): bool
    {
        $meta = static::meta();
        $this->assertPersisted('save');
        $changes = $this->dirty();

        if ($changes === []) {
            return true;
        }

        if ($meta->timestamps && !array_key_exists('updated_at', $changes)) {
            $this->updated_at = ModelMetadata::now();
            $changes['updated_at'] = $meta->toStorage($meta->columns['updated_at'], $this->updated_at);
        }

        $builder = $this->connection()->table($meta->table)->where($meta->key, $this->getKey());

        if ($meta->version !== null) {
            $before = $this->original[$meta->version] ?? null;

            if (!is_int($before)) {
                throw new ModelException(sprintf('%s::$%s was not loaded, so the update cannot be checked against concurrent changes.', static::class, $meta->version));
            }

            $changes[$meta->version] = $before + 1;
            $builder->where($meta->version, $before);
        }

        $affected = $builder->update($changes);

        if ($meta->version !== null) {
            if ($affected === 0) {
                throw new StaleModelException(static::class);
            }

            $this->{$meta->version} = $changes[$meta->version];
        }

        $this->original = $this->storable();

        return true;
    }

    /**
     * The initialized columns as what is bound in SQL.
     *
     * @return array<string, bool|int|float|string|null>
     */
    private function storable(): array
    {
        $meta = static::meta();
        $values = [];

        foreach (array_intersect_key(get_object_vars($this), $meta->columns) as $name => $value) {
            $values[$name] = $meta->toStorage($meta->columns[$name], $value);
        }

        return $values;
    }

    private function assertPersisted(string $operation): void
    {
        if (!$this->exists || $this->getKey() === null) {
            throw new ModelException(sprintf('%s::%s() needs a model that was loaded or saved.', static::class, $operation));
        }
    }

    /**
     * The relation declared by the method $name, or an error if there is none.
     *
     * @internal
     */
    public function relationFor(string $name): Relation
    {
        if (!$this->isRelationMethod($name)) {
            throw new ModelException(sprintf('%s has no relation "%s". A relation is a public method declared to return a Relation.', static::class, $name));
        }

        return $this->{$name}();
    }

    /**
     * One property value, or null when it is not set.
     *
     * @internal
     */
    public function attribute(string $name): mixed
    {
        return get_object_vars($this)[$name] ?? null;
    }

    private function isRelationMethod(string $name): bool
    {
        if (!method_exists($this, $name)) {
            return false;
        }

        $method = new ReflectionMethod($this, $name);
        $type = $method->getReturnType();

        return $method->isPublic() && !$method->isStatic() && $type instanceof \ReflectionNamedType && is_a($type->getName(), Relation::class, true);
    }

    private static function snake(string $class): string
    {
        $short = ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}
