<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

// Two connections rather than two threads, because only a commit forced between the first worker's read of its row and its write reproduces the order in which two reviewers of the demo met on SQLite.
#[Group('persistence')]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
final class ConcurrentVerdictStoreTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private ScriptedVendor $first;
    private ScriptedVendor $second;
    private SecondWorker $other;
    private BeforeFirstAppendOf $ledger;

    public function testTwoVerdictsStoredAtOnceAreBothStoredWithOneCallToEachReviewer(): void
    {
        $round = $this->storeBothVerdictsAtOnce();

        self::assertSame([1, 1], [$this->first->calls, $this->second->calls]);
        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict], [$this->stateOf(0), $this->stateOf(1)]);
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerFindingsRecorded, LedgerEventKind::ReviewerFindingsRecorded], $this->fixture->ledgerKinds());
        self::assertSame([LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived], $this->other->ledger->kinds());
        self::assertSame(RoundState::Complete, $this->fixture->round($round)->state());
    }

    public function testOnSqliteTheFirstStoreMeetsALockTimeoutAndTheNextTransactionStoresTheVerdict(): void
    {
        if (!$this->fixture->entities->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Only SQLite refuses a write after a snapshot another connection made stale.');
        }

        $this->storeBothVerdictsAtOnce();

        self::assertSame(['the event was not stored: '.LockWaitTimeoutException::class], $this->ledger->failures);
        self::assertSame(1, $this->first->calls);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->fixture->useWalOnSqlite();
        $this->first = new ScriptedVendor();
        $this->second = new ScriptedVendor();
    }

    private function storeBothVerdictsAtOnce(): string
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();
        [$mine, $theirs] = $this->fixture->bus->messages;
        $this->other = $this->fixture->secondWorker($this->second);
        $this->ledger = new BeforeFirstAppendOf($this->fixture->ledger(), LedgerEventKind::VerdictReceived, fn () => ($this->other->handler)($theirs));

        $this->fixture->worker($this->fixture->entities, $this->first, $this->ledger)($mine);

        return $round;
    }

    private function stateOf(int $message): ReviewerState
    {
        return $this->fixture->reviewer($this->fixture->bus->messages[$message]->reviewerId)->state();
    }
}
