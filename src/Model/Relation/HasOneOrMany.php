<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Closure;
use Ethernize\Strata\Model\Model;
use Ethernize\Strata\Model\ModelMetadata;
use Ethernize\Strata\Model\ModelQuery;
use Ethernize\Strata\Query\QueryBuilder;

/**
 * The related table holds the foreign key: a category has many products (products.category_id).
 */
abstract class HasOneOrMany extends Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, protected readonly string $foreignKey, protected readonly string $localKey)
    {
        parent::__construct($parent, $related);
    }

    /**
     * @param list<Model> $related
     */
    abstract protected function match(array $related): Model|array|null;

    public function eagerLoad(string $name, array $parents, ?Closure $constraint, array $nested): void
    {
        $keys = $this->keys($parents, $this->localKey);
        $grouped = [];

        if ($keys !== []) {
            $query = $this->newQuery($parents[0])->constrainTo(fn (QueryBuilder $b): mixed => $b->whereIn($this->qualified($this->foreignKey), $keys));

            if ($constraint !== null) {
                $constraint($query);
            }

            $models = $query->get();
            $this->loadNested($models, $nested);

            foreach ($models as $model) {
                $grouped[(string) $model->attribute($this->foreignKey)][] = $model;
            }
        }

        foreach ($parents as $parent) {
            $key = $parent->attribute($this->localKey);
            $parent->setRelation($name, $this->match($key === null ? [] : ($grouped[(string) $key] ?? [])));
        }
    }

    protected function constrainForParent(ModelQuery $query): void
    {
        $key = $this->parentKey($this->localKey);
        $query->constrainTo(fn (QueryBuilder $b): mixed => $b->where($this->qualified($this->foreignKey), $key));
    }

    /**
     * Creates a related model linked to the parent. The foreign key is set by the relation, not by the
     * values, so it never has to be #[Fillable].
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Model
    {
        $key = $this->parentKey($this->localKey);
        $model = ModelMetadata::for($this->related)->instantiate()->useConnection($this->parent->connection());
        $model->fill($attributes);
        $model->{$this->foreignKey} = $key;
        $model->save();

        return $model;
    }
}
