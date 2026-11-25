<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\DeadlineHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DeadlineHttpClient::class)]
final class DeadlineHttpClientTest extends TestCase
{
    private ?string $traceSetting = null;

    public function testARequestAfterTheDeadlineFailsWithoutItsBearerAmongTheTraceArguments(): void
    {
        $clock = new MockClock();
        $client = new DeadlineHttpClient(new MockHttpClient(), $clock, 0.0);

        try {
            $client->request('GET', '/installation/repositories', ['auth_bearer' => 'dummy-credential-marker']);
            self::fail('A request after the deadline must not be sent.');
        } catch (TransportException $late) {
            self::assertSame('the forge check ran out of time', $late->getMessage());
            self::assertInstanceOf(SensitiveParameterValue::class, $late->getTrace()[0]['args'][2] ?? null);
        }
    }

    public function testAClockSetBackDuringTheRunNeverGivesARequestMoreThanTheWholeBudget(): void
    {
        $clock = new MockClock('2026-11-25T12:00:00Z');
        $budgets = [];
        $sent = new MockHttpClient(static function (string $method, string $url, array $options) use (&$budgets): MockResponse {
            $budgets[] = $options['max_duration'];

            return new MockResponse();
        });
        $client = new DeadlineHttpClient($sent, $clock, 15.0);

        $clock->modify('-60 seconds');
        $client->request('GET', '/installation/repositories');

        self::assertSame([15.0], $budgets);
    }

    public function testEachRequestGetsTheTimeTheEarlierOnesLeft(): void
    {
        $clock = new MockClock('2026-11-25T12:00:00Z');
        $budgets = [];
        $sent = new MockHttpClient(static function (string $method, string $url, array $options) use (&$budgets): MockResponse {
            $budgets[] = $options['max_duration'];

            return new MockResponse();
        });
        $client = new DeadlineHttpClient($sent, $clock, 15.0);

        $clock->sleep(4);
        $client->request('GET', '/installation');
        $clock->sleep(11);

        self::assertSame([11.0], $budgets);
        $this->expectException(TransportException::class);
        $client->request('GET', '/installation/repositories');
    }

    // Trace arguments are recorded only with this setting off, and the test must see what a host that records them would.
    protected function setUp(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->traceSetting = false === $previous ? null : $previous;
    }

    protected function tearDown(): void
    {
        if (null !== $this->traceSetting) {
            ini_set('zend.exception_ignore_args', $this->traceSetting);
        }
    }
}
