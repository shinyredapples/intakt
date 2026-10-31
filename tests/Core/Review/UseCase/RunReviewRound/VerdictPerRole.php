<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Override;

// Answers by the reviewer's role prompt, so a verdict belongs to one reviewer whatever order the messages arrive in; the briefs show what each reviewer was told.
final class VerdictPerRole implements Vendor
{
    /** @var array<string, ReviewBrief> */
    public array $briefs = [];

    /**
     * @param array<string, Verdict> $verdicts
     */
    public function __construct(private readonly array $verdicts)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        $this->briefs[$request->conversation->rolePrompt] = $request->brief;

        return $this->verdicts[$request->conversation->rolePrompt] ?? new Verdict(Decision::Approve, [], [], []);
    }
}
