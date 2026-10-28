<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final class TierProviderDisabled extends TierResolutionFailure
{
    public function __construct(string $project, Tier $tier, string $disabledMessage)
    {
        parent::__construct(sprintf('Project "%s" maps the "%s" tier to a disabled provider. %s', $project, $tier->value, $disabledMessage));
    }
}
