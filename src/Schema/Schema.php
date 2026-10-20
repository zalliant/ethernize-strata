<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Closure;
use Ethernize\Strata\Connection;
use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * Minimal schema operations for migrations. It is deliberately small: create,
 * drop, rename and look up tables. Anything else goes through rawStatement(),
 * written for the engine you use.
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
        $definitions = [];

        foreach ($blueprint->columns() as $column) {
            $definitions[] = Identifier::quote($dialect, $column->name) . ' ' . $dialect->columnSql($column, $column->hasDefault ? $this->literal($column) : '');
        }

        $this->connection->executeRaw(sprintf('CREATE TABLE %s (%s)', Identifier::quote($dialect, $table), implode(', ', $definitions)));

        foreach ($blueprint->indexes() as $index) {
            $this->connection->executeRaw(sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                Identifier::quote($dialect, $this->indexName($table, $index['columns'], $index['unique'])),
                Identifier::quote($dialect, $table),
                implode(', ', array_map(static fn (string $name): string => Identifier::quote($dialect, $name), $index['columns'])),
            ));
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

    private function literal(Column $column): string
    {
        $value = $column->default;

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->connection->dialect()->name() === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0'),
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') ?: '0',
            default => $this->connection->quoteLiteral($value),
        };
    }

    /**
     * @param list<string> $columns
     */
    private function indexName(string $table, array $columns, bool $unique): string
    {
        $name = ($unique ? 'uniq_' : 'idx_') . $table . '_' . implode('_', $columns);

        return strlen($name) <= 60 ? $name : ($unique ? 'uniq_' : 'idx_') . substr(sha1($name), 0, 32);
    }
}
