<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Kernel;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

// A journey test case, because what it proves is that the instance's container and the journey agree on where the hub records.
#[CoversClass(JourneyTestCase::class)]
#[UsesClass(JourneyVendor::class)]
#[UsesClass(RecordingHub::class)]
#[UsesClass(JourneySteps::class)]
#[UsesClass(LiveRoundDriver::class)]
final class JourneyContainerTest extends JourneyTestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $directory = $this->kernel?->getShareDir();
        $this->kernel?->shutdown();

        if (null !== $directory) {
            new Filesystem()->remove($directory.'/'.RecordingHub::FILE);
        }
    }

    protected function journey(): string
    {
        return 'journey support';
    }

    public function testTheTestContainerReviewsWithTheJourneyVendor(): void
    {
        self::assertInstanceOf(JourneyVendor::class, $this->container()->get(Vendor::class));
    }

    public function testAnUpdatePublishedThroughTheTestContainerIsReadByTheJourney(): void
    {
        $hub = $this->container()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);

        $hub->publish(new Update('review/rounds/1', '<turbo-stream>changed</turbo-stream>', true));

        self::assertEquals([new Update('review/rounds/1', '<turbo-stream>changed</turbo-stream>', true)], $this->publishedUpdates());
    }

    public function testTheJourneyGetsALiveRoundDriverOnTheTestContainersCommandBus(): void
    {
        self::assertInstanceOf(LiveRoundDriver::class, $this->liveRoundDriver());
    }

    public function testTheDriversQueueIsSharedWithItsConsoleWorker(): void
    {
        $this->liveRoundDriver();
        $kernel = new ReflectionProperty(JourneyTestCase::class, 'driverKernel')->getValue($this);
        self::assertInstanceOf(Kernel::class, $kernel);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        self::assertInstanceOf(DoctrineTransport::class, $container->get('messenger.transport.async'));
    }

    public function testTheDriversKernelGetsTheDefaultSettingsAJourneyProcessHasNotLoaded(): void
    {
        $uri = $_ENV['DEFAULT_URI'] ?? null;
        unset($_ENV['DEFAULT_URI']);

        try {
            $this->liveRoundDriver();

            self::assertSame('http://localhost', $_ENV['DEFAULT_URI'] ?? null);
        } finally {
            $this->shutDownDriverKernel();
            $_ENV['DEFAULT_URI'] = $uri;
        }
    }

    public function testTheDriverLeavesTheEnvironmentOfTheOtherTestsAsItFoundIt(): void
    {
        $before = $_ENV;
        $this->liveRoundDriver();

        $this->shutDownDriverKernel();

        self::assertSame($before, $_ENV);
    }

    private function container(): ContainerInterface
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
