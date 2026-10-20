<?php

declare(strict_types=1);

namespace Ethernize\Strata\Query;

/**
 * SQL text plus its bound values, kept apart on purpose.
 *
 * @internal produced by QueryBuilder; use Connection::selectRaw()/executeRaw() for hand-written SQL
 */
final class CompiledQuery
{
    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {
    }
}
