<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\LogFailedJudging;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\FailingBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

#[CoversClass(LogFailedJudging::class)]
#[UsesClass(JudgeRound::class)]
final class LogFailedJudgingTest extends TestCase
{
    private const string ROUND = '0193a1b2-0000-7000-8000-000000000001';

    public function testAJudgingThatFailedForGoodIsLoggedAsAnErrorNamingTheRound(): void
    {
        $log = new BufferingLogger();
        $failure = new RuntimeException('gave up');

        new LogFailedJudging($log)(new WorkerMessageFailedEvent(new Envelope(new JudgeRound(self::ROUND)), 'async', $failure));

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $log->cleanLogs();
        [[$level, , $context]] = $logs;
        self::assertSame(['error', self::ROUND, $failure], [$level, $context['round'], $context['exception']]);
    }

    public function testAJudgingThatWillStillBeRetriedIsNotLogged(): void
    {
        $log = new BufferingLogger();
        $event = new WorkerMessageFailedEvent(new Envelope(new JudgeRound(self::ROUND)), 'async', new RuntimeException('again'));
        $event->setForRetry();

        new LogFailedJudging($log)($event);

        self::assertSame([], $log->cleanLogs());
    }

    public function testAFailedMessageOfAnotherKindIsNotLogged(): void
    {
        $log = new BufferingLogger();

        new LogFailedJudging($log)(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('other')));

        self::assertSame([], $log->cleanLogs());
    }

    public function testARealWorkerParksAFailedJudgingAndKeepsRunning(): void
    {
        $log = new BufferingLogger();
        $incoming = new InMemoryTransport();
        $incoming->send(new Envelope(new JudgeRound(self::ROUND)));
        $failed = new InMemoryTransport();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, new LogFailedJudging($log));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn (): InMemoryTransport => $failed])));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        new Worker(['async' => $incoming], new FailingBus(), $dispatcher, clock: new MockClock('2026-10-30 12:00:00', 'UTC'))->run(['sleep' => 0]);

        self::assertCount(1, $failed->getSent());
        self::assertCount(1, $log->cleanLogs());
    }
}
