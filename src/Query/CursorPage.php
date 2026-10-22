<?php

declare(strict_types=1);

namespace Ethernize\Strata\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of a keyset ("cursor") pagination: the rows plus the opaque cursor of the next page, or null
 * at the end. Cursors are not signed and need not be: a forged one only changes a bound comparison value.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final class CursorPage implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $perPage,
        public readonly ?string $nextCursor,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
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
        return ['items' => $this->items, 'per_page' => $this->perPage, 'next_cursor' => $this->nextCursor];
    }

    public static function encode(int|float|string $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): int|float|string|null
    {
        if ($cursor === '' || strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $value = $json === false ? null : json_decode($json, true);

        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }
}
