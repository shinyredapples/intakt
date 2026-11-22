<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\RoundNotAfterLatest;
use Intakt\Core\Review\Domain\TrackedFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingLedger::class)]
#[CoversClass(TrackedFinding::class)]
#[CoversClass(RoundNotAfterLatest::class)]
#[UsesClass(FindingStatus::class)]
#[UsesClass(Finding::class)]
#[UsesClass(GoalpostRule::class)]
#[UsesClass(Verdict::class)]
final class FindingLedgerTest extends TestCase
{
    public function testFindingIdsStayStableOverThreeRounds(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('y')]);
        $ledger->recordRound(2, [$this->finding('F1'), $this->finding('F2')]);
        $ledger->recordRound(3, [$this->finding('F1'), $this->finding('F2')]);

        $all = $ledger->all();
        self::assertSame(['F1', 'F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $all));
        self::assertSame([1, 2, 3], $all[0]->rounds);
        self::assertSame([1, 2, 3], $all[1]->rounds);
    }

    public function testAResolvedFindingClosesAndIsNoLongerOpen(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('y')]);

        $ledger->resolve(['F1']);

        self::assertSame(['F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->open()));
        self::assertSame(FindingStatus::Resolved, $ledger->all()[0]->status);
    }

    public function testANewFindingInALaterRoundGetsAFreshIdWithoutChangingExistingOnes(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);
        $ledger->recordRound(2, [$this->finding('F1'), $this->finding('new')]);

        $all = $ledger->all();
        self::assertSame('F1', $all[0]->id);
        self::assertSame('F2', $all[1]->id);
        self::assertSame([2], $all[1]->rounds);
    }

    public function testTwoReviewersReusingTheSameNewIdInOneRoundGetTwoLedgerIds(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('F1'), $this->finding('F1')]);

        self::assertSame(['F1', 'F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->all()));
    }

    public function testAResolvedFindingReportedAgainIsOpenAgain(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);
        $ledger->resolve(['F1']);

        $ledger->recordRound(3, [$this->finding('F1')]);

        self::assertSame(['F1'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->open()));
    }

    public function testTwoReviewersRepeatingAnExistingIdInOneRoundShareThatFinding(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);
        $ledger->recordRound(2, [$this->finding('F1'), $this->finding('F1')]);
        $ledger->recordRound(3, [$this->finding('F1'), $this->finding('F1')]);

        $ledger->resolve(['F1']);

        self::assertSame([], $ledger->open());
        self::assertSame([1, 2, 3], $ledger->all()[0]->rounds);
    }

    public function testAFindingThatReusesAnIdIssuedInTheSameRoundGetsItsOwnId(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('F1')]);

        self::assertSame(['F1', 'F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->all()));
    }

    public function testAFindingsFirstAndLatestRoundAreItsFirstAndLastAppearance(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);
        $ledger->recordRound(2, []);
        $ledger->recordRound(3, [$this->finding('F1')]);

        $tracked = $ledger->all()[0];
        self::assertSame(1, $tracked->firstRound());
        self::assertSame(3, $tracked->latestRound());
    }

    public function testResolvingAnUnknownIdLeavesOpenFindingsUnchanged(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->resolve(['F9']);

        self::assertCount(1, $ledger->open());
    }

    public function testRecordingTheSameRoundAgainIsRefusedAndLeavesTheLedgerUnchanged(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('y')]);
        $ledger->resolve(['F1']);
        $all = $ledger->all();
        $open = $ledger->open();

        $this->recordRefusedRound($ledger, 1);

        self::assertEquals($all, $ledger->all());
        self::assertEquals($open, $ledger->open());
    }

    public function testRecordingAnEarlierRoundIsRefusedAndLeavesTheLedgerUnchanged(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(3, [$this->finding('x'), $this->finding('y')]);
        $ledger->resolve(['F1']);
        $all = $ledger->all();
        $open = $ledger->open();

        $this->recordRefusedRound($ledger, 2);

        self::assertEquals($all, $ledger->all());
        self::assertEquals($open, $ledger->open());
    }

    public function testTheRefusalNamesTheRefusedAndTheLatestRound(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(4, []);

        $refusal = $this->refusalOf($ledger, 2);

        self::assertSame('Round 2 is not after the latest recorded round 4.', $refusal?->getMessage());
    }

    public function testTheFirstRoundMayBeAnyNumberAndLaterRoundsOnlyNeedToBeHigher(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(5, [$this->finding('x')]);
        $ledger->recordRound(6, []);
        $ledger->recordRound(9, [$this->finding('F1')]);

        self::assertSame([5, 9], $ledger->all()[0]->rounds);
    }

    private function recordRefusedRound(FindingLedger $ledger, int $round): void
    {
        self::assertInstanceOf(RoundNotAfterLatest::class, $this->refusalOf($ledger, $round), 'Round '.$round.' was recorded out of order.');
    }

    private function refusalOf(FindingLedger $ledger, int $round): ?RoundNotAfterLatest
    {
        try {
            $ledger->recordRound($round, [$this->finding('F1'), $this->finding('new')]);
        } catch (RoundNotAfterLatest $refusal) {
            return $refusal;
        }

        return null;
    }

    public function testAVerdictWithResolvedIdsClosesThoseFindings(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('y')]);

        $ledger->recordVerdict(2, $this->verdict(['F1'], []));

        self::assertSame(['F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->open()));
    }

    public function testARepeatFindingKeepsItsId(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)]));

        self::assertSame(['F1'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->all()));
        self::assertSame([1, 2], $ledger->all()[0]->rounds);
    }

    public function testANonRepeatFindingGetsANewIdEvenWhenItsIdMatchesAnOpenOne(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1')]));

        self::assertSame(['F1', 'F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->all()));
    }

    public function testIdsStayStableOverThreeRoundsOfVerdictsAndTheLastResolvesThem(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a'), $this->finding('b')]));
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true), $this->finding('F2', true)]));
        $ledger->recordVerdict(3, $this->verdict(['F2'], [$this->finding('F1', true)]));

        self::assertSame(['F1'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->open()));
        self::assertSame([1, 2, 3], $ledger->all()[0]->rounds);
        self::assertSame(2, $ledger->all()[1]->latestRound());
        self::assertSame(FindingStatus::Resolved, $ledger->all()[1]->status);
    }

    public function testAFindingOneReviewerRepeatsStaysOpenWhenAnotherResolvesItAfterwardsInTheSameRound(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)]));
        $ledger->recordVerdict(2, $this->verdict(['F1'], []));

        self::assertSame(FindingStatus::Open, $ledger->all()[0]->status);
        self::assertSame([1, 2], $ledger->all()[0]->rounds);
    }

    public function testAFindingOneReviewerRepeatsStaysOpenWhenAnotherResolvedItEarlierInTheSameRound(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->recordVerdict(2, $this->verdict(['F1'], []));
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)]));

        self::assertSame(FindingStatus::Open, $ledger->all()[0]->status);
        self::assertSame([1, 2], $ledger->all()[0]->rounds);
    }

    public function testAFindingResolvedInALaterRoundThanItWasLastReportedCloses(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)]));

        $ledger->recordVerdict(3, $this->verdict(['F1'], []));

        self::assertSame(FindingStatus::Resolved, $ledger->all()[0]->status);
    }

    /**
     * @return iterable<string, array{Verdict}>
     */
    public static function failedReviews(): iterable
    {
        yield 'a recorded invalid output' => [new Verdict(Decision::Changes, [new Finding(Verdict::INVALID_OUTPUT, Severity::P1, null, null, null, 'Broken.', FixableBy::Builder, false, null, null)], [], [])];
        yield 'incomplete stream' => [Verdict::incompleteStream('the stream ended.', new Evidence('the call to anthropic, model big', 'cut by: platform'))];
        yield 'unread approval' => [Verdict::unreadApproval()];
        yield 'no tools' => [Verdict::noTools()];
        yield 'cut approval' => [Verdict::cutApproval()];
        yield 'reviewer stopped' => [Verdict::reviewerStopped()];
    }

    #[DataProvider('failedReviews')]
    public function testAFailedReviewAddsNoFindingToTheChange(Verdict $failed): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x')]);

        $ledger->recordVerdict(2, $failed);

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $ledger->openForReviewers()));
        self::assertSame([1], $ledger->all()[0]->rounds);
    }

    public function testAnotherReviewersFindingBesideAStoredChangesVerdictWithAReservedIdStaysOpen(): void
    {
        $ledger = new FindingLedger();

        $ledger->recordVerdict(1, new Verdict(Decision::Changes, Verdict::noTools()->findings, [], []));
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('cart-total')]));

        self::assertCount(1, $ledger->open());
        self::assertSame('F1', $ledger->open()[0]->id);
    }

    public function testAFindingThatOnlySharesItsTextWithAFailedReviewIsRecorded(): void
    {
        $ledger = new FindingLedger();

        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('The reviewer output is not a valid verdict')]));

        self::assertCount(1, $ledger->openForReviewers());
    }

    public function testALedgerRebuiltFromItsTrackedFindingsKeepsThemAndIssuesTheNextId(): void
    {
        $earlier = new FindingLedger();
        $earlier->recordRound(1, [$this->finding('x'), $this->finding('y')]);

        $ledger = new FindingLedger($earlier->all());
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F2', true), $this->finding('new')]));

        self::assertSame(['F1', 'F2', 'F3'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->all()));
        self::assertSame([1, 2], $ledger->all()[1]->rounds);
    }

    public function testOpenFindingsForReviewersCarryTheirLedgerIdAndLatestText(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(1, [$this->finding('x'), $this->finding('y')]);
        $ledger->resolve(['F1']);

        $open = $ledger->openForReviewers();

        self::assertCount(1, $open);
        self::assertSame('F2', $open[0]->id);
        self::assertSame('Text y', $open[0]->text);
    }

    public function testARepeatKeepsTheSeverityItWasReportedWith(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a', severity: Severity::P1)]));
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true, Severity::P3)]));

        self::assertSame(Severity::P1, $ledger->all()[0]->latest->severity);
    }

    public function testALoweredFindingRepeatedAsP1StaysP3(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a')]));
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('b', severity: Severity::P1)]));

        $ledger->recordVerdict(3, $this->verdict([], [$this->finding('F2', true, Severity::P1)]));

        self::assertSame(Severity::P3, $ledger->all()[1]->latest->severity);
    }

    public function testARoundOneP3RepeatedAsP1StaysP3(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a', severity: Severity::P3)]));

        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true, Severity::P1)]));

        self::assertSame(Severity::P3, $ledger->all()[0]->latest->severity);
    }

    public function testTheLedgerStoresALoweredNewFindingAsP3(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a')]));
        $ledger->recordVerdict(2, $this->verdict([], [$this->finding('b', severity: Severity::P1)]));

        self::assertSame([Severity::P2, Severity::P3], array_map(static fn (TrackedFinding $tracked): Severity => $tracked->latest->severity, $ledger->all()));
    }

    public function testAVerdictTellsTheLedgerIdsItsFindingsWereTrackedUnder(): void
    {
        $ledger = new FindingLedger();

        self::assertSame(['F1', 'F2'], $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a'), $this->finding('b')])));
    }

    public function testAFindingAReviewerReportsAgainIsTrackedUnderItsEarlierId(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a'), $this->finding('b')]));

        self::assertSame(['F2', 'F3'], $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F2', true), $this->finding('c')])));
    }

    public function testTwoReviewersRepeatingOneFindingInARoundAreBothTrackedUnderItsId(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $this->verdict([], [$this->finding('a')]));

        self::assertSame([['F1'], ['F1']], [$ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)])), $ledger->recordVerdict(2, $this->verdict([], [$this->finding('F1', true)]))]);
    }

    public function testAFailedReviewIsTrackedUnderNoId(): void
    {
        self::assertSame([], new FindingLedger()->recordVerdict(1, Verdict::noTools()));
    }

    public function testALedgerApartForARoundTracksItsNewFindingsUnderTheRoundsPrefix(): void
    {
        $earlier = new FindingLedger();
        $earlier->recordVerdict(1, $this->verdict([], [$this->finding('a')]));

        self::assertSame(['F1', 'R3-F2'], FindingLedger::apartFor(3, $earlier->all())->recordVerdict(3, $this->verdict([], [$this->finding('F1', true), $this->finding('b')])));
    }

    /**
     * @param list<string>  $resolved
     * @param list<Finding> $findings
     */
    private function verdict(array $resolved, array $findings): Verdict
    {
        return new Verdict(Decision::Changes, $findings, array_map(static fn (string $id): ResolvedFinding => new ResolvedFinding($id), $resolved), []);
    }

    private function finding(string $id, bool $repeat = false, Severity $severity = Severity::P2): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, null, null);
    }
}
