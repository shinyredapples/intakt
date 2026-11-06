<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use UnexpectedValueException;

final class McpServerClient
{
    private ?string $session = null;

    private string $lastBody = '';

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly string $path = '/mcp/owner',
        private readonly ?string $bearer = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    public function call(string $method, array $params = [], int $id = 1): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        $server = ['HTTP_HOST' => 'localhost', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }

        if (null !== $this->bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->bearer;
        }

        $response = $this->kernel->handle(Request::create(
            'http://localhost'.$this->path,
            'POST',
            server: $server,
            content: $body,
        ));
        $this->session ??= $response->headers->get('Mcp-Session-Id');

        $this->lastBody = $response instanceof StreamedResponse ? $this->streamed($response) : (string) $response->getContent();

        return $this->decode($this->lastBody);
    }

    public function lastBody(): string
    {
        return $this->lastBody;
    }

    /**
     * @return array<mixed>
     */
    public function initialize(string $protocolVersion): array
    {
        $params = ['protocolVersion' => $protocolVersion, 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']];

        return $this->call('initialize', $params);
    }

    /**
     * @param array<mixed> $answer
     */
    public static function at(array $answer, string ...$path): mixed
    {
        $value = $answer;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new UnexpectedValueException(sprintf('The answer has no "%s".', implode('.', $path)));
            }

            $value = $value[$key];
        }

        return $value;
    }

    // Progress notifications are sent while the call runs, so the answer is a stream.
    private function streamed(StreamedResponse $response): string
    {
        $sent = '';
        // A callback because the server flushes after each event, which would otherwise pass the text on to the test runner.
        ob_start(static function (string $chunk) use (&$sent): string {
            $sent .= $chunk;

            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return $sent;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $content): array
    {
        // An answer arrives as plain JSON or as server-sent events, because the server picks per request; the last event is the answer, the ones before are notifications.
        if (false !== preg_match_all('/^data: (.*)$/m', $content, $matches) && [] !== $matches[1]) {
            $content = array_last($matches[1]);
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new UnexpectedValueException('The answer is not a JSON object.');
    }
}
