<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class RoundTierNotMapped extends RuntimeException implements ReportableFailure
{
    public function __construct(string $project, Tier $tier)
    {
        parent::__construct(sprintf('Project "%s" maps no model to the "%s" tier, which this round needs: map a model to it in the project\'s tiers and review again.', $project, $tier->value));
    }
}
