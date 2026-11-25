<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(AppJwt::class)]
final class GitHubCallsTest extends TestCase
{
    private const string SECRET = 'credential-that-must-stay-secret';

    public function testTheInstallationTokenIsNotAnArgumentInTheTraceOfARateLimit(): void
    {
        $calls = $this->callsAnswering(static fn (): ResponseInterface => new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]));

        $failure = $this->failureOf(static fn () => $calls->asInstallation('GET', 'https://api.github.com/installation/repositories', ['auth_bearer' => self::SECRET]));

        self::assertInstanceOf(ForgeRateLimited::class, $failure);
        self::assertNotContains(self::SECRET, $this->optionValues($failure));
    }

    public function testTheAppJwtIsNotAnArgumentInTheTraceOfATransportFailure(): void
    {
        $calls = $this->callsAnswering(static fn (): ResponseInterface => throw new TransportException('connection reset'));

        $failure = $this->failureOf(static fn () => $calls->asApp('https://api.github.com/app/installations/678', ['auth_bearer' => self::SECRET]));

        self::assertInstanceOf(TransportExceptionInterface::class, $failure);
        self::assertNotContains(self::SECRET, $this->optionValues($failure));
    }

    public function testTheOptionsStillReachTheRequest(): void
    {
        $sent = [];
        $calls = $this->callsAnswering(static function (string $method, string $url, array $options) use (&$sent): ResponseInterface {
            $headers = $options['normalized_headers'];
            self::assertIsArray($headers);
            $sent[] = (array) ($headers['authorization'] ?? []);

            return new MockResponse('{}');
        });

        $calls->asInstallation('GET', 'https://api.github.com/installation/repositories', ['auth_bearer' => self::SECRET]);

        self::assertSame([['Authorization: Bearer '.self::SECRET]], $sent);
    }

    public function testARateLimitedQueryPausesQueriesButNotRestCalls(): void
    {
        $answers = [new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after' => '600']]), new MockResponse('{}')];
        $calls = $this->callsAnswering(static function () use (&$answers): ResponseInterface {
            return array_shift($answers) ?? self::fail('Only two requests were expected.');
        });
        self::assertInstanceOf(ForgeRateLimited::class, $this->failureOf(static fn () => $calls->query(['json' => ['query' => '{}']])));

        self::assertSame(200, $calls->asInstallation('GET', 'https://api.github.com/installation/repositories', [])->getStatusCode());
        self::assertInstanceOf(ForgeRateLimited::class, $this->failureOf(static fn () => $calls->query(['json' => ['query' => '{}']])));
    }

    private function callsAnswering(callable $answer): GitHubCalls
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient($answer);
        $tokens = new InstallationTokens(new InstallationTokenMinter(new AppJwt('1', 'unused', $clock), $http, new NullLogger()), '678', $clock);

        return new GitHubCalls($http, new RateLimitReadings(new ArrayAdapter(clock: $clock), $clock, new LockFactory(new InMemoryStore())), $tokens);
    }

    private function failureOf(callable $call): Throwable
    {
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArguments);
        }

        self::fail('The call must fail.');
    }

    /**
     * @return list<mixed>
     */
    private function optionValues(Throwable $failure): array
    {
        // Only this class's frames, because the HTTP client's own frames below them are not this class's to redact.
        $frames = array_filter($failure->getTrace(), static fn (array $frame): bool => GitHubCalls::class === ($frame['class'] ?? null));
        self::assertNotSame([], $frames);

        $values = [];
        foreach ($frames as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (is_array($argument)) {
                    array_push($values, ...array_values($argument));
                }
            }
        }

        return $values;
    }
}
