<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Override;
use Throwable;

// Stands in for a vendor because a test must never reach a provider; the call count tells a test which reviewers ran.
final class ScriptedVendor implements Vendor
{
    public int $calls = 0;

    /** @var list<string> */
    public array $conversations = [];

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        ++$this->calls;
        $this->conversations[] = $request->conversation->id->toRfc4122();

        return $this->failure instanceof Throwable ? throw $this->failure : new Verdict(Decision::Approve, [], [], []);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }
}
