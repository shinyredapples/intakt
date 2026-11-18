<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

// Lets the MCP SDK's own HTTP client talk to the kernel in the test process, so no server has to listen.
final readonly class KernelHttpClient implements ClientInterface
{
    public function __construct(private HttpKernelInterface $kernel)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->kernel->handle($this->requestFrom($request));

        return new Response($response->getStatusCode(), $this->headersOf($response->headers->all()), $this->bodyOf($response));
    }

    private function requestFrom(RequestInterface $request): Request
    {
        $server = ['HTTP_HOST' => 'localhost'];

        foreach ($request->getHeaders() as $name => $values) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }

        $server['CONTENT_TYPE'] = $request->getHeaderLine('Content-Type');

        return Request::create(
            (string) $request->getUri(),
            $request->getMethod(),
            server: $server,
            content: (string) $request->getBody(),
        );
    }

    /**
     * @param array<list<string|null>> $headers
     *
     * @return array<list<string>>
     */
    private function headersOf(array $headers): array
    {
        return array_map(static fn (array $values): array => array_map(strval(...), $values), $headers);
    }

    private function bodyOf(HttpResponse $response): string
    {
        if (!$response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        // The stream flushes between events, so a collecting callback keeps what a plain buffer would pass on to the terminal.
        $body = '';
        ob_start(static function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return $body;
    }
}
