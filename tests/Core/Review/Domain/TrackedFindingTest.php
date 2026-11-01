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

#[CoversClass(TrackedFinding::class)]
#[UsesClass(FindingLedger::class)]
#[UsesClass(FindingStatus::class)]
#[UsesClass(Finding::class)]
final class TrackedFindingTest extends TestCase
{
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

    private function finding(string $id): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, false, null, null);
    }
}
