<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\TrackedFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingLedger::class)]
#[CoversClass(TrackedFinding::class)]
#[UsesClass(FindingStatus::class)]
#[UsesClass(Finding::class)]
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

    /**
     * @param list<string>  $resolved
     * @param list<Finding> $findings
     */
    private function verdict(array $resolved, array $findings): Verdict
    {
        return new Verdict(Decision::Changes, $findings, $resolved, []);
    }

    private function finding(string $id, bool $repeat = false): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, null, null);
    }
}
