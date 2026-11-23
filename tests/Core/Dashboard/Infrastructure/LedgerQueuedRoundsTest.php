<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerQueuedRounds;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerQueuedRounds::class)]
#[CoversClass(QueuedRound::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
final class LedgerQueuedRoundsTest extends TestCase
{
    private DashboardLedger $ledger;

    public function testRoundsTheWatcherQueuedAreListedNewestFirstAndNamedAfterTheirPullRequest(): void
    {
        $first = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $second = $this->ledger->queueRoundOf('beta', '8', 'b');

        self::assertSame([[$second, 'beta', 'Pull request 8'], [$first, 'alpha', 'Pull request 7']], $this->queued());
    }

    public function testARoundWhoseReviewStartedIsNoLongerQueued(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->startRound($round, 'alpha');

        self::assertSame([], $this->queued());
    }

    public function testAnUnreadableStartLeavesTheRoundQueued(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, json_encode(['round' => $round, 'project' => []], JSON_THROW_ON_ERROR)));

        self::assertSame([[$round, 'alpha', 'Pull request 7']], $this->queued());
    }

    public function testANewerWatcherLineOfThePullRequestEndsTheWait(): void
    {
        $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->keepHeadPending('alpha', '7', 'b');

        self::assertSame([], $this->queued());
    }

    public function testARoundQueuedAgainAfterAnotherLineIsListed(): void
    {
        $this->ledger->forgetHead('alpha', '7', 'a');
        $round = $this->ledger->queueRoundOf('alpha', '7', 'b');

        self::assertSame([[$round, 'alpha', 'Pull request 7']], $this->queued());
    }

    public function testTheSamePullRequestNumberInTwoProjectsIsTwoRounds(): void
    {
        $alpha = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $beta = $this->ledger->queueRoundOf('beta', '7', 'b');

        self::assertSame([[$beta, 'beta', 'Pull request 7'], [$alpha, 'alpha', 'Pull request 7']], $this->queued());
    }

    public function testAQueueLineWithoutARoundOrProjectIsSkipped(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"7","head":"a"}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"change":"8","head":"a","round":"r-1"}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"9","head":"a","round":""}'));

        self::assertSame([], $this->queued());
    }

    public function testAQueueLineWithoutARoundIdLeavesTheReadableQueueLineBeforeIt(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'a');
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"7","head":"a","round":null}'));

        self::assertSame([[$round, 'alpha', 'Pull request 7']], $this->queued());
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function queued(): array
    {
        return array_map(static fn (QueuedRound $round): array => [$round->round, $round->project, $round->title()], new LedgerQueuedRounds(new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())))->in($this->ledger->readInOrder()));
    }
}
