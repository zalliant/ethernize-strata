<?php

declare(strict_types=1);

namespace Ethernize\Strata\Query;

use Ethernize\Strata\Dialect\Dialect;
use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * The ON clause of a join with more than one condition:
 *
 *   ->join('prices', fn (JoinClause $j) => $j->on('prices.product_id', '=', 'products.id')->where('prices.active', true))
 *
 * on() compares two columns; where() compares a column with a bound value.
 */
final class JoinClause
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /** @var list<array{boolean: string, left: string, operator: string, right: string, value: mixed, column: bool}> */
    private array $conditions = [];

    public function __construct(private readonly Dialect $dialect)
    {
    }

    public function on(string $left, string $operator, string $right): self
    {
        return $this->add('and', $left, $operator, $right, true);
    }

    public function orOn(string $left, string $operator, string $right): self
    {
        return $this->add('or', $left, $operator, $right, true);
    }

    public function where(string $column, string $operator, bool|int|float|string $value): self
    {
        return $this->add('and', $column, $operator, $value, false);
    }

    public function orWhere(string $column, string $operator, bool|int|float|string $value): self
    {
        return $this->add('or', $column, $operator, $value, false);
    }

    /**
     * @param list<mixed> $bindings appended to
     * @internal
     */
    public function compile(array &$bindings): string
    {
        if ($this->conditions === []) {
            throw new InvalidQueryException('A join needs at least one on() condition.');
        }

        $sql = '';

        foreach ($this->conditions as $index => $condition) {
            $left = Identifier::quote($this->dialect, $condition['left']);

            if ($condition['column']) {
                $part = $left . ' ' . $condition['operator'] . ' ' . Identifier::quote($this->dialect, (string) $condition['right']);
            } else {
                $part = $left . ' ' . $condition['operator'] . ' ?';
                $bindings[] = $condition['value'];
            }

            $sql .= ($index === 0 ? '' : ' ' . strtoupper($condition['boolean']) . ' ') . $part;
        }

        return $sql;
    }

    private function add(string $boolean, string $left, string $operator, mixed $right, bool $column): self
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException('Unsupported join operator. Use one of: ' . implode(', ', self::OPERATORS) . '.');
        }

        if (is_float($right) && !is_finite($right)) {
            throw new InvalidQueryException('Cannot bind a non-finite number.');
        }

        // Validated now so the mistake surfaces where it was made, not when the SQL is compiled.
        Identifier::quote($this->dialect, $left);

        if ($column) {
            Identifier::quote($this->dialect, (string) $right);
        }

        $this->conditions[] = ['boolean' => $boolean, 'left' => $left, 'operator' => $operator, 'right' => $column ? (string) $right : '', 'value' => $column ? null : $right, 'column' => $column];

        return $this;
    }
}
