<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// Marks the named findings reject and every other one act, so a place can carry two different judge calls.
final readonly class JudgeRejectingFindings implements Vendor
{
    /**
     * @param list<string> $rejected
     */
    public function __construct(
        private Vendor $reviewers,
        private array $rejected,
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
        return new Judgement(array_map(fn (Finding $finding): JudgedFinding => new JudgedFinding($finding->id, in_array($finding->id, $this->rejected, true) ? Mark::Reject : Mark::Act), $request->findings));
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return $this->reviewers->mapsAModelTo($project, $tier);
    }
}
