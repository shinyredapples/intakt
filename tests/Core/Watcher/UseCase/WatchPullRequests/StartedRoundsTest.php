<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(StartedRounds::class)]
#[CoversClass(StartedRound::class)]
#[CoversClass(QueuedRound::class)]
#[CoversClass(DiscardQueuedRound::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
final class StartedRoundsTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private RecordingCommandBus $commands;
    private StartedRounds $rounds;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->commands = new RecordingCommandBus();
        $this->rounds = new StartedRounds($entities, new PendingHeads($entities, new MockClock('2026-11-02T10:00:00+00:00')), $this->commands);
    }

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

    private function green(): OpenChange
    {
        return RecordedForge::change('7', 'b', '2026-11-02T09:00:00Z');
    }

    private function deleteRoundsOnNextLoad(): void
    {
        $this->entities->clear();
        $this->entities->getEventManager()->addEventListener([Events::postLoad], new DeletedOnFirstLoad($this->entities->getConnection(), 'watcher_started_round'));
    }

    private function storedRounds(): int
    {
        $this->entities->clear();

        return $this->entities->getRepository(StartedRound::class)->count([]);
    }
}
