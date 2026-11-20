<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;

#[CoversClass(BlockReviewerOfFailedMessage::class)]
#[UsesClass(Kernel::class)]
final class FailedMessageListenerOrderTest extends KernelTestCase
{
    public function testTheContainerRunsTheBlockOfAFailedMessageAfterTheRetryDecisionAndBeforeTheMessageIsParked(): void
    {
        $order = $this->failureListenerClasses();

        self::assertSame(
            [SendFailedMessageForRetryListener::class, BlockReviewerOfFailedMessage::class, SendFailedMessageToFailureTransportListener::class],
            array_values(array_intersect($order, [SendFailedMessageForRetryListener::class, BlockReviewerOfFailedMessage::class, SendFailedMessageToFailureTransportListener::class])),
        );
    }

    /**
     * @return list<class-string>
     */
    private function failureListenerClasses(): array
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return array_map(
            static fn (mixed $listener): string => is_array($listener) && is_object($listener[0]) ? $listener[0]::class : (is_object($listener) ? $listener::class : get_debug_type($listener)),
            $events->getListeners(WorkerMessageFailedEvent::class),
        );
    }
}
