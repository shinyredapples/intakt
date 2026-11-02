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

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sever(): void
    {
        $this->severed = true;
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->severed ? throw new RuntimeException('Connection refused.') : $this->inner->sendRequest($request);
    }
}
