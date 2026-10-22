<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * A model was declared or used incorrectly: bad metadata, a value that does not fit a property's type, a
 * relation that was not loaded. Messages name classes and properties, never values.
 */
class ModelException extends StrataException
{
}
