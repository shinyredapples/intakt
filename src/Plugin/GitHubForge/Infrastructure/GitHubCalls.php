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
        $installationId = $this->tokens->installationId;
        $this->readings->refuseWhileLimited($installationId);

        $response = $this->http->request($method, $url, $options);
        $this->readings->observe($installationId, $response->getStatusCode(), $response->getHeaders(false));

        return $response;
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
}
