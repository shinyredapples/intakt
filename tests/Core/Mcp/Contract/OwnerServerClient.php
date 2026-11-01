<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class OwnerServerClient
{
    private ?string $session = null;

    private string $lastBody = '';

    public function __construct(
        private readonly HttpKernelInterface $kernel,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = [], int $id = 1): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        $server = ['HTTP_HOST' => 'localhost', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }

        $response = $this->kernel->handle(Request::create('http://localhost/mcp/owner', 'POST', server: $server, content: $body));
        $this->session ??= $response->headers->get('Mcp-Session-Id');

        $this->lastBody = (string) $response->getContent();

        return $this->decode($this->lastBody);
    }

    public function lastBody(): string
    {
        return $this->lastBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function initialize(string $protocolVersion): array
    {
        $params = ['protocolVersion' => $protocolVersion, 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']];

        return $this->call('initialize', $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $content): array
    {
        // An answer arrives as plain JSON or as one server-sent event, because the server picks per request.
        if (str_starts_with($content, 'event:') || str_contains($content, "\ndata:") || str_starts_with($content, 'data:')) {
            preg_match('/^data: (.*)$/m', $content, $matches);
            $content = $matches[1];
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
