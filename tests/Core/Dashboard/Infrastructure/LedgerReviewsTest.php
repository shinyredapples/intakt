<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\ListedReviews;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerReviews::class)]
#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(LedgerRounds::class)]
#[CoversClass(RoundPayloads::class)]
#[CoversClass(LedgerPayloads::class)]
#[CoversClass(ReviewSummary::class)]
#[CoversClass(RoundStart::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(ReviewStarted::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
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

    public function testRoundsAreOrderedByTheirFirstReadableStart(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha"}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
        );

        self::assertSame(['r1', 'r2'], array_map(static fn (ReviewSummary $review): string => $review->round, $reviews));
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

    public function testAStartAndAJudgeRowInTodaysFormatListAJudgedRound(): void
    {
        $reviews = $this->reviewsOf(
            $this->row(LedgerEventKind::ReviewStarted, new ReviewStarted('r1', 'alpha', 3, 'feat/thing', 1, 'Add the thing.', '/repos/alpha', 'main')),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', ['F1'], [], [new JudgeDoneFinding('F1', Severity::P1, 'Broken.')])),
        );

        self::assertSame([['r1', 'alpha', 3, ReviewState::Judged]], array_map(static fn (ReviewSummary $review): array => [$review->round, $review->project, $review->reviewers, $review->state], $reviews));
    }

    public function testAJudgeRowWithAnUnreadableFindingListsItsRoundJudged(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1"}]}'),
        );

        self::assertSame(ReviewState::Judged, $reviews[0]->state);
    }

    public function testAJudgeRowThatDoesNotDeserializeLeavesItsRoundInProgress(): void
    {
        $reviews = $this->reviewsOf(
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":"F1","follow_ups":[]}'),
        );

        self::assertSame(ReviewState::InProgress, $reviews[0]->state);
    }

    public function testAStartedRoundIsListedWithItsChangeAndRoundNumber(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('sample-change', 'r1', 1);

        $reviews = $this->listOf($ledger);

        self::assertSame([['sample-change', 1, null, null]], array_map(static fn (ReviewSummary $review): array => [$review->change, $review->number, $review->outcome, $review->findingCount], $reviews));
    }

    public function testARoundStartedBeforeTheLedgerNamedItsChangeIsListedWithoutChangeOrNumber(): void
    {
        $reviews = $this->reviewsOf($this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'));

        self::assertSame([['', null]], array_map(static fn (ReviewSummary $review): array => [$review->change, $review->number], $reviews));
    }

    public function testAJudgedRoundIsListedWithTheDecidedOutcome(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOfQuorum('sample-change', 'r1', 'all');
        $ledger->answer('r1', 'correctness', 0);
        $ledger->answer('r1', 'security', 0);
        $ledger->judgeRound('r1');

        self::assertSame(ReviewOutcome::Approved, $this->listOf($ledger)[0]->outcome);
    }

    public function testAJudgedRoundWithAFailedReviewerIsListedAsTheQuorumDecidesIt(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOfQuorum('sample-change', 'r1', 'all');
        $ledger->answer('r1', 'correctness', 0);
        $ledger->failReviewer('r1', 'security', 'timeout');
        $ledger->judgeRound('r1');

        self::assertSame(ReviewOutcome::Blocked, $this->listOf($ledger)[0]->outcome);
    }

    public function testAJudgedRoundStartedWithoutAQuorumIsListedWithoutAnOutcome(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('sample-change', 'r1', 1);
        $ledger->judgeRound('r1');

        self::assertSame([ReviewState::Judged, null], [$this->listOf($ledger)[0]->state, $this->listOf($ledger)[0]->outcome]);
    }

    public function testAJudgedRoundIsListedWithTheCountOfItsJudgedFindings(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('sample-change', 'r1', 1);
        $ledger->judgeFindings('r1', ['F1' => 'act', 'F2' => 'defer', 'F3' => 'reject']);

        self::assertSame(3, $this->listOf($ledger)[0]->findingCount);
    }

    public function testAFindingAnActEntryStandsForIsNotCountedTwice(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('sample-change', 'r1', 1);
        $ledger->judgeOneEntryOf('r1', 'F1', 'F2');

        self::assertSame(1, $this->listOf($ledger)[0]->findingCount);
    }

    public function testARoundJudgedBeforeFindingTextWasRecordedCountsTheMarkedFindings(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('sample-change', 'r1', 1);
        $ledger->judgeMarksOnly('r1', ['F1'], ['F2']);

        self::assertSame(2, $this->listOf($ledger)[0]->findingCount);
    }

    public function testTheSecondRoundOfAChangeIsListedWithItsOwnNumberAndOutcome(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOfQuorum('sample-change', 'r1', 'all');
        $ledger->answer('r1', 'correctness', 1);
        $ledger->answer('r1', 'security', 0);
        $ledger->judgeFindings('r1', ['F1' => 'act']);
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"alpha","reviewers":2,"change":"sample-change","number":2,"quorum":"all"}'));
        $ledger->answer('r2', 'correctness', 0);
        $ledger->answer('r2', 'security', 0);
        $ledger->judgeRound('r2');

        $reviews = $this->listOf($ledger);

        self::assertSame([['r2', 2, ReviewOutcome::Approved, 0], ['r1', 1, ReviewOutcome::ChangesRequested, 1]], array_map(static fn (ReviewSummary $review): array => [$review->round, $review->number, $review->outcome, $review->findingCount], $reviews));
    }

    /**
     * @return list<ReviewSummary>
     */
    private function reviewsOf(RecordedEvent ...$events): array
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents(array_values($events)));

        return $this->listOf($ledger);
    }

    /**
     * @return list<ReviewSummary>
     */
    private function listOf(Ledger $ledger): array
    {
        return ListedReviews::on($ledger)->newestFirst();
    }

    // Serialized as the writers do, so the reader is shown the row it meets in production.
    private function row(LedgerEventKind $kind, object $payload): RecordedEvent
    {
        return $this->event($kind, StoredSerializerFactory::create()->serialize($payload, 'json'));
    }

    private function event(LedgerEventKind $kind, string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, $payload), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
