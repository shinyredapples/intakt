<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Closure;
use Symfony\Component\HttpClient\Response\MockResponse;

// The network calls of the fake engine, kept apart so a test can see which networks exist and the engine's refusals stay as strict as the real ones.
final class FakeNetworks
{
    /** @var array<string, array<string, mixed>> */
    private array $networks = [];

    /**
     * @param Closure(): array<string, FakeContainer> $containers
     */
    public function __construct(private readonly Closure $containers)
    {
    }

    public function leave(string $job): void
    {
        $this->networks['intakt-job-'.$job] = ['Name' => 'intakt-job-'.$job, 'Internal' => true, 'Labels' => ['intakt.job' => $job]];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->networks);
    }

    /**
     * @return array<string, mixed>
     */
    public function of(string $name): array
    {
        return $this->networks[$name];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function missing(array $names): array
    {
        return array_values(array_diff($names, ['none', 'bridge'], $this->names()));
    }

    // Null for a call that is not about networks, so the engine answers it instead.
    /**
     * @param array<string, mixed> $options
     */
    public function answer(string $method, string $path, array $options): ?MockResponse
    {
        return match (true) {
            'POST' === $method && 'networks/create' === $path => $this->create($options),
            'GET' === $method && 'networks' === $path => $this->json(array_values($this->networks)),
            'DELETE' === $method && 1 === preg_match('#^networks/([\w.-]+)$#', $path, $call) => $this->remove($call[1]),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function create(array $options): MockResponse
    {
        /** @var array{Name: string, Internal: bool, Labels: array<string, string>} $network */
        $network = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '{}', true, flags: JSON_THROW_ON_ERROR);

        if (isset($this->networks[$network['Name']])) {
            return $this->json(['message' => sprintf('network with name %s already exists', $network['Name'])], 409);
        }

        $this->networks[$network['Name']] = $network;

        return $this->json(['Id' => 'feed'.$network['Name']], 201);
    }

    /**
     * @param array<mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    // Refused while a container is still on it, because the real engine refuses that too.
    private function remove(string $name): MockResponse
    {
        if (!isset($this->networks[$name])) {
            return $this->json(['message' => sprintf('network %s not found', $name)], 404);
        }

        if ([] !== array_filter(($this->containers)(), static fn (FakeContainer $container): bool => in_array($name, $container->networks, true))) {
            return $this->json(['message' => sprintf('error while removing network: network %s has active endpoints', $name)], 403);
        }

        unset($this->networks[$name]);

        return new MockResponse('', ['http_code' => 204]);
    }
}
