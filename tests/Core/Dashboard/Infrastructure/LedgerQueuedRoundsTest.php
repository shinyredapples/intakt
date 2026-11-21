<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerQueuedRounds;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerQueuedRounds::class)]
#[CoversClass(QueuedRound::class)]
#[UsesClass(LedgerPayload::class)]
final class LedgerQueuedRoundsTest extends TestCase
{
    private DashboardLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    public function testRoundsTheWatcherQueuedAreListedNewestFirstAndNamedAfterTheirPullRequest(): void
    {
        $first = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $second = $this->ledger->queueRoundOf('beta', '8', 'b');

        $queued = new LedgerQueuedRounds()->in($this->ledger->readInOrder());

        self::assertSame([[$second, 'beta', 'Pull request 8'], [$first, 'alpha', 'Pull request 7']], array_map(static fn (QueuedRound $round): array => [$round->round, $round->project, $round->title()], $queued));
    }

    public function testARoundWhoseReviewStartedIsNoLongerQueued(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->startRound($round, 'alpha');

        self::assertSame([], new LedgerQueuedRounds()->in($this->ledger->readInOrder()));
    }

    public function testARoundTheWatcherMarkedStaleIsNoLongerQueued(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->markVerdictsStale('alpha', '7', $round);

        self::assertSame([], new LedgerQueuedRounds()->in($this->ledger->readInOrder()));
    }

    public function testAQueueLineWithoutARoundOrProjectIsSkipped(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"7","head":"a"}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"change":"7","head":"a","round":"r-1"}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"7","head":"a","round":""}'));

        self::assertSame([], new LedgerQueuedRounds()->in($this->ledger->readInOrder()));
    }

    public function testOneQueuedRoundIsFoundByItsId(): void
    {
        $this->ledger->queueRoundOf('alpha', '7', 'a');
        $round = $this->ledger->queueRoundOf('alpha', '8', 'b');

        self::assertSame('8', new LedgerQueuedRounds()->roundIn($this->ledger->readInOrder(), $round)?->change);
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        $this->ledger->queueRoundOf('alpha', '7', 'a');

        self::assertNull(new LedgerQueuedRounds()->roundIn($this->ledger->readInOrder(), 'r-unknown'));
    }
}
