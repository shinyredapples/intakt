<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\RoundReviewer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(RoundReviewer::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelReviewer::class)]
final class RoundReviewerHoldTest extends TestCase
{
    public function testARunningReviewerIsHeldForOneHourFromItsStart(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));
        $reviewer->moveTo(ReviewerState::Running->value);

        self::assertEquals(new DateTimeImmutable('2026-10-30 13:00:00Z'), $reviewer->heldUntil());
    }

    public function testAReviewerThatIsNotRunningIsNotHeld(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));
        $reviewer->moveTo(ReviewerState::Failed->value);

        self::assertNull($reviewer->heldUntil());
    }

    public function testARunningReviewerWithoutARecordedStartIsNotHeld(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->moveTo(ReviewerState::Running->value);

        self::assertNull($reviewer->heldUntil());
    }

    private function reviewer(): RoundReviewer
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewSubject('alpha', __DIR__, 'Add the thing.'), new ChangeRound('feat/thing', 1, '[]'));
        $round->addReviewer(new UuidV7(), new PanelReviewer(new ReviewerRole('role', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1));

        return $round->reviewers()[0];
    }
}
