<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * The description of a table passed to Schema::create() or Schema::table(). It only collects columns,
 * keys, indexes, constraints and (when altering) changes; Schema turns them into SQL for the connection's
 * dialect.
 *
 * CHECK constraints are described by column, operator and value, never by SQL text, so a constraint can be
 * rendered for any engine and values are always quoted by the connection.
 */
final class Blueprint
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /** @var list<Column> */
    private array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool, name: string}> */
    private array $indexes = [];

    /** @var list<string>|null */
    private ?array $primary = null;

    /** @var list<ForeignKey> */
    private array $foreignKeys = [];

    /** @var list<array{name: string, kind: string, column: string, operator: string, values: list<bool|int|float|string>}> */
    private array $checks = [];

    /** @var list<string> */
    private array $dropColumns = [];

    /** @var array<string, string> from => to */
    private array $renameColumns = [];

    /** @var list<string> */
    private array $dropIndexes = [];

    /** @var list<string> */
    private array $dropForeign = [];

    /** @var list<string> */
    private array $dropChecks = [];

    public function __construct(public readonly string $table, public readonly bool $altering = false)
    {
        Identifier::assertName($table);
    }

    /**
     * Auto-incrementing primary key named "id" (or $name).
     */
    public function id(string $name = 'id'): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'bigInteger', null, true));
    }

    public function string(string $name, int $length = 255): Column
    {
        if ($length < 1 || $length > 4000) {
            throw new InvalidQueryException('A string column has between 1 and 4000 characters.');
        }

        return $this->add(new Column(Identifier::assertName($name), 'string', $length));
    }

    public function text(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'text'));
    }

    public function integer(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'integer'));
    }

    public function bigInteger(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'bigInteger'));
    }

    public function boolean(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'boolean'));
    }

    public function float(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'float'));
    }

    /**
     * Exact decimal number. Money is better kept as integer cents; use this when the unit is not a cent.
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        if ($precision < 1 || $precision > 38 || $scale < 0 || $scale > $precision) {
            throw new InvalidQueryException('A decimal has a precision from 1 to 38 and a scale from 0 up to the precision.');
        }

        return $this->add(new Column(Identifier::assertName($name), 'decimal', $precision, false, $scale));
    }

    public function date(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'date'));
    }

    public function dateTime(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'dateTime'));
    }

    /**
     * A column for the id of another table (unsigned bigint, to match id()). Chain ->constrained('table') to
     * add the foreign key.
     */
    public function foreignId(string $name): Column
    {
        $column = $this->add((new Column(Identifier::assertName($name), 'bigInteger'))->unsigned());
        $column->foreignRegistrar = fn (string $table, string $referenced): ForeignKey => $this->foreign($name)->on($table)->references($referenced);

        return $column;
    }

    /**
     * created_at and updated_at, both nullable date-times that the application fills in.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * A nullable deleted_at column for soft deletes.
     */
    public function softDeletes(string $name = 'deleted_at'): Column
    {
        return $this->dateTime($name)->nullable();
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = $this->names($columns);
        $this->indexes[] = ['columns' => $columns, 'unique' => false, 'name' => $name !== null ? Identifier::assertName($name) : self::indexName($this->table, $columns, false)];
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $columns = $this->names($columns);
        $this->indexes[] = ['columns' => $columns, 'unique' => true, 'name' => $name !== null ? Identifier::assertName($name) : self::indexName($this->table, $columns, true)];
    }

    /**
     * A (composite) primary key, for tables without an id() such as link tables. Only when creating.
     *
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): void
    {
        if ($this->altering) {
            throw new InvalidQueryException('A primary key cannot be added to an existing table here: SQLite cannot do it at all. Rebuild the table or use rawStatement().');
        }

        $this->primary = $this->names($columns);
    }

    /**
     * @param string|list<string> $columns
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        $columns = $this->names($columns);

        return $this->foreignKeys[] = new ForeignKey($columns, $name !== null ? Identifier::assertName($name) : self::foreignName($this->table, $columns));
    }

    /**
     * CHECK (column operator value): check('price_cents', '>=', 0).
     */
    public function check(string $column, string $operator, bool|int|float|string $value, ?string $name = null): void
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException('Unsupported check operator. Use one of: ' . implode(', ', self::OPERATORS) . '.');
        }

        $this->addCheck('compare', $column, $operator, [self::finite($value)], $name);
    }

    /**
     * CHECK (left operator right) between two columns: checkColumns('ends_at', '>=', 'starts_at').
     */
    public function checkColumns(string $left, string $operator, string $right, ?string $name = null): void
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException('Unsupported check operator. Use one of: ' . implode(', ', self::OPERATORS) . '.');
        }

        $this->addCheck('columns', $left, $operator, [Identifier::assertName($right)], $name);
    }

    /**
     * CHECK (column IN (...)), the portable form of an enumeration.
     *
     * @param list<int|float|string> $values
     */
    public function checkIn(string $column, array $values, ?string $name = null): void
    {
        if ($values === [] || !array_is_list($values) || count($values) > 200) {
            throw new InvalidQueryException('checkIn() takes a list of 1 to 200 values.');
        }

        $this->addCheck('in', $column, 'IN', array_map(static fn (mixed $value): int|float|string => is_bool($value) || !(is_int($value) || is_float($value) || is_string($value)) ? throw new InvalidQueryException('checkIn() values must be int, float or string.') : self::finite($value), $values), $name);
    }

    /**
     * CHECK (column BETWEEN from AND to).
     */
    public function checkBetween(string $column, int|float|string $from, int|float|string $to, ?string $name = null): void
    {
        $this->addCheck('between', $column, 'BETWEEN', [self::finite($from), self::finite($to)], $name);
    }

    // Changes to an existing table (Schema::table() only)

    public function dropColumn(string ...$columns): void
    {
        $this->assertAltering('dropColumn');

        foreach ($columns as $column) {
            $this->dropColumns[] = Identifier::assertName($column);
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->assertAltering('renameColumn');
        $this->renameColumns[Identifier::assertName($from)] = Identifier::assertName($to);
    }

    /**
     * Drops an index by name, or by the columns it was created for with the default name.
     *
     * @param string|list<string> $nameOrColumns
     */
    public function dropIndex(string|array $nameOrColumns): void
    {
        $this->assertAltering('dropIndex');
        $this->dropIndexes[] = is_array($nameOrColumns) ? self::indexName($this->table, $this->names($nameOrColumns), false) : Identifier::assertName($nameOrColumns);
    }

    /**
     * @param string|list<string> $nameOrColumns
     */
    public function dropUnique(string|array $nameOrColumns): void
    {
        $this->assertAltering('dropUnique');
        $this->dropIndexes[] = is_array($nameOrColumns) ? self::indexName($this->table, $this->names($nameOrColumns), true) : Identifier::assertName($nameOrColumns);
    }

    /**
     * @param string|list<string> $nameOrColumns
     */
    public function dropForeign(string|array $nameOrColumns): void
    {
        $this->assertAltering('dropForeign');
        $this->dropForeign[] = is_array($nameOrColumns) ? self::foreignName($this->table, $this->names($nameOrColumns)) : Identifier::assertName($nameOrColumns);
    }

    public function dropCheck(string $name): void
    {
        $this->assertAltering('dropCheck');
        $this->dropChecks[] = Identifier::assertName($name);
    }

    // Reading (used by Schema)

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<array{columns: list<string>, unique: bool, name: string}>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<string>|null
     */
    public function primaryKey(): ?array
    {
        return $this->primary;
    }

    /**
     * @return list<ForeignKey>
     */
    public function foreignKeys(): array
    {
        foreach ($this->foreignKeys as $key) {
            if ($key->referencedTable === null) {
                throw new InvalidQueryException(sprintf('The foreign key on (%s) needs on("table").', implode(', ', $key->columns)));
            }
        }

        return $this->foreignKeys;
    }

    /**
     * @return list<array{name: string, kind: string, column: string, operator: string, values: list<bool|int|float|string>}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array{dropColumns: list<string>, renameColumns: array<string, string>, dropIndexes: list<string>, dropForeign: list<string>, dropChecks: list<string>}
     */
    public function changes(): array
    {
        return ['dropColumns' => $this->dropColumns, 'renameColumns' => $this->renameColumns, 'dropIndexes' => $this->dropIndexes, 'dropForeign' => $this->dropForeign, 'dropChecks' => $this->dropChecks];
    }

    /**
     * @param list<string> $columns
     */
    public static function indexName(string $table, array $columns, bool $unique): string
    {
        return self::shorten(($unique ? 'uniq_' : 'idx_') . $table . '_' . implode('_', $columns), $unique ? 'uniq_' : 'idx_');
    }

    /**
     * @param list<string> $columns
     */
    public static function foreignName(string $table, array $columns): string
    {
        return self::shorten('fk_' . $table . '_' . implode('_', $columns), 'fk_');
    }

    private static function shorten(string $name, string $prefix): string
    {
        return strlen($name) <= 60 ? $name : $prefix . substr(sha1($name), 0, 32);
    }

    private function add(Column $column): Column
    {
        foreach ($this->columns as $existing) {
            if ($existing->name === $column->name) {
                throw new InvalidQueryException(sprintf('The column "%s" is defined twice.', $column->name));
            }
        }

        return $this->columns[] = $column;
    }

    /**
     * @param list<bool|int|float|string> $values
     */
    private function addCheck(string $kind, string $column, string $operator, array $values, ?string $name): void
    {
        Identifier::assertName($column);
        $this->checks[] = [
            'name' => $name !== null ? Identifier::assertName($name) : self::shorten('chk_' . $this->table . '_' . $column . '_' . (count($this->checks) + 1), 'chk_'),
            'kind' => $kind,
            'column' => $column,
            'operator' => $operator,
            'values' => $values,
        ];
    }

    private static function finite(bool|int|float|string $value): bool|int|float|string
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidQueryException('A check value must be finite.');
        }

        return $value;
    }

    private function assertAltering(string $method): void
    {
        if (!$this->altering) {
            throw new InvalidQueryException(sprintf('%s() changes an existing table: use Schema::table(), not Schema::create().', $method));
        }
    }

    /**
     * @param string|list<string> $columns
     * @return list<string>
     */
    private function names(string|array $columns): array
    {
        $list = array_map(static fn (string $name): string => Identifier::assertName($name), (array) $columns);

        if ($list === []) {
            throw new InvalidQueryException('A key or index needs at least one column.');
        }

        return array_values($list);
    }
}
