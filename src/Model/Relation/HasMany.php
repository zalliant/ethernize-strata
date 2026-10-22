<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Relation;

use Ethernize\Strata\Model\Model;

final class HasMany extends HasOneOrMany
{
    /**
     * @param list<Model> $related
     * @return list<Model>
     */
    protected function match(array $related): array
    {
        return $related;
    }
}
