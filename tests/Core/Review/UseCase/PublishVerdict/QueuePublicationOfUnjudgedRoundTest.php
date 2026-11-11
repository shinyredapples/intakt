<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\PublishVerdict\QueuePublicationOfUnjudgedRound;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

#[CoversClass(QueuePublicationOfUnjudgedRound::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(PublishVerdict::class)]
final class QueuePublicationOfUnjudgedRoundTest extends TestCase
{
    private const string ROUND = '0193a1b2-0000-7000-8000-000000000001';

    public function testAFailedMessageOfAnotherKindQueuesNothing(): void
    {
        $commands = self::createMock(MessageBusInterface::class);
        $commands->expects(self::never())->method('dispatch');

        new QueuePublicationOfUnjudgedRound($commands, new BufferingLogger())(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('other')));
    }

    public function testAPublicationThatCannotBeQueuedIsLoggedAsAnErrorNamingTheRound(): void
    {
        $log = new BufferingLogger();
        $commands = self::createStub(MessageBusInterface::class);
        $commands->method('dispatch')->willThrowException(new RuntimeException('queue unavailable'));

        new QueuePublicationOfUnjudgedRound($commands, $log)(new WorkerMessageFailedEvent(new Envelope(new JudgeRound(self::ROUND)), 'async', new RuntimeException('gave up')));

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $log->cleanLogs();
        [[$level, , $context]] = $logs;
        self::assertSame(['error', self::ROUND], [$level, $context['round']]);
    }

    public function testARealWorkerStillParksTheJudgingWhenItsPublicationCannotBeQueued(): void
    {
        $commands = self::createStub(MessageBusInterface::class);
        $commands->method('dispatch')->willThrowException(new RuntimeException('queue unavailable'));
        $log = new BufferingLogger();
        $incoming = new InMemoryTransport();
        $incoming->send(new Envelope(new JudgeRound(self::ROUND)));
        $failed = new InMemoryTransport();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, new QueuePublicationOfUnjudgedRound($commands, $log));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn (): InMemoryTransport => $failed])));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        new Worker(['async' => $incoming], new FailingBus(), $dispatcher, clock: new MockClock('2026-10-30 12:00:00', 'UTC'))->run(['sleep' => 0]);

        self::assertCount(1, $failed->getSent());
        self::assertSame(['error'], array_column($log->cleanLogs(), 0));
    }
}
