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

// One deadline over several requests, because Symfony's timeout and max_duration bound each request alone: every request may take its full slice, and none starts without a full slice left, so the whole read ends by the deadline.
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
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->secondsLeft() < self::SLICE_SECONDS) {
            throw new TransportException('The time for this check ran out before the next request.');
        }

        return $this->http->request($method, $url, ['timeout' => self::SLICE_SECONDS, 'max_duration' => self::SLICE_SECONDS] + $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->http->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
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
