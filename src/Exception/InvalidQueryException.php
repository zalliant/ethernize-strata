<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * The query was built incorrectly: invalid identifier, unknown operator,
 * unsupported value type, missing WHERE on update or delete, and so on.
 * Thrown before anything reaches the database.
 */
final class InvalidQueryException extends StrataException
{
}
