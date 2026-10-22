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
 *
 * Joins, grouping, aggregates, DISTINCT and pagination are covered too. Select lists, ordering and HAVING take
 * columns ("orders.total"), aggregates ("count(*)", "sum(items.qty)") and, in select(), an alias ("... as total"):
 *
 *   $connection->table('orders as o')
 *       ->join('customers as c', 'c.id', '=', 'o.customer_id')
 *       ->select('c.name', 'sum(o.total_cents) as spent')
 *       ->groupBy('c.name')->having('sum(o.total_cents)', '>', 10000)
 *       ->orderBy('spent', 'desc')->paginate(20);
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

    /** @var list<array<string, mixed>> */
    private array $havings = [];

    /** @var list<array{type: string, table: string, on: JoinClause}> */
    private array $joins = [];

    /** @var list<string> */
    private array $groups = [];

    private bool $distinct = false;

    /** @var list<array{string, string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private bool $allowAll = false;

    /** The table in FROM, quoted, with its alias when it has one. */
    private readonly string $from;

    /**
     * @param string $table a table name, or "table alias" / "table as alias"
     */
    public function __construct(private readonly Connection $connection, private readonly string $table)
    {
        $this->from = Identifier::table($connection->dialect(), $table)[0];
    }

    // Building

    public function select(string ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('select() needs at least one column.');
        }

        foreach ($columns as $column) {
            Identifier::expression($this->connection->dialect(), $column, allowAlias: true);
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

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * Inner join. Either three arguments (join('customers c', 'c.id', '=', 'orders.customer_id')) or a closure
     * that receives a JoinClause for several conditions. Both sides of the comparison are columns.
     *
     * @param string|Closure(JoinClause): void $first column of the joined table, or the closure
     */
    public function join(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    /**
     * Right join, for the engines that have it (SQLite 3.39 and later, MySQL, MariaDB, PostgreSQL). On a
     * server without it the call fails; write the query as a leftJoin() with the tables swapped instead.
     */
    public function rightJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): self
    {
        if (!$this->connection->dialect()->supportsRightJoin($this->connection->serverVersion())) {
            throw new InvalidQueryException('This database server does not support RIGHT JOIN. Use leftJoin() with the tables swapped.');
        }

        return $this->addJoin('RIGHT', $table, $first, $operator, $second);
    }

    public function groupBy(string ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('groupBy() needs at least one column.');
        }

        foreach ($columns as $column) {
            Identifier::quote($this->connection->dialect(), $column);
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * Condition on a group: having('count(*)', '>', 1) or having('sum(items.qty)', '>=', 10). The first argument
     * is an aggregate or a grouped column (an alias from select() is not portable here: PostgreSQL rejects it).
     */
    public function having(string $expression, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('and', $expression, $operator, $value, func_num_args(), true);
    }

    public function orHaving(string $expression, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('or', $expression, $operator, $value, func_num_args(), true);
    }

    /**
     * Adds conditions that every row must satisfy whatever was added before, even an orWhere(): what was
     * already there is grouped, and the conditions $apply adds are AND-ed outside that group. Used for scopes
     * that must not be bypassed (soft deletes, the parent of a relation).
     *
     * @param Closure(self): mixed $apply adds conditions with where(), whereIn(), whereNull()...
     */
    public function constrain(Closure $apply): self
    {
        $before = $this->wheres;
        $this->wheres = [];
        $apply($this);
        $added = $this->wheres;
        $this->wheres = $before === [] ? $added : [...$added, ['type' => 'group', 'boolean' => 'and', 'wheres' => $before]];

        return $this;
    }

    /**
     * @param bool|int|float|string $from
     * @param bool|int|float|string $to
     */
    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        Identifier::quote($this->connection->dialect(), $column);
        $this->wheres[] = ['type' => 'between', 'boolean' => 'and', 'column' => $column, 'from' => $this->scalar($from), 'to' => $this->scalar($to), 'agg' => false];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidQueryException('The order direction must be "asc" or "desc".');
        }

        Identifier::expression($this->connection->dialect(), $column);
        $this->orders[] = [$column, $direction];

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
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
     * Number of matching rows, or of non-null values of a column (count('distinct customer_id') counts
     * different ones). Columns, ordering and paging are ignored; a grouped, DISTINCT or HAVING query counts
     * the rows it would return.
     */
    public function count(string $column = '*'): int
    {
        if ($this->groups !== [] || $this->havings !== [] || ($this->distinct && $column === '*')) {
            $inner = $this->compileSelect(ordered: false, paged: false);
            $sql = 'SELECT COUNT(*) AS aggregate FROM (' . $inner->sql . ') AS ' . $this->connection->dialect()->quoteIdentifier('counted');
            $rows = $this->connection->fetchAll(new CompiledQuery($sql, $inner->bindings));

            return (int) ($rows[0]['aggregate'] ?? 0);
        }

        $result = $this->aggregate('count', $column);

        return (int) $result;
    }

    /**
     * Whether at least one row matches.
     */
    public function exists(): bool
    {
        $copy = clone $this;
        $copy->columns = ['*'];
        $copy->orders = [];
        $copy->limit = 1;
        $copy->offset = null;

        return $copy->get() !== [];
    }

    public function sum(string $column): int|float|null
    {
        return self::number($this->aggregate('sum', $column));
    }

    public function avg(string $column): ?float
    {
        $value = self::number($this->aggregate('avg', $column));

        return $value === null ? null : (float) $value;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    /**
     * The first column of the first row, or null.
     */
    public function value(string $column): mixed
    {
        $copy = clone $this;
        $copy->columns = [$column];
        $row = $copy->first();

        return $row === null ? null : $row[array_key_first($row)];
    }

    /**
     * One column of every row.
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $copy = clone $this;
        $copy->columns = [$column];

        return array_map(static fn (array $row): mixed => $row[array_key_first($row)], $copy->get());
    }

    /**
     * One page by offset, with the total count (two queries). Pages start at 1. Use simplePaginate() when the
     * total is not needed, or cursorPaginate() for long lists: a large offset still reads every skipped row.
     *
     * @return Page<array<string, mixed>>
     */
    public function paginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertPaging($perPage, $page);
        $total = $this->count();
        $items = $total === 0 ? [] : $this->forPage($perPage, $page)->get();

        return new Page($items, $perPage, $page, $total, $page * $perPage < $total);
    }

    /**
     * One page by offset without counting (one query, reading one row more than it returns).
     *
     * @return Page<array<string, mixed>>
     */
    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertPaging($perPage, $page);
        $rows = $this->forPage($perPage + 1, $page, $perPage)->get();

        return new Page(array_slice($rows, 0, $perPage), $perPage, $page, null, count($rows) > $perPage);
    }

    /**
     * Keyset pagination: the next page starts after the last value of $column, so every page costs the same.
     * The column must be unique and not null (an id, usually), and must appear in the rows (qualified names
     * like "orders.id" are read as "id"). The builder sets the order; orderBy(), limit() and offset() are refused.
     *
     * @return CursorPage<array<string, mixed>>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $column = 'id', string $direction = 'asc'): CursorPage
    {
        $this->assertPaging($perPage, 1);
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidQueryException('The order direction must be "asc" or "desc".');
        }

        if ($this->orders !== [] || $this->limit !== null || $this->offset !== null) {
            throw new InvalidQueryException('cursorPaginate() sets the order and the limit itself. Remove orderBy(), limit() and offset().');
        }

        $query = clone $this;

        if ($cursor !== null && $cursor !== '') {
            $after = CursorPage::decode($cursor);

            if ($after === null) {
                throw new InvalidQueryException('The cursor is not valid.');
            }

            $query->where($column, $direction === 'asc' ? '>' : '<', $after);
        }

        $rows = $query->orderBy($column, $direction)->limit($perPage + 1)->get();
        $more = count($rows) > $perPage;
        $items = array_slice($rows, 0, $perPage);
        $key = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        $last = $items === [] ? null : ($items[count($items) - 1][$key] ?? null);

        if ($more && !is_int($last) && !is_float($last) && !is_string($last)) {
            throw new InvalidQueryException(sprintf('The cursor column "%s" must be selected and not null.', $key));
        }

        return new CursorPage($items, $perPage, $more ? CursorPage::encode($last) : null);
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

    private function compileSelect(bool $ordered = true, bool $paged = true): CompiledQuery
    {
        $dialect = $this->connection->dialect();
        $bindings = [];
        $columns = implode(', ', array_map(static fn (string $column): string => Identifier::expression($dialect, $column, allowAlias: true), $this->columns));
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $columns . ' FROM ' . $this->from . $this->compileJoins($bindings) . $this->compileWhere($bindings);

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(static fn (string $column): string => Identifier::quote($dialect, $column), $this->groups));
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . $this->compileConditions($this->havings, $bindings);
        }

        if ($ordered && $this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                static fn (array $order): string => Identifier::expression($dialect, $order[0]) . ' ' . strtoupper($order[1]),
                $this->orders,
            ));
        }

        return new CompiledQuery($paged ? $sql . $dialect->limitOffset($this->limit, $this->offset) : $sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings appended to
     */
    private function compileJoins(array &$bindings): string
    {
        $sql = '';

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $join['table'] . ' ON ' . $join['on']->compile($bindings);
        }

        return $sql;
    }

    /**
     * A single aggregate over the filtered rows (count, sum, avg, min, max).
     */
    private function aggregate(string $function, string $column): mixed
    {
        if ($this->groups !== [] || $this->havings !== []) {
            throw new InvalidQueryException(sprintf('%s() cannot be used on a grouped query. Select the aggregate instead: select("%s(...) as total").', $function, $function));
        }

        $bindings = [];
        $sql = 'SELECT ' . Identifier::expression($this->connection->dialect(), $function . '(' . $column . ')') . ' AS aggregate FROM ' . $this->from . $this->compileJoins($bindings) . $this->compileWhere($bindings);
        $rows = $this->connection->fetchAll(new CompiledQuery($sql, $bindings));

        return $rows[0]['aggregate'] ?? null;
    }

    private function forPage(int $fetch, int $page, ?int $perPage = null): self
    {
        $copy = clone $this;
        $copy->limit = $fetch;
        $copy->offset = ($page - 1) * ($perPage ?? $fetch);

        return $copy;
    }

    private function assertPaging(int $perPage, int $page): void
    {
        if ($perPage < 1 || $perPage > 1000) {
            throw new InvalidQueryException('A page has between 1 and 1000 rows.');
        }

        if ($page < 1 || $page > 1000000) {
            throw new InvalidQueryException('The page number must be between 1 and 1000000.');
        }
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : (float) $value;
        }

        throw new InvalidQueryException('The database returned a value that is not a number.');
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
            $column = isset($where['column']) ? (($where['agg'] ?? false) ? Identifier::expression($dialect, $where['column']) : Identifier::quote($dialect, $where['column'])) : '';
            $part = match ($where['type']) {
                'basic' => $column . ' ' . $where['operator'] . ' ?',
                'null' => $column . ($where['negate'] ? ' IS NOT NULL' : ' IS NULL'),
                'between' => $column . ' BETWEEN ? AND ?',
                'in' => $where['values'] === []
                    ? ($where['negate'] ? '1 = 1' : '0 = 1')
                    : $column . ($where['negate'] ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
                'group' => '(' . $this->compileConditions($where['wheres'], $bindings) . ')',
            };

            if ($where['type'] === 'basic') {
                $bindings[] = $where['value'];
            } elseif ($where['type'] === 'between') {
                array_push($bindings, $where['from'], $where['to']);
            } elseif ($where['type'] === 'in') {
                array_push($bindings, ...$where['values']);
            }

            $sql .= ($index === 0 ? '' : ' ' . strtoupper($where['boolean']) . ' ') . $part;
        }

        return $sql;
    }

    // Helpers

    private function addWhere(string $boolean, string|Closure $column, mixed $operator, mixed $value, int $arguments, bool $having = false): self
    {
        if ($column instanceof Closure) {
            if ($having) {
                throw new InvalidQueryException('having() takes an expression, not a closure.');
            }

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

        $having ? Identifier::expression($this->connection->dialect(), $column) : Identifier::quote($this->connection->dialect(), $column);

        if ($value === null) {
            if ($operator === '=' || $operator === '!=' || $operator === '<>') {
                $this->condition($having, ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'negate' => $operator !== '=', 'agg' => $having]);

                return $this;
            }

            throw new InvalidQueryException('Only = and != can compare with null.');
        }

        $this->condition($having, ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $this->scalar($value), 'agg' => $having]);

        return $this;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function condition(bool $having, array $condition): void
    {
        if ($having) {
            $this->havings[] = $condition;
        } else {
            $this->wheres[] = $condition;
        }
    }

    /**
     * @param string|Closure(JoinClause): void $first
     */
    private function addJoin(string $type, string $table, string|Closure $first, ?string $operator, ?string $second): self
    {
        $dialect = $this->connection->dialect();
        [$quoted] = Identifier::table($dialect, $table);
        $on = new JoinClause($dialect);

        if ($first instanceof Closure) {
            $first($on);
        } elseif ($operator === null || $second === null) {
            throw new InvalidQueryException('A join takes (table, column, operator, column) or (table, closure).');
        } else {
            $on->on($first, $operator, $second);
        }

        $ignored = [];
        $on->compile($ignored); // a join without conditions fails here, where it was written

        $this->joins[] = ['type' => $type, 'table' => $quoted, 'on' => $on];

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

    /** Writes address the table by its name: an alias only exists inside a SELECT. */
    private function quotedTable(): string
    {
        return Identifier::quote($this->connection->dialect(), preg_split('/\s+/', trim($this->table))[0]);
    }
}
