<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Exception\InvalidArgumentException;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;

#[CoversClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
final class MercureLiveUpdatesTest extends TestCase
{
    private const string HUB = 'http://localhost/.well-known/mercure';

    // Shaped like a JWT only, because the hub refuses any other shape before it posts.
    private const string TOKEN = 'publisher.token.unsigned';

    private const string TOPIC = 'dashboard/projects';

    private const string STREAM = '<turbo-stream action="replace" target="project-filter"><template><nav id="project-filter"></nav></template></turbo-stream>';

    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $posted = [];

    private BufferingLogger $log;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    public function testAnEventIsPostedToTheHubOnItsTopicWithItsTurboStream(): void
    {
        $this->updates($this->hubThatAccepts())->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame(['POST', self::HUB], [$this->posted[0]['method'], $this->posted[0]['url']]);
        self::assertSame(['topic' => self::TOPIC, 'data' => self::STREAM, 'private' => 'on'], $this->formOf($this->posted[0]['options']));
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAnEventIsPostedWithThePublisherToken(): void
    {
        $this->updates($this->hubThatAccepts())->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame(['Authorization: Bearer '.self::TOKEN],$this->posted[0]['options']['normalized_headers']['authorization'] ?? null);
    }

    public function testEventsReachTheHubInTheOrderTheyArePublished(): void
    {
        $updates = $this->updates($this->hubThatAccepts());

        $updates->publish(new LiveEvent('dashboard/projects', self::STREAM));
        $updates->publish(new LiveEvent('dashboard/waiting', self::STREAM));

        self::assertSame(['dashboard/projects', 'dashboard/waiting'], array_map(fn (array $post): string => $this->formOf($post['options'])['topic'] ?? '', $this->posted));
    }

    public function testAHubThatAnswersWithAnErrorDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $this->updates(new MockHttpClient(new MockResponse('down', ['http_code' => 503])))->publish(new LiveEvent(self::TOPIC, self::STREAM));

        $this->assertLoggedOneWarningFor(self::TOPIC);
    }

    public function testADroppedConnectionToTheHubDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $this->updates(new MockHttpClient(new MockResponse(info: ['error' => 'Connection reset by peer'])))->publish(new LiveEvent(self::TOPIC, self::STREAM));

        $this->assertLoggedOneWarningFor(self::TOPIC);
    }

    public function testAnUnreachableHubDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $unreachable = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Could not resolve host: localhost'));

        $this->updates($unreachable)->publish(new LiveEvent(self::TOPIC, self::STREAM));

        $this->assertLoggedOneWarningFor(self::TOPIC);
    }

    public function testAPublisherTokenTheHubCannotUseDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $updates = new MercureLiveUpdates(new Hub(self::HUB, new StaticTokenProvider(''), httpClient: $this->hubThatAccepts()), $this->log);

        $updates->publish(new LiveEvent(self::TOPIC, self::STREAM));

        $logs = $this->log->cleanLogs();
        self::assertSame(['warning', 'A live dashboard update was not published.', self::TOPIC], [$logs[0][0], $logs[0][1], $logs[0][2]['topic'] ?? null]);
        self::assertInstanceOf(InvalidArgumentException::class, $logs[0][2]['exception'] ?? null);
        self::assertSame([], $this->posted);
    }

    private function hubThatAccepts(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->posted[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('urn:uuid:'.count($this->posted));
        });
    }

    private function updates(MockHttpClient $hub): MercureLiveUpdates
    {
        return new MercureLiveUpdates(new Hub(self::HUB, new StaticTokenProvider(self::TOKEN), httpClient: $hub), $this->log);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    private function formOf(array $options): array
    {
        parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);

        return $form;
    }

    private function assertLoggedOneWarningFor(string $topic): void
    {
        $logs = $this->log->cleanLogs();

        self::assertCount(1, $logs);
        self::assertSame(['warning', 'A live dashboard update was not published.'], [$logs[0][0], $logs[0][1]]);
        self::assertSame($topic, $logs[0][2]['topic'] ?? null);
        self::assertInstanceOf(RuntimeException::class, $logs[0][2]['exception'] ?? null);
    }
}
