<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

use Ethernize\Core\Http\Diagnosable;

/**
 * A statement failed in the database.
 *
 * getMessage() carries only the SQLSTATE class. The SQL text (structure only,
 * never the bound values) is available from sql(). The driver message, which
 * may repeat values from the row (a duplicate key error does), is available
 * from driverMessage() for code that decides to log it, and is never part of
 * the exception chain.
 */
final class QueryException extends StrataException implements Diagnosable
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

    public function hint(): ?string
    {
        $message = strtolower($this->driverMessage);

        return match (true) {
            in_array($this->sqlState, ['42S02', '42P01'], true), str_contains($message, 'no such table'), str_contains($message, "doesn't exist") && str_contains($message, 'table')
                => 'A table does not exist. If it should, run the migrations: php bin/strata.php migrate (and php bin/strata.php status to see which are pending).',
            in_array($this->sqlState, ['42S22', '42703'], true), str_contains($message, 'no such column'), str_contains($message, 'unknown column')
                => 'A column does not exist. Check the spelling, and that the migration that adds it has run.',
            $this->isConstraintViolation()
                => 'A unique, foreign key, NOT NULL or CHECK constraint rejected the row. The driver message (debug only) says which one.',
            str_contains($message, 'locked'), str_contains($message, 'deadlock'), $this->sqlState === '40001'
                => 'The database was busy or two transactions collided. Keep transactions short; retrying the whole operation is safe.',
            default => null,
        };
    }

    public function context(): array
    {
        return ['SQL' => $this->sql, 'Driver message' => $this->driverMessage];
    }

    /**
     * True for integrity constraint violations (SQLSTATE class 23).
     */
    public function isConstraintViolation(): bool
    {
        return $this->sqlState !== null && str_starts_with($this->sqlState, '23');
    }
}
