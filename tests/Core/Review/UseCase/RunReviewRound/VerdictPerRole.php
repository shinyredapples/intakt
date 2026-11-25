<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Throwable;

// Stands in for a vendor because a test must never reach a provider. It answers by the reviewer's role prompt, so a verdict belongs to one reviewer whatever order the messages arrive in; the briefs and the call log show what each reviewer was told and which reviewers ran.
final class VerdictPerRole implements Vendor
{
    public int $calls = 0;

    /** @var array<string, ReviewBrief> */
    public array $briefs = [];

    /** @var list<string> */
    public array $conversations = [];

    /** @var list<?string> */
    public array $rounds = [];

    /**
     * @param array<string, Verdict> $verdicts
     */
    public function __construct(
        private readonly array $verdicts = [],
        private readonly ?Throwable $failure = null,
        private readonly Verdict $otherwise = new Verdict(Decision::Approve, [], [], []),
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        ++$this->calls;
        $this->conversations[] = $request->conversation->id->toRfc4122();
        $this->rounds[] = $request->conversation->round?->toRfc4122();
        $this->briefs[$request->conversation->rolePrompt] = $request->brief;

        return $this->failure instanceof Throwable ? throw $this->failure : new VendorReview($this->verdicts[$request->conversation->rolePrompt] ?? $this->otherwise);
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
