<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Attribute;

use Attribute;

/**
 * A public property that is not a column: it is never read from or written to the database.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Ignore
{
}
