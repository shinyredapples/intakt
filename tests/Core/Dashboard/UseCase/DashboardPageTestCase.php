<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Closure;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Tests\Core\Server\RecordedAdminApi;
use Intakt\Tests\Support\PinnableClock;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

abstract class DashboardPageTestCase extends WebTestCase
{
    protected DashboardLedger $ledger;

    protected KernelBrowser $browser;

    protected ServerStatus $server;

    private ReplayMarkBus $replayMarks;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->serveFromMemory();
    }

    // Apart from the client, so a test can create the clock first and prove this setup still sets the moment.
    protected function serveFromMemory(): void
    {
        $this->ledger = new DashboardLedger();
        // The ledger stamps its rows at one moment, so a round only counts as overdue in the tests that move the clock on.
        self::clockAt('2026-10-28T12:00:00+00:00');
        // Answered without the database, because these pages read every other fact from the ledger in memory and a page test creates no schema.
        $this->replayMarks = new ReplayMarkBus();
        $this->serveTheSameFactsIn(self::getContainer());
    }

    // A kernel of its own, as another worker of the install would be, that reads the same ledger and replay marks at the same moment.
    protected function anotherWorker(): KernelInterface
    {
        $kernel = self::createKernel();
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $clock = $container->get(ClockInterface::class);
        assert($clock instanceof PinnableClock);
        $clock->pinAt('2026-10-28T12:00:00+00:00');
        $this->serveTheSameFactsIn($container);

        return $kernel;
    }

    // Taken through the application's factory on the same file store, so it stands for a press of another worker that still runs.
    protected function holdReviewLock(string $firstRound): LockInterface
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock(RoundLock::KEY_PREFIX.$firstRound);
        self::assertTrue($lock->acquire());

        return $lock;
    }

    /**
     * @return Closure(): ?bool
     */
    // Asked by the first listener of the main response, so it tells whether a press arriving while the answer is still built would find the review free.
    protected function reviewFreeWhileAnswering(string $firstRound): Closure
    {
        $free = null;
        $locks = self::getContainer()->get(LockFactory::class);
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event) use ($locks, $firstRound, &$free): void {
            if (!$event->isMainRequest()) {
                return;
            }

            $lock = $locks->createLock(RoundLock::KEY_PREFIX.$firstRound);
            $free = $lock->acquire();
            $lock->release();
        }, PHP_INT_MAX);

        return static function () use (&$free): ?bool {
            return $free;
        };
    }

    /**
     * @return list<string>
     */
    protected static function reviewLockFiles(): array
    {
        $files = glob(self::getContainer()->getParameter('kernel.cache_dir').'/locks/sf.'.RoundLock::KEY_PREFIX.'*');

        return false === $files ? [] : $files;
    }

    protected function flagAsReplay(string $project): void
    {
        $this->replayMarks->flag($project);
    }

    protected function storeRoundAs(string $round, ReplayMark $mark): void
    {
        $this->replayMarks->store($round, $mark);
    }

    private function serveTheSameFactsIn(TestContainer $container): void
    {
        $container->set(Ledger::class, $this->ledger);
        $container->set(ReplayMarks::class, new ReplayMarks($this->replayMarks));
        // Recorded answers, because the board reads the server status on every load and no test may reach a live admin API; read through a stub, because the container refuses a second replacement.
        $this->server = new RecordedAdminApi()->status();
        $status = self::createStub(ServerStatus::class);
        $status->method('read')->willReturnCallback(fn (): ServerReading => $this->server->read());
        $container->set(ServerStatus::class, $status);
    }
}
