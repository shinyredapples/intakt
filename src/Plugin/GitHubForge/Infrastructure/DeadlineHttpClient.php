<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// One deadline for a whole run of requests, because a limit per request lets a paged listing run on long after it (decision D5 of #3826).
final class DeadlineHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly ClockInterface $clock,
        private readonly DateTimeImmutable $deadline,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $left = (float) $this->deadline->format('U.u') - (float) $this->clock->now()->format('U.u');
        if ($left <= 0) {
            throw new TransportException('the forge check ran out of time');
        }

        return $this->client->request($method, $url, ['max_duration' => $left] + $options);
    }
}
