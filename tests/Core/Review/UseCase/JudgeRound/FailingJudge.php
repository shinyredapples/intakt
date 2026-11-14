<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use LogicException;
use Override;
use Throwable;

// A double of its own, because the limits a vendor account throws must reach the handler unchanged to show which of them it retries.
final readonly class FailingJudge implements Vendor
{
    public function __construct(private Throwable $failure)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        throw new LogicException('This vendor only judges.');
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        throw $this->failure;
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return true;
    }
}
