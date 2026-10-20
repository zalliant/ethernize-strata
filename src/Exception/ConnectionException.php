<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * The connection could not be opened. The driver's own message is not exposed
 * and not chained (it can include host and user names), only its numeric code.
 */
final class ConnectionException extends StrataException
{
    public function __construct(string $connection, ?int $driverCode = null)
    {
        parent::__construct(sprintf('Could not connect to the database connection "%s".', $connection), (int) $driverCode);
    }
}
