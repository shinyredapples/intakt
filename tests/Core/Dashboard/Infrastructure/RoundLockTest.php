<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RoundLock::class)]
final class RoundLockTest extends TestCase
{
    public function testAHeldRoundIsBusyForAnotherPress(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $first = new RoundLock($locks);

        self::assertTrue($first->tryHolding('round-1'));
        self::assertFalse(new RoundLock($locks)->tryHolding('round-1'));
    }

    public function testTheEndOfASubRequestKeepsTheRoundHeld(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $first = new RoundLock($locks);
        $first->tryHolding('round-1');

        $first($this->finished(HttpKernelInterface::SUB_REQUEST));

        self::assertFalse(new RoundLock($locks)->tryHolding('round-1'));
    }

    public function testTheEndOfTheMainRequestFreesTheRound(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $first = new RoundLock($locks);
        $first->tryHolding('round-1');

        $first($this->finished(HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue(new RoundLock($locks)->tryHolding('round-1'));
    }

    public function testAResetFreesEveryHeldRound(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $first = new RoundLock($locks);
        $first->tryHolding('round-1');
        $first->tryHolding('round-2');

        $first->reset();

        $second = new RoundLock($locks);
        self::assertTrue($second->tryHolding('round-1'));
        self::assertTrue($second->tryHolding('round-2'));
    }

    private function finished(int $type): FinishRequestEvent
    {
        return new FinishRequestEvent(self::createStub(HttpKernelInterface::class), new Request(), $type);
    }
}
