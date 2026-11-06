<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Symfony\Component\HttpClient\Response\MockResponse;

// The provider's side of the wire: it keeps every request body, so a test reads which model, options, tools and messages a call carried.
final class ProviderAnswers
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $requests = [];

    /**
     * @param array{body: string} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($request));
        $this->requests[] = $request;
        $answer = ['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Answer of '.$request['model']], 'finish_reason' => 'stop']]];

        return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}
