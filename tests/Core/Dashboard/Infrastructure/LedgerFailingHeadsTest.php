<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerFailingHeads::class)]
#[CoversClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(LedgerPayload::class)]
final class LedgerFailingHeadsTest extends TestCase
{
    private DashboardLedger $ledger;

    public function testAFailedHeadIsListedWithItsCauseFailuresAndNextTry(): void
    {
        $this->ledger->failHead('alpha', '7', 2);

        $heads = $this->failing();

        self::assertCount(1, $heads);
        self::assertSame(['alpha', '7', 'RuntimeException: commit a not fetched', 2, '2026-10-28T12:08:00+00:00', false], [$heads[0]->project, $heads[0]->change, $heads[0]->fault->cause, $heads[0]->fault->failures, $heads[0]->fault->retryAt->format(DATE_ATOM), $heads[0]->handedOver]);
    }

    public function testOnlyTheLatestFailureOfAPullRequestIsListed(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $this->ledger->failHead('alpha', '7', 2);

        self::assertSame([2], array_map(static fn (FailingHead $head): int => $head->fault->failures, $this->failing()));
    }

    public function testAHeadReadSinceItFailedIsNoLongerListed(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $this->ledger->keepHeadPending('alpha', '7', 'a');

        self::assertSame([], $this->failing());
    }

    public function testAHandedOverHeadStaysMarkedThroughLaterFailuresOfTheSameCommit(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');

        $this->ledger->failHead('alpha', '7', 5);

        self::assertTrue($this->failing()[0]->handedOver);
    }

    public function testAHandedOverHeadReadAsPendingBeforeItsNextFailureStaysMarked(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');
        $this->ledger->keepHeadPending('alpha', '7', 'a');

        $this->ledger->failHead('alpha', '7', 5);

        self::assertCount(1, $this->failing());
        self::assertTrue($this->failing()[0]->handedOver);
    }

    public function testAHandedOverHeadReadAsRedBeforeItsNextFailureStaysMarked(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');
        $this->ledger->recordRedHead('alpha', '7', 'a');

        $this->ledger->failHead('alpha', '7', 5);

        self::assertTrue($this->failing()[0]->handedOver);
    }

    public function testAHandedOverHeadThatFailsAgainAfterItsRoundStartedIsNotMarked(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');
        $this->ledger->queueRoundOf('alpha', '7', 'a');

        $this->ledger->failHead('alpha', '7', 1);

        self::assertFalse($this->failing()[0]->handedOver);
    }

    public function testAHeadForgottenWithItsClosedPullRequestIsNoLongerListed(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $this->ledger->forgetHead('alpha', '7', 'a');

        self::assertSame([], $this->failing());
    }

    public function testAHandedOverHeadThatFailsAgainAfterItWasForgottenIsNotMarked(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');
        $this->ledger->forgetHead('alpha', '7', 'a');

        $this->ledger->failHead('alpha', '7', 1);

        self::assertFalse($this->failing()[0]->handedOver);
    }

    public function testAFailureOfAMovedHeadAfterAHandOverIsNotMarkedHandedOver(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'a');

        $this->ledger->failMovedHead('alpha', '7');

        self::assertFalse($this->failing()[0]->handedOver);
    }

    public function testTheSamePullRequestNumberInTwoProjectsIsListedForEach(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $this->ledger->failHead('beta', '7', 1);

        $this->ledger->keepHeadPending('beta', '7', 'a');

        self::assertSame(['alpha'], array_map(static fn (FailingHead $head): string => $head->project, $this->failing()));
    }

    public function testAnUnreadableFailureIsNotListedAndClearsTheEarlierOne(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $this->ledger->failHeadWithoutCause('alpha', '7');

        self::assertSame([], $this->failing());
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    /**
     * @return list<FailingHead>
     */
    private function failing(): array
    {
        return new LedgerFailingHeads($this->ledger)->now();
    }
}
