<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\DeadlineHttpClient;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(DeadlineHttpClient::class)]
final class DeadlineHttpClientTest extends TestCase
{
    public function testARequestWithAFullSliceLeftIsSentWithTheSliceAsItsLimits(): void
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $sent = [];
        $client = new DeadlineHttpClient($this->recording($sent), $clock, $clock->now()->modify('+15 seconds'));

        $client->request('GET', 'https://api.github.com/', ['timeout' => 60, 'max_duration' => 0]);

        self::assertEqualsWithDelta(5.0, $sent['timeout'], 0.0);
        self::assertEqualsWithDelta(5.0, $sent['max_duration'], 0.0);
    }

    public function testARequestWithLessThanAFullSliceLeftIsSentWithTheTimeLeftAsItsLimits(): void
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $sent = [];
        $client = new DeadlineHttpClient($this->recording($sent), $clock, $clock->now()->modify('+3 seconds'));

        $client->request('GET', 'https://api.github.com/');

        self::assertEqualsWithDelta(3.0, $sent['timeout'], 0.0);
        self::assertEqualsWithDelta(3.0, $sent['max_duration'], 0.0);
    }

    public function testARequestWithNoTimeLeftIsRefusedBeforeItIsSent(): void
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient(static fn (): ResponseInterface => throw new LogicException('must not be sent'));
        $client = new DeadlineHttpClient($http, $clock, $clock->now());

        $this->expectException(TransportException::class);

        $client->request('GET', 'https://api.github.com/');
    }

    public function testAClientWithOtherOptionsKeepsTheSameDeadline(): void
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $client = new DeadlineHttpClient(new MockHttpClient(new MockResponse('{}')), $clock, $clock->now()->modify('+6 seconds'));
        $clock->sleep(7);

        $this->expectException(TransportException::class);

        $client->withOptions(['headers' => ['X-Test' => 'yes']])->request('GET', 'https://api.github.com/');
    }

    /**
     * @param array<mixed> $sent
     */
    private function recording(array &$sent): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
            $sent = $options;

            return new MockResponse('{}');
        });
    }
}
