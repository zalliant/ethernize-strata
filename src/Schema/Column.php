<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Closure;
use Ethernize\Strata\Exception\InvalidQueryException;

/**
 * A column being defined. Created by Blueprint; configure it fluently.
 */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public bool|int|float|string|null $default = null;

    /** MySQL and MariaDB only: UNSIGNED integers. Other engines have no such type and ignore it. */
    public bool $unsigned = false;

    /** @var (Closure(string, string): ForeignKey)|null set by Blueprint::foreignId() */
    public ?Closure $foreignRegistrar = null;

    /**
     * @param ?int $length characters of a string, or the precision of a decimal
     * @param ?int $scale  digits after the point of a decimal
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly bool $autoIncrement = false,
        public readonly ?int $scale = null,
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

    public function unsigned(bool $unsigned = true): self
    {
        $this->unsigned = $unsigned;

        return $this;
    }

    /**
     * Declares the foreign key of a foreignId() column: ->constrained('categories') references categories.id.
     * Chain onDelete()/onUpdate() on the result.
     */
    public function constrained(string $table, string $column = 'id'): ForeignKey
    {
        if ($this->foreignRegistrar === null) {
            throw new InvalidQueryException('constrained() is only available on foreignId() columns. Use $table->foreign() for other columns.');
        }

        return ($this->foreignRegistrar)($table, $column);
    }
}
