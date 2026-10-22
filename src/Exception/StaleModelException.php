<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * An update with optimistic concurrency found the row changed (or deleted) since it was read.
 * Reload the model and decide what to do with the user's edit; nothing was written.
 */
final class StaleModelException extends ModelException
{
    public function __construct(string $model)
    {
        parent::__construct(sprintf('This %s was changed by someone else since it was loaded. Nothing was saved.', $model));
    }
}
