<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * findOrFail() or firstOrFail() found nothing. Turn it into a 404 in the layer that knows it was a request.
 */
final class ModelNotFoundException extends ModelException
{
    public function __construct(public readonly string $model)
    {
        parent::__construct(sprintf('No %s matches the query.', $model));
    }
}
