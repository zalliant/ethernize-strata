<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Ethernize\Strata\Model\Model;

final class HasOne extends HasOneOrMany
{
    /**
     * @param list<Model> $related
     */
    protected function match(array $related): ?Model
    {
        return $related[0] ?? null;
    }
}
