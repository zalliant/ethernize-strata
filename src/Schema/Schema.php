<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * Schema operations for migrations: create, alter, drop, rename and look up tables. Foreign keys and CHECK
 * constraints are part of create(); table() alters an existing table.
 *
 * What the engines can and cannot do is not hidden:
 *
 *  - SQLite cannot add a foreign key or a CHECK constraint to an existing table, nor drop one. Those calls
 *    fail with an explanation; define them in create(), or rebuild the table with rawStatement().
 *  - SQLite adds a NOT NULL column to an existing table only with a default value, and drops a column only
 *    when no index or key uses it.
 *  - MySQL ignores CHECK constraints before 8.0.16 (MariaDB: 10.2.1) and does not roll back DDL. renameColumn()
 *    needs MySQL 8.0 or MariaDB 10.5.2; the server's own error is reported on older versions.
 *
 * Anything else goes through rawStatement(), written for the engine you use.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param Closure(Blueprint): void $define
     */
    public function create(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);

        if ($blueprint->columns() === []) {
            throw new InvalidQueryException('A table needs at least one column.');
        }

        $dialect = $this->connection->dialect();
        $names = array_map(static fn (Column $column): string => $column->name, $blueprint->columns());
        $definitions = [];

        foreach ($blueprint->columns() as $column) {
            $definitions[] = $this->columnDefinition($column);
        }

        $primary = $blueprint->primaryKey();

        if ($primary !== null) {
            if (array_filter($blueprint->columns(), static fn (Column $column): bool => $column->autoIncrement) !== []) {
                throw new InvalidQueryException('A table with id() already has a primary key.');
            }

            $this->assertKnown($names, $primary, 'primary key');
            $definitions[] = 'PRIMARY KEY (' . $this->columnList($primary) . ')';
        }

        foreach ($blueprint->foreignKeys() as $foreign) {
            $this->assertKnown($names, $foreign->columns, 'foreign key');
            $definitions[] = $this->foreignDefinition($foreign);
        }

        foreach ($blueprint->checks() as $check) {
            $this->assertKnown($names, [$check['column']], 'check');
            $definitions[] = 'CONSTRAINT ' . Identifier::quote($dialect, $check['name']) . ' ' . $this->checkDefinition($check);
        }

        foreach ($blueprint->indexes() as $index) {
            $this->assertKnown($names, $index['columns'], 'index');
        }

        $this->connection->executeRaw(sprintf('CREATE TABLE %s (%s)', Identifier::quote($dialect, $table), implode(', ', $definitions)));

        foreach ($blueprint->indexes() as $index) {
            $this->createIndex($table, $index);
        }
    }

    /**
     * Alters an existing table: add columns, indexes, foreign keys and checks, and drop or rename columns,
     * indexes and constraints. The order is fixed: constraints and indexes are dropped first, then columns
     * are dropped and renamed, then new columns, indexes and constraints are added.
     *
     * @param Closure(Blueprint): void $define
     */
    public function table(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table, altering: true);
        $define($blueprint);

        $dialect = $this->connection->dialect();
        $quotedTable = Identifier::quote($dialect, $table);
        $changes = $blueprint->changes();
        $foreignKeys = $blueprint->foreignKeys();

        if (($changes['dropForeign'] !== [] || $changes['dropChecks'] !== [] || $foreignKeys !== [] || $blueprint->checks() !== []) && !$dialect->altersConstraints()) {
            throw new InvalidQueryException(sprintf(
                '%s cannot add or drop a foreign key or a CHECK constraint on an existing table. Define it in Schema::create(), or rebuild the table with rawStatement().',
                $this->connection->driver(),
            ));
        }

        foreach ($changes['dropForeign'] as $name) {
            $this->connection->executeRaw($dialect->dropForeignSql($quotedTable, Identifier::quote($dialect, $name)));
        }

        foreach ($changes['dropChecks'] as $name) {
            $this->connection->executeRaw($dialect->dropCheckSql($quotedTable, Identifier::quote($dialect, $name)));
        }

        foreach ($changes['dropIndexes'] as $name) {
            $this->connection->executeRaw($dialect->dropIndexSql(Identifier::quote($dialect, $name), $quotedTable));
        }

        foreach ($changes['dropColumns'] as $name) {
            $this->connection->executeRaw(sprintf('ALTER TABLE %s DROP COLUMN %s', $quotedTable, Identifier::quote($dialect, $name)));
        }

        foreach ($changes['renameColumns'] as $from => $to) {
            $this->connection->executeRaw(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $quotedTable, Identifier::quote($dialect, $from), Identifier::quote($dialect, $to)));
        }

        foreach ($blueprint->columns() as $column) {
            $this->assertCanAdd($column);
            $this->connection->executeRaw(sprintf('ALTER TABLE %s ADD COLUMN %s', $quotedTable, $this->columnDefinition($column)));
        }

        foreach ($blueprint->indexes() as $index) {
            $this->createIndex($table, $index);
        }

        foreach ($foreignKeys as $foreign) {
            $this->connection->executeRaw(sprintf('ALTER TABLE %s ADD %s', $quotedTable, $this->foreignDefinition($foreign)));
        }

        foreach ($blueprint->checks() as $check) {
            $this->connection->executeRaw(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $quotedTable, Identifier::quote($dialect, $check['name']), $this->checkDefinition($check)));
        }
    }

    public function drop(string $table): void
    {
        $this->connection->executeRaw('DROP TABLE ' . Identifier::quote($this->connection->dialect(), $table));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->executeRaw('DROP TABLE IF EXISTS ' . Identifier::quote($this->connection->dialect(), $table));
    }

    public function rename(string $from, string $to): void
    {
        $dialect = $this->connection->dialect();
        $this->connection->executeRaw(sprintf('ALTER TABLE %s RENAME TO %s', Identifier::quote($dialect, $from), Identifier::quote($dialect, $to)));
    }

    public function hasTable(string $table): bool
    {
        Identifier::assertName($table);

        return $this->connection->selectRaw($this->connection->dialect()->tableExistsSql(), [$table]) !== [];
    }

    /**
     * Escape hatch: runs one SQL statement as written, for changes this class does not cover.
     * The text must be written by the developer and is checked like any other raw SQL.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function rawStatement(string $sql, array $bindings = []): void
    {
        $this->connection->executeRaw($sql, $bindings);
    }

    private function columnDefinition(Column $column): string
    {
        $dialect = $this->connection->dialect();

        return Identifier::quote($dialect, $column->name) . ' ' . $dialect->columnSql($column, $column->hasDefault ? $this->literal($column->default) : '');
    }

    /**
     * @param list<string> $columns
     */
    private function columnList(array $columns): string
    {
        $dialect = $this->connection->dialect();

        return implode(', ', array_map(static fn (string $name): string => Identifier::quote($dialect, $name), $columns));
    }

    private function foreignDefinition(ForeignKey $foreign): string
    {
        $dialect = $this->connection->dialect();
        $sql = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            Identifier::quote($dialect, (string) $foreign->name),
            $this->columnList($foreign->columns),
            Identifier::quote($dialect, (string) $foreign->referencedTable),
            $this->columnList($foreign->referencedColumns),
        );

        if ($foreign->onDelete !== null) {
            $sql .= ' ON DELETE ' . strtoupper($foreign->onDelete);
        }

        if ($foreign->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($foreign->onUpdate);
        }

        return $sql;
    }

    /**
     * @param array{name: string, kind: string, column: string, operator: string, values: list<bool|int|float|string>} $check
     */
    private function checkDefinition(array $check): string
    {
        $column = Identifier::quote($this->connection->dialect(), $check['column']);
        $values = $check['values'];

        return 'CHECK (' . match ($check['kind']) {
            'compare' => $column . ' ' . $check['operator'] . ' ' . $this->literal($values[0]),
            'columns' => $column . ' ' . $check['operator'] . ' ' . Identifier::quote($this->connection->dialect(), (string) $values[0]),
            'in' => $column . ' IN (' . implode(', ', array_map(fn (mixed $value): string => $this->literal($value), $values)) . ')',
            'between' => $column . ' BETWEEN ' . $this->literal($values[0]) . ' AND ' . $this->literal($values[1]),
        } . ')';
    }

    /**
     * @param array{columns: list<string>, unique: bool, name: string} $index
     */
    private function createIndex(string $table, array $index): void
    {
        $this->connection->executeRaw(sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $index['unique'] ? 'UNIQUE ' : '',
            Identifier::quote($this->connection->dialect(), $index['name']),
            Identifier::quote($this->connection->dialect(), $table),
            $this->columnList($index['columns']),
        ));
    }

    /**
     * @param list<string> $known
     * @param list<string> $columns
     */
    private function assertKnown(array $known, array $columns, string $what): void
    {
        foreach ($columns as $column) {
            if (!in_array($column, $known, true)) {
                throw new InvalidQueryException(sprintf('The %s uses "%s", which is not a column of this table.', $what, $column));
            }
        }
    }

    private function assertCanAdd(Column $column): void
    {
        if ($column->autoIncrement) {
            throw new InvalidQueryException('id() cannot be added to an existing table.');
        }

        if ($this->connection->driver() === 'sqlite' && !$column->nullable && !$column->hasDefault) {
            throw new InvalidQueryException(sprintf('SQLite adds "%s" to an existing table only if it is nullable or has a default().', $column->name));
        }
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->connection->dialect()->name() === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0'),
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') ?: '0',
            default => $this->connection->quoteLiteral((string) $value),
        };
    }
}
