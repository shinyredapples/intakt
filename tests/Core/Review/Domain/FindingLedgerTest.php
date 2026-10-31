<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
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

    private function finding(string $id): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, false, null, null);
    }
}
