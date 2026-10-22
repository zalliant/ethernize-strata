<?php

declare(strict_types=1);

namespace Ethernize\Strata\Schema;

use Ethernize\Strata\Exception\InvalidQueryException;
use Ethernize\Strata\Support\Identifier;

/**
 * A foreign key being defined:
 *
 *   $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
 *
 * The actions are the four every supported engine shares: cascade, restrict, set null, no action.
 */
final class ForeignKey
{
    public const ACTIONS = ['cascade', 'restrict', 'set null', 'no action'];

    public ?string $referencedTable = null;

    /** @var list<string> */
    public array $referencedColumns = ['id'];

    public ?string $onDelete = null;
    public ?string $onUpdate = null;

    /**
     * @param list<string> $columns
     */
    public function __construct(public readonly array $columns, public ?string $name = null)
    {
    }

    /**
     * @param string|list<string> $columns
     */
    public function references(string|array $columns): self
    {
        $this->referencedColumns = array_values(array_map(static fn (string $name): string => Identifier::assertName($name), (array) $columns));

        if (count($this->referencedColumns) !== count($this->columns)) {
            throw new InvalidQueryException('A foreign key must reference as many columns as it has.');
        }

        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = Identifier::assertName($table);

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    public function named(string $name): self
    {
        $this->name = Identifier::assertName($name);

        return $this;
    }

    private static function action(string $action): string
    {
        $action = strtolower(trim($action));

        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidQueryException('A foreign key action is one of: ' . implode(', ', self::ACTIONS) . '.');
        }

        return $action;
    }
}
