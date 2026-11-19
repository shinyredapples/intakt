<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
        $pressing = new RequestStack([new Request()]);

        self::assertTrue(new RoundLock($locks, $pressing)->tryHolding('round-1'));
        self::assertFalse(new RoundLock($locks, new RequestStack([new Request()]))->tryHolding('round-1'));
    }

    public function testTheEndOfASubRequestKeepsTheRoundHeld(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $main = new Request();
        $sub = new Request();
        $rounds = new RoundLock($locks, new RequestStack([$main, $sub]));
        $rounds->tryHolding('round-1');

        $rounds($this->finished($sub, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse(new RoundLock($locks, new RequestStack([new Request()]))->tryHolding('round-1'));
    }

    public function testTheEndOfTheMainRequestFreesEveryRoundItHeld(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $main = new Request();
        $rounds = new RoundLock($locks, new RequestStack([$main]));
        $rounds->tryHolding('round-1');
        $rounds->tryHolding('round-2');

        $rounds($this->finished($main, HttpKernelInterface::MAIN_REQUEST));

        $other = new RoundLock($locks, new RequestStack([new Request()]));
        self::assertTrue($other->tryHolding('round-1'));
        self::assertTrue($other->tryHolding('round-2'));
    }

    public function testTheEndOfOneRequestKeepsTheRoundsOfAnotherHeld(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $requests = new RequestStack();
        $rounds = new RoundLock($locks, $requests);
        $first = new Request();
        $second = new Request();
        $requests->push($first);
        $rounds->tryHolding('round-1');
        $requests->pop();
        $requests->push($second);
        $rounds->tryHolding('round-2');

        $rounds($this->finished($first, HttpKernelInterface::MAIN_REQUEST));

        $other = new RoundLock($locks, new RequestStack([new Request()]));
        self::assertTrue($other->tryHolding('round-1'));
        self::assertFalse($other->tryHolding('round-2'));
    }

    public function testARoundCannotBeHeldOutsideARequest(): void
    {
        $this->expectException(LogicException::class);

        new RoundLock(new LockFactory(new InMemoryStore()), new RequestStack())->tryHolding('round-1');
    }

    private function finished(Request $request, int $type): FinishRequestEvent
    {
        return new FinishRequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
    }
}
