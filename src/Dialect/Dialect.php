<?php

declare(strict_types=1);

namespace Ethernize\Strata\Dialect;

use Ethernize\Strata\Config\ConnectionConfig;
use Ethernize\Strata\Schema\Column;
use PDO;

/**
 * Everything that differs between database engines, in one place: how to
 * connect, how to quote, how to page, how to describe columns.
 */
interface Dialect
{
    public function name(): string;

    public function dsn(ConnectionConfig $config): string;

    /**
     * Extra PDO options for this driver (merged over the common ones).
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(ConnectionConfig $config): array;

    /**
     * Runs once right after connecting (session settings).
     */
    public function afterConnect(PDO $pdo, ConnectionConfig $config): void;

    /**
     * Quotes one already validated identifier segment.
     */
    public function quoteIdentifier(string $segment): string;

    /**
     * LIMIT/OFFSET clause with a leading space, or an empty string.
     */
    public function limitOffset(?int $limit, ?int $offset): string;

    /**
     * Whether RIGHT JOIN exists on the server of this version (SQLite only has it since 3.39).
     */
    public function supportsRightJoin(string $serverVersion): bool;

    /**
     * Whether ALTER TABLE can add a foreign key or a CHECK constraint to an existing table.
     */
    public function altersConstraints(): bool;

    /**
     * Whether DDL statements can be rolled back with the transaction.
     */
    public function transactionalDdl(): bool;

    /**
     * Whether a backslash escapes the next character inside string literals.
     */
    public function backslashEscapes(): bool;

    /**
     * Whether new ids are read with INSERT ... RETURNING instead of lastInsertId().
     */
    public function usesReturning(): bool;

    /**
     * Column definition SQL (without the name).
     */
    public function columnSql(Column $column, string $literal): string;

    /**
     * DROP INDEX for an already quoted index and table.
     */
    public function dropIndexSql(string $quotedIndex, string $quotedTable): string;

    /**
     * ALTER TABLE ... drop a foreign key, for an already quoted table and constraint name.
     */
    public function dropForeignSql(string $quotedTable, string $quotedName): string;

    /**
     * ALTER TABLE ... drop a CHECK constraint, for an already quoted table and constraint name.
     */
    public function dropCheckSql(string $quotedTable, string $quotedName): string;

    /**
     * SQL that returns one row when the named table exists. One bound parameter: the table name.
     */
    public function tableExistsSql(): string;
}
