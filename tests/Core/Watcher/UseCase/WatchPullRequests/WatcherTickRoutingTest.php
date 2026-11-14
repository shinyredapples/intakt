<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransportFactory;

#[CoversClass(WatchPullRequests::class)]
final class WatcherTickRoutingTest extends KernelTestCase
{
    public function testScheduledMessagesGoThroughTheMessengerRouting(): void
    {
        $factory = self::getContainer()->get('scheduler.messenger_transport_factory');
        self::assertInstanceOf(SchedulerTransportFactory::class, $factory);

        // The option is consumed at build time and the factory keeps it to itself, so only its argument shows what the configuration set.
        self::assertTrue(new ReflectionProperty(SchedulerTransportFactory::class, 'useMessengerRouting')->getValue($factory));
    }

    public function testTheWatcherTickHasNoSenderSoItRunsInTheSchedulerConsumer(): void
    {
        $senders = self::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocatorInterface::class, $senders);

        self::assertSame([], iterator_to_array($senders->getSenders(new Envelope(new WatchPullRequests('alpha')))));
    }
}
