<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Verdict;
use NoDiscard;

interface Vendor
{
    /**
     * @throws UsageNotRecorded
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function review(string $project, Tier $tier, string $task): Verdict;
}
