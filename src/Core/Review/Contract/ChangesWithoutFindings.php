<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DomainException;

// A failure, never a verdict, because a changes or blocked verdict without findings gives the builder nothing to fix and would reach the judge as a rejection that names nothing.
final class ChangesWithoutFindings extends DomainException
{
    public static function fromTheReviewer(): self
    {
        return new self('The reviewer gave no approval without naming a finding.');
    }
}
