<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Elapsed;
use Intakt\Core\Dashboard\Domain\HandledLine;
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
use Override;
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

// Against the real built-in hub: that a page holding the dashboard's cookie receives the pushed Waiting on you page, and a cookie without the grant does not, is the hub's behavior, which a double would only assume.
#[Group('hub')]
#[CoversClass(LiveWaitingPage::class)]
#[CoversClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(HandledLine::class)]
#[UsesClass(Elapsed::class)]
#[UsesClass(EndedOutageNotice::class)]
#[UsesClass(Account::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(DashboardTopics::class)]
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

    #[Override]
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

    #[Override]
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

    public function testAnOutageEndedWhileThePageIsOpenReachesAPageHoldingTheDashboardCookieUnderYouHandledToday(): void
    {
        $stream = $this->subscribe(LivePageRendering::listenerGrants());

        $this->pageWithAnOutageEndedToday()->publishLatest();

        $received = $this->receivedWithin($stream, '</turbo-stream>', self::SECONDS_TO_WAIT);
        self::assertStringContainsString('target="waiting-side"', $received);
        self::assertStringContainsString('Readings of account claude_code.main came back', $received);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testACookieGrantingOnlyTheShellTopicGetsNoWaitingPageBecauseItIsPrivate(): void
    {
        $stream = $this->subscribe([DashboardTopics::SHELL]);

        $this->pageWithAnOutageEndedToday()->publishLatest();

        self::assertStringNotContainsString('came back', $this->receivedWithin($stream, 'came back', 2));
    }

    private function pageWithAnOutageEndedToday(): LiveWaitingPage
    {
        $clock = new MockClock('2026-11-02 12:00:00 UTC');
        $notices = new ChangingOwnerNotices();
        $notices->ended = [new EndedOutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T09:14:00+00:00'))];
        $pages = new LedgerReviewPages(new DashboardLedger(), new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $hub = new Hub($this->url, new StaticTokenProvider($this->token([Grant::ACTION_PUBLISH], ['*'])), httpClient: $this->client);

        return new LiveWaitingPage(new WaitingPageReading($notices, $pages, $clock, []), $twig, new MercureLiveUpdates($hub, $this->logger), new LockFactory(new InMemoryStore()), $clock, $this->logger);
    }

    /**
     * @param array<string, list<string>>|list<string> $granted
     */
    private function subscribe(array $granted): ResponseInterface
    {
        $response = $this->client->request('GET', $this->url, [
            'query' => ['match' => DashboardTopics::WAITING],
            'headers' => ['Authorization' => 'Bearer '.$this->token([Grant::ACTION_SUBSCRIBE], $granted)],
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

    /**
     * @param list<string>                               $actions
     * @param array<string, list<string>>|list<string> $topics
     */
    private function token(array $actions, array $topics): string
    {
        return new LcobucciFactory(self::SECRET)->create([new Grant($actions, $topics)], ['iss' => $this->issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => $this->url]);
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
