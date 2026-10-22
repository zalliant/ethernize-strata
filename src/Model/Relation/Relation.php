<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Closure;
use Ethernize\Strata\Exception\ModelException;
use Ethernize\Strata\Model\Model;
use Ethernize\Strata\Model\ModelMetadata;
use Ethernize\Strata\Model\ModelQuery;

/**
 * A declared link between two models. A relation method on a model returns one of these; with() and load()
 * use it to fetch the related rows for many models at once (one query, not one per model), and calling the
 * method directly gives a query limited to that one model:
 *
 *   $product->reviews()->where('approved', true)->orderBy('id', 'desc')->get();
 *
 * Whatever is added to such a query cannot widen it beyond its parent: the link is applied outside it.
 *
 * @mixin ModelQuery
 */
abstract class Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(protected readonly Model $parent, protected readonly string $related)
    {
        ModelMetadata::for($related);
    }

    /**
     * Loads the relation for every model in $parents and stores it on them under $name.
     *
     * @param list<Model> $parents
     * @param Closure(ModelQuery): mixed|null $constraint narrows the related query
     * @param array<string, Closure|null> $nested relations of the related models to load as well
     * @internal
     */
    abstract public function eagerLoad(string $name, array $parents, ?Closure $constraint, array $nested): void;

    /**
     * Limits a query on the related model to the rows linked to the parent.
     *
     * @internal
     */
    abstract protected function constrainForParent(ModelQuery $query): void;

    /**
     * A query on the related model, limited to this relation's parent.
     */
    public function query(): ModelQuery
    {
        $query = $this->newQuery($this->parent);
        $this->constrainForParent($query);

        return $query;
    }

    /**
     * @return list<Model>
     */
    public function get(): array
    {
        return $this->query()->get();
    }

    public function first(): ?Model
    {
        return $this->query()->first();
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->query()->{$method}(...$arguments);
    }

    protected function newQuery(Model $from): ModelQuery
    {
        return ($this->related)::on($from->connection());
    }

    /**
     * @param list<Model> $parents
     * @return list<int|string>
     */
    protected function keys(array $parents, string $attribute): array
    {
        $keys = [];

        foreach ($parents as $parent) {
            $value = $parent->attribute($attribute);

            if (is_int($value) || is_string($value)) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }

    protected function parentKey(string $attribute): int|string
    {
        $value = $this->parent->attribute($attribute);

        if (!is_int($value) && !is_string($value)) {
            throw new ModelException(sprintf('%s::$%s is not set, so its relation cannot be queried.', $this->parent::class, $attribute));
        }

        return $value;
    }

    protected function qualified(string $column): string
    {
        return ModelMetadata::for($this->related)->table . '.' . $column;
    }

    /**
     * @param list<Model> $models
     * @param array<string, Closure|null> $nested
     */
    protected function loadNested(array $models, array $nested): void
    {
        ModelQuery::loadRelations($models, $nested);
    }
}
