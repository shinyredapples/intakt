<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

// One deadline over several requests, because Symfony's timeout and max_duration bound each request alone: each request gets its slice or the time left, whichever is shorter, so the whole read ends by the deadline without refusing a request that still fits. Nothing is sent once no time is left, because a max_duration of 0 would mean no limit at all.
#[Exclude]
final readonly class DeadlineHttpClient implements HttpClientInterface
{
    public const int SLICE_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
        private DateTimeImmutable $deadline,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $limit = min(self::SLICE_SECONDS, $this->secondsLeft());
        if ($limit <= 0) {
            throw new TransportException('The time for this check ran out before the next request.');
        }

        return $this->http->request($method, $url, ['timeout' => $limit, 'max_duration' => $limit] + $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->http->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->http->withOptions($options), $this->clock, $this->deadline);
    }

    private function secondsLeft(): float
    {
        return (float) $this->deadline->format('U.u') - (float) $this->clock->now()->format('U.u');
    }
}
