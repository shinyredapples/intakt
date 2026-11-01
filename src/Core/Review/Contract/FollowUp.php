<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Wraps the finding because the follow-up inbox, a later task, adds what it needs here, and a caller must not take a bare finding for an open one.
#[Exclude]
final readonly class FollowUp
{
    public function __construct(public Finding $finding)
    {
    }
}
