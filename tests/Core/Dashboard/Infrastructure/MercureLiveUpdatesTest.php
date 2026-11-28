<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\RefusalLogging;
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
#[UsesClass(RefusalLogging::class)]
final class MercureLiveUpdatesTest extends TestCase
{
    private const string HUB = 'http://localhost/.well-known/mercure';

    // Shaped like a JWT only, because the hub refuses any other shape before it posts.
    private const string TOKEN = 'publisher.token.unsigned';

    private const string TOPIC = 'dashboard/projects';

    private const string STREAM = '<turbo-stream action="replace" target="project-filter"><template><nav id="project-filter"></nav></template></turbo-stream>';

    private const string WARNING = 'A live dashboard update was not published.';

    /**
     * @var list<array{request: string, form: array<array-key, mixed>, authorization: mixed}>
     */
    private array $posted = [];

    private BufferingLogger $log;

    public function testAnEventIsPostedToTheHubOnItsTopicWithItsTurboStream(): void
    {
        $this->updates($this->hubThatAccepts())->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame('POST '.self::HUB, $this->posted[0]['request']);
        self::assertSame(['topic' => self::TOPIC, 'data' => self::STREAM, 'private' => 'on'], $this->posted[0]['form']);
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAnEventIsPostedWithThePublisherToken(): void
    {
        $this->updates($this->hubThatAccepts())->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame(['Authorization: Bearer '.self::TOKEN], $this->posted[0]['authorization']);
    }

    public function testEventsReachTheHubInTheOrderTheyArePublished(): void
    {
        $updates = $this->updates($this->hubThatAccepts());

        $updates->publish(new LiveEvent('dashboard/projects', self::STREAM));
        $updates->publish(new LiveEvent('dashboard/waiting', self::STREAM));

        self::assertSame(['dashboard/projects', 'dashboard/waiting'], array_column(array_column($this->posted, 'form'), 'topic'));
    }

    public function testAHubThatAnswersWithAnErrorDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $this->updates(new MockHttpClient(new MockResponse('down', ['http_code' => 503])))->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame([['warning', self::WARNING, self::TOPIC, RuntimeException::class]], $this->loggedWarnings());
    }

    public function testADroppedConnectionToTheHubDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $this->updates(new MockHttpClient(new MockResponse(info: ['error' => 'Connection reset by peer'])))->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame([['warning', self::WARNING, self::TOPIC, RuntimeException::class]], $this->loggedWarnings());
    }

    public function testAnUnreachableHubDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $unreachable = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Could not resolve host: localhost'));

        $this->updates($unreachable)->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame([['warning', self::WARNING, self::TOPIC, RuntimeException::class]], $this->loggedWarnings());
    }

    public function testAPublisherTokenTheHubCannotUseDoesNotFailTheCallerAndIsLoggedWithTheTopic(): void
    {
        $updates = new MercureLiveUpdates(new Hub(self::HUB, new StaticTokenProvider(''), httpClient: $this->hubThatAccepts()), $this->log);

        $updates->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertSame([['warning', self::WARNING, self::TOPIC, InvalidArgumentException::class]], $this->loggedWarnings());
        self::assertSame([], $this->posted);
    }

    public function testAnEventTheHubTookIsReportedAsDelivered(): void
    {
        self::assertTrue($this->updates($this->hubThatAccepts())->publish(new LiveEvent(self::TOPIC, self::STREAM)));
    }

    public function testAnEventTheHubRefusedIsReportedAsNotDelivered(): void
    {
        $delivered = $this->updates(new MockHttpClient(new MockResponse('down', ['http_code' => 503])))->publish(new LiveEvent(self::TOPIC, self::STREAM));

        self::assertFalse($delivered);
        self::assertCount(1, $this->loggedWarnings());
    }

    public function testARefusalItsCallerLogsItselfIsReportedAndNotLogged(): void
    {
        $delivered = $this->updates(new MockHttpClient(new MockResponse('down', ['http_code' => 503])))->publish(new LiveEvent(self::TOPIC, self::STREAM, RefusalLogging::ByCaller));

        self::assertFalse($delivered);
        self::assertSame([], $this->log->cleanLogs());
    }

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    private function hubThatAccepts(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $headers = $options['normalized_headers'] ?? [];
            $this->posted[] = ['request' => $method.' '.$url, 'form' => $form, 'authorization' => is_array($headers) ? $headers['authorization'] ?? null : null];

            return new MockResponse('urn:uuid:'.count($this->posted));
        });
    }

    private function updates(MockHttpClient $hub): MercureLiveUpdates
    {
        return new MercureLiveUpdates(new Hub(self::HUB, new StaticTokenProvider(self::TOKEN), httpClient: $hub), $this->log);
    }

    /**
     * @return list<array{mixed, mixed, mixed, class-string|null}>
     */
    private function loggedWarnings(): array
    {
        return array_values(array_map(static function (mixed $record): array {
            $record = is_array($record) ? $record : [];
            $context = is_array($record[2] ?? null) ? $record[2] : [];
            $exception = $context['exception'] ?? null;

            return [$record[0] ?? null, $record[1] ?? null, $context['topic'] ?? null, is_object($exception) ? $exception::class : null];
        }, $this->log->cleanLogs()));
    }
}
