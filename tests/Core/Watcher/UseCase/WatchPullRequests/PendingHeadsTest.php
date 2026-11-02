<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(PendingHeads::class)]
#[CoversClass(PendingHead::class)]
#[UsesClass(HeadDecision::class)]
final class PendingHeadsTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private MockClock $clock;
    private PendingHeads $pending;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-02T10:00:00+00:00');
        $this->pending = new PendingHeads($entities, $this->clock);
    }

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
