<?php

declare(strict_types=1);

namespace Ethernize\Strata\Model\Attribute;

use Attribute;

/**
 * Includes a property in toArray() and JSON. Nothing is exposed by default, so adding a column (a password
 * hash, a token) never leaks it by accident. On a relation method, it includes the relation when it has been
 * loaded.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Expose
{
}
