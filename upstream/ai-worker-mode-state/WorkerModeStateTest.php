<?php

declare(strict_types=1);

namespace Intakt\Upstream\WorkerModeState;

use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Verdict
{
    public string $label = '';
}

final class OwnerTools
{
    public function listProjects(): string
    {
        return 'owner answer';
    }
}

final class ReviewTools
{
    public function readFile(): string
    {
        return 'review answer';
    }
}

// One platform and two MCP servers are built once and serve every request after it, as one worker does; each request gets a new transport, as each HTTP request does.
#[CoversNothing]
final class WorkerModeStateTest extends TestCase
{
    // Warm-up fills lazy caches (the serializer, the session stores); what is measured afterwards must not keep growing.
    private const int WARM_UP_CALLS = 100;

    private const int CALLS = 1_000;

    // Room for the allocator and garbage collector to settle; a leak of even one small object per call over 900 calls is far above it.
    private const int MEMORY_TOLERANCE_BYTES = 262_144;

    public function testASecondRequestInTheSameKernelNeverSeesTheFirstRequestsModel(): void
    {
        $platform = $this->platform();

        foreach (['model-a', 'model-b', 'model-a', 'model-b'] as $model) {
            $answer = $platform->invoke($model, new MessageBag(Message::ofUser('hello')))->asText();

            self::assertSame('answer of '.$model, $answer);
        }
    }

    public function testAPlainRequestAfterAStructuredOneGetsTextNotTheEarlierObject(): void
    {
        $platform = $this->platform();

        $verdict = $platform->invoke('model-a', new MessageBag(Message::ofUser('judge')), ['response_format' => Verdict::class])->asObject();
        $plain = $platform->invoke('model-b', new MessageBag(Message::ofUser('hello')))->asText();

        self::assertInstanceOf(Verdict::class, $verdict);
        self::assertSame('answer of model-b', $plain);
    }

    public function testASecondMcpSessionNeverListsTheFirstSessionsTools(): void
    {
        [$owner, $review] = [$this->ownerServer(), $this->reviewServer()];
        $sessions = [$this->open($owner), $this->open($review), $this->open($owner), $this->open($review)];

        foreach ($sessions as $index => [$server, $session]) {
            $names = $this->toolNames($server, $session);

            self::assertSame(0 === $index % 2 ? ['list_projects'] : ['read_file'], $names);
        }
    }

    public function testASessionOfOneServerIsRefusedByTheOtherServer(): void
    {
        [$ownerServer, $ownerSession] = $this->open($this->ownerServer());
        $review = $this->reviewServer();

        $answer = $this->post($review, $ownerSession, '{"jsonrpc":"2.0","id":3,"method":"tools/list"}');

        self::assertGreaterThanOrEqual(400, $answer['status']);
        self::assertStringNotContainsString('list_projects', $answer['body']);
        self::assertStringNotContainsString('read_file', $answer['body']);
    }

    public function testMemoryStaysFlatOverOneThousandAlternatingCalls(): void
    {
        $platform = $this->platform();
        [$ownerServer, $ownerSession] = $this->open($this->ownerServer());
        [$reviewServer, $reviewSession] = $this->open($this->reviewServer());
        $atWarmUp = 0;

        for ($call = 1; $call <= self::CALLS; ++$call) {
            $this->callOnce($call, $platform, $ownerServer, $ownerSession, $reviewServer, $reviewSession);

            if (self::WARM_UP_CALLS === $call) {
                gc_collect_cycles();
                $atWarmUp = memory_get_usage();
            }
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $atWarmUp;

        self::assertLessThan(self::MEMORY_TOLERANCE_BYTES, $growth, sprintf('memory grew by %d bytes between call %d and call %d', $growth, self::WARM_UP_CALLS, self::CALLS));
    }

    private function callOnce(int $call, PlatformInterface $platform, Server $ownerServer, string $ownerSession, Server $reviewServer, string $reviewSession): void
    {
        // Four turns of one pattern: model a, owner tool, model b, review tool.
        match ($call % 4) {
            0 => self::assertSame('answer of model-a', $platform->invoke('model-a', new MessageBag(Message::ofUser('hello')))->asText()),
            1 => self::assertStringContainsString('owner answer', $this->post($ownerServer, $ownerSession, $this->toolCall('list_projects', $call))['body']),
            2 => self::assertSame('answer of model-b', $platform->invoke('model-b', new MessageBag(Message::ofUser('hello')))->asText()),
            3 => self::assertStringContainsString('review answer', $this->post($reviewServer, $reviewSession, $this->toolCall('read_file', $call))['body']),
        };
    }

    // The generic bridge over a mock HTTP client, because the mock platform's client records every call it gets, which would read as a leak.
    private function platform(): PlatformInterface
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new PlatformSubscriber());
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $text = isset($request['response_format']) ? '{"label":"ok"}' : 'answer of '.$request['model'];
            $answer = ['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];

            return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        }, 'https://localhost');

        return GenericFactory::createPlatform('https://localhost', 'key', $http, eventDispatcher: $events);
    }

    private function ownerServer(): Server
    {
        return Server::builder()->setServerInfo('owner', '1.0.0')->setSession(new InMemorySessionStore())
            ->addTool([new OwnerTools(), 'listProjects'], 'list_projects', description: 'Lists projects')
            ->build();
    }

    private function reviewServer(): Server
    {
        return Server::builder()->setServerInfo('review', '1.0.0')->setSession(new InMemorySessionStore())
            ->addTool([new ReviewTools(), 'readFile'], 'read_file', description: 'Reads a file')
            ->build();
    }

    /**
     * @return array{Server, string}
     */
    private function open(Server $server): array
    {
        $initialize = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"probe","version":"1.0.0"}}}';
        $opened = $this->post($server, null, $initialize);
        self::assertNotSame('', $opened['session']);
        $this->post($server, $opened['session'], '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        return [$server, $opened['session']];
    }

    /**
     * @return list<string>
     */
    private function toolNames(Server $server, string $session): array
    {
        $answer = $this->post($server, $session, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
        $decoded = json_decode(preg_replace('/^.*?data: /s', '', $answer['body']) ?? '', true, flags: JSON_THROW_ON_ERROR);

        return array_column($decoded['result']['tools'], 'name');
    }

    private function toolCall(string $tool, int $id): string
    {
        return sprintf('{"jsonrpc":"2.0","id":%d,"method":"tools/call","params":{"name":"%s","arguments":{}}}', $id, $tool);
    }

    /**
     * @return array{status: int, session: string, body: string}
     */
    private function post(Server $server, ?string $session, string $body): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

        if (null !== $session) {
            $headers['Mcp-Session-Id'] = $session;
            $headers['MCP-Protocol-Version'] = '2025-11-25';
        }

        // A streamed answer is flushed between events, so a collecting callback keeps what a plain buffer would pass on.
        $streamed = '';
        ob_start(static function (string $chunk) use (&$streamed): string {
            $streamed .= $chunk;

            return '';
        });
        $response = $server->run(new StreamableHttpTransport(new ServerRequest('POST', 'http://localhost/mcp', $headers, $body)));
        $text = (string) $response->getBody();
        ob_end_clean();

        return ['status' => $response->getStatusCode(), 'session' => $response->getHeaderLine('Mcp-Session-Id'), 'body' => $streamed.$text];
    }
}
