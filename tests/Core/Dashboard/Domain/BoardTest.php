<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\BuildTask;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\MascotPose;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Domain\WorkingCard;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Board::class)]
#[CoversClass(BoardColumn::class)]
#[CoversClass(Review::class)]
#[CoversClass(WorkingCard::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(Lane::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(Character::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(Headline::class)]
#[UsesClass(BuildTask::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class BoardTest extends TestCase
{
    public function testTheBoardHasTheNineColumnsOfTheDesignInTheirOrder(): void
    {
        $names = array_map(static fn (BoardColumn $column): string => $column->value, BoardColumn::cases());

        self::assertSame(['Waiting on you', 'Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'In review', 'Merging', 'Done'], $names);
    }

    public function testABoardWithoutCardsShowsWaitingOnYouInReviewAndDoneInTheirOrder(): void
    {
        self::assertSame([BoardColumn::WaitingOnYou, BoardColumn::InReview, BoardColumn::Done], new Board([])->shownColumns());
    }

    public function testABoardWithoutCardsFoldsTheColumnsOfFeaturesNotRunningYetIntoComingNextInTheirOrder(): void
    {
        self::assertSame([BoardColumn::Backlog, BoardColumn::Ready, BoardColumn::Planned, BoardColumn::Building, BoardColumn::Checking, BoardColumn::Merging], new Board([])->comingNext());
    }

    public function testABoardWithCardsInReviewAndDoneStillFoldsTheSameColumns(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())])]);

        self::assertSame([[BoardColumn::WaitingOnYou, BoardColumn::InReview, BoardColumn::Done], 6], [$board->shownColumns(), count($board->comingNext())]);
    }

    public function testAReviewWhoseLatestRoundRunsIsACardInInReview(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertSame(['r1'], $this->roundsIn($board, BoardColumn::InReview));
    }

    public function testAReviewWhoseLatestRoundIsJudgedIsACardInDone(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertSame(['r1'], $this->roundsIn($board, BoardColumn::Done));
    }

    public function testAReviewWithAJudgedRoundAndANewRunningOneIsInInReviewOnly(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning()), $this->round('r2', 'feat/a', 2)])]);

        self::assertSame([['r2'], []], [$this->roundsIn($board, BoardColumn::InReview), $this->roundsIn($board, BoardColumn::Done)]);
    }

    public function testEveryColumnWithoutATaskSourceHoldsNothing(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())])]);

        $others = array_filter(BoardColumn::cases(), static fn (BoardColumn $column): bool => !in_array($column, [BoardColumn::InReview, BoardColumn::Done], true));

        self::assertSame(array_fill(0, 7, 0), array_values(array_map($board->countOf(...), $others)));
    }

    public function testAColumnCountsEveryCardOfEveryProject(): void
    {
        $board = new Board([
            new Review([$this->round('r1', 'feat/a', 1)]),
            new Review([$this->started(new RoundStart('r2', 'beta', 2), new RoundPlace('feat/b', 1), $this->morning())]),
            new Review([$this->started(new RoundStart('r3', 'gamma', 2), new RoundPlace('feat/c', 1), $this->morning())]),
        ]);

        self::assertSame(3, $board->countOf(BoardColumn::InReview));
    }

    public function testACardCarriesItsProject(): void
    {
        $board = new Board([new Review([$this->started(new RoundStart('r1', 'beta', 2), new RoundPlace('feat/a', 1), $this->morning())])]);

        self::assertSame('beta', $board->cardsIn(BoardColumn::InReview)[0]->project);
    }

    public function testACardShowsItsLatestRoundForItsNumberReviewersAndLink(): void
    {
        $card = new Review([$this->round('r1', 'feat/a', 1), $this->started(new RoundStart('r2', 'alpha', 3), new RoundPlace('feat/a', 2), $this->morning())]);

        self::assertSame(['r2', 2, 3], [$card->latest()->round, $card->latest()->number, $card->latest()->reviewers]);
    }

    public function testCardsInAColumnShowTheNewestLatestRoundFirst(): void
    {
        $board = new Board([
            new Review([$this->started(new RoundStart('r1', 'alpha', 2), new RoundPlace('feat/a', 1), new DateTimeImmutable('2026-11-01T10:00:00+00:00'))]),
            new Review([$this->started(new RoundStart('r2', 'alpha', 2), new RoundPlace('feat/b', 1), new DateTimeImmutable('2026-11-03T10:00:00+00:00'))]),
            new Review([$this->started(new RoundStart('r3', 'alpha', 2), new RoundPlace('feat/c', 1), new DateTimeImmutable('2026-11-02T10:00:00+00:00'))]),
        ]);

        self::assertSame(['r2', 'r3', 'r1'], $this->roundsIn($board, BoardColumn::InReview));
    }

    public function testTheBoardTellsWhoIsWorkingFromItsReviews(): void
    {
        $running = $this->round('r1', 'feat/a', 1)->withOutcomes([new ReviewerOutcome('r1-a', 'correctness', 'standard', $this->morning())]);
        $board = new Board([new Review([$running]), new Review([$this->round('r2', 'feat/b', 1)])]);

        self::assertSame(['r1-a'], array_map(static fn (Lane $lane): string => $lane->reviewer, $board->whoIsWorking()->lanes()));
    }

    public function testDoneStartsOpenWhenEveryOtherColumnIsEmpty(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertFalse($board->startsCollapsed(BoardColumn::Done));
    }

    public function testDoneStartsCollapsedWhileAnotherColumnHoldsACard(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())])]);

        self::assertTrue($board->startsCollapsed(BoardColumn::Done));
    }

    public function testABoardWhoseOnlyCardsAreDoneStartsScrolledToDone(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertTrue($board->startsScrolledToDone());
    }

    public function testABoardWithACardOutsideDoneStartsAtItsFirstColumn(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())])]);

        self::assertFalse($board->startsScrolledToDone());
    }

    public function testABoardWithoutCardsStartsAtItsFirstColumn(): void
    {
        self::assertFalse(new Board([])->startsScrolledToDone());
    }

    public function testDoneStartsOpenOnABoardWithoutCards(): void
    {
        self::assertFalse(new Board([])->startsCollapsed(BoardColumn::Done));
    }

    public function testABoardWithoutCardsIsTheFirstDay(): void
    {
        self::assertTrue(new Board([])->isFirstDay());
    }

    public function testABoardWithOneReviewIsNoLongerTheFirstDay(): void
    {
        self::assertFalse(new Board([new Review([$this->round('r1', 'feat/a', 1)])])->isFirstDay());
    }

    public function testBacklogStillStartsCollapsedWhenEveryOtherColumnIsEmpty(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertTrue($board->startsCollapsed(BoardColumn::Backlog));
    }

    public function testAColumnThatNeverFoldsStaysOpenWhileOthersHoldCards(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertFalse($board->startsCollapsed(BoardColumn::InReview));
    }

    public function testABoardWithoutCardsHasTheHeadlineOfAFreshInstall(): void
    {
        self::assertSame('Nothing is running yet. Start the first review.', new Board([])->headline()->opening());
    }

    public function testTheBoardsHeadlineTellsOfItsRunningReviews(): void
    {
        $running = $this->round('r1', 'feat/a', 1)->withOutcomes([new ReviewerOutcome('r1-a', 'correctness', 'standard', $this->morning())]);
        $board = new Board([new Review([$running]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())])]);

        $headline = $board->headline();

        self::assertSame('The Code reviewers are reviewing change feat/a of alpha.', $headline->opening());
    }

    public function testOnTheFirstDayEveryColumnGivesTheLineOfWhatLandsInIt(): void
    {
        $board = new Board([]);

        self::assertSame(
            array_map(static fn (BoardColumn $column): string => $column->emptyLine(), BoardColumn::cases()),
            array_map($board->emptyLineOf(...), BoardColumn::cases()),
        );
    }

    public function testAnEmptyColumnStillGivesItsLineOnceAReviewHasRun(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertSame('Reviews appear here once the judge has decided them', $board->emptyLineOf(BoardColumn::Done));
    }

    public function testAColumnWithACardGivesNoEmptyLine(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertNull($board->emptyLineOf(BoardColumn::InReview));
    }

    public function testOnTheFirstDayWhoIsWorkingSaysThatReviewersAppearWhileTheyReview(): void
    {
        self::assertSame('No reviewer is working right now: reviewers appear here while they review a change', new Board([])->nobodyWorkingLine());
    }

    public function testWhoIsWorkingKeepsItsLineWhenNobodyRunsOnceAReviewHasRun(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertSame('No reviewer is working right now: reviewers appear here while they review a change', $board->nobodyWorkingLine());
    }

    public function testWhoIsWorkingHasNoEmptyLineWhileAReviewerRuns(): void
    {
        $running = $this->round('r1', 'feat/a', 1)->withOutcomes([new ReviewerOutcome('r1-a', 'correctness', 'standard', $this->morning())]);

        self::assertNull(new Board([new Review([$running])])->nobodyWorkingLine());
    }

    public function testWorkingNowShowsTheBuildsTasksInFlightBeforeTheReviewsInProgress(): void
    {
        $inReview = new Review([$this->round('r1', 'feat/a', 1)]);
        $done = new Review([$this->round('r2', 'feat/b', 1)->judgedWith([], $this->morning())]);

        $cards = new Board([$inReview, $done], [$this->inFlight('alpha'), $this->failed('beta')])->workingNow();

        self::assertSame(
            [['task-1', 'Build', 'developer', null], ['feat/a', 'Review', 'code_reviewer', 'r1']],
            array_map(static fn (WorkingCard $card): array => [$card->title, $card->step->value, $card->roles[0]->value, $card->round], $cards),
        );
    }

    public function testAnOldJudgedChangeWithAFindingToFixIsNoWorkingCard(): void
    {
        $toFix = [new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)];
        $board = new Board([new Review([$this->round('r1', 'feat/old', 1)->judgedWith($toFix, $this->morning())])]);

        self::assertSame([], $board->workingNow());
        self::assertNotNull($board->nobodyWorkingNowLine());
    }

    public function testARoundThatEndedWithoutAJudgesCallIsNoWorkingCard(): void
    {
        $ended = $this->round('r1', 'feat/a', 1)->endedAs(new RoundEnding(RoundEnds::NoOutcomeInTime, 'No outcome in time.'));
        $board = new Board([new Review([$ended])]);

        self::assertSame([], $board->workingNow());
        self::assertNotNull($board->nobodyWorkingNowLine());
    }

    public function testWorkingNowKeepsItsOwnLineWhileNoChangeIsInProgress(): void
    {
        self::assertSame('Nobody is working yet: reviewers appear here while they review a change', new Board([])->nobodyWorkingNowLine());
    }

    // An open round whose first reviewer has not started is a Working now card, while the Status page still has no reviewer to list (#3386, decision 7).
    public function testAnOpenRoundWithNoReviewerRunningHasACardButKeepsTheStatusPagesLine(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertNull($board->nobodyWorkingNowLine());
        self::assertSame('No reviewer is working right now: reviewers appear here while they review a change', $board->nobodyWorkingLine());
    }

    public function testTheHedgehogStandsBesideTheMeerkatWhileTheSecurityReviewerReads(): void
    {
        $answered = new ReviewerOutcome('r1-c', 'correctness', 'standard', $this->morning())->answered(Decision::Approve, 0, ProjectMode::Live);
        $reading = $this->round('r1', 'feat/a', 1)->withOutcomes([$answered, new ReviewerOutcome('r1-s', 'security', 'standard', $this->morning())]);
        $done = $this->round('r2', 'feat/b', 1)->withOutcomes([$answered, new ReviewerOutcome('r2-s', 'security', 'standard', $this->morning())->answered(Decision::Approve, 0, ProjectMode::Live)]);

        $cards = new Board([new Review([$reading]), new Review([$done])])->workingNow();

        self::assertSame([[Character::CodeReviewer, Character::SecurityEngineer], [Character::CodeReviewer]], array_map(static fn (WorkingCard $card): array => $card->roles, $cards));
        self::assertSame('Reviewing', $cards[0]->state());
    }

    public function testAWorkingCardOfAReviewWithoutAChangeIsNamedAfterItsRound(): void
    {
        $cards = new Board([new Review([$this->round('r1', '', 1)])])->workingNow();

        self::assertSame('Round r1', $cards[0]->title);
    }

    public function testWorkingNowHasNoEmptyLineWhileATaskIsBuilt(): void
    {
        self::assertNull(new Board([], [$this->inFlight('alpha')])->nobodyWorkingNowLine());
    }

    public function testATaskTheBuildRecordedEndsTheFirstDayBeforeAnyReview(): void
    {
        self::assertFalse(new Board([], [$this->failed('alpha')])->isFirstDay());
    }

    public function testOnTheFirstDayTheOctopusWaits(): void
    {
        self::assertSame(MascotPose::Waiting, new Board([])->mascot());
    }

    public function testABoardWhoseEveryCardIsDoneShowsTheAsleepOctopus(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])]);

        self::assertSame(MascotPose::Asleep, $board->mascot());
    }

    public function testABoardWithARunningReviewShowsNoOctopus(): void
    {
        self::assertNull(new Board([new Review([$this->round('r1', 'feat/a', 1)])])->mascot());
    }

    public function testABuildInFlightShowsNoOctopus(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([], $this->morning())])], [$this->inFlight('alpha')]);

        self::assertNull($board->mascot());
    }

    public function testABuildInFlightWithNoCardShowsNoOctopus(): void
    {
        self::assertNull(new Board([], [$this->inFlight('alpha')])->mascot());
    }

    public function testATaskAtRestWithNoCardShowsTheAsleepOctopus(): void
    {
        self::assertSame(MascotPose::Asleep, new Board([], [$this->failed('alpha')])->mascot());
    }

    public function testTheHeadlineDoesNotSayNothingIsRunningWhileABuildIsInFlight(): void
    {
        self::assertSame('A build is running.', new Board([], [$this->inFlight('alpha')])->headline()->opening());
    }

    public function testTheHeadlineOfATaskAtRestWithNoCardSaysNothingIsRunning(): void
    {
        self::assertSame('Nothing is running.', new Board([], [$this->failed('alpha')])->headline()->opening());
    }

    public function testIdleProjectsAreThoseWhoseEveryCardIsDone(): void
    {
        $board = new Board([
            new Review([$this->started(new RoundStart('r1', 'alpha', 2), new RoundPlace('feat/a', 1), $this->morning())->judgedWith([], $this->morning())]),
            new Review([$this->started(new RoundStart('r2', 'beta', 2), new RoundPlace('feat/b', 1), $this->morning())]),
        ]);

        self::assertSame(['alpha'], $board->idleProjects());
    }

    public function testIdleProjectsLeaveOutAProjectWithABuildInFlight(): void
    {
        $board = new Board(
            [
                new Review([$this->started(new RoundStart('r1', 'alpha', 2), new RoundPlace('feat/a', 1), $this->morning())->judgedWith([], $this->morning())]),
                new Review([$this->started(new RoundStart('r2', 'beta', 2), new RoundPlace('feat/b', 1), $this->morning())->judgedWith([], $this->morning())]),
            ],
            [$this->inFlight('beta'), $this->failed('beta')],
        );

        self::assertSame(['alpha'], $board->idleProjects());
    }

    public function testAProjectWithOnlyATaskAtRestIsIdle(): void
    {
        $board = new Board([new Review([$this->started(new RoundStart('r1', 'beta', 2), new RoundPlace('feat/b', 1), $this->morning())])], [$this->failed('alpha')]);

        self::assertSame(['alpha'], $board->idleProjects());
    }

    /**
     * @return list<string>
     */
    private function roundsIn(Board $board, BoardColumn $column): array
    {
        return array_map(static fn (Review $card): string => $card->latest()->round, $board->cardsIn($column));
    }

    private function round(string $round, string $change, int $number): ReviewedRound
    {
        return $this->started(new RoundStart($round, 'alpha', 2), new RoundPlace($change, $number), $this->morning());
    }

    private function started(RoundStart $start, RoundPlace $place, DateTimeImmutable $startedAt): ReviewedRound
    {
        return new ReviewedRound($start, $place, $startedAt, null);
    }

    private function inFlight(string $project): BuildTask
    {
        return new BuildTask($project, 'task-1', true);
    }

    private function failed(string $project): BuildTask
    {
        return new BuildTask($project, 'task-2', false);
    }

    private function morning(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01T10:00:00+00:00');
    }
}
