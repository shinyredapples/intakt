<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Tests\Support\WorkerPorts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Against the real built-in hub, because history and the Last-Event-ID catch-up are the hub's behavior and a double would only repeat what the test assumes.
#[Group('hub')]
#[CoversClass(MercureRoundUpdates::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
final class MercureHubTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    // The hub's own value for the whole history, used because it makes the hub answer at once, which tells the test the subscriber is registered before it publishes.
    private const string FROM_THE_START = 'earliest';

    private const int SECONDS_TO_WAIT = 10;

    // One hub for the class, because starting it costs more than a test; a round of its own per test keeps the tests apart on its history.
    private static Process $hub;

    private static string $storage;

    private static string $url;

    private static string $issuer;

    private static string $admin;

    private HttpClientInterface $client;

    private MercureRoundUpdates $updates;

    private string $round;

    public static function setUpBeforeClass(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $ports = WorkerPorts::ofThisWorker();
        $port = $ports->next();
        self::$admin = 'localhost:'.$ports->next();
        self::$storage = sys_get_temp_dir().'/intakt-hub-'.bin2hex(random_bytes(6));
        mkdir(self::$storage);
        self::$issuer = 'http://localhost:'.$port;
        self::$url = self::$issuer.'/.well-known/mercure';
        self::$hub = new Process([$frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), [
            'SERVER_PORT' => (string) $port,
            'CADDY_ADMIN' => self::$admin,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => self::$issuer,
            'MERCURE_BOLT_PATH' => self::$storage.'/hub.db',
        ]);
        self::$hub->start();
        self::waitForTheHub($port);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$hub)) {
            self::$hub->stop(5);
        }

        if (isset(self::$storage)) {
            new Filesystem()->remove(self::$storage);
        }
    }

    protected function setUp(): void
    {
        $this->round = Uuid::v7()->toRfc4122();
        $this->client = HttpClient::create();
        $logger = new BufferingLogger();
        $this->updates = new MercureRoundUpdates(
            new MercureLiveUpdates(new Hub(self::$url, new StaticTokenProvider($this->token(new Grant([Grant::ACTION_PUBLISH], ['*']))), httpClient: $this->client), $logger),
            new Environment(new FilesystemLoader(dirname(__DIR__, 4).'/templates')),
            $logger,
        );
    }

    public function testEventsPublishedByARoundReachASubscribedClientInOrder(): void
    {
        $stream = $this->subscribe(self::FROM_THE_START);
        $this->waitForTheGreeting($stream);

        $this->publishThree();

        self::assertSame(['1 of 2 reviewers finished.', '2 of 2 reviewers finished.', 'Judged.'], $this->roundStatesIn($this->read($stream, 3)));
    }

    public function testAClientThatReconnectsWithLastEventIdReceivesTheEventsItMissed(): void
    {
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(1, 2, false));
        $first = $this->subscribe(self::FROM_THE_START);
        $seen = $this->read($first, 1);
        $first->cancel();

        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, false));
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, true));
        $second = $this->subscribe($seen[0]['id']);

        self::assertSame(['2 of 2 reviewers finished.', 'Judged.'], $this->roundStatesIn($this->read($second, 2)));
    }

    // The dashboard's connection controller reconnects with a new EventSource, which cannot set the Last-Event-ID header.
    public function testAClientThatAsksInTheAddressForTheEventsAfterTheLastOneItReceivedGetsTheEventsItMissed(): void
    {
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(1, 2, false));
        $first = $this->subscribe(self::FROM_THE_START);
        $seen = $this->read($first, 1);
        $first->cancel();

        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, false));
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, true));
        $topic = MercureRoundUpdates::topicOf($this->round);
        $second = $this->client->request('GET', self::$url, [
            'query' => ['match' => $topic, 'last_event_id' => $seen[0]['id']],
            'headers' => ['Authorization' => 'Bearer '.$this->token(new Grant([Grant::ACTION_SUBSCRIBE], [$topic]))],
            'timeout' => self::SECONDS_TO_WAIT,
        ]);

        self::assertSame(['2 of 2 reviewers finished.', 'Judged.'], $this->roundStatesIn($this->read($second, 2)));
    }

    public function testAClientWithoutASubscriberTokenReceivesNothing(): void
    {
        $response = $this->client->request('GET', self::$url, ['query' => ['match' => MercureRoundUpdates::topicOf($this->round)]]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheAdminApiTheStatusCommandReadsReportsTheHubReady(): void
    {
        $response = $this->client->request('GET', 'http://'.self::$admin.'/mercure/health/ready');

        self::assertSame(200, $response->getStatusCode());
    }

    private function publishThree(): void
    {
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(1, 2, false));
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, false));
        $this->updates->roundChanged(Uuid::fromString($this->round), new RoundProgress(2, 2, true));
    }

    private function subscribe(string $lastEventId): ResponseInterface
    {
        $topic = MercureRoundUpdates::topicOf($this->round);
        $headers = ['Authorization' => 'Bearer '.$this->token(new Grant([Grant::ACTION_SUBSCRIBE], [$topic])), 'Last-Event-ID' => $lastEventId];

        $response = $this->client->request('GET', self::$url, ['query' => ['match' => $topic], 'headers' => $headers, 'timeout' => self::SECONDS_TO_WAIT]);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function waitForTheGreeting(ResponseInterface $response): void
    {
        foreach ($this->client->stream($response, self::SECONDS_TO_WAIT) as $chunk) {
            if (!$chunk->isFirst() && '' !== $chunk->getContent()) {
                return;
            }
        }
    }

    /**
     * @return list<array{id: string, data: string}>
     */
    private function read(ResponseInterface $response, int $count): array
    {
        $events = [];
        $buffer = '';

        foreach ($this->client->stream($response, self::SECONDS_TO_WAIT) as $chunk) {
            $buffer .= $chunk->getContent();

            while (false !== ($end = strpos($buffer, "\n\n"))) {
                $event = $this->parsed(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 2);

                if (null !== $event) {
                    $events[] = $event;
                }
            }

            if (count($events) >= $count) {
                break;
            }
        }

        self::assertCount($count, $events);

        return $events;
    }

    /**
     * @return array{id: string, data: string}|null
     */
    private function parsed(string $block): ?array
    {
        $id = null;
        $data = [];

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'id: ')) {
                $id = substr($line, 4);
            }

            if (str_starts_with($line, 'data: ')) {
                $data[] = substr($line, 6);
            }
        }

        return null === $id ? null : ['id' => $id, 'data' => implode("\n", $data)];
    }

    /**
     * @param list<array{id: string, data: string}> $events
     *
     * @return list<string>
     */
    private function roundStatesIn(array $events): array
    {
        return array_map(static fn (array $event): string => 1 === preg_match('/<p id="[^"]+">([^<]+)<\/p>/', $event['data'], $found) ? $found[1] : $event['data'], $events);
    }

    private function token(Grant $grant): string
    {
        return new LcobucciFactory(self::SECRET)->create([$grant], ['iss' => self::$issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => self::$url]);
    }

    private static function waitForTheHub(int $port): void
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

        self::fail('The hub did not start: '.self::$hub->getErrorOutput());
    }
}
