<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Closure;
use Ethernize\Strata\Model\Model;
use Ethernize\Strata\Model\ModelMetadata;
use Ethernize\Strata\Model\ModelQuery;
use Ethernize\Strata\Query\QueryBuilder;

/**
 * The model holds the foreign key: a product belongs to a category (products.category_id).
 */
final class BelongsTo extends Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, private readonly string $foreignKey, private readonly string $ownerKey)
    {
        parent::__construct($parent, $related);
    }

    public function eagerLoad(string $name, array $parents, ?Closure $constraint, array $nested): void
    {
        $keys = $this->keys($parents, $this->foreignKey);
        $byKey = [];

        if ($keys !== []) {
            $query = $this->newQuery($parents[0])->constrainTo(fn (QueryBuilder $b): mixed => $b->whereIn($this->qualified($this->ownerKey), $keys));

            if ($constraint !== null) {
                $constraint($query);
            }

            $models = $query->get();
            $this->loadNested($models, $nested);

            foreach ($models as $model) {
                $byKey[(string) $model->attribute($this->ownerKey)] = $model;
            }
        }

        foreach ($parents as $parent) {
            $key = $parent->attribute($this->foreignKey);
            $parent->setRelation($name, $key === null ? null : ($byKey[(string) $key] ?? null));
        }
    }

    protected function constrainForParent(ModelQuery $query): void
    {
        $key = $this->parent->attribute($this->foreignKey);
        $query->constrainTo(fn (QueryBuilder $b): mixed => $key === null ? $b->whereIn($this->qualified($this->ownerKey), []) : $b->where($this->qualified($this->ownerKey), $key));
    }

    /**
     * Associates the parent with a saved model by copying its key. Save the parent to store it.
     */
    public function associate(?Model $owner): void
    {
        $this->parent->{$this->foreignKey} = $owner?->attribute($this->ownerKey);
    }
}
