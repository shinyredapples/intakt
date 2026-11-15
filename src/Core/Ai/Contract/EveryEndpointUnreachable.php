<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

// A TransportExceptionInterface, so it ends a call like the HTTP client's own transport error; the message names only the provider, never the transport error's text, which names the request's URL and so can echo a key.
final class EveryEndpointUnreachable extends RuntimeException implements TransportExceptionInterface
{
    public function __construct(Provider $provider)
    {
        parent::__construct(sprintf('Every account of provider "%s" could not reach its endpoint.', $provider->value));
    }
}
