<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// One deadline for a whole run of requests, because a limit per request lets a paged listing run on long after it (decision D5 of #3826).
final class DeadlineHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    private readonly float $startedAt;

    // The clock is one that only runs forward (the caller passes a monotonic one), because a wall clock set back during the run would lengthen the budget.
    public function __construct(
        HttpClientInterface $client,
        private readonly ClockInterface $elapsed,
        private readonly float $seconds,
    ) {
        $this->client = $client;
        $this->startedAt = $this->moment();
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, #[SensitiveParameter] array $options = []): ResponseInterface
    {
        // Never more than the whole budget, so even a clock that does step back cannot give one request more than the run may take.
        $left = min($this->seconds, $this->seconds - ($this->moment() - $this->startedAt));
        if ($left <= 0) {
            throw new TransportException('the forge check ran out of time');
        }

        return $this->client->request($method, $url, ['max_duration' => $left] + $options);
    }

    private function moment(): float
    {
        return (float) $this->elapsed->now()->format('U.u');
    }
}
