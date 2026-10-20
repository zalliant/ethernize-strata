<?php

declare(strict_types=1);

namespace Ethernize\Strata\Query;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * A small fluent query builder for one table.
 *
 * Column and table names are validated and quoted; values are always bound
 * parameters. There is no method that accepts a fragment of SQL: use
 * Connection::selectRaw() or executeRaw() when the builder is not enough.
 *
 *   $connection->table('notes')->where('done', false)->orderBy('id', 'desc')->limit(10)->get();
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];
    private const MAX_IN = 10000;
    private const MAX_PARAMETERS = 10000;

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array<string, mixed>> */
    private array $wheres = [];

    /** @var list<array{string, string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private bool $allowAll = false;

    public function __construct(private readonly Connection $connection, private readonly string $table)
    {
        Identifier::quote($connection->dialect(), $table);
    }

    // Building

    public function select(string ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('select() needs at least one column.');
        }

        foreach ($columns as $column) {
            Identifier::quote($this->connection->dialect(), $column, allowStar: true);
        }

        $this->columns = array_values($columns);

        return $this;
    }

    /**
     * where('done', false)  or  where('age', '>=', 18)  or  where(fn ($q) => $q->where(...)->orWhere(...))
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('and', $column, $operator, $value, func_num_args());
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('or', $column, $operator, $value, func_num_args());
    }

    /**
     * @param list<bool|int|float|string|null> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addIn($column, $values, false);
    }

    /**
     * @param list<bool|int|float|string|null> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addIn($column, $values, true);
    }

    public function whereNull(string $column): self
    {
        Identifier::quote($this->connection->dialect(), $column);
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'negate' => false];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        Identifier::quote($this->connection->dialect(), $column);
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'negate' => true];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidQueryException('The order direction must be "asc" or "desc".');
        }

        Identifier::quote($this->connection->dialect(), $column);
        $this->orders[] = [$column, $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidQueryException('The limit cannot be negative.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException('The offset cannot be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Confirms that update() or delete() should touch every row of the table.
     */
    public function allowAll(): self
    {
        $this->allowAll = true;

        return $this;
    }

    // Reading

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->fetchAll($this->compileSelect());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $copy = clone $this;
        $copy->limit = 1;

        return $copy->get()[0] ?? null;
    }

    /**
     * Number of matching rows. Columns, ordering and paging are ignored.
     */
    public function count(): int
    {
        $bindings = [];
        $sql = 'SELECT COUNT(*) AS aggregate FROM ' . $this->quotedTable() . $this->compileWhere($bindings);
        $rows = $this->connection->fetchAll(new CompiledQuery($sql, $bindings));

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    public function toSql(): string
    {
        return $this->compileSelect()->sql;
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return array_values($this->compileSelect()->bindings);
    }

    // Writing

    /**
     * Inserts one row (an array of column => value) or several (a list of such arrays).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @return int number of rows inserted
     */
    public function insert(array $values): int
    {
        return $this->connection->execute($this->compileInsert($values, null));
    }

    /**
     * Inserts one row and returns its generated id.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $idColumn = 'id'): int|string
    {
        if ($values === [] || array_is_list($values)) {
            throw new InvalidQueryException('insertGetId() takes one row as column => value.');
        }

        $dialect = $this->connection->dialect();

        if ($dialect->usesReturning()) {
            $rows = $this->connection->fetchAll($this->compileInsert($values, $idColumn));
            $id = $rows[0][$idColumn] ?? null;

            if (!is_int($id) && !is_string($id)) {
                throw new InvalidQueryException('The database did not return the generated id.');
            }

            return $id;
        }

        $this->connection->execute($this->compileInsert($values, null));

        return $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     * @return int number of rows changed
     */
    public function update(array $values): int
    {
        if ($values === [] || array_is_list($values)) {
            throw new InvalidQueryException('update() takes column => value pairs.');
        }

        $this->assertScoped('update');
        $dialect = $this->connection->dialect();
        $bindings = [];
        $assignments = [];

        foreach ($values as $column => $value) {
            $assignments[] = Identifier::quote($dialect, (string) $column) . ' = ?';
            $bindings[] = $this->scalar($value);
        }

        $sql = 'UPDATE ' . $this->quotedTable() . ' SET ' . implode(', ', $assignments) . $this->compileWhere($bindings);

        return $this->connection->execute(new CompiledQuery($sql, $bindings));
    }

    /**
     * Adds $amount to a column in one atomic statement.
     */
    public function increment(string $column, int $amount = 1): int
    {
        $this->assertScoped('increment');
        $quoted = Identifier::quote($this->connection->dialect(), $column);
        $bindings = [$amount];
        $sql = 'UPDATE ' . $this->quotedTable() . ' SET ' . $quoted . ' = ' . $quoted . ' + ?' . $this->compileWhere($bindings);

        return $this->connection->execute(new CompiledQuery($sql, $bindings));
    }

    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }

    /**
     * @return int number of rows deleted
     */
    public function delete(): int
    {
        $this->assertScoped('delete');
        $bindings = [];
        $sql = 'DELETE FROM ' . $this->quotedTable() . $this->compileWhere($bindings);

        return $this->connection->execute(new CompiledQuery($sql, $bindings));
    }

    // Compilation

    private function compileSelect(): CompiledQuery
    {
        $dialect = $this->connection->dialect();
        $bindings = [];
        $columns = implode(', ', array_map(fn (string $column): string => Identifier::quote($dialect, $column, allowStar: true), $this->columns));
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->quotedTable() . $this->compileWhere($bindings);

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (array $order): string => Identifier::quote($dialect, $order[0]) . ' ' . strtoupper($order[1]),
                $this->orders,
            ));
        }

        return new CompiledQuery($sql . $dialect->limitOffset($this->limit, $this->offset), $bindings);
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    private function compileInsert(array $values, ?string $returning): CompiledQuery
    {
        if ($values === []) {
            throw new InvalidQueryException('insert() needs at least one value.');
        }

        $rows = array_is_list($values) ? $values : [$values];
        $dialect = $this->connection->dialect();
        $first = $rows[0];

        if (!is_array($first) || $first === [] || array_is_list($first)) {
            throw new InvalidQueryException('Each row must be an array of column => value.');
        }

        $columns = array_keys($first);

        if (count($rows) * count($columns) > self::MAX_PARAMETERS) {
            throw new InvalidQueryException('Too many values in one insert. Insert in smaller chunks.');
        }

        $bindings = [];
        $placeholders = [];

        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $columns) {
                throw new InvalidQueryException('All rows of an insert must have the same columns, in the same order.');
            }

            foreach ($row as $value) {
                $bindings[] = $this->scalar($value);
            }

            $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quotedTable(),
            implode(', ', array_map(static fn (int|string $column): string => Identifier::quote($dialect, (string) $column), $columns)),
            implode(', ', $placeholders),
        );

        if ($returning !== null) {
            $sql .= ' RETURNING ' . Identifier::quote($dialect, $returning);
        }

        return new CompiledQuery($sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings appended to
     */
    private function compileWhere(array &$bindings, ?array $wheres = null): string
    {
        $sql = $this->compileConditions($wheres ?? $this->wheres, $bindings);

        return $sql === '' ? '' : ' WHERE ' . $sql;
    }

    /**
     * @param list<array<string, mixed>> $wheres
     * @param list<mixed>                $bindings
     */
    private function compileConditions(array $wheres, array &$bindings): string
    {
        $dialect = $this->connection->dialect();
        $sql = '';

        foreach ($wheres as $index => $where) {
            $part = match ($where['type']) {
                'basic' => Identifier::quote($dialect, $where['column']) . ' ' . $where['operator'] . ' ?',
                'null' => Identifier::quote($dialect, $where['column']) . ($where['negate'] ? ' IS NOT NULL' : ' IS NULL'),
                'in' => $where['values'] === []
                    ? ($where['negate'] ? '1 = 1' : '0 = 1')
                    : Identifier::quote($dialect, $where['column']) . ($where['negate'] ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
                'group' => '(' . $this->compileConditions($where['wheres'], $bindings) . ')',
            };

            if ($where['type'] === 'basic') {
                $bindings[] = $where['value'];
            } elseif ($where['type'] === 'in') {
                array_push($bindings, ...$where['values']);
            }

            $sql .= ($index === 0 ? '' : ' ' . strtoupper($where['boolean']) . ' ') . $part;
        }

        return $sql;
    }

    // Helpers

    private function addWhere(string $boolean, string|Closure $column, mixed $operator, mixed $value, int $arguments): self
    {
        if ($column instanceof Closure) {
            $nested = new self($this->connection, $this->table);
            $column($nested);

            if ($nested->wheres !== []) {
                $this->wheres[] = ['type' => 'group', 'boolean' => $boolean, 'wheres' => $nested->wheres];
            }

            return $this;
        }

        if ($arguments === 2) {
            [$operator, $value] = ['=', $operator];
        }

        $operator = strtoupper(trim((string) $operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException('Unsupported operator. Use one of: ' . implode(', ', self::OPERATORS) . '.');
        }

        Identifier::quote($this->connection->dialect(), $column);

        if ($value === null) {
            if ($operator === '=' || $operator === '!=' || $operator === '<>') {
                $this->wheres[] = ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'negate' => $operator !== '='];

                return $this;
            }

            throw new InvalidQueryException('Only = and != can compare with null.');
        }

        $this->wheres[] = ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $this->scalar($value)];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    private function addIn(string $column, array $values, bool $negate): self
    {
        Identifier::quote($this->connection->dialect(), $column);

        if (!array_is_list($values)) {
            throw new InvalidQueryException('whereIn() takes a list of values.');
        }

        if (count($values) > self::MAX_IN) {
            throw new InvalidQueryException('Too many values in whereIn(). Split the query.');
        }

        $this->wheres[] = ['type' => 'in', 'boolean' => 'and', 'column' => $column, 'values' => array_map(fn (mixed $value): mixed => $this->scalar($value), $values), 'negate' => $negate];

        return $this;
    }

    private function scalar(mixed $value): bool|int|float|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        throw new InvalidQueryException(sprintf('Cannot bind a value of type %s. Use null, bool, int, float or string.', get_debug_type($value)));
    }

    private function assertScoped(string $operation): void
    {
        if ($this->wheres === [] && !$this->allowAll) {
            throw new InvalidQueryException(sprintf('%s() without a condition would change every row. Add a where(), or call allowAll() to confirm.', $operation));
        }
    }

    private function quotedTable(): string
    {
        return Identifier::quote($this->connection->dialect(), $this->table);
    }
}
