<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

use RuntimeException;

/**
 * Base class of everything Strata throws on purpose. Messages never contain
 * credentials or bound values, so they are safe to log.
 */
class StrataException extends RuntimeException
{
}
