<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Closure;
use Ethernize\Strata\Exception\ModelException;
use Ethernize\Strata\Model\Model;
use Ethernize\Strata\Model\ModelMetadata;
use Ethernize\Strata\Model\ModelQuery;
use Ethernize\Strata\Query\QueryBuilder;
use Ethernize\Strata\Support\Identifier;

/**
 * Many to many through a link table: a product has many tags, a tag has many products
 * (product_tag.product_id, product_tag.tag_id). The link table is not a model; attach(), detach() and
 * sync() write to it directly, with bound values and inside a transaction.
 */
final class BelongsToMany extends Relation
{
    private const OWNER = 'pivot_owner_key';

    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $pivot,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey,
        private readonly string $relatedKey,
    ) {
        parent::__construct($parent, $related);
        Identifier::assertName($pivot);
        Identifier::assertName($foreignPivotKey);
        Identifier::assertName($relatedPivotKey);
    }

    public function eagerLoad(string $name, array $parents, ?Closure $constraint, array $nested): void
    {
        $keys = $this->keys($parents, $this->parentKey);
        $grouped = [];

        if ($keys !== []) {
            $query = $this->joined($this->newQuery($parents[0]))
                ->constrainTo(fn (QueryBuilder $b): mixed => $b->whereIn($this->pivot . '.' . $this->foreignPivotKey, $keys))
                ->select($this->qualified('*'), $this->pivot . '.' . $this->foreignPivotKey . ' as ' . self::OWNER);

            if ($constraint !== null) {
                $constraint($query);
            }

            $rows = $query->rows();
            $models = $query->hydrateAll($rows);
            $this->loadNested($models, $nested);

            foreach ($models as $index => $model) {
                $grouped[(string) $rows[$index][self::OWNER]][] = $model;
            }
        }

        foreach ($parents as $parent) {
            $key = $parent->attribute($this->parentKey);
            $parent->setRelation($name, $key === null ? [] : ($grouped[(string) $key] ?? []));
        }
    }

    protected function constrainForParent(ModelQuery $query): void
    {
        $key = $this->parentKey($this->parentKey);
        $this->joined($query)->constrainTo(fn (QueryBuilder $b): mixed => $b->where($this->pivot . '.' . $this->foreignPivotKey, $key));
    }

    /**
     * Links the parent to the given related keys, skipping the ones already linked.
     *
     * @param list<int|string>|int|string $ids
     * @return list<int|string> the keys that were linked now
     */
    public function attach(array|int|string $ids): array
    {
        $ids = $this->ids($ids);
        $owner = $this->parentKey($this->parentKey);
        $connection = $this->parent->connection();

        return $connection->transaction(function () use ($ids, $owner, $connection): array {
            $linked = array_map('strval', $connection->table($this->pivot)->where($this->foreignPivotKey, $owner)->whereIn($this->relatedPivotKey, $ids)->pluck($this->relatedPivotKey));
            $new = array_values(array_filter($ids, static fn (int|string $id): bool => !in_array((string) $id, $linked, true)));

            if ($new !== []) {
                $connection->table($this->pivot)->insert(array_map(fn (int|string $id): array => [$this->foreignPivotKey => $owner, $this->relatedPivotKey => $id], $new));
            }

            return $new;
        });
    }

    /**
     * Removes the links to the given keys, or every link of the parent when none are given.
     *
     * @param list<int|string>|int|string|null $ids
     * @return int number of links removed
     */
    public function detach(array|int|string|null $ids = null): int
    {
        $builder = $this->parent->connection()->table($this->pivot)->where($this->foreignPivotKey, $this->parentKey($this->parentKey));

        if ($ids !== null) {
            $ids = $this->ids($ids);

            if ($ids === []) {
                return 0;
            }

            $builder->whereIn($this->relatedPivotKey, $ids);
        }

        return $builder->delete();
    }

    /**
     * Makes the parent linked to exactly the given keys.
     *
     * @param list<int|string> $ids
     * @return array{attached: list<int|string>, detached: list<int|string>}
     */
    public function sync(array $ids): array
    {
        $ids = $this->ids($ids);
        $owner = $this->parentKey($this->parentKey);
        $connection = $this->parent->connection();

        return $connection->transaction(function () use ($ids, $owner, $connection): array {
            $current = $connection->table($this->pivot)->where($this->foreignPivotKey, $owner)->pluck($this->relatedPivotKey);
            $wanted = array_map('strval', $ids);
            $remove = array_values(array_filter($current, static fn (mixed $id): bool => !in_array((string) $id, $wanted, true)));

            if ($remove !== []) {
                $connection->table($this->pivot)->where($this->foreignPivotKey, $owner)->whereIn($this->relatedPivotKey, $remove)->delete();
            }

            return ['attached' => $this->attach($ids), 'detached' => $remove];
        });
    }

    private function joined(ModelQuery $query): ModelQuery
    {
        return $query->join($this->pivot, $this->pivot . '.' . $this->relatedPivotKey, '=', $this->qualified($this->relatedKey));
    }

    /**
     * @param list<int|string>|int|string $ids
     * @return list<int|string>
     */
    private function ids(array|int|string $ids): array
    {
        $ids = is_array($ids) ? $ids : [$ids];

        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new ModelException('Keys to attach or detach must be integers or strings.');
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }
}
