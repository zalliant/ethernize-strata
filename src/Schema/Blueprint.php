<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * The description of a table passed to Schema::create(). It only collects
 * columns and indexes; Schema turns them into SQL for the connection's dialect.
 */
final class Blueprint
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool}> */
    private array $indexes = [];

    public function __construct(public readonly string $table)
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

    public function dateTime(string $name): Column
    {
        return $this->add(new Column(Identifier::assertName($name), 'dateTime'));
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns): void
    {
        $this->indexes[] = ['columns' => $this->names($columns), 'unique' => false];
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns): void
    {
        $this->indexes[] = ['columns' => $this->names($columns), 'unique' => true];
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        return $this->indexes;
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
     * @param string|list<string> $columns
     * @return list<string>
     */
    private function names(string|array $columns): array
    {
        $list = array_map(static fn (string $name): string => Identifier::assertName($name), (array) $columns);

        if ($list === []) {
            throw new InvalidQueryException('An index needs at least one column.');
        }

        return array_values($list);
    }
}
