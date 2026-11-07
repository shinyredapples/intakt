<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

// Journeys run only under `bin/check --journeys`, which starts bin/instance and so sets the address below.
abstract class JourneyTestCase extends TestCase
{
    private const string ADDRESS_VARIABLE = 'INTAKT_INSTANCE_URL';
    private const string ROOT = __DIR__.'/../../..';
    // The instance runs in the test environment, so it reads the same file.
    private const string TEST_SETTINGS = '.env.test';
    private const string DEFAULT_SETTINGS = '.env';
    private const string ENVIRONMENT = 'test';
    private const int CONSOLE_SECONDS = 60;
    private const string QUEUE_VARIABLE = 'MESSENGER_TRANSPORT_DSN';
    // The instance's database, because the worker that steps a round is a console process of its own, which would never see a message queued in memory here.
    private const string SHARED_QUEUE = 'doctrine://default';

    private JourneySteps $steps;

    private HttpBrowser $browser;

    private ?Kernel $driverKernel = null;

    /** @var array<string,?string> */
    private array $environmentBefore = [];

    abstract protected function journey(): string;

    final protected function setUp(): void
    {
        $this->steps = new JourneySteps($this->journey());
        $this->browser = new HttpBrowser(HttpClient::create());
    }

    /**
     * @template Result
     *
     * @param callable(): Result $action
     *
     * @return Result
     */
    final protected function step(string $name, callable $action): mixed
    {
        return $this->steps->run($name, $action);
    }

    final protected function visit(string $path): Crawler
    {
        $crawler = $this->browser->request('GET', $this->address().$path, server: ['HTTP_HOST' => 'localhost:'.$this->dashboardPort()]);

        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'GET '.$path.' did not answer 200');

        return $crawler;
    }

    /**
     * @param list<string>         $arguments
     * @param array<string,string> $environment
     */
    final protected function console(array $arguments, array $environment = []): string
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/console', '--no-interaction', ...$arguments], self::ROOT, $environment, null, self::CONSOLE_SECONDS);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'bin/console '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput().$process->getOutput()));

        return $process->getOutput();
    }

    /**
     * @return list<Update>
     */
    final protected function publishedUpdates(): array
    {
        // Asked of the kernel, because it alone decides where the instance's hub records, from the APP_SHARE_DIR bin/instance gives the journey too.
        $directory = new Kernel(self::ENVIRONMENT, false)->getShareDir();

        return RecordingHub::updatesIn($directory ?? self::fail('the instance has no share directory, so its hub records nowhere'));
    }

    final protected function liveRoundDriver(): LiveRoundDriver
    {
        // Read from $_ENV first by the container, and put back after the test, so the harness's other tests keep what .env.test gives them.
        foreach ($this->driverEnvironment() as $name => $value) {
            $before = $_ENV[$name] ?? null;
            $this->environmentBefore[$name] = is_string($before) ? $before : null;
            $_ENV[$name] = $value;
        }

        // A kernel of the journey's own, on the instance's database and share directory, because a round is queued through the command bus the dashboard uses and the console has no door for it.
        $this->driverKernel = new Kernel(self::ENVIRONMENT, false);
        $this->driverKernel->boot();
        $container = $this->driverKernel->getContainer()->get('test.service_container');
        $bus = $container instanceof ContainerInterface ? $container->get(MessageBusInterface::class) : null;

        return new LiveRoundDriver(
            $bus instanceof MessageBusInterface ? $bus : self::fail('the test container has no message bus'),
            fn (array $arguments): string => $this->console($arguments, [self::QUEUE_VARIABLE => self::SHARED_QUEUE]),
            fn (): array => $this->publishedUpdates(),
            new MonotonicClock(),
        );
    }

    #[After]
    final protected function shutDownDriverKernel(): void
    {
        if (!$this->driverKernel instanceof Kernel) {
            return;
        }

        $this->driverKernel->shutdown();
        $this->driverKernel = null;

        foreach ($this->environmentBefore as $name => $before) {
            if (null === $before) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $before;
            }
        }

        $this->environmentBefore = [];
    }

    // The defaults bin/console reads, because the journey's PHPUnit loads no Dotenv; parsed rather than loaded, because a worker handed Dotenv's list of loaded names would load the in-memory queue of .env.test over the shared one.
    /**
     * @return array<string,string>
     */
    private function driverEnvironment(): array
    {
        $defaults = [...$this->settingsIn(self::DEFAULT_SETTINGS), ...$this->settingsIn(self::TEST_SETTINGS)];
        // Because a variable the process has (the instance's database and directories) wins over a file, as in Dotenv's own loading.
        $unset = array_filter($defaults, static fn (string $name): bool => false === getenv($name), ARRAY_FILTER_USE_KEY);

        return [...$unset, self::QUEUE_VARIABLE => self::SHARED_QUEUE];
    }

    /**
     * @return array<string,string>
     */
    private function settingsIn(string $file): array
    {
        $settings = [];

        foreach (new Dotenv()->parse((string) file_get_contents(self::ROOT.'/'.$file)) as $name => $value) {
            if (is_string($value)) {
                $settings[(string) $name] = $value;
            }
        }

        return $settings;
    }

    // Because the dashboard refuses any other host name or port than its own, and the instance listens on a free port.
    private function dashboardPort(): string
    {
        $port = $this->settingsIn(self::TEST_SETTINGS)['DASHBOARD_PORT'] ?? null;

        return is_string($port) && '' !== $port ? $port : self::fail('DASHBOARD_PORT is not set in '.self::TEST_SETTINGS);
    }

    private function address(): string
    {
        $address = getenv(self::ADDRESS_VARIABLE);

        if (!is_string($address) || '' === $address) {
            self::fail(self::ADDRESS_VARIABLE.' is not set: run the journeys with bin/check --journeys');
        }

        return $address;
    }
}
