<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\DiscardedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\RoundTransactionLost;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Support\KernelTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Throwable;

#[Group('persistence')]
#[CoversClass(StartedRounds::class)]
#[CoversClass(DiscardedRounds::class)]
#[CoversClass(StartedRound::class)]
#[CoversClass(QueuedRound::class)]
#[CoversClass(DiscardQueuedRound::class)]
#[CoversClass(RoundTransactionLost::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
final class StartedRoundsTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private RecordingCommandBus $commands;
    private PendingHeads $pending;
    private RecordingLogger $logger;
    private StartedRounds $rounds;

    public function testARoundQueuedBeforeItsRecordRolledBackIsDiscardedAndLeavesNoRecord(): void
    {
        $this->entities->getConnection()->executeStatement('DROP TABLE watcher_pending_head');

        try {
            $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
            self::fail('The failed end of the wait was swallowed.');
        } catch (Exception) {
        }

        self::assertEquals([RecordingCommandBus::queued(1)], $this->commands->discarded);
        self::assertSame(0, $this->storedRounds());
    }

    public function testARoundQueuedBeforeItsRecordFailedToFlushIsDiscardedAndLeavesNoRecord(): void
    {
        $connection = $this->entities->getConnection();

        try {
            $this->rounds->startOnce('alpha', $this->green(), function () use ($connection): QueuedRound {
                $this->insertConflictingRound($connection);

                return RecordingCommandBus::queued(1);
            });
            self::fail('The failed record of the round was swallowed.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertEquals([RecordingCommandBus::queued(1)], $this->commands->discarded);
        self::assertSame(0, $this->storedRounds());
    }

    // The connection is lost while the round is queued, so DBAL would write the record and the end of the wait in autocommit.
    public function testARoundQueuedBeforeItsTransactionWasLostIsDiscardedAndLeavesTheHeadDue(): void
    {
        $connection = $this->entities->getConnection();
        $green = $this->green();
        $this->pending->enqueue('alpha', [$green]);

        try {
            $this->rounds->startOnce('alpha', $green, static function () use ($connection): QueuedRound {
                $connection->close();

                return RecordingCommandBus::queued(1);
            });
            self::fail('The lost transaction was swallowed.');
        } catch (RoundTransactionLost) {
        }

        self::assertEquals([RecordingCommandBus::queued(1)], $this->commands->discarded);
        self::assertSame(0, $this->storedRounds());
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    // A commit past DBAL (native, or implicit as on MySQL's DDL) leaves DBAL's own count of transactions at one, so the record and the end of the wait would be written in autocommit.
    public function testARoundQueuedBeforeACommitPastDbalIsDiscardedAndLeavesTheHeadDue(): void
    {
        $connection = $this->entities->getConnection();
        $green = $this->green();
        $this->pending->enqueue('alpha', [$green]);

        try {
            $this->rounds->startOnce('alpha', $green, static function () use ($connection): QueuedRound {
                $native = $connection->getNativeConnection();
                self::assertInstanceOf(PDO::class, $native);
                $native->commit();

                return RecordingCommandBus::queued(1);
            });
            self::fail('The lost transaction was swallowed.');
        } catch (RoundTransactionLost) {
        }

        self::assertEquals([RecordingCommandBus::queued(1)], $this->commands->discarded);
        self::assertSame(0, $this->storedRounds());
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    // A commit past the native connection would make the record's flush open a savepoint outside a transaction, which PostgreSQL refuses before the rollback is reached, so the transaction ends through DBAL and the rollback fails for want of one.
    public function testARoundQueuedBeforeARollbackThatItselfFailsIsStillDiscardedAndTheOriginalFailureIsRethrown(): void
    {
        $connection = $this->entities->getConnection();

        try {
            $this->rounds->startOnce('alpha', $this->green(), static function () use ($connection): QueuedRound {
                $connection->commit();

                return RecordingCommandBus::queued(1);
            });
            self::fail('The lost transaction was swallowed.');
        } catch (RoundTransactionLost) {
        }

        self::assertEquals([RecordingCommandBus::queued(1)], $this->commands->discarded);
    }

    public function testADiscardThatFailsIsLoggedAndTheOriginalFailureIsRethrown(): void
    {
        $discardFailure = new RuntimeException('the discard failed');
        $this->commands->discardFailure = $discardFailure;
        $connection = $this->entities->getConnection();

        try {
            $this->rounds->startOnce('alpha', $this->green(), function () use ($connection): QueuedRound {
                $this->insertConflictingRound($connection);

                return RecordingCommandBus::queued(1);
            });
            self::fail('The failed record of the round was swallowed.');
        } catch (Throwable $thrown) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        }

        self::assertCount(1, $this->logger->logged);
        self::assertSame($discardFailure, $this->logger->logged[0][1]['exception']);
        self::assertSame('round-1', $this->logger->logged[0][1]['round']);
        self::assertSame(0, $this->storedRounds());
    }

    public function testAStartThatFailsItselfLeavesTheDiscardToTheStart(): void
    {
        try {
            $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => throw new RuntimeException('the review start failed'));
            self::fail('The failed start was swallowed.');
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->commands->discarded);
    }

    public function testARoundRecordedAndCommittedIsNotDiscarded(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));

        self::assertSame([], $this->commands->discarded);
        self::assertSame(1, $this->storedRounds());
    }

    public function testARoundAnotherWorkerRemovedWhileTheOwedLinesWereReadIsNotOwed(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->deleteRoundsOnNextLoad();

        $owed = $this->rounds->notYetRecorded('alpha');

        self::assertSame([], $owed);
    }

    public function testARoundAnotherWorkerRemovedWhileTheChangesRoundsWereReadIsNotOpen(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->deleteRoundsOnNextLoad();

        $open = $this->rounds->openRoundsBefore('alpha', RecordedForge::change('7', 'e', '2026-11-02T10:05:00Z'));

        self::assertSame([], $open);
    }

    public function testARoundStillStoredIsOpenBeforeANewerHead(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->entities->clear();

        $open = $this->rounds->openRoundsBefore('alpha', RecordedForge::change('7', 'e', '2026-11-02T10:05:00Z'));

        self::assertSame(['round-1'], array_map(static fn (StartedRound $round): string => $round->roundId(), $open));
    }

    public function testAPullRequestWhoseRoundStartedAtAnEarlierStaleHeadHasARound(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->markStored(stale: true, recorded: true);

        self::assertTrue($this->rounds->hasRoundOf('alpha', RecordedForge::change('7', 'e', '2026-11-02T10:05:00Z')));
    }

    public function testAPullRequestWithoutAStartedRoundHasNoRound(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));

        self::assertFalse($this->rounds->hasRoundOf('alpha', RecordedForge::change('8', 'b', '2026-11-02T09:00:00Z')));
        self::assertFalse($this->rounds->hasRoundOf('beta', $this->green()));
    }

    public function testAReturningHeadWhoseStaleRoundStillOwesItsQueuedLineStartsNoRound(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->markStored(
            stale: true,
            recorded: false,
        );
        $started = 0;

        $this->rounds->startOnce('alpha', $this->green(), static function () use (&$started): QueuedRound {
            ++$started;

            return RecordingCommandBus::queued(2);
        });

        self::assertSame(0, $started);
        self::assertSame('round-1', $this->storedRoundId());
    }

    public function testAReturningHeadWhoseStaleRoundStillOwesItsQueuedLineStaysDueUntilTheLineIsRecorded(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $old = $this->rounds->notYetRecorded('alpha')[0];
        $this->rounds->markStaleOnce($old, static function (): void {});
        $this->pending->enqueue('alpha', [$this->green()]);

        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(2));
        $this->rounds->recordOnce($old, static function (): void {});

        self::assertCount(1, $this->pending->dueFor('alpha'));
    }

    // The listing lock is released before the heads are looked at, so a second tick records the owed line while the first is inside the start.
    public function testALineRecordedBetweenTheStartedCheckAndTheEndOfTheWaitKeepsTheHeadDue(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $old = $this->rounds->notYetRecorded('alpha')[0];
        $this->rounds->markStaleOnce($old, static function (): void {});
        $this->pending->enqueue('alpha', [$this->green()]);
        $this->entities->getEventManager()->addEventListener([Events::postLoad], new RecordedOnFirstLoad($this->rounds, $old));
        $started = 0;

        $this->rounds->startOnce('alpha', $this->green(), static function () use (&$started): QueuedRound {
            ++$started;

            return RecordingCommandBus::queued(2);
        });

        self::assertSame(0, $started);
        self::assertCount(1, $this->pending->dueFor('alpha'));
    }

    public function testADelayedRecorderOfAReplacedRoundDoesNotClaimTheFreshRoundsQueuedLine(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $delayed = $this->rounds->notYetRecorded('alpha')[0];
        $this->rounds->recordOnce($delayed, static function (): void {});
        $this->rounds->markStaleOnce($delayed, static function (): void {});
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(2));
        $recorded = [];

        $this->rounds->recordOnce($delayed, static function (StartedRound $round) use (&$recorded): void {
            $recorded[] = $round->roundId();
        });

        self::assertSame([], $recorded);
        self::assertSame(['round-2'], array_map(static fn (StartedRound $round): string => $round->roundId(), $this->rounds->notYetRecorded('alpha')));
    }

    public function testADelayedMarkerOfAReplacedRoundDoesNotMarkTheFreshRoundStale(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $delayed = $this->rounds->notYetRecorded('alpha')[0];
        $this->rounds->recordOnce($delayed, static function (): void {});
        $this->rounds->markStaleOnce($delayed, static function (): void {});
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(2));
        $marked = [];

        $this->rounds->markStaleOnce($delayed, static function (StartedRound $round) use (&$marked): void {
            $marked[] = $round->roundId();
        });

        self::assertSame([], $marked);
        self::assertSame(0, $this->entities->getRepository(StartedRound::class)->count(['stale' => true]));
    }

    public function testAFailedStartOnAReturningHeadKeepsItsStaleRound(): void
    {
        $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(1));
        $this->markStored(
            stale: true,
            recorded: true,
        );
        $this->entities->getConnection()->executeStatement('DROP TABLE watcher_pending_head');

        try {
            $this->rounds->startOnce('alpha', $this->green(), static fn (): QueuedRound => RecordingCommandBus::queued(2));
            self::fail('The failed end of the wait was swallowed.');
        } catch (Exception) {
        }

        self::assertSame('round-1', $this->storedRoundId());
        self::assertSame(1, $this->entities->getRepository(StartedRound::class)->count(['stale' => true]));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->commands = new RecordingCommandBus();
        $this->pending = new PendingHeads($entities, new MockClock('2026-11-02T10:00:00+00:00'));
        $this->logger = new RecordingLogger();
        $this->rounds = new StartedRounds($entities, $this->pending, new DiscardedRounds($this->commands, $this->logger));
    }

    private function green(): OpenChange
    {
        return RecordedForge::change('7', 'b', '2026-11-02T09:00:00Z');
    }

    // Plain DML, because DDL commits the open transaction on MySQL and MariaDB; the flush then meets the same key.
    private function insertConflictingRound(Connection $connection): void
    {
        $connection->insert('watcher_started_round', [
            'project' => 'alpha',
            'change_ref' => '7',
            'head' => $this->green()->head->hash,
            'round_id' => 'round-0',
            'stale' => false,
            'queued_recorded' => false,
            'labels' => [],
        ], ['stale' => Types::BOOLEAN, 'queued_recorded' => Types::BOOLEAN, 'labels' => Types::JSON]);
    }

    private function deleteRoundsOnNextLoad(): void
    {
        $this->entities->clear();
        $this->entities->getEventManager()->addEventListener([Events::postLoad], new DeletedOnFirstLoad($this->entities->getConnection(), 'watcher_started_round'));
    }

    private function markStored(bool $stale, bool $recorded): void
    {
        $this->entities->getConnection()->executeStatement('UPDATE watcher_started_round SET stale = ?, queued_recorded = ?', [$stale, $recorded], [Types::BOOLEAN, Types::BOOLEAN]);
        $this->entities->clear();
    }

    private function storedRoundId(): string
    {
        $this->entities->clear();

        return $this->entities->getRepository(StartedRound::class)->findOneBy([])?->roundId() ?? '';
    }

    private function storedRounds(): int
    {
        $this->entities->clear();

        return $this->entities->getRepository(StartedRound::class)->count([]);
    }
}
