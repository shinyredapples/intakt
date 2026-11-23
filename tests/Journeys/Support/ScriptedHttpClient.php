<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

// The test environment's HTTP client: with a script directory it answers every call from the armed script after holding it for the script's weight, so a consumer's model calls overlap measurably and reach no provider; without one it is the real client.
final readonly class ScriptedHttpClient implements HttpClientInterface, ResetInterface
{
    private MockHttpClient $scripted;

    public function __construct(
        private HttpClientInterface $inner,
        private ClockInterface $clock,
        private string $directory,
        private KeptScript $kept = new KeptScript(),
        ?MockHttpClient $scripted = null,
    ) {
        $this->scripted = $scripted ?? new MockHttpClient($this->answer(...));
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ('' === $this->directory) {
            return $this->inner->request($method, $url, $options);
        }

        return $this->scripted->request($method, $url, $options);
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ('' === $this->directory) {
            return $this->inner->stream($responses, $timeout);
        }

        return $this->scripted->stream($responses, $timeout);
    }

    // The scripted client takes the options too, so a scripted request is sent with the same defaults (a base_uri, headers) the real one would be.
    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->clock, $this->directory, $this->kept, $this->scripted->withOptions($options));
    }

    // Passed on to the real client, since this decorator took over its kernel.reset tag; the kept script is left alone on purpose.
    #[Override]
    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    private function answer(): MockResponse
    {
        $scripts = new ScriptDirectory($this->directory, $this->clock);
        ['weight' => $weight, 'answers' => $answers] = $this->kept->readFrom($scripts);
        $call = $scripts->callStarted();
        $this->clock->sleep($weight);
        $scripts->callEnded($call);
        $answer = $answers[($call - 1) % count($answers)];

        return new MockResponse($answer['body'], ['response_headers' => ['content-type' => $answer['content_type']]]);
    }
}
