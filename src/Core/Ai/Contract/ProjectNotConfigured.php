<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final class ProjectNotConfigured extends TierResolutionFailure
{
    public function __construct(string $project)
    {
        parent::__construct(sprintf('Project "%s" is not configured.', $project));
    }
}
