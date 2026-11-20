<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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

    public function testTheEndOfASubRequestCopiedFromTheMainRequestKeepsTheRoundHeld(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $main = new Request();
        $rounds = new RoundLock($locks, new RequestStack([$main]));
        $rounds->tryHolding('round-1');
        $other = new RoundLock($locks, new RequestStack([new Request()]));

        $rounds($this->finished($main->duplicate(), HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($other->tryHolding('round-1'));

        $rounds($this->finished($main, HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue($other->tryHolding('round-1'));
    }

    public function testASubRequestCopiedAndHandledInsideAPressKeepsTheRoundHeldUntilThePressEnds(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $requests = new RequestStack();
        $rounds = new RoundLock($locks, $requests);
        $events = new EventDispatcher();
        $events->addListener(KernelEvents::FINISH_REQUEST, $rounds);
        $kernel = new HttpKernel($events, new ControllerResolver(), $requests);
        $main = Request::create('/');
        $heldAfterTheSubRequest = null;
        $main->attributes->set('_controller', static function () use ($rounds, $main, $kernel, $locks, &$heldAfterTheSubRequest): Response {
            $rounds->tryHolding('round-1');
            $sub = $main->duplicate();
            $sub->attributes->set('_controller', static fn (): Response => new Response('sub'));
            $kernel->handle($sub, HttpKernelInterface::SUB_REQUEST, false);
            $heldAfterTheSubRequest = !$locks->createLock(RoundLock::KEY_PREFIX.'round-1')->acquire();

            return new Response('main');
        });

        $kernel->handle($main, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertTrue($heldAfterTheSubRequest);
        self::assertTrue($locks->createLock(RoundLock::KEY_PREFIX.'round-1')->acquire());
    }

    // Freed by auto-release and not by the listener, because the kernel skips kernel.finish_request when a response listener throws while the error answer is built (#3021, D6).
    public function testAPressWhoseResponseListenerFailsFreesTheRoundOnceItsRequestIsGone(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $requests = new RequestStack();
        $rounds = new RoundLock($locks, $requests);
        $events = new EventDispatcher();
        $events->addListener(KernelEvents::FINISH_REQUEST, $rounds);
        $events->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
            $event->setResponse(new Response('failed', 500));
        });
        $events->addListener(KernelEvents::RESPONSE, static fn (): never => throw new RuntimeException('a response listener failed'));
        $kernel = new HttpKernel($events, new ControllerResolver(), $requests);
        $main = Request::create('/');
        $main->attributes->set('_controller', static function () use ($rounds): Response {
            $rounds->tryHolding('round-1');

            throw new RuntimeException('the press failed');
        });

        $response = $kernel->handle($main);
        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($locks->createLock(RoundLock::KEY_PREFIX.'round-1')->acquire());
        unset($main, $response);
        gc_collect_cycles();

        self::assertTrue($locks->createLock(RoundLock::KEY_PREFIX.'round-1')->acquire());
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
