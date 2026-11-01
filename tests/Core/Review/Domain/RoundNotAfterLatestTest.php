<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\RoundNotAfterLatest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundNotAfterLatest::class)]
#[UsesClass(FindingLedger::class)]
final class RoundNotAfterLatestTest extends TestCase
{
    public function testTheRefusalNamesTheRefusedAndTheLatestRound(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordRound(4, []);

        try {
            $ledger->recordRound(2, []);
        } catch (RoundNotAfterLatest $refusal) {
            self::assertSame('Round 2 is not after the latest recorded round 4.', $refusal->getMessage());

            return;
        }

        self::fail('Round 2 was recorded out of order.');
    }
}
