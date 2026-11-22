<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Kernel;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

// A journey test case, because what it proves is that the instance's container and the journey agree on where the hub records.
#[CoversClass(JourneyTestCase::class)]
#[UsesClass(JourneyVendor::class)]
#[UsesClass(ScriptedJourneyVendor::class)]
#[UsesClass(ScriptedHttpClient::class)]
#[UsesClass(JourneyKeyCheck::class)]
#[UsesClass(RecordingHub::class)]
#[UsesClass(JourneySteps::class)]
#[UsesClass(LiveRoundDriver::class)]
final class JourneyContainerTest extends JourneyTestCase
{
    private ?Kernel $kernel = null;

    public function testTheTestContainerReviewsWithTheScriptedJourneyVendor(): void
    {
        self::assertInstanceOf(ScriptedJourneyVendor::class, $this->container()->get(Vendor::class));
    }

    public function testWithAScriptDirectoryTheTestContainersVendorAsksSymfonyAiVendor(): void
    {
        $before = $_SERVER;
        $_SERVER['INTAKT_JOURNEY_SCRIPTS'] = sys_get_temp_dir();

        try {
            $vendor = $this->container()->get(Vendor::class);
            self::assertInstanceOf(Vendor::class, $vendor);

            $this->expectException(ProjectNotConfigured::class);

            $vendor->mapsAModelTo('no-such-project', Tier::Standard);
        } finally {
            $_SERVER = $before;
        }
    }

    public function testTheTestContainersHttpClientIsTheScriptedOne(): void
    {
        self::assertInstanceOf(ScriptedHttpClient::class, $this->container()->get('http_client'));
    }

    public function testTheTestContainerChecksPastedKeysWithTheJourneyKeyCheck(): void
    {
        self::assertInstanceOf(JourneyKeyCheck::class, $this->container()->get(KeyCheck::class));
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
        [$environment, $server] = [$_ENV, $_SERVER];
        unset($_ENV['DEFAULT_URI'], $_SERVER['DEFAULT_URI']);

        try {
            $this->liveRoundDriver();

            self::assertSame('http://localhost', $_ENV['DEFAULT_URI'] ?? null);
        } finally {
            $this->shutDownDriverKernel();
            [$_ENV, $_SERVER] = [$environment, $server];
        }
    }

    public function testTheDriverLeavesTheEnvironmentOfTheOtherTestsAsItFoundIt(): void
    {
        $before = $_ENV;
        $this->liveRoundDriver();

        $this->shutDownDriverKernel();

        self::assertSame($before, $_ENV);
    }

    public function testTheDriverKeepsASettingTheProcessAlreadyHasInItsEnvironment(): void
    {
        $before = $_ENV;
        $_ENV['DEFAULT_URI'] = 'http://localhost:8080';

        try {
            $this->liveRoundDriver();

            self::assertSame('http://localhost:8080', $_ENV['DEFAULT_URI']);
        } finally {
            $this->shutDownDriverKernel();
            $_ENV = $before;
        }
    }

    public function testAskingForTheDriverTwiceStillLeavesTheEnvironmentAsItFoundIt(): void
    {
        $before = $_ENV;

        try {
            $this->liveRoundDriver();
            $this->liveRoundDriver();
            $this->shutDownDriverKernel();

            self::assertSame($before, $_ENV);
        } finally {
            $_ENV = $before;
        }
    }

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

    private function container(): TestContainer
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(TestContainer::class, $container);

        return $container;
    }
}
