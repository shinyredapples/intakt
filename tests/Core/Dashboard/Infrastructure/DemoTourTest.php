<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\Domain\TourStage;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\TourCaptionsOn;
use Intakt\Tests\Support\ReplayMarkBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoTour::class)]
#[CoversClass(TourStage::class)]
#[CoversClass(TourCaption::class)]
#[CoversClass(LedgerReviewPages::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class DemoTourTest extends TestCase
{
    private const string DEMO = CreateDemoProjectCommand::NAME;

    private DashboardLedger $ledger;

    /** @var list<string> */
    private array $replays = [self::DEMO];

    public function testOutsideTheComposeDemoThereIsNoCaptionAtAll(): void
    {
        $this->startRound('r1', 1);

        self::assertNull($this->tour('')->caption());
    }

    public function testOnTheComposeDemoBeforeADemoRunTheCaptionHasNoStage(): void
    {
        self::assertEquals(TourCaption::beforeTheRun(), $this->tour()->caption());
    }

    public function testAnotherProjectsReviewIsNoDemoRun(): void
    {
        $this->ledger->startRound('r1', 'sample');

        self::assertFalse($this->caption()->isPlayed());
    }

    public function testADemoProjectThatIsNoReplayHasNoDemoRun(): void
    {
        $this->replays = [];
        $this->startRound('r1', 1);

        self::assertFalse($this->caption()->isPlayed());
    }

    public function testAStartedRoundWithNoReviewerYetIsTheStageWhereTheChangeArrives(): void
    {
        $this->startRound('r1', 1);

        self::assertSame(TourStage::ChangeArrives, $this->caption()->stage);
    }

    public function testTheCaptionLinksTheLatestRoundOfTheDemoRun(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);
        $this->startRound('r2', 2);

        self::assertSame('r2', $this->caption()->round);
    }

    public function testReviewersStillReadingWithNoVerdictAreTheReadingStage(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');

        self::assertSame(TourStage::ReviewersRead, $this->caption()->stage);
    }

    public function testOneVerdictWhileAnotherReviewerReadsIsTheStageWhereFindingsArrive(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');
        $this->answer('r1', 'correctness', 2);

        self::assertSame(TourStage::FindingsArrive, $this->caption()->stage);
    }

    public function testAVerdictWhileAReviewerOfThePanelHasNotStartedIsStillTheStageWhereFindingsArrive(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->answer('r1', 'correctness', 2);

        self::assertSame(TourStage::FindingsArrive, $this->caption()->stage);
    }

    public function testWhileTheReviewersReadTheCaptionHoldsTheReviewersOfTheLatestRound(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');

        self::assertSame(['correctness', 'security'], array_map(static fn (ReviewerOutcome $outcome): string => $outcome->role, $this->caption()->reviewers));
    }

    public function testWhileFindingsArriveTheCaptionStillHoldsTheReviewers(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');
        $this->answer('r1', 'correctness', 2);

        self::assertCount(2, $this->caption()->reviewers);
    }

    public function testOnceEveryReviewerAnsweredTheCaptionNamesNoReviewer(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');
        $this->answer('r1', 'correctness', 2);
        $this->answer('r1', 'security', 0);

        self::assertSame([], $this->caption()->reviewers);
    }

    public function testEveryReviewerAnsweredIsTheJudgesStage(): void
    {
        $this->startRound('r1', 1);
        $this->startReviewer('r1', 'correctness');
        $this->startReviewer('r1', 'security');
        $this->answer('r1', 'correctness', 2);
        $this->answer('r1', 'security', 0);

        self::assertSame(TourStage::JudgeWeighs, $this->caption()->stage);
    }

    public function testAJudgedFirstRoundIsTheActListStage(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);

        self::assertSame(TourStage::RoundEnds, $this->caption()->stage);
    }

    public function testALaterRoundStartsAgainAtTheChangeArrivesStage(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);
        $this->startRound('r2', 2);

        self::assertSame(TourStage::ChangeArrives, $this->caption()->stage);
    }

    public function testASecondRoundInProgressPassesTheReviewersStagesAgain(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);
        $this->startRound('r2', 2);
        $this->startReviewer('r2', 'correctness');

        self::assertSame(TourStage::ReviewersRead, $this->caption()->stage);
    }

    public function testAJudgedSecondRoundWithNothingToFixIsTheLastStage(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);
        $this->startRound('r2', 2);
        $this->judge('r2', []);

        self::assertSame(TourStage::RoundEnds, $this->caption()->stage);
        self::assertSame(5, TourStage::RoundEnds->number());
    }

    public function testAJudgedSecondRoundThatStillHasFindingsToFixIsTheActListStageAgain(): void
    {
        $this->startRound('r1', 1);
        $this->judge('r1', ['f1']);
        $this->startRound('r2', 2);
        $this->judge('r2', ['f2']);

        self::assertSame(TourStage::RoundEnds, $this->caption()->stage);
    }

    public function testTheStagesAreCountedInTheOrderARunPassesThem(): void
    {
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (TourStage $stage): int => $stage->number(), TourStage::cases()));
        self::assertSame(5, TourStage::count());
    }

    public function testEveryStageHasOneShortSentence(): void
    {
        foreach (TourStage::cases() as $stage) {
            self::assertMatchesRegularExpression('/^[A-Z][^.]*[.]$/', $stage->sentence());
            self::assertLessThanOrEqual(100, strlen($stage->sentence()));
        }
    }

    public function testTheLastStageSaysTheRoundIsCheckedInTheReviewPagesPlainWords(): void
    {
        self::assertSame('Checked: To fix lists what still needs changing.', TourStage::RoundEnds->sentence());
    }

    public function testEveryStageSentenceIsAtMostNineWords(): void
    {
        foreach (TourStage::cases() as $stage) {
            self::assertLessThanOrEqual(9, substr_count($stage->sentence(), ' ') + 1, $stage->value);
        }
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    private function caption(): TourCaption
    {
        $caption = $this->tour()->caption();
        self::assertInstanceOf(TourCaption::class, $caption);

        return $caption;
    }

    private function startRound(string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => self::DEMO, 'reviewers' => 2, 'change' => 'sample-change', 'number' => $number]);
    }

    private function tour(string $composeService = 'intakt'): DemoTour
    {
        return TourCaptionsOn::tour($this->ledger, new ReplayMarks(new ReplayMarkBus($this->replays)), $composeService);
    }

    private function startReviewer(string $round, string $role): void
    {
        $this->add(LedgerEventKind::ReviewerStarted, ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard']);
    }

    private function answer(string $round, string $role, int $findings): void
    {
        $this->add(LedgerEventKind::VerdictReceived, ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard', 'decision' => 0 === $findings ? 'approve' : 'changes', 'findings' => $findings]);
    }

    /**
     * @param list<string> $act
     */
    private function judge(string $round, array $act): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => $act, 'follow_ups' => [], 'findings' => array_map(static fn (string $id): array => ['id' => $id, 'severity' => 'P2', 'text' => 'Text of '.$id.'.'], $act)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function add(LedgerEventKind $kind, array $payload): void
    {
        $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
