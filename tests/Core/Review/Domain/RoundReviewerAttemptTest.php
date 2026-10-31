<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ReviewerNotStarted;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Tests\Core\Review\SampleIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(RoundReviewer::class)]
#[CoversClass(ReviewerNotStarted::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelReviewer::class)]
final class RoundReviewerAttemptTest extends TestCase
{
    public function testEveryStartGivesTheReviewerANewConversation(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));
        $first = $reviewer->conversationId();

        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));

        self::assertFalse($first->equals($reviewer->conversationId()));
    }

    public function testAReviewerThatWasNeverStartedHasNoConversation(): void
    {
        $this->expectException(ReviewerNotStarted::class);

        $this->reviewer()->conversationId();
    }

    public function testTheSameStartIsTheSameAttempt(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));

        self::assertTrue($reviewer->isSameAttemptAs(clone $reviewer));
    }

    public function testAnotherStartIsAnotherAttempt(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 12:00:00Z'));
        $taken = clone $reviewer;

        $reviewer->recordStart(new DateTimeImmutable('2026-10-30 13:00:00Z'));

        self::assertFalse($reviewer->isSameAttemptAs($taken));
    }

    public function testAReviewerThatWasNeverStartedIsNoAttemptOfAnyWorker(): void
    {
        $reviewer = $this->reviewer();

        self::assertFalse($reviewer->isSameAttemptAs(clone $reviewer));
    }

    private function reviewer(): RoundReviewer
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewSubject('alpha', __DIR__, 'Add the thing.'), SampleIdentity::any());
        $round->addReviewer(new UuidV7(), new PanelReviewer(new ReviewerRole('role', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1));

        return $round->reviewers()[0];
    }
}
