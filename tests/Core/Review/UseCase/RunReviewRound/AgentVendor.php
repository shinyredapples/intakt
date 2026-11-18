<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Symfony\AI\Platform\PlatformInterface;

// Runs the real reviewer on a scripted platform, so a worker's review goes through the agent and its progress updates without the vendor stack.
final readonly class AgentVendor implements Vendor
{
    public function __construct(
        private Reviewer $reviewer,
        private PlatformInterface $platform,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return new VendorReview($this->reviewer->review($request, $this->platform, new ResolvedModel(Provider::Anthropic, 'reviewer-model')));
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return true;
    }
}
