<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpNext::class)]
#[CoversClass(Board::class)]
#[UsesClass(QueuedRound::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class UpNextTest extends TestCase
{
    public function testARoundWhoseReviewersStartedIsInProgressWithThoseReviewers(): void
    {
        $round = $this->round('r1', '2026-11-01T10:00:00+00:00')->withOutcomes([$this->reviewer('r1-a', 'correctness'), $this->reviewer('r1-b', 'security')->failed('The reviewer timed out.')]);

        $upNext = new Board([new Review([$round])])->upNext([]);

        self::assertSame(['r1'], $this->ids($upNext->inProgress));
        self::assertSame(['correctness', 'security'], array_map(static fn (ReviewerOutcome $outcome): string => $outcome->role, $upNext->inProgress[0]->outcomes));
        self::assertSame([], $upNext->waitingToStart);
    }

    public function testARoundNoReviewerHasStartedIsWaitingToStart(): void
    {
        $upNext = new Board([new Review([$this->round('r1', '2026-11-01T10:00:00+00:00')])])->upNext([]);

        self::assertSame([[], ['r1']], [$this->ids($upNext->inProgress), $this->ids($upNext->waitingToStart)]);
    }

    public function testAReviewerBlockedBeforeItStartedLeavesTheRoundWaitingToStart(): void
    {
        $blocked = new ReviewerOutcome('r1-a', 'correctness', 'standard', new DateTimeImmutable('2026-11-01T10:01:00+00:00'))->blocked('The review message failed for good.');
        $round = $this->round('r1', '2026-11-01T10:00:00+00:00')->withOutcomes([$blocked]);

        $upNext = new Board([new Review([$round])])->upNext([]);

        self::assertSame([[], ['r1']], [$this->ids($upNext->inProgress), $this->ids($upNext->waitingToStart)]);
        self::assertSame([], $upNext->waitingToStart[0]->startedOutcomes());
    }

    public function testARoundWithOneStartedReviewerIsInProgressAndNamesOnlyTheStartedRole(): void
    {
        $blocked = new ReviewerOutcome('r1-b', 'security', 'standard', new DateTimeImmutable('2026-11-01T10:01:00+00:00'))->blocked('The review message failed for good.');
        $round = $this->round('r1', '2026-11-01T10:00:00+00:00')->withOutcomes([$this->reviewer('r1-a', 'correctness'), $blocked]);

        $upNext = new Board([new Review([$round])])->upNext([]);

        self::assertSame([['r1'], []], [$this->ids($upNext->inProgress), $this->ids($upNext->waitingToStart)]);
        self::assertSame(['correctness'], array_map(static fn (ReviewerOutcome $outcome): string => $outcome->role, $upNext->inProgress[0]->startedOutcomes()));
    }

    public function testAJudgedOrEndedRoundIsNotListed(): void
    {
        $judged = $this->round('r1', '2026-11-01T10:00:00+00:00')->judgedWith([], new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $ended = $this->round('r2', '2026-11-01T10:00:00+00:00')->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'The judge gave no call.'));

        $upNext = new Board([new Review([$judged]), new Review([$ended])])->upNext([]);

        self::assertSame([[], []], [$upNext->inProgress, $upNext->waitingToStart]);
    }

    public function testOnlyTheLatestRoundOfAReviewIsListed(): void
    {
        $earlier = $this->round('r1', '2026-11-01T10:00:00+00:00')->judgedWith([], new DateTimeImmutable('2026-11-01T11:00:00+00:00'));

        $upNext = new Board([new Review([$earlier, $this->round('r2', '2026-11-01T12:00:00+00:00')])])->upNext([]);

        self::assertSame(['r2'], $this->ids($upNext->waitingToStart));
    }

    public function testEachGroupListsTheNewestRoundFirstAsTheBoardDoes(): void
    {
        $upNext = new Board([
            new Review([$this->round('r1', '2026-11-01T10:00:00+00:00', 'feat/a')]),
            new Review([$this->round('r2', '2026-11-03T10:00:00+00:00', 'feat/b')]),
            new Review([$this->round('r3', '2026-11-02T10:00:00+00:00', 'feat/c')]),
        ])->upNext([]);

        self::assertSame(['r2', 'r3', 'r1'], $this->ids($upNext->waitingToStart));
    }

    public function testARoundTheWatcherQueuedIsWaitingAndEndsTheEmptyLine(): void
    {
        $upNext = new Board([])->upNext([new QueuedRound('r1', 'alpha', '7')]);

        self::assertSame([true, null, ['r1']], [$upNext->isWaiting(), $upNext->emptyLine(), array_map(static fn (QueuedRound $round): string => $round->round, $upNext->queued)]);
    }

    public function testWithNoRoundWaitingOrQueuedNothingIsWaiting(): void
    {
        $round = $this->round('r1', '2026-11-01T10:00:00+00:00')->withOutcomes([$this->reviewer('r1-a', 'correctness')]);

        self::assertFalse(new Board([new Review([$round])])->upNext([])->isWaiting());
    }

    public function testWithNothingRunningOrQueuedTheListSaysSo(): void
    {
        self::assertSame('Nothing running or queued.', new Board([])->upNext([])->emptyLine());
    }

    public function testWithARoundListedThereIsNoEmptyLine(): void
    {
        self::assertNull(new Board([new Review([$this->round('r1', '2026-11-01T10:00:00+00:00')])])->upNext([])->emptyLine());
    }

    private function round(string $round, string $startedAt, string $change = 'feat/a'): ReviewedRound
    {
        $moment = DateTimeImmutable::createFromFormat(DATE_ATOM, $startedAt, new DateTimeZone('UTC'));

        if (false === $moment) {
            throw new LogicException('Not a time: '.$startedAt);
        }

        return new ReviewedRound(new RoundStart($round, 'alpha', 2), new RoundPlace($change, 1), $moment, null);
    }

    private function reviewer(string $id, string $role): ReviewerOutcome
    {
        $at = new DateTimeImmutable('2026-11-01T10:01:00+00:00');

        return new ReviewerOutcome($id, $role, 'standard', $at)->restarted($at, null);
    }

    /**
     * @param list<ReviewedRound> $rounds
     *
     * @return list<string>
     */
    private function ids(array $rounds): array
    {
        return array_map(static fn (ReviewedRound $round): string => $round->round, $rounds);
    }
}
