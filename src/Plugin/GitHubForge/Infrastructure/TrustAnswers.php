<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TrustAnswers
{
    private const array HEADERS = ['Accept' => 'application/vnd.github+json'];

    public function __construct(
        #[Target('github.client')]
        private HttpClientInterface $http,
    ) {
    }

    /**
     * @return array<mixed>
     *
     * @throws TrustNotChecked
     */
    public function get(string $path, #[SensitiveParameter] string $credential): array
    {
        try {
            $response = $this->http->request('GET', $path, ['auth_bearer' => $credential, 'headers' => self::HEADERS]);
            $status = $response->getStatusCode();
            $body = 200 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $failure) {
            throw TrustNotChecked::becauseGitHubWasUnreachable($failure::class);
        }

        return 200 === $status ? $body : throw TrustNotChecked::becauseGitHubRefused($status);
    }
}
