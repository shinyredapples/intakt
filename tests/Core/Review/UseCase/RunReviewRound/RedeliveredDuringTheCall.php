<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Override;

// Runs what a test chooses in the middle of the vendor call, which forces the order "second delivery while the first is still reviewing" without any timing.
final readonly class RedeliveredDuringTheCall implements Vendor
{
    public function __construct(private Closure $meanwhile)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        ($this->meanwhile)();

        return new Verdict(Decision::Approve, [], [], []);
    }
}
