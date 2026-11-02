<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use LogicException;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

// The HTTP client of the configured reviewer MCP connection: a configured connection takes fixed headers, and the credential names one round. No reset: the round is set only for the length of one call.
final class ReviewServerHttpClient implements ClientInterface
{
    private ?Uuid $round = null;

    public function __construct(
        private readonly ReviewCredential $credential,
        #[Autowire(service: 'psr18.http_client')]
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * Scoped to one call, so a request outside a review carries no credential and a worker never sends one round's credential for the next.
     *
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    public function during(Uuid $round, Closure $call): mixed
    {
        $this->round = $round;

        try {
            return $call();
        } finally {
            $this->round = null;
        }
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $round = $this->round ?? throw new LogicException('The review server is reached only during a review, with the credential of its round.');

        return $this->http->sendRequest($request->withHeader('Authorization', 'Bearer '.$this->credential->issueFor($round->toRfc4122())));
    }
}
