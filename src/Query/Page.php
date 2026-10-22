<?php

declare(strict_types=1);

namespace Ethernize\Strata\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of results. Built by QueryBuilder::paginate() (with a total) and simplePaginate() (without one).
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final class Page implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly ?int $total,
        public readonly bool $hasMore,
    ) {
    }

    /** Number of the last page, or null when the total is not known. */
    public function lastPage(): ?int
    {
        return $this->total === null ? null : max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'total' => $this->total,
            'has_more' => $this->hasMore,
        ];
    }
}
