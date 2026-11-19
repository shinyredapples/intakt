<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Generator;
use Override;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

// Answers the Anthropic API from a script directory while JOURNEY_SCRIPT names one, so a journey drives the real reviewer, stop check and account limit of an install without a provider; with no script it passes every request on.
final class ScriptedHttpClient implements HttpClientInterface
{
    private const string ANTHROPIC = 'https://api.anthropic.com/';
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private ?MockHttpClient $scripted = null;

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly ?string $script,
    ) {
    }

    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null === $this->script || '' === $this->script || !str_starts_with($url, self::ANTHROPIC)) {
            return $this->inner->request($method, $url, $options);
        }

        $this->scripted ??= new MockHttpClient(fn (): MockResponse => new MockResponse($this->events(new ScriptDirectory($this->script)), ['response_headers' => ['content-type: text/event-stream']]));

        return $this->scripted->request($method, $url, $options);
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $first = $responses instanceof ResponseInterface ? $responses : (is_array($responses) ? array_first($responses) : null);

        return $first instanceof MockResponse && $this->scripted instanceof MockHttpClient ? $this->scripted->stream($responses, $timeout) : $this->inner->stream($responses, $timeout);
    }

    #[Override]
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->scripted = null;

        return $clone;
    }

    /**
     * @return Generator<int,string>
     */
    private function events(ScriptDirectory $directory): Generator
    {
        $call = $directory->callStarted();
        $text = self::APPROVE;
        $half = intdiv(strlen($text), 2);

        yield $this->event('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_journey', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'output_tokens' => 1]]]);
        yield $this->event('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
        yield $this->delta(substr($text, 0, $half));

        // Between two deltas, because the stop check runs at each one: a request made while the call is held lands at the second.
        $directory->holdWhileScripted();

        yield $this->delta(substr($text, $half));
        yield $this->event('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        yield $this->event('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'output_tokens' => 20]]);
        yield $this->event('message_stop', ['type' => 'message_stop']);

        $directory->callEnded($call);
    }

    private function delta(string $text): string
    {
        return $this->event('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function event(string $name, array $data): string
    {
        return 'event: '.$name."\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
    }
}
