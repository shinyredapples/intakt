<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Kernel;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

// A journey test case, because what it proves is that the instance's container and the journey agree on where the hub records.
#[CoversClass(JourneyTestCase::class)]
#[UsesClass(JourneyVendor::class)]
#[UsesClass(RecordingHub::class)]
#[UsesClass(JourneySteps::class)]
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

    private function container(): TestContainer
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(TestContainer::class, $container);

        return $container;
    }
}
