<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Platform\Contract\KeptForTheProcess;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

// The test environment's HTTP client: with a script directory it answers every call from the armed script after holding it for the script's weight, so a consumer's model calls overlap measurably and reach no provider; without one it is the real client.
final class ScriptedHttpClient implements HttpClientInterface
{
    // Kept for the process, because the worker resets services after every message and each worker must answer all its calls from the script it read first.
    #[KeptForTheProcess]
    private ?float $weight = null;

    // Kept for the process for the same reason as the weight: a reset between messages must not make a worker read a script armed again in the middle of its run.
    /**
     * @var list<array{content_type: string, body: string}>
     */
    #[KeptForTheProcess]
    private array $scripted = [];

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly ClockInterface $clock,
        private readonly string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ('' === $this->directory) {
            return $this->inner->request($method, $url, $options);
        }

        return new MockHttpClient($this->answer(...))->request($method, $url, $options);
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ('' === $this->directory) {
            return $this->inner->stream($responses, $timeout);
        }

        return new MockHttpClient()->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->clock, $this->directory);
    }

    private function answer(): MockResponse
    {
        $scripts = new ScriptDirectory($this->directory, $this->clock);
        $weight = $this->weightOf($scripts);
        $call = $scripts->callStarted();
        $this->clock->sleep($weight);
        $scripts->callEnded($call);
        $answer = $this->scripted[($call - 1) % count($this->scripted)];

        return new MockResponse($answer['body'], ['response_headers' => ['content-type' => $answer['content_type']]]);
    }

    private function weightOf(ScriptDirectory $scripts): float
    {
        if (null === $this->weight) {
            ['weight' => $this->weight, 'answers' => $this->scripted] = $scripts->script();
        }

        return $this->weight;
    }
}
