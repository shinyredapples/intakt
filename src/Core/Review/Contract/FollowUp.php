<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A finding the judge deferred: real, but not part of this task.
#[Exclude]
final readonly class FollowUp
{
    public function __construct(public Finding $finding)
    {
    }
}
