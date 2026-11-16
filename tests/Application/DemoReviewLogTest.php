<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Throwable;

// Covers nothing because what is proven is the wiring: the application's queue, bus and handlers on the shipped settings, where the demo sets no GitHub App.
#[Group('persistence')]
#[CoversNothing]
final class DemoReviewLogTest extends KernelTestCase
{
    private const array WARNING_AND_ABOVE = ['warning', 'error', 'critical', 'alert', 'emergency'];

    public function testADemoReviewWithoutAGitHubAppLeavesNoFailureAndNoTraceInTheLog(): void
    {
        $this->judgeADemoRound();

        $worker = $this->drainTheQueue();

        self::assertSame([], $worker['failures']);
        self::assertSame([], $this->warningsAndTraces($worker['log']));
    }

    // Live like the demo's project, on a local branch like its sample change, so nothing but the publication's wiring decides what the worker logs.
    private function judgeADemoRound(): void
    {
        $fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes([]));
        $vendor = new VerdictPerRole([]);
        $fixture->startFor(RoundFixture::CHANGE, $fixture->bus, 2);
        foreach ($fixture->bus->messages as $message) {
            $fixture->deliver($message, $vendor);
        }
        foreach ($fixture->judgings() as $judging) {
            $fixture->judge($vendor)($judging);
        }

        self::assertNotSame([], $this->queue()->getSent());
    }

    /**
     * @return array{failures: list<string>, log: BufferingLogger}
     */
    private function drainTheQueue(): array
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $bus = self::getContainer()->get('command.bus');
        $failures = [];
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable()->getMessage();
        });
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $log = new BufferingLogger();

        new Worker(['async' => $this->queue()], $bus, $events, $log)->run(['sleep' => 0]);

        return ['failures' => $failures, 'log' => $log];
    }

    /**
     * @return list<string>
     */
    private function warningsAndTraces(BufferingLogger $log): array
    {
        /** @var list<array{string, string, array<mixed>}> $records */
        $records = $log->cleanLogs();
        $flagged = [];
        foreach ($records as [$level, $message, $context]) {
            if (in_array($level, self::WARNING_AND_ABOVE, true) || array_any($context, static fn (mixed $value): bool => $value instanceof Throwable)) {
                $flagged[] = $level.': '.$message;
            }
        }

        return $flagged;
    }

    private function queue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);

        return $queue;
    }
}
