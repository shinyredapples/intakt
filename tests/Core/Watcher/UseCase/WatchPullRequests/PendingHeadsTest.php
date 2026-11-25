<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(PendingHeads::class)]
#[CoversClass(PendingHead::class)]
#[UsesClass(HeadDecision::class)]
#[UsesClass(HeadFailure::class)]
final class PendingHeadsTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private MockClock $clock;
    private PendingHeads $pending;

    public function testAKeptHeadWaitsUntilItsReadAgainTime(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$old]);

        $this->pending->keep('alpha', $this->pendingDecision($old));

        self::assertSame([], $this->pending->dueFor('alpha'));
    }

    public function testANewerHeadQueuedBeforeAnOlderOneIsKeptStaysDueNow(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->enqueue('alpha', [RecordedForge::change('7', 'b', '2026-11-02T09:30:00Z')]);

        $this->pending->keep('alpha', $this->pendingDecision($old));

        self::assertSame([str_repeat('b', 40)], $this->dueHeads());
    }

    public function testANewerHeadQueuedOnceTheOlderRowIsReadStaysDueNow(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->entities->getEventManager()->addEventListener([Events::postLoad], new NewerHeadOnFirstLoad($this->entities->getConnection(), str_repeat('b', 40), '2026-11-02 10:00:00.000000'));

        $this->pending->keep('alpha', $this->pendingDecision($old));

        self::assertSame([str_repeat('b', 40)], $this->dueHeads());
    }

    public function testAHeadMadeToWaitUntilAMomentIsNotDueBeforeItAndCountsNoFailure(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);

        $this->pending->waitUntil('alpha', $head, $this->clock->now()->modify('+30 minutes'));

        self::assertSame([], $this->pending->dueFor('alpha'));
        self::assertSame(0, $this->pending->failuresOf('alpha', $head));
        $this->clock->modify('+30 minutes');
        self::assertSame([str_repeat('a', 40)], $this->dueHeads());
    }

    public function testANewerHeadQueuedBeforeAnOlderOneIsMadeToWaitStaysDueNow(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->enqueue('alpha', [RecordedForge::change('7', 'b', '2026-11-02T09:30:00Z')]);

        $this->pending->waitUntil('alpha', $old, $this->clock->now()->modify('+30 minutes'));

        self::assertSame([str_repeat('b', 40)], $this->dueHeads());
    }

    public function testABackedOffHeadKeepsItsFailuresAndWaitsUntilItsNextTry(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);

        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 3, $this->clock->now()->modify('+4 minutes'));

        self::assertSame(3, $this->pending->failuresOf('alpha', $head));
        self::assertSame([], $this->pending->dueFor('alpha'));
    }

    public function testANewerHeadQueuedBeforeAnOlderOneIsBackedOffStaysDueWithoutFailures(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $newer = RecordedForge::change('7', 'b', '2026-11-02T09:30:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->enqueue('alpha', [$newer]);

        $this->pending->backOff(new HeadFailure('alpha', $old, 'RuntimeException: down'), 3, $this->clock->now()->modify('+4 minutes'));

        self::assertSame(0, $this->pending->failuresOf('alpha', $newer));
        self::assertSame([str_repeat('b', 40)], $this->dueHeads());
    }

    public function testTheSameHeadListedAgainBeforeItsOlderListingIsBackedOffStaysDueWithoutFailures(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->enqueue('alpha', [RecordedForge::change('7', 'a', '2026-11-02T09:30:00Z')]);

        $this->pending->backOff(new HeadFailure('alpha', $old, 'RuntimeException: down'), 3, $this->clock->now()->modify('+4 minutes'));

        self::assertSame(0, $this->pending->failuresOf('alpha', $old));
        self::assertSame([str_repeat('a', 40)], $this->dueHeads());
    }

    public function testAHeadSettledAfterFailingCountsItsFailuresAnew(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 4, $this->clock->now());

        $this->pending->clearFailures('alpha', $head);

        self::assertSame(0, $this->pending->failuresOf('alpha', $head));
    }

    public function testAHeadTheListingNamesAgainCountsItsFailuresAnew(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 4, $this->clock->now()->modify('+8 minutes'));

        $this->pending->enqueue('alpha', [RecordedForge::change('7', 'a', '2026-11-02T09:45:00Z')]);

        self::assertSame(0, $this->pending->failuresOf('alpha', $head));
        self::assertSame([str_repeat('a', 40)], $this->dueHeads());
    }

    public function testAHeadAnotherWorkerSettledWhileItWasReadIsNotDue(): void
    {
        $this->pending->enqueue('alpha', [RecordedForge::change('7', 'a', '2026-11-02T09:00:00Z')]);
        $this->entities->clear();
        $this->entities->getEventManager()->addEventListener([Events::postLoad], new DeletedOnFirstLoad($this->entities->getConnection(), 'watcher_pending_head'));

        $due = $this->pending->dueFor('alpha');

        self::assertSame([], $due);
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->clock = new MockClock('2026-11-02T10:00:00+00:00');
        $this->pending = new PendingHeads($entities, $this->clock);
    }

    private function pendingDecision(OpenChange $open): HeadDecision
    {
        return new HeadDecision($open, HeadAdmission::Pending, $this->clock->now()->modify('+60 seconds'));
    }

    /**
     * @return list<string>
     */
    private function dueHeads(): array
    {
        return array_map(static fn (OpenChange $open): string => $open->head->hash, $this->pending->dueFor('alpha'));
    }
}
