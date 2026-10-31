<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Override;
use Throwable;

// Answers every review with an approval, or throws the failure it was given; counts the calls so a test sees which reviewers ran.
final class ScriptedVendor implements Vendor
{
    public int $calls = 0;

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        ++$this->calls;

        return $this->failure instanceof Throwable ? throw $this->failure : new Verdict(Decision::Approve, [], [], []);
    }
}
