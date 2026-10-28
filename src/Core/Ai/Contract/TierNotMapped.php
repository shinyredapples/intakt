<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final class TierNotMapped extends TierResolutionFailure
{
    public function __construct(string $project, Tier $tier)
    {
        parent::__construct(sprintf('Project "%s" maps no model to the "%s" tier.', $project, $tier->value));
    }
}
