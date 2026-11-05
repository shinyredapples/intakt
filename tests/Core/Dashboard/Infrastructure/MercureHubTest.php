<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

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
final class MercureHubTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const string ROUND = '01a19a97-48cf-70f1-bdae-965425786c46';

    // The hub's own value for the whole history, used because it makes the hub answer at once, which tells the test the subscriber is registered before it publishes.
    private const string FROM_THE_START = 'earliest';

    private const int SECONDS_TO_WAIT = 10;

    private Process $hub;

    private string $storage;

    private string $url;

    private string $issuer;

    private string $admin;

    private HttpClientInterface $client;

    private MercureRoundUpdates $updates;

    protected function setUp(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $ports = WorkerPorts::ofThisWorker();
        $port = $ports->next();
        $this->admin = 'localhost:'.$ports->next();
        $this->storage = sys_get_temp_dir().'/intakt-hub-'.bin2hex(random_bytes(6));
        mkdir($this->storage);
        $this->issuer = 'http://localhost:'.$port;
        $this->url = $this->issuer.'/.well-known/mercure';
        $this->hub = new Process([$frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), [
            'SERVER_PORT' => (string) $port,
            'CADDY_ADMIN' => $this->admin,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => $this->issuer,
            'MERCURE_BOLT_PATH' => $this->storage.'/hub.db',
        ]);
        $this->hub->start();
        $this->waitForTheHub($port);
        $this->client = HttpClient::create();
        $this->updates = new MercureRoundUpdates(
            new Hub($this->url, new StaticTokenProvider($this->token(new Grant([Grant::ACTION_PUBLISH], ['*']))), httpClient: $this->client),
            new Environment(new FilesystemLoader(dirname(__DIR__, 4).'/templates')),
            new BufferingLogger(),
        );
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

    public function testEventsPublishedByARoundReachASubscribedClientInOrder(): void
    {
        $stream = $this->subscribe(self::FROM_THE_START);
        $this->waitForTheGreeting($stream);

        $this->publishThree();

        self::assertSame(['1 of 2 reviewers finished.', '2 of 2 reviewers finished.', 'Judged.'], $this->roundStatesIn($this->read($stream, 3)));
    }

    public function testAClientThatReconnectsWithLastEventIdReceivesTheEventsItMissed(): void
    {
        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));
        $first = $this->subscribe(self::FROM_THE_START);
        $seen = $this->read($first, 1);
        $first->cancel();

        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, false));
        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));
        $second = $this->subscribe($seen[0]['id']);

        self::assertSame(['2 of 2 reviewers finished.', 'Judged.'], $this->roundStatesIn($this->read($second, 2)));
    }

    public function testAClientWithoutASubscriberTokenReceivesNothing(): void
    {
        $response = $this->client->request('GET', $this->url, ['query' => ['match' => MercureRoundUpdates::topicOf(self::ROUND)]]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheAdminApiTheStatusCommandReadsReportsTheHubReady(): void
    {
        $response = $this->client->request('GET', 'http://'.$this->admin.'/mercure/health/ready');

        self::assertSame(200, $response->getStatusCode());
    }

    private function publishThree(): void
    {
        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));
        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, false));
        $this->updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));
    }

    private function subscribe(string $lastEventId): ResponseInterface
    {
        $topic = MercureRoundUpdates::topicOf(self::ROUND);
        $headers = ['Authorization' => 'Bearer '.$this->token(new Grant([Grant::ACTION_SUBSCRIBE], [$topic])), 'Last-Event-ID' => $lastEventId];

        $response = $this->client->request('GET', $this->url, ['query' => ['match' => $topic], 'headers' => $headers, 'timeout' => self::SECONDS_TO_WAIT]);
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
        return new LcobucciFactory(self::SECRET)->create([$grant], ['iss' => $this->issuer, 'sub' => 'test', 'client_id' => 'test', 'aud' => $this->url]);
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
