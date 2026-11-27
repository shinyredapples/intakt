<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\ConfiguredProjects;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\PublishProjectFilter;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Tests\Support\ListedReviews;
use Intakt\Tests\Support\WorkerPorts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Against the real built-in hub: delivery to a subscriber of the shell topic and the connection dropping are the hub's behavior, which a double would only assume.
#[Group('hub')]
#[CoversClass(MercureLiveUpdates::class)]
#[CoversClass(LivePageRendering::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(DashboardTopics::class)]
#[UsesClass(PublishProjectFilter::class)]
#[UsesClass(FilterProjects::class)]
#[UsesClass(ConfiguredProjects::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundOpened::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(ProjectNames::class)]
final class ShellHubTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const int SECONDS_TO_WAIT = 10;

    private Process $hub;

    private string $storage;

    private string $url;

    private string $issuer;

    private HttpClientInterface $client;

    private BufferingLogger $logger;

    public function testAnEventPublishedForTheShellTopicReachesASubscriberOfTheShellTopic(): void
    {
        $stream = $this->subscribeToTheShell();
        $this->updates()->publish(new LiveEvent(DashboardTopics::SHELL, '<turbo-stream action="refresh"></turbo-stream>'));

        $received = '';

        foreach ($this->client->stream($stream, self::SECONDS_TO_WAIT) as $chunk) {
            $received .= $chunk->getContent();

            if (str_contains($received, 'turbo-stream')) {
                break;
            }
        }

        self::assertStringContainsString('data: <turbo-stream action="refresh"></turbo-stream>', $received);
    }

    public function testTheProjectFilterPublishedWhenARoundOpensReachesASubscriberOfTheShellTopic(): void
    {
        $stream = $this->subscribeToTheShell();
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([
            new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":1}'), new DateTimeImmutable('2026-11-10T12:00:00+00:00')),
        ]));
        $projects = new FilterProjects(ListedReviews::on($ledger), new ConfiguredProjects(self::createConfiguredStub(TierResolver::class, ['projects' => new ProjectNames()]), []));
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4).'/templates'));

        new PublishProjectFilter($projects, $twig, $this->updates(), new LockFactory(new InMemoryStore()), $this->logger)(new RoundOpened('r1', 'alpha'));

        $received = '';

        foreach ($this->client->stream($stream, self::SECONDS_TO_WAIT) as $chunk) {
            $received .= $chunk->getContent();

            if (str_contains($received, '</turbo-stream>')) {
                break;
            }
        }

        self::assertStringContainsString('data: <turbo-stream action="update" target="projfilter"><template><option value="">All projects</option><option value="alpha">alpha</option></template></turbo-stream>', $received);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testASubscriberSeesItsConnectionEndWhenTheHubStopsAndPublishingToAStoppedHubOnlyLogsAWarning(): void
    {
        $stream = $this->subscribeToTheShell();
        $this->hub->stop(5);

        $ended = false;

        try {
            foreach ($this->client->stream($stream, self::SECONDS_TO_WAIT) as $chunk) {
                $ended = $chunk->isLast();
            }
        } catch (TransportExceptionInterface) {
            $ended = true;
        }

        $this->updates()->publish(new LiveEvent(DashboardTopics::SHELL, '<turbo-stream action="refresh"></turbo-stream>'));

        self::assertTrue($ended);
        self::assertCount(1, $this->logger->cleanLogs());
    }

    public function testAReviewTabReconnectingWithTheCookieAListPageSetStillReceivesItsRoundUpdates(): void
    {
        $stream = $this->subscribe(MercureRoundUpdates::topicOf('round-1'), LivePageRendering::listenerGrants());

        $this->updates()->publish(new LiveEvent(MercureRoundUpdates::topicOf('round-1'), '<p>round progress</p>'));

        self::assertStringContainsString('round progress', $this->receivedWithin($stream, 'round progress', self::SECONDS_TO_WAIT));
    }

    public function testACookieGrantingOnlyTheShellTopicGetsNoRoundUpdateBecauseTheRoundsArePrivate(): void
    {
        $stream = $this->subscribe(MercureRoundUpdates::topicOf('round-1'), [DashboardTopics::SHELL]);

        $this->updates()->publish(new LiveEvent(MercureRoundUpdates::topicOf('round-1'), '<p>round progress</p>'));

        self::assertStringNotContainsString('round progress', $this->receivedWithin($stream, 'round progress', 2));
    }

    protected function setUp(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $ports = WorkerPorts::ofThisWorker();
        $port = $ports->next();
        $this->storage = sys_get_temp_dir().'/intakt-hub-'.bin2hex(random_bytes(6));
        mkdir($this->storage);
        $this->issuer = 'http://localhost:'.$port;
        $this->url = $this->issuer.'/.well-known/mercure';
        $this->hub = new Process([$frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), [
            'SERVER_PORT' => (string) $port,
            'CADDY_ADMIN' => 'localhost:'.$ports->next(),
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => $this->issuer,
            'MERCURE_BOLT_PATH' => $this->storage.'/hub.db',
        ]);
        $this->hub->start();
        $this->waitForTheHub($port);
        $this->client = HttpClient::create();
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        if (isset($this->hub)) {
            $this->hub->stop(5);
        }

        if (isset($this->storage)) {
            new Filesystem()->remove($this->storage);
        }
    }

    private function subscribeToTheShell(): ResponseInterface
    {
        $response = $this->client->request('GET', $this->url, [
            'query' => ['match' => DashboardTopics::SHELL, 'last_event_id' => 'earliest'],
            'headers' => ['Authorization' => 'Bearer '.$this->token(Grant::ACTION_SUBSCRIBE, DashboardTopics::SHELL)],
            'timeout' => self::SECONDS_TO_WAIT,
        ]);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function updates(): MercureLiveUpdates
    {
        $hub = new Hub($this->url, new StaticTokenProvider($this->token(Grant::ACTION_PUBLISH, '*')), httpClient: $this->client);

        return new MercureLiveUpdates($hub, $this->logger);
    }

    /**
     * @param array<string, list<string>>|list<string> $granted
     */
    private function subscribe(string $topic, array $granted): ResponseInterface
    {
        $token = new LcobucciFactory(self::SECRET)->create([new Grant([Grant::ACTION_SUBSCRIBE], $granted)], ['iss' => $this->issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => $this->url]);
        $response = $this->client->request('GET', $this->url, [
            'query' => ['match' => $topic],
            'headers' => ['Authorization' => 'Bearer '.$token],
            'timeout' => self::SECONDS_TO_WAIT,
        ]);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function receivedWithin(ResponseInterface $stream, string $awaited, int $seconds): string
    {
        $received = '';

        foreach ($this->client->stream($stream, $seconds) as $chunk) {
            if ($chunk->isTimeout()) {
                break;
            }

            $received .= $chunk->getContent();

            if (str_contains($received, $awaited)) {
                break;
            }
        }

        $stream->cancel();

        return $received;
    }

    private function token(string $action, string $topic): string
    {
        return new LcobucciFactory(self::SECRET)->create([new Grant([$action], [$topic])], ['iss' => $this->issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => $this->url]);
    }

    private function waitForTheHub(int $port): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

            if (false !== $connection) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('The hub did not start: '.$this->hub->getErrorOutput());
    }
}
