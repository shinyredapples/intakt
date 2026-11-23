<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;

#[CoversClass(BlockReviewerOfFailedMessage::class)]
#[UsesClass(Kernel::class)]
final class FailedMessageListenerOrderTest extends KernelTestCase
{
    private const array EXPECTED_ORDER = [
        SendFailedMessageForRetryListener::class,
        BlockReviewerOfFailedMessage::class,
        SendFailedMessageToFailureTransportListener::class,
    ];

    public function testTheContainerRunsTheBlockOfAFailedMessageAfterTheRetryDecisionAndBeforeTheMessageIsParked(): void
    {
        $order = $this->failureListenerClasses();

        self::assertSame(self::EXPECTED_ORDER, array_values(array_intersect($order, self::EXPECTED_ORDER)));
    }

    // The default priority 0 already falls between the retry listener (100) and the parking listener (-100), so the container order alone stays green without the declaration.
    public function testTheBlockOfAFailedMessageDeclaresItsPlaceByTheListenersAroundItAndNotByAPriority(): void
    {
        $attributes = new ReflectionClass(BlockReviewerOfFailedMessage::class)->getAttributes(AsEventListener::class);
        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();

        self::assertSame(
            ['after' => SendFailedMessageForRetryListener::class, 'before' => SendFailedMessageToFailureTransportListener::class, 'priority' => null],
            ['after' => $listener->after, 'before' => $listener->before, 'priority' => $listener->priority],
        );
    }

    /**
     * @return list<string>
     */
    private function failureListenerClasses(): array
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return array_map(
            static fn (mixed $listener): string => is_array($listener) ? get_debug_type($listener[0]) : get_debug_type($listener),
            $events->getListeners(WorkerMessageFailedEvent::class),
        );
    }
}
