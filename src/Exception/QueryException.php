<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * A statement failed in the database.
 *
 * getMessage() carries only the SQLSTATE class. The SQL text (structure only,
 * never the bound values) is available from sql(). The driver message, which
 * may repeat values from the row (a duplicate key error does), is available
 * from driverMessage() for code that decides to log it, and is never part of
 * the exception chain.
 */
final class QueryException extends StrataException
{
    public function __construct(
        private readonly string $sql,
        private readonly ?string $sqlState,
        private readonly ?int $driverCode,
        private readonly string $driverMessage,
    ) {
        parent::__construct(sprintf('The database rejected a statement (SQLSTATE %s).', $sqlState ?? 'unknown'), (int) $driverCode);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    public function driverCode(): ?int
    {
        return $this->driverCode;
    }

    public function driverMessage(): string
    {
        return $this->driverMessage;
    }

    /**
     * True for integrity constraint violations (SQLSTATE class 23).
     */
    public function isConstraintViolation(): bool
    {
        return $this->sqlState !== null && str_starts_with($this->sqlState, '23');
    }
}
