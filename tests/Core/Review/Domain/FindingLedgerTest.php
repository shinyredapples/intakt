<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\RoundNotAfterLatest;
use Intakt\Core\Review\Domain\TrackedFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingLedger::class)]
#[CoversClass(TrackedFinding::class)]
#[CoversClass(RoundNotAfterLatest::class)]
#[UsesClass(FindingStatus::class)]
#[UsesClass(Finding::class)]
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

        $this->expectException(RoundNotAfterLatest::class);
        $this->expectExceptionMessage('Round 2 is not after the latest recorded round 4.');

        $ledger->recordRound(2, []);
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
        try {
            $ledger->recordRound($round, [$this->finding('F1'), $this->finding('new')]);
        } catch (RoundNotAfterLatest) {
            return;
        }

        self::fail('Round '.$round.' was recorded out of order.');
    }

    private function finding(string $id): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, false, null, null);
    }
}
