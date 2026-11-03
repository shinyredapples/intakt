<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Symfony\AI\Platform\PlatformInterface;

// The real reviewer agent on one platform, without the vendor's tiers and accounts, so a round test runs the agent's own execution.
final readonly class ReviewerOnPlatform implements Vendor
{
    public function __construct(
        private ReviewerAgent $reviewer,
        private PlatformInterface $platform,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return new VendorReview($this->reviewer->review($request, $this->platform, 'reviewer-model'));
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }
}
