<?php

declare(strict_types=1);

namespace Ethernize\Strata\Exception;

/**
 * Mass assignment named a property that is not marked #[Fillable] (or does not exist).
 */
final class MassAssignmentException extends ModelException
{
    /**
     * @param list<string> $names
     */
    public function __construct(string $class, public readonly array $names)
    {
        parent::__construct(sprintf('%s cannot be filled with: %s. Mark the property #[Fillable], or assign it in code.', $class, implode(', ', $names)));
    }
}
