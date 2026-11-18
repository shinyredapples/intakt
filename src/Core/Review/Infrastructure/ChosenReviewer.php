<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// The reviewer the vendor is given: the request says where the review runs, because the review handler decides that from the install's container engine.
#[AsAlias(Reviewer::class)]
final readonly class ChosenReviewer implements Reviewer
{
    public function __construct(
        private ReviewerAgent $inProcess,
        private ReviewerInJob $inJob,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, ResolvedModel $model): Verdict
    {
        return $request->job instanceof JobReview ? $this->inJob->review($request, $request->job, $model) : $this->inProcess->review($request, $platform, $model);
    }
}
