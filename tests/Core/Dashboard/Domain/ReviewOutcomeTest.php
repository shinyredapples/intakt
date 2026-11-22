<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewOutcome::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
final class ReviewOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{Decision, ReviewOutcome}>
     */
    public static function decisions(): iterable
    {
        yield 'approve' => [Decision::Approve, ReviewOutcome::Approved];
        yield 'changes' => [Decision::Changes, ReviewOutcome::ChangesRequested];
        yield 'blocked' => [Decision::Blocked, ReviewOutcome::Blocked];
    }

    public function testChangesRequestedReadsChangesNeededInPlainWords(): void
    {
        self::assertSame('Changes needed', ReviewOutcome::ChangesRequested->plainWords());
    }

    public function testTheOtherOutcomesKeepTheirOwnWordsInPlainWords(): void
    {
        self::assertSame(['Approved', 'Blocked', 'Failed'], array_map(static fn (ReviewOutcome $outcome): string => $outcome->plainWords(), [ReviewOutcome::Approved, ReviewOutcome::Blocked, ReviewOutcome::Failed]));
    }

    #[DataProvider('decisions')]
    public function testAJudgedRoundTakesItsOutcomeFromTheDecision(Decision $decision, ReviewOutcome $outcome): void
    {
        self::assertSame($outcome, ReviewOutcome::of($this->judged()->decidedAs($decision)));
    }

    public function testTheOutcomesReadInPlainWords(): void
    {
        self::assertSame(['Approved', 'Changes requested', 'Blocked', 'Failed'], array_column(ReviewOutcome::cases(), 'value'));
    }

    public function testARoundStillInReviewHasNoOutcome(): void
    {
        self::assertNull(ReviewOutcome::of($this->round()));
    }

    public function testAJudgedRoundWithoutARecordedDecisionHasNoOutcome(): void
    {
        self::assertNull(ReviewOutcome::of($this->judged()));
    }

    public function testARoundWhoseJudgingFailedHasFailed(): void
    {
        $ended = $this->round()->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'The judge gave no call.'));

        self::assertSame(ReviewOutcome::Failed, ReviewOutcome::of($ended));
    }

    public function testARoundWithoutAnOutcomeInTimeIsBlocked(): void
    {
        $ended = $this->round()->endedAs(new RoundEnding(RoundEnds::NoOutcomeInTime, 'No outcome within 600 seconds.'));

        self::assertSame(ReviewOutcome::Blocked, ReviewOutcome::of($ended));
    }

    public function testARoundEndedAsJudgedShowsItsQuorumOutcomeNotBlocked(): void
    {
        $ended = $this->judged()->decidedAs(Decision::Approve)->endedAs(new RoundEnding(RoundEnds::Judged, 'Judged.'));

        self::assertSame(ReviewOutcome::Approved, ReviewOutcome::of($ended));
    }

    public function testARoundWhoseReviewersAllFailedHasFailedRatherThanBlocked(): void
    {
        $round = $this->judged()->withOutcomes([$this->reviewer('a')->failed('timeout'), $this->reviewer('b')->failed('timeout')])->decidedAs(Decision::Blocked);

        self::assertSame(ReviewOutcome::Failed, ReviewOutcome::of($round));
    }

    public function testARoundStillInReviewWhoseReviewersAllFailedHasNoOutcome(): void
    {
        $round = $this->round()->withOutcomes([$this->reviewer('a')->failed('timeout'), $this->reviewer('b')->failed('timeout')]);

        self::assertNull(ReviewOutcome::of($round));
    }

    public function testARoundWithOneFailedReviewerKeepsTheDecision(): void
    {
        $round = $this->judged()->withOutcomes([$this->reviewer('a')->failed('timeout'), $this->reviewer('b')])->decidedAs(Decision::Changes);

        self::assertSame(ReviewOutcome::ChangesRequested, ReviewOutcome::of($round));
    }

    public function testOnlyApprovedShowsTheCheckMark(): void
    {
        $checks = array_filter(ReviewOutcome::cases(), static fn (ReviewOutcome $outcome): bool => 'check' === $outcome->icon());

        self::assertSame([ReviewOutcome::Approved], array_values($checks));
    }

    public function testEachOutcomeHasItsOwnIcon(): void
    {
        $icons = array_map(static fn (ReviewOutcome $outcome): string => $outcome->icon(), ReviewOutcome::cases());

        self::assertSame($icons, array_unique($icons));
    }

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r-1', 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function judged(): ReviewedRound
    {
        return $this->round()->judgedWith([], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));
    }

    private function reviewer(string $id): ReviewerOutcome
    {
        return new ReviewerOutcome($id, 'Code reviewer', 'standard', new DateTimeImmutable('2026-10-28T11:01:00+00:00'));
    }
}
