<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Domain\UpNextRound;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(UpNext::class)]
#[CoversClass(UpNextRound::class)]
#[UsesClass(Board::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
final class UpNextTest extends TestCase
{
    private const string FIRST = '0199a5b4-0000-7000-8000-000000000001';
    private const string SECOND = '0199a5b4-0000-7000-8000-000000000002';

    private DashboardLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    public function testARoundWithRunningReviewersIsListedAsRunningWithItsReviewers(): void
    {
        $this->ledger->startRoundOf('feat/a', self::FIRST, 1);
        $this->ledger->startReviewer(self::FIRST, 'security');
        $this->ledger->startReviewer(self::FIRST, 'tests');

        $rounds = $this->upNext()->rounds();

        self::assertCount(1, $rounds);
        self::assertTrue($rounds[0]->isRunning());
        self::assertSame(['security', 'tests'], $rounds[0]->reviewers);
        self::assertSame(self::FIRST, $rounds[0]->round);
    }

    public function testARoundThatHasNotStartedAReviewerIsListedAsWaiting(): void
    {
        $this->ledger->startRoundOf('feat/a', self::FIRST, 1);

        $rounds = $this->upNext()->rounds();

        self::assertCount(1, $rounds);
        self::assertFalse($rounds[0]->isRunning());
    }

    public function testRunningRoundsComeBeforeWaitingOnes(): void
    {
        $this->ledger->startRoundOf('feat/a', self::FIRST, 1);
        $this->ledger->startRoundOf('feat/b', self::SECOND, 1);
        $this->ledger->startReviewer(self::SECOND, 'security');

        self::assertSame([self::SECOND, self::FIRST], array_map(static fn (UpNextRound $round): string => $round->round, $this->upNext()->rounds()));
    }

    public function testAJudgedRoundIsNotListed(): void
    {
        $this->ledger->startRoundOf('feat/a', self::FIRST, 1);
        $this->ledger->startReviewer(self::FIRST, 'security');
        $this->ledger->judgeRound(self::FIRST);

        self::assertTrue($this->upNext()->isEmpty());
    }

    public function testAFinishedReviewerLeavesTheRoundOutOfTheRunningReviewers(): void
    {
        $this->ledger->startRoundOf('feat/a', self::FIRST, 1);
        $this->ledger->startReviewer(self::FIRST, 'security');
        $this->ledger->startReviewer(self::FIRST, 'tests');
        $this->ledger->answer(self::FIRST, 'security', 0);

        self::assertSame(['tests'], $this->upNext()->rounds()[0]->reviewers);
    }

    public function testWithNothingRunningOrWaitingTheListIsEmptyAndSaysSo(): void
    {
        self::assertTrue($this->upNext()->isEmpty());
        self::assertSame('Nothing running or queued.', UpNext::NOTHING);
    }

    private function upNext(): UpNext
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));
        $pages = new LedgerReviewPages($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return UpNext::of($pages->board());
    }
}
