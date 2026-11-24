<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

// Journeys run only under `bin/check --journeys`, which starts bin/instance and so sets the address below.
abstract class JourneyTestCase extends TestCase
{
    private const string ADDRESS_VARIABLE = 'INTAKT_INSTANCE_URL';
    private const string ADMIN_ADDRESS_VARIABLE = 'INTAKT_INSTANCE_ADMIN_URL';
    private const string ROOT = __DIR__.'/../../..';
    // The instance runs in the test environment, so it reads the same file.
    private const string TEST_SETTINGS = '.env.test';
    private const string DEFAULT_SETTINGS = '.env';
    private const string ENVIRONMENT = 'test';
    private const int CONSOLE_SECONDS = 60;
    private const string QUEUE_VARIABLE = 'MESSENGER_TRANSPORT_DSN';
    // The instance's database, because the worker that steps a round is a console process of its own, which would never see a message queued in memory here.
    private const string SHARED_QUEUE = 'doctrine://default';
    private const string ADMIN_API_VARIABLE = 'FRANKENPHP_ADMIN_URL';
    // On a reserved name that resolves nowhere, so even a reader that sent a request would reach no server.
    private const string REFUSED_ADMIN_API = 'http://admin-api.invalid';

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

    // As a restart of Intakt does: a worker keeps the container it booted with, so it is restarted through FrankenPHP's admin API; the built-in server boots a kernel per request and needs nothing.
    final protected function restartTheDashboard(): void
    {
        $admin = getenv(self::ADMIN_ADDRESS_VARIABLE);

        if (!is_string($admin) || '' === $admin) {
            return;
        }

        $response = HttpClient::create()->request('POST', $admin.'/frankenphp/workers/restart');

        self::assertSame(200, $response->getStatusCode(), 'the dashboard worker was not restarted: '.$response->getContent(false));
    }

    // The redirects below are read, not followed, because BrowserKit would send the target's own host name, which the dashboard refuses; the journey visits the path they return.
    final protected function redirectOf(string $path): string
    {
        $this->browser->followRedirects(false);

        try {
            $this->browser->request('GET', $this->address().$path, server: ['HTTP_HOST' => 'localhost:'.$this->dashboardPort()]);
        } finally {
            $this->browser->followRedirects(true);
        }

        $response = $this->browser->getResponse();
        $location = $response->getHeader('Location');
        self::assertContains($response->getStatusCode(), [302, 303], 'GET '.$path.' did not redirect but answered '.$response->getStatusCode());

        return is_string($location) && '' !== $location ? $location : self::fail('GET '.$path.' redirected without a Location');
    }

    // Sent with the HTTP client and the browser's cookies, because HttpBrowser refuses any request body without symfony/mime, which Intakt does not need.
    final protected function redirectOfSubmit(Form $form): string
    {
        $uri = $form->getUri();
        $cookies = $this->browser->getCookieJar();
        $sent = [];
        foreach (array_filter($cookies->allRawValues($uri), is_string(...)) as $name => $value) {
            $sent[] = $name.'='.$value;
        }
        $what = $form->getMethod().' '.$uri;
        $response = HttpClient::create()->request($form->getMethod(), $uri, [
            'headers' => [
                'Host' => 'localhost:'.$this->dashboardPort(),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => implode('; ', $sent),
            ],
            'body' => http_build_query($form->getPhpValues()),
            'max_redirects' => 0,
        ]);
        $headers = $response->getHeaders(false);
        $cookies->updateFromSetCookie($headers['set-cookie'] ?? [], $uri);
        self::assertContains($response->getStatusCode(), [302, 303], $what.' did not redirect but answered '.$response->getStatusCode());

        return $headers['location'][0] ?? self::fail($what.' redirected without a Location');
    }

    /**
     * @param list<string>         $arguments
     * @param array<string,string> $environment
     */
    final protected function console(array $arguments, array $environment = [], int $seconds = self::CONSOLE_SECONDS): string
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/console', '--no-interaction', ...$arguments], self::ROOT, $environment, null, $seconds);
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
        // A kernel of the journey's own, on the instance's database and share directory, because a round is queued through the command bus the dashboard uses and the console has no door for it.
        $kernel = $this->bootDriverKernel([]);
        // Typed by the container's own dump, which the phpstan gate reads, so the bus needs no instanceof guard.
        $bus = $kernel->getContainer()->get('test.service_container')->get(MessageBusInterface::class);

