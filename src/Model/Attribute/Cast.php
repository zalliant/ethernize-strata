<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Attribute;

use Attribute;

/**
 * Chooses how a property is stored when its PHP type is not enough:
 *
 *   'date'   a DateTimeImmutable kept as a date (Y-m-d) instead of a date and time
 *   'json'   an array kept as JSON text
 *
 * int, float, string, bool, DateTimeImmutable (UTC, Y-m-d H:i:s) and backed enums need no attribute: the
 * declared type decides.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(public readonly string $as)
    {
    }
}
