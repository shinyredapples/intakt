<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use LogicException;
use Override;
use Throwable;

// Fails every judge call with the given exception, as a limited or busy vendor account does.
final readonly class FailingJudge implements Vendor
{
    public function __construct(private Throwable $failure)
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
        throw $this->failure;
    }
}
