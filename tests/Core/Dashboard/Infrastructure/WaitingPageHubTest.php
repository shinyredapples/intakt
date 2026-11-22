<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\LiveWaitingPage;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\UnpricedRoundCosts;
use Intakt\Tests\Support\WorkerPorts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Twig\Environment;

// Against the real built-in hub: whether the dashboard's grants let a subscriber of the Waiting on you topic receive the page is the hub's behavior, which a double would only assume.
#[Group('hub')]
#[CoversClass(LiveWaitingPage::class)]
#[CoversClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(DashboardTopics::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
final class WaitingPageHubTest extends KernelTestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const int SECONDS_TO_WAIT = 10;

    private Process $hub;

    private string $storage;

    private string $url;

    private string $issuer;

    private HttpClientInterface $client;

    private BufferingLogger $logger;

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

        parent::tearDown();
    }

    public function testThePublishedWaitingPageReachesASubscriberWithTheDashboardsGrants(): void
    {
        $stream = $this->subscribe(DashboardTopics::WAITING, LivePageRendering::listenerGrants());

        $this->waitingPage()->publishTheWaitingPage();

        $received = $this->receivedWithin($stream, 'target="waiting-side"', self::SECONDS_TO_WAIT);
        self::assertStringContainsString('data: <turbo-stream action="replace" method="morph" target="waiting-head">', $received);
        self::assertStringContainsString('target="waiting-list"', $received);
        self::assertStringContainsString('target="waiting-side"', $received);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testACookieGrantingOnlyTheShellTopicGetsNoWaitingPageBecauseThePageIsPrivate(): void
    {
        $stream = $this->subscribe(DashboardTopics::WAITING, ['exact' => [DashboardTopics::SHELL]]);

        $this->waitingPage()->publishTheWaitingPage();

        self::assertStringNotContainsString('waiting-list', $this->receivedWithin($stream, 'waiting-list', 2));
    }

    private function waitingPage(): LiveWaitingPage
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $clock = new MockClock('2026-11-02 12:00:00 UTC');
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('waitingAndEndedOnTheDayOf')->willReturn(new OwnerNoticesOfTheDay([], []));
        $pages = new LedgerReviewPages(new DashboardLedger(), new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
        $hub = new Hub($this->url, new StaticTokenProvider($this->token()), httpClient: $this->client);

        return new LiveWaitingPage(new WaitingPageReading($notices, $pages, $clock, []), $twig, new MercureLiveUpdates($hub, $this->logger), new LockFactory(new InMemoryStore()), $clock, $this->logger);
    }

    /**
     * @param array<string, list<string>> $granted
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

    private function token(): string
    {
        return new LcobucciFactory(self::SECRET)->create([new Grant([Grant::ACTION_PUBLISH], ['*'])], ['iss' => $this->issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => $this->url]);
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
