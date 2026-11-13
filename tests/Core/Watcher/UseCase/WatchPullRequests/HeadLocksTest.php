<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Review\Contract\BootLimits;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadLocks;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;

// Three due heads, all locked before the first one settles, because the heads boot one after another while every lock is held.
#[Group('persistence')]
#[CoversClass(HeadLocks::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
final class HeadLocksTest extends KernelTestCase
{
    private ExpiringLockStore $store;
    private LockFactory $factory;
    private HeadLocks $locks;

    protected function setUp(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $pending = new PendingHeads($fixture->entities, $fixture->clock);
        $pending->enqueue('alpha', array_map(static fn (string $id): OpenChange => new OpenChange(new ChangeId($id), new CommitId(str_repeat('a', 40)), $fixture->clock->now()), ['1', '2', '3']));
        $this->store = new ExpiringLockStore();
        $this->factory = new LockFactory($this->store);
        $this->locks = new HeadLocks($pending, $this->factory, self::createStub(InstallWideFailures::class), new NullLogger());
    }

    public function testTheLastDueHeadStaysLockedWhileMainAndTheHeadsBeforeItBootForTheirLongest(): void
    {
        $this->locks->whileHoldingDue('alpha', function (): void {
            $this->store->now = 3 * BootLimits::LONGEST_SECONDS + 299;

            self::assertFalse($this->secondWorkerLocksTheThirdHead());
        });
    }

    public function testALockLeftByATickThatDiedRunsOutOnceEveryBootCouldHaveEnded(): void
    {
        $this->locks->whileHoldingDue('alpha', function (): void {
            $this->store->now = 4 * BootLimits::LONGEST_SECONDS + 300;

            self::assertTrue($this->secondWorkerLocksTheThirdHead());
        });
    }

    private function secondWorkerLocksTheThirdHead(): bool
    {
        return $this->factory->createLock('watcher-change-'.hash('sha256', json_encode(['alpha', '3'], JSON_THROW_ON_ERROR)), 60, false)->acquire(false);
    }
}