        return new LiveRoundDriver(
            $bus,
            fn (array $arguments): string => $this->console($arguments, [self::QUEUE_VARIABLE => self::SHARED_QUEUE]),
            fn (): array => $this->publishedUpdates(),
            new MonotonicClock(),
        );
    }

    // The instance's pages served by a kernel of the journey's own on its database, because bin/instance hands its server only an allowlist of variables, so a page that reads one the journey sets (the sample repository) needs this kernel.
    /**
     * @param array<string,string> $environment
     */
    final protected function dashboardWith(array $environment): KernelBrowser
    {
        // An admin URL the reader refuses before any request, because the board reads the server status on each load and a journey must never reach an admin API that happens to listen on this machine; set in $_ENV, so it holds after the reboot KernelBrowser does before each later request, and first, so a journey can still override it.
        $browser = new KernelBrowser($this->bootDriverKernel([self::ADMIN_API_VARIABLE => self::REFUSED_ADMIN_API, ...$environment]), ['HTTP_HOST' => 'localhost:'.$this->dashboardPort()]);
        $browser->followRedirects();

        return $browser;
    }

    // Asked of the reader's own refusal, so a journey reads the reason the board shows for the admin URL dashboardWith() sets.
    final protected function refusedAdminApiReason(): string
    {
        return AdminApiNotOnLocalhost::becauseOfTheAddress('http', (string) parse_url(self::REFUSED_ADMIN_API, PHP_URL_HOST))->getMessage();
    }

    // On the shared queue, because the worker that runs the queued round is another console process than the tick that queued it.
    /**
     * @param array<string,string> $environment
     */
    final protected function watcherStart(array $environment): WatcherStart
    {
        return $this->watcherStartOn(fn (array $arguments, array $variables, int $seconds): string => $this->console($arguments, [...$environment, ...$variables, self::QUEUE_VARIABLE => self::SHARED_QUEUE], $seconds));
    }

    // A worker of its own on the shared queue, because a message the journey's dashboard kernel queues runs in a queue worker, never in the request.
    /**
     * @param array<string,string> $environment
     */
    final protected function runOneQueuedMessage(array $environment, int $seconds): string
    {
        return $this->console(['messenger:consume', 'async', '--limit=1', '--time-limit='.$seconds], [...$environment, self::QUEUE_VARIABLE => self::SHARED_QUEUE]);
    }

    // Because the provider keys and the projects count only in a container built after they are set, as the container entrypoint does.
    /**
     * @param array<string,string> $environment
     */
    final protected function rebuildContainer(array $environment): void
    {
        $this->containerRebuild($this->console(...))->rebuild($environment, self::CONSOLE_SECONDS);
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

    final protected function address(): string
    {
        $address = getenv(self::ADDRESS_VARIABLE);

        if (!is_string($address) || '' === $address) {
            self::fail(self::ADDRESS_VARIABLE.' is not set: run the journeys with bin/check --journeys');
        }

        return $address;
    }

    /**
     * @param array<string,string> $environment
     */
    private function bootDriverKernel(array $environment): Kernel
    {
        // Because a second call would otherwise save the first call's settings as the ones to put back.
        $this->shutDownDriverKernel();

        // Read from $_ENV first by the container, and put back after the test, so the harness's other tests keep what .env.test gives them.
        foreach ([...$this->driverEnvironment(), ...$environment] as $name => $value) {
            $before = $_ENV[$name] ?? null;
            $this->environmentBefore[$name] = is_string($before) ? $before : null;
            $_ENV[$name] = $value;
        }

        $this->driverKernel = new Kernel(self::ENVIRONMENT, false);
        $this->driverKernel->boot();

        return $this->driverKernel;
    }

    /**
     * @param Closure(list<string>, array<string,string>, int): string $console
     */
    private function watcherStartOn(Closure $console): WatcherStart
    {
        return new WatcherStart($console, $this->containerRebuild($console));
    }

    /**
     * @param Closure(list<string>, array<string,string>, int): string $console
     */
    private function containerRebuild(Closure $console): ContainerRebuild
    {
        // Asked of the kernel, because it alone decides where the instance's container is compiled, from the APP_CACHE_DIR bin/instance gives the journey too.
        $kernel = new Kernel(self::ENVIRONMENT, false);

        return new ContainerRebuild(ContainerRebuild::loaderOf($kernel, $kernel->getBuildDir()), $console);
    }

    // The defaults bin/console reads, because the journey's PHPUnit loads no Dotenv; parsed rather than loaded, because a worker handed Dotenv's list of loaded names would load the in-memory queue of .env.test over the shared one.
    /**
     * @return array<string,string>
     */
    private function driverEnvironment(): array
    {
        $defaults = [...$this->settingsIn(self::DEFAULT_SETTINGS), ...$this->settingsIn(self::TEST_SETTINGS)];
        // Because a variable the process has (the instance's database and directories) wins over a file, as in Dotenv's own loading.
        $unset = array_filter($defaults, static fn (string $name): bool => !array_key_exists($name, $_ENV) && (!array_key_exists($name, $_SERVER) || str_starts_with($name, 'HTTP_')) && false === getenv($name), ARRAY_FILTER_USE_KEY);

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
}
