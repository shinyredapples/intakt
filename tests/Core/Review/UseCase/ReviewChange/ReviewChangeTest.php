<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\AwaitedReviewer;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundTierNotMapped;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CountedVote;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[Group('persistence')]
#[CoversClass(ReviewChange::class)]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(ChangeReview::class)]
#[UsesClass(ModelNames::class)]
#[CoversClass(RoundNotNext::class)]
#[CoversClass(RoundTierNotMapped::class)]
#[CoversClass(StoredVerdicts::class)]
#[CoversClass(UpperWait::class)]
#[CoversClass(RoundEndReasons::class)]
#[CoversClass(RoundEnds::class)]
#[UsesClass(FailedJudgings::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(CountedVote::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(AwaitedReviewer::class)]
#[UsesClass(IgnoredProgress::class)]
#[UsesClass(ChangeReviewRequest::class)]
#[UsesClass(BlockReasons::class)]
#[UsesClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayProject::class)]
#[UsesClass(ReplayMark::class)]
final class ReviewChangeTest extends KernelTestCase
{
    // One sleep per review ceiling, so the two-reviewer panel reaches its upper wait of three ceilings in three polls.
    private const int A_REVIEW_CEILING = RoundReviewer::REVIEW_CEILING_SECONDS;

    private RoundFixture $fixture;

    // Answers that every tier is mapped, so only the tests of the check before a round set the mapping.
    private Vendor $tiers;

    /**
     * @var list<Envelope>
     */
    private array $parked = [];

    public function testATwoReviewerRoundWhereBothApproveIsApproved(): void
    {
        $review = $this->reviewWith(new VerdictPerRole([]));

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame(1, $review->round);
        self::assertSame([Decision::Approve, Decision::Approve], array_map(static fn ($vote): Decision => $vote->verdict->verdict, $review->verdicts));
    }

