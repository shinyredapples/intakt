<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// Records the judge's model call the way the real vendor does, with the round the judge request names, so a round's token count can be shown to include it.
final readonly class JudgeCallingVendor implements Vendor
{
    /**
     * @param Closure(JudgeRequest): void $judgeCall
     */
    public function __construct(
        private Vendor $reviewers,
        private Closure $judgeCall,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return $this->reviewers->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        ($this->judgeCall)($request);

        return $this->reviewers->judge($project, $request);
    }
}
