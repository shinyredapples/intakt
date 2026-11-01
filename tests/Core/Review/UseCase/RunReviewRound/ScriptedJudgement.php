<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use LogicException;
use Override;

// Answers every judge call with the given judgement and keeps the request, so a test sees what the judge was asked.
final class ScriptedJudgement implements Vendor
{
    public ?JudgeRequest $asked = null;

    public function __construct(private readonly Judgement $judgement)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        throw new LogicException('This vendor only judges.');
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        $this->asked = $request;

        return $this->judgement;
    }
}
