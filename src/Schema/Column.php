<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Ethernize\Strata\Exception\InvalidQueryException;

/**
 * A column being defined. Created by Blueprint; configure it fluently.
 */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public bool|int|float|string|null $default = null;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly bool $autoIncrement = false,
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(bool|int|float|string|null $value): self
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidQueryException('A default value must be finite.');
        }

        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }
}
