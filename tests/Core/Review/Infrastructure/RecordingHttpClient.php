<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $sent = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return $this->inner->sendRequest($request);
    }
}
