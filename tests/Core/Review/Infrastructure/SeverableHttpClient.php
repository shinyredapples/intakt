<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SeverableHttpClient implements ClientInterface
{
    private bool $severed = false;

    private ?ResponseInterface $answer = null;

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    // Without an answer the connection is refused; with one, every later request gets it instead of the server's.
    public function sever(?ResponseInterface $answer = null): void
    {
        $this->severed = true;
        $this->answer = $answer;
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->severed) {
            return $this->inner->sendRequest($request);
        }

        return $this->answer ?? throw new RuntimeException('Connection refused.');
    }
}
