<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(FailedHeads::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
#[UsesClass(HeadFailure::class)]
#[UsesClass(WatcherLedger::class)]
final class FailedHeadsTest extends KernelTestCase
{
    public function testTheSameHeadListedAgainWhileItsFourthFailureIsRecordedStaysDueWithoutFailures(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $clock = new MockClock('2026-11-04T10:00:00+00:00');
        $pending = new PendingHeads($entities, $clock);
        $old = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $listedAgain = RecordedForge::change('7', 'a', '2026-11-04T09:30:00Z');
        $pending->enqueue('alpha', [$old]);
        $pending->backOff(new HeadFailure('alpha', $old, 'RuntimeException: down'), 3, $clock->now());
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(static function () use ($pending, $listedAgain): AppendResult {
            $pending->enqueue('alpha', [$listedAgain]);

            return AppendResult::appended(Uuid::v7());
        });

        new FailedHeads($pending, new WatcherLedger($ledger), $clock)->backOff('alpha', $old, new RuntimeException('down'));

        self::assertCount(1, $pending->dueFor('alpha'));
        self::assertSame(0, $pending->failuresOf('alpha', $listedAgain));
    }
}
