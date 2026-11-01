<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerReviews::class)]
#[CoversClass(ReviewSummary::class)]
#[CoversClass(RoundStart::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
final class LedgerReviewsTest extends TestCase
{
    public function testEventsOfKindsTheDashboardDoesNotReadAreIgnored(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","decision":"approve"}'),
            $this->event(LedgerEventKind::UsageRecorded, 'not json at all'),
        );

        self::assertCount(1, $reviews);
        self::assertSame(ReviewState::InProgress, $reviews[0]->state);
    }

    public function testAPayloadThatIsNotValidJsonIsSkipped(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{broken'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"beta","reviewers":1}'),
        );

        self::assertSame(['r2'], array_map(static fn (ReviewSummary $review): string => $review->round, $reviews));
    }

    public function testAStartedEventWithoutAProjectOrReviewerCountIsSkipped(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","reviewers":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"beta","reviewers":"two"}'),
            $this->event(LedgerEventKind::ReviewStarted, '"just a string"'),
        );

        self::assertSame([], $reviews);
    }

    public function testARepeatedStartWithAnUnreadablePayloadKeepsTheRound(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha"}'),
        );

        self::assertSame([['r1', 'alpha', 2]], array_map(static fn (ReviewSummary $review): array => [$review->round, $review->project, $review->reviewers], $reviews));
    }

    public function testARepeatedStartDoesNotReplaceTheFirstReadableOne(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"beta","reviewers":5}'),
        );

        self::assertSame([['r1', 'alpha', 2]], array_map(static fn (ReviewSummary $review): array => [$review->round, $review->project, $review->reviewers], $reviews));
    }

    public function testAReadableStartAfterAnUnreadableOneListsTheRound(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha"}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":3}'),
        );

        self::assertSame([['r1', 'alpha', 3]], array_map(static fn (ReviewSummary $review): array => [$review->round, $review->project, $review->reviewers], $reviews));
    }

    public function testAJudgeDecisionMarksOnlyItsOwnRound(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[]}'),
        );

        self::assertSame(['r2' => ReviewState::InProgress, 'r1' => ReviewState::Judged], array_column(array_map(static fn (ReviewSummary $review): array => [$review->round, $review->state], $reviews), 1, 0));
    }

    /**
     * @return list<ReviewSummary>
     */
    private function reviewsOf(RecordedEvent ...$events): array
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents(array_values($events)));

        return new LedgerReviews($ledger)->newestFirst();
    }

    private function event(LedgerEventKind $kind, string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, $payload), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
