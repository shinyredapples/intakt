<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Throwable;

// Runs what a test chooses in the middle of the vendor call, because that forces the order "second delivery while the first is still reviewing" without any timing.
final readonly class RedeliveredDuringTheCall implements Vendor
{
    public function __construct(private Closure $meanwhile, private ?Throwable $failure = null)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        ($this->meanwhile)();

        return $this->failure instanceof Throwable ? throw $this->failure : new VendorReview(new Verdict(Decision::Approve, [], [], []));
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
