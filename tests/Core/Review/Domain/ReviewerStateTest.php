<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Domain\ReviewerState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerState::class)]
final class ReviewerStateTest extends TestCase
{
    #[TestWith([ReviewerState::Verdict])]
    #[TestWith([ReviewerState::Failed])]
    #[TestWith([ReviewerState::Blocked])]
    public function testAReviewerWithAVerdictAFailureOrABlockHasFinished(ReviewerState $state): void
    {
        self::assertTrue($state->isFinished());
    }

    #[TestWith([ReviewerState::Queued])]
    #[TestWith([ReviewerState::Running])]
    public function testAQueuedOrRunningReviewerHasNotFinished(ReviewerState $state): void
    {
        self::assertFalse($state->isFinished());
    }
}
