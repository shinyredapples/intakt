<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Every call with the installation's token passes here, so none skips the installation's pause or leaves its budget unrecorded.
final readonly class GitHubCalls
{
    public function __construct(
        #[Target('github.client')]
        private HttpClientInterface $http,
        private RateLimitReadings $readings,
        private InstallationTokens $tokens,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ForgeRateLimited
     * @throws ExceptionInterface
     */
    public function asInstallation(string $method, string $url, #[SensitiveParameter] array $options): ResponseInterface
    {
        return $this->inPool(RateLimitReadings::CORE, fn (): ResponseInterface => $this->http->request($method, $url, $options));
    }

    // Apart from the REST calls, because GitHub counts GraphQL queries in their own pool.
    /**
     * @param array<string, mixed> $options
     *
     * @throws ForgeRateLimited
     * @throws ExceptionInterface
     */
    public function query(#[SensitiveParameter] array $options): ResponseInterface
    {
        return $this->inPool(RateLimitReadings::GRAPHQL, fn (): ResponseInterface => $this->http->request('POST', '/graphql', $options));
    }

    // Outside the installation's pause, because a call with the App's own proof spends the App's budget, not the installation's.
    /**
     * @param array<string, mixed> $options
     *
     * @throws ExceptionInterface
     */
    public function asApp(string $url, #[SensitiveParameter] array $options): ResponseInterface
    {
        return $this->http->request('GET', $url, $options);
    }

    /**
     * @param callable(): ResponseInterface $request
     *
     * @throws ForgeRateLimited
     * @throws ExceptionInterface
     */
    private function inPool(string $pool, callable $request): ResponseInterface
    {
        $installationId = $this->tokens->installationId;
        $this->readings->refuseWhileLimited($installationId, $pool);

        $response = $request();
        // The request's pool only where GitHub names none, because the pool GitHub names is the one that counted the call.
        $this->readings->observe($installationId, $response->getStatusCode(), $response->getHeaders(false) + ['x-ratelimit-resource' => [$pool]]);

        return $response;
    }
}
