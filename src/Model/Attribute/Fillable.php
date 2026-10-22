<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Attribute;

use Attribute;

/**
 * Allows a property to be set by mass assignment (create(), fill(), update() with an array). Properties
 * without it can only be assigned in code, one at a time. Mass assignment of anything else is refused, not
 * ignored, so a form field that should not be there is noticed instead of silently dropped.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Fillable
{
}
