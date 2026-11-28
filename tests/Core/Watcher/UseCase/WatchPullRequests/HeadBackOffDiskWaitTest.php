<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadBackOff;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[CoversClass(HeadBackOff::class)]
#[UsesClass(FailedHeads::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(NewWorkHeld::class)]
#[UsesClass(DiskHold::class)]
final class HeadBackOffDiskWaitTest extends KernelTestCase
{
    public function testARejectedDiskWaitDoesNotEscapeThePerHeadBoundary(): void
    {
        $container = self::getContainer();
        EmptyDatabase::rowsIn($container);
        $pending = $container->get(PendingHeads::class);
        $head = RecordedForge::change('1', 'a', '2026-11-04T09:00:00Z');
        $pending->enqueue('alpha', [$head]);
        $container->get('doctrine.dbal.default_connection')->executeStatement(
            "CREATE TEMP TRIGGER refuse_disk_wait BEFORE UPDATE ON watcher_pending_head
             BEGIN SELECT RAISE(ABORT, 'cannot save the retry'); END",
        );
        $boundary = new HeadBackOff($container->get(FailedHeads::class), $pending, $container->get('doctrine'), new RecordingLogger());

        $boundary->tryAlone('alpha', $head, $this->heldStep());

        self::assertContainsEquals($head, $pending->dueFor('alpha'));
    }

    public function testAHeldHeadIsNotLoggedAsAFailure(): void
    {
        $container = self::getContainer();
        EmptyDatabase::rowsIn($container);
        $pending = $container->get(PendingHeads::class);
        $head = RecordedForge::change('1', 'a', '2026-11-04T09:00:00Z');
        $pending->enqueue('alpha', [$head]);
        $logger = new RecordingLogger();
        $boundary = new HeadBackOff($container->get(FailedHeads::class), $pending, $container->get('doctrine'), $logger);

        $boundary->tryAlone('alpha', $head, $this->heldStep());

        self::assertNull(array_find($logger->logged, static fn (array $line): bool => 'A head failed; it backs off alone.' === $line[0]));
    }

    /**
     * @return Closure(): null
     */
    private function heldStep(): Closure
    {
        $held = new NewWorkHeld(new DiskHold(DiskFloor::Bytes, 'var/mirrors', 1, 2));

        return static function () use ($held): null {
            throw new RuntimeException('start stopped', 0, $held);
        };
    }
}