    public function testARoundOfAReplayProjectIsBlockedThoughBothReviewersApprove(): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay('alpha'));

        $review = $this->reviewWith(new VerdictPerRole([]));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([RoundEndReasons::REPLAY], [...$review->reasons]);
    }

    public function testARoundOfALiveProjectIsApprovedBesideAReplayProject(): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay('beta'));

        $review = $this->reviewWith(new VerdictPerRole([]));

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame([], [...$review->reasons]);
    }

    public function testARoundWithAChangesVerdictBelowQuorumAsksForChanges(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 2.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));

        self::assertSame(Decision::Changes, $review->decision);
    }

    public function testARoundWhoseReviewerIsBlockedIsBlocked(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 2.' => new Verdict(Decision::Blocked, [], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
    }

    public function testAFailedReviewerBesideAnApprovingOneBlocksTheRoundAndIsNamedInPlainWords(): void
    {
        $review = $this->reviewWith(new StoppingAtTheLimitForRole('Look at part 1.'));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame(['role-1 reviewer failed, stopped at its output limit'], [...$review->reasons]);
    }

    public function testAReviewerThatCouldNotReachItsEndpointIsNamedWithThatCauseInPlainWords(): void
    {
        $review = $this->reviewWith(new UnreachableForRole('Look at part 1.'));

        self::assertSame(['role-1 reviewer failed, the model endpoint could not be reached'], [...$review->reasons]);
    }

    public function testAReviewerBlockedBecauseNoProviderIsLeftIsNamedBlockedWithItsReason(): void
    {
        $review = $this->reviewWith(new OutOfProvidersForRole('Look at part 1.'));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame(['role-1 reviewer blocked, '.OutOfProvidersForRole::REASON], [...$review->reasons]);
    }

    public function testAReviewerWithABlockedVerdictIntaktGaveIsNamedBlockedWithItsReason(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => Verdict::noTools()]));

        self::assertSame(['role-1 reviewer blocked, '.Verdict::noTools()->findings[0]->text], [...$review->reasons]);
    }

    public function testAFailedReviewerBesideOneAskingForChangesGivesChangesAndStillNamesTheFailure(): void
    {
        $changes = new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], []);

        $review = $this->reviewWith(new StoppingAtTheLimitForRole('Look at part 1.', ['Look at part 2.' => $changes]));

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame(['role-1 reviewer failed, stopped at its output limit'], [...$review->reasons]);
    }

    public function testTheActListHoldsOnlyTheFindingsTheJudgeMarkedAct(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P1), $this->finding('B', Severity::P3)], [], []);

        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => $verdict]));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testAP1TheJudgeRejectsIsStillOnTheActListAndAP3IsNot(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P1), $this->finding('B', Severity::P3)], [], []);

        $review = $this->reviewWith(new RejectingJudge(new VerdictPerRole(['Look at part 1.' => $verdict])));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testAReviewerWhoseOnlyFindingsAreP3AndAllDeferredCountsAsApprovingWithFollowUps(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P3), $this->finding('B', Severity::P3)], [], []);

        $review = $this->reviewWith(new DeferringJudge(new VerdictPerRole(['Look at part 1.' => $verdict])));

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame([], $review->actList);
    }

    public function testAReviewerWhoseOnlyFindingIsAP3TheJudgeRejectsCountsAsApproving(): void
    {
        $review = $this->reviewWith(new RejectingJudge(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P3)], [], [])])));

        self::assertSame(Decision::Approve, $review->decision);
    }

    public function testTheVerdictShownForAReviewerCountedAsApprovingStillReadsAsItsOwn(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P3)], [], []);

        $review = $this->reviewWith(new DeferringJudge(new VerdictPerRole(['Look at part 1.' => $verdict])));

        self::assertSame([Decision::Changes, Decision::Approve], array_map(static fn ($vote): Decision => $vote->verdict->verdict, $review->verdicts));
    }

    public function testAP3TheJudgeMarksActKeepsTheRoundAtChangesWithThatFindingOnTheActList(): void
    {
        $review = $this->reviewWith(new JudgeRejectingFindings(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P3)], [], [])]), []));

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testARoundThatEndsAsChangesForAP2NamesItOnTheActList(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P2), $this->finding('B', Severity::P3)], [], []);

        $review = $this->reviewWith(new DeferringJudge(new VerdictPerRole(['Look at part 1.' => $verdict])));

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testARoundWhoseOnlyChangesAreAP2TheQuoteCheckDroppedIsBlockedAndNotSentBackWithNothingToAct(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->quotingMissingCode(Severity::P2)], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([], $review->actList);
    }

    public function testAP3TheQuoteCheckDroppedNeverCountsAsApprovalBecauseTheJudgeNeverSawIt(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->quotingMissingCode(Severity::P3)], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
    }

    public function testAChangesVerdictNamingAReservedFailureIdBlocksTheRoundAndSendsNoEmptyActList(): void
    {
        $failed = new Finding(Verdict::NO_TOOLS, Severity::P2, null, null, null, 'The review could not run.', FixableBy::Owner, false, null, null);

        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$failed], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([], $review->actList);
    }

    public function testAP3TheJudgeLeftWithoutAMarkBlocksTheRoundAndNeverCountsAsApproval(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P3)], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([], $review->actList);
    }

    public function testTwoReviewersReportingOnePlaceGiveOneActEntryThatNamesBothFindings(): void
    {
        $review = $this->reviewWith(new VerdictPerRole($this->bothReportLines(7, 7)));

        self::assertCount(1, $review->actList);
        self::assertSame(['F2'], [...$review->actList[0]->merged]);
    }

    public function testAP3TheJudgeRejectsAtThePlaceOfAP1StaysOutOfItsEntry(): void
    {
        $review = $this->reviewWith(new JudgeRejectingFindings(new VerdictPerRole($this->bothReportLines(7, 7, Severity::P3)), ['F2']));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
        self::assertSame([], [...$review->actList[0]->merged]);
    }

    public function testAP1TheJudgeRejectsAtThePlaceOfAnotherP1StillJoinsItsEntry(): void
    {
        $review = $this->reviewWith(new JudgeRejectingFindings(new VerdictPerRole($this->bothReportLines(7, 7)), ['F2']));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
        self::assertSame(['F2'], [...$review->actList[0]->merged]);
    }

    public function testFindingsAtDifferentLinesOfOneFileStaySeparate(): void
    {
        $review = $this->reviewWith(new VerdictPerRole($this->bothReportLines(7, 8)));

        self::assertSame(['F1', 'F2'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testARoundThatNeverReportsEndsAtTheUpperWaitAsBlockedWithNoOutcomeInTime(): void
    {
        $review = $this->reviewChange(static function (): void {}, sleepsFor: self::A_REVIEW_CEILING)->review($this->request());

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame(RoundEnds::NoOutcomeInTime, $review->end);
        self::assertSame([], $review->actList);
        self::assertStringStartsWith(RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within '.(2 * self::A_REVIEW_CEILING + UpperWait::JUDGE_SECONDS).' seconds', [...$review->reasons][0]);
        self::assertSame('2026-10-30 15:00:00', $this->fixture->clock->now()->format('Y-m-d H:i:s'));
    }

    public function testARoundTheWorkersFinishAtTheLastPollBeforeTheUpperWaitIsJudged(): void
    {
        $vendor = new VerdictPerRole([]);
        $work = function () use ($vendor): void {
            if ('14:00:00' !== $this->fixture->clock->now()->format('H:i:s')) {
                return;
            }

            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, $vendor);
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };

        $review = $this->reviewChange($work, sleepsFor: self::A_REVIEW_CEILING)->review($this->request());

        self::assertSame([Decision::Approve, [], RoundEnds::Judged], [$review->decision, [...$review->reasons], $review->end]);
    }

    public function testARoundWhoseJudgingFailedForGoodIsBlockedForTheFailedJudgingBeforeTheCeiling(): void
    {
        $review = $this->reviewChange($this->reviewersFinishAndTheJudgingOf(fn (): string => $this->openRoundId()))->review($this->request());

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([RoundEndReasons::JUDGING_FAILED], [...$review->reasons]);
        self::assertSame(RoundEnds::JudgingFailed, $review->end);
        self::assertSame([], $review->actList);
        self::assertSame('2026-10-30 12:00:01', $this->fixture->clock->now()->format('Y-m-d H:i:s'));
    }

    public function testAFailedJudgingOfAnotherRoundLeavesTheWaitToTheCeiling(): void
    {
        $review = $this->reviewChange($this->reviewersFinishAndTheJudgingOf(static fn (): string => '0192a000-0000-7000-8000-0000000000ff'), sleepsFor: self::A_REVIEW_CEILING)->review($this->request());

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertStringStartsWith(RoundEndReasons::NO_OUTCOME_IN_TIME, [...$review->reasons][0]);
    }

    public function testEachPollReportsTheProgressOfTheRound(): void
    {
        $progress = new RecordingProgress();

        $this->reviewChange(static function (): void {}, sleepsFor: self::A_REVIEW_CEILING)->review($this->request(), $progress);

        // The first read of the round reports too, because the caller should see the start before any wait.
        self::assertCount(4, $progress->reported);
        $awaited = $progress->reported[3]->awaited;
        self::assertEquals(new RoundProgress(0, 2, false, [], 0, [new AwaitedReviewer('role-1', Tier::Standard, $awaited[0]->id), new AwaitedReviewer('role-2', Tier::Standard, $awaited[1]->id)]), $progress->reported[3]);
    }

    public function testTheLastReportSaysTheRoundIsJudgedWhenTheWorkersFinishIt(): void
    {
        $progress = new RecordingProgress();
        $work = function (): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, new VerdictPerRole([]));
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge(new VerdictPerRole([]))($judging);
            }
        };

        $this->reviewChange($work)->review($this->request(), $progress);

        $last = array_last($progress->reported);
        self::assertInstanceOf(RoundProgress::class, $last);
        self::assertSame([2, 2, true, [FinishedState::Verdict, FinishedState::Verdict]], [$last->reviewersFinished, $last->reviewers, $last->judged, array_values($last->finished)]);
    }

    public function testACompleteRoundWhoseEveryFindingTheQuoteCheckDropsWaitsOnNoJudge(): void
    {
        self::assertSame([true, false], $this->findingsToJudgeOnceComplete($this->quotingMissingCode(Severity::P2)));
    }

    public function testACompleteRoundWithAFindingTheQuoteCheckKeepsWaitsOnTheJudge(): void
    {
        self::assertSame([true, true], $this->findingsToJudgeOnceComplete($this->finding('A', Severity::P2)));
    }

    public function testEveryProgressAChangeReviewReportsHasNoMoreFinishedThanStartedReviewers(): void
    {
        $progress = new RecordingProgress();
        $work = function (): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, new VerdictPerRole([]));
            }
        };

        $this->reviewChange($work, sleepsFor: self::A_REVIEW_CEILING)->review($this->request(), $progress);

        self::assertNotEmpty($progress->reported);
        foreach ($progress->reported as $reported) {
            self::assertLessThanOrEqual($reported->reviewersStarted, $reported->reviewersFinished);
            self::assertLessThanOrEqual($reported->reviewers, $reported->reviewersStarted);
        }
    }

    public function testTheTokensOfTheCallsMadeForTheRoundAreAddedAndNoOthers(): void
    {
        $this->recordUsage('before', 1000);
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, $this->openRoundId()));

        self::assertSame([1, 100, 20, 120], [$review->tokens->calls, $review->tokens->prompt, $review->tokens->completion, $review->tokens->total]);
    }

    public function testTheTokensOfTheJudgeCallOfTheRoundAreCounted(): void
    {
        $reviewers = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]);
        $vendor = new JudgeCallingVendor($reviewers, fn (JudgeRequest $request) => $this->recordUsage('judge', 300, $request->round->toRfc4122()));

        $review = $this->reviewWith($vendor);

        self::assertSame([1, 250, 50, 300], [$review->tokens->calls, $review->tokens->prompt, $review->tokens->completion, $review->tokens->total]);
    }

    public function testTheCostOfTheCallsMadeForTheRoundComesWithTheReview(): void
    {
        $this->recordUsage('before', 1000);
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, $this->openRoundId()));

        self::assertEqualsWithDelta((100 * 1.0 + 20 * 2.0) / 1_000_000, $review->cost->usd, 1e-12);
    }

    public function testTheTokensOfACallMadeForAnotherRoundAreLeftOut(): void
    {
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, '0192a000-0000-7000-8000-0000000000ff'));

        self::assertSame([0, 0], [$review->tokens->calls, $review->tokens->total]);
    }

    public function testARoundNumberThatIsNotTheNextOneIsRefusedBeforeAnythingStarts(): void
    {
        try {
            $this->reviewChange(static function (): void {})->review($this->request(round: 2));
            self::fail('Round 2 of a change without rounds was started.');
        } catch (RoundNotNext $refused) {
            self::assertStringContainsString('the next round of this change is round 1', $refused->getMessage());
        }

        self::assertSame([], $this->fixture->storedRounds());
    }

    public function testARoundAnotherCallerOpensFirstIsRefusedThoughItWasNextWhenAskedFor(): void
    {
        $bus = new InterveningRoundBus(fn (): ReviewRound => $this->fixture->start(1), $this->commandBus());

        $this->expectException(RoundNotNext::class);

        $this->reviewChange(static function (): void {}, bus: $bus)->review($this->request(round: 1));
    }

    public function testTheNextRoundNumberIsAccepted(): void
    {
        $this->reviewWith(new VerdictPerRole([]));

        $second = $this->reviewWith(new VerdictPerRole([]), round: 2);

        self::assertSame(2, $second->round);
    }

    public function testAProjectWithoutALightTierIsRefusedBeforeAnyReviewerStarts(): void
    {
        $this->tiers = $this->mapping(Tier::Standard);

        try {
            $this->reviewChange(static function (): void {})->review($this->request());
            self::fail('A round started for a project without a light tier.');
        } catch (RoundTierNotMapped $refused) {
            self::assertStringContainsString('Project "alpha" maps no model to the "light" tier', $refused->getMessage());
        }

        self::assertSame([[], []], [$this->fixture->storedRounds(), $this->fixture->bus->messages]);
    }

    public function testAProjectWithoutTheReviewersTierIsRefusedNamingThatTier(): void
    {
        $this->tiers = $this->mapping(Tier::Light);
        $this->expectExceptionObject(new RoundTierNotMapped('alpha', Tier::Standard));

        $this->reviewChange(static function (): void {})->start($this->request());
    }

    public function testAProjectMappingTheReviewersTierAndTheJudgesTierStartsTheRound(): void
    {
        $this->tiers = $this->mapping(Tier::Standard, Tier::Light);

        $this->reviewChange(static function (): void {})->start($this->request());

        self::assertCount(1, $this->fixture->storedRounds());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->tiers = new VerdictPerRole([]);
    }

    private function mapping(Tier ...$mapped): Vendor
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('mapsAModelTo')->willReturnCallback(static fn (string $project, Tier $tier): bool => in_array($tier, $mapped, true));

        return $vendor;
    }

    private function reviewWith(Vendor $vendor, ?int $round = null, ?Closure $duringTheRound = null): ChangeReview
    {
        $delivered = 0;
        $work = function () use ($vendor, &$delivered, $duringTheRound): void {
            if ($duringTheRound instanceof Closure && 0 === $delivered) {
                $duringTheRound();
            }

            foreach (array_slice($this->fixture->bus->messages, $delivered) as $message) {
                $this->fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };

        return $this->reviewChange($work)->review($this->request($round));
    }

    // Every reviewer answers and no judge runs, so the round stays complete until the upper wait; the first report is before the round completes and the last one after.
    /**
     * @return array{bool, bool}
     */
    private function findingsToJudgeOnceComplete(Finding $finding): array
    {
        $progress = new RecordingProgress();
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);
        $work = function () use ($vendor): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, $vendor);
            }
        };

        $this->reviewChange($work, sleepsFor: self::A_REVIEW_CEILING)->review($this->request(), $progress);

        return [$progress->reported[0]->findingsToJudge, array_last($progress->reported)->findingsToJudge];
    }

    private function reviewChange(Closure $work, ?MessageBusInterface $bus = null, ?int $sleepsFor = null): ReviewChange
    {
        $serializer = $this->serializer();
        $findings = new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round'));

        return new ReviewChange(
            $bus ?? $this->commandBus(),
            $this->fixture->transactions(),
            $findings,
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices(['big' => ['input' => 1.0, 'output' => 2.0, 'cache_read' => 1.0, 'cache_write' => 1.0]])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work, $sleepsFor),
            new FailureInWords(),
            $this->tiers,
            new FailedJudgings($this->failureTransport(), $this->fixture->ledger(), $this->fixture->ledgerPayloads()),
        );
    }

    // Messenger's own handling, so a refusal reaches the caller unwrapped as the application's command bus unwraps it; the reviewers' messages go to the test's workers.
    private function commandBus(): MessageBusInterface
    {
        $handler = $this->fixture->startHandlerOn($this->fixture->bus);

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))], unwrapExceptions: true);
    }

    private function serializer(): SerializerInterface
    {
        return self::getContainer()->get('serializer.stored');
    }

    private function failureTransport(): ListableReceiverInterface
    {
        $transport = self::createStub(ListableReceiverInterface::class);
        $transport->method('all')->willReturnCallback(fn (): array => $this->parked);

        return $transport;
    }

    private function request(?int $round = null): ChangeReviewRequest
    {
        $panel = $this->panel();

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE, $round), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    // The roles and prompts RoundFixture's workers answer by, so a scripted verdict reaches the reviewer it names.
    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    private function openRoundId(): string
    {
        $round = array_first($this->fixture->storedRounds());
        self::assertInstanceOf(ReviewRound::class, $round);

        return $round->id()->toRfc4122();
    }

    // The reviewers answer with a finding, so the judge would be asked, and the judging is parked in the failure transport instead, as a worker parks it after its last retry.
    /**
     * @param Closure(): string $round
     */
    private function reviewersFinishAndTheJudgingOf(Closure $round): Closure
    {
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]);

        return function () use ($vendor, $round): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, $vendor);
            }

            $this->parked = [new Envelope(new JudgeRound($round()))];
        };
    }

    // Both rows UsageLedger writes, because only the started one names the round and only the settled one carries the tokens.
    private function recordUsage(string $call, int $total, ?string $round = null): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'model' => 'big'];
        $this->append(null === $round ? $started : $started + ['round' => $round]);
        $this->append(['call' => $call, 'phase' => 'settled', 'usages' => [['account' => null, 'model' => null, 'prompt' => $total - intdiv($total, 6), 'completion' => intdiv($total, 6), 'total' => $total]]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(array $payload): void
    {
        $appended = $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::UsageRecorded, json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertTrue($appended->succeeded());
    }

    /**
     * @return array<string, Verdict>
     */
    private function bothReportLines(int $first, int $second, Severity $secondSeverity = Severity::P1): array
    {
        return [
            'Look at part 1.' => new Verdict(Decision::Changes, [$this->findingAt('A', $first, Severity::P1)], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [$this->findingAt('B', $second, $secondSeverity)], [], []),
        ];
    }

    private function findingAt(string $id, int $line, Severity $severity): Finding
    {
        return new Finding($id, $severity, 'src/A.php', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    // A quote of code the change does not hold, so the quote check drops it before the judge.
    private function quotingMissingCode(Severity $severity): Finding
    {
        return new Finding('A', $severity, 'src/Thing.php', 3, null, 'The call to `missingProbeSymbol` needs a guard.', FixableBy::Builder, false, null, null);
    }
}
