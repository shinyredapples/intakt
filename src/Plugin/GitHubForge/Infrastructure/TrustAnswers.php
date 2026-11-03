<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class TrustAnswers
{
    private const array HEADERS = ['Accept' => 'application/vnd.github+json'];

    public function __construct(
        private GitHubCalls $calls,
    ) {
    }

    /**
     * @return array<mixed>
     *
     * @throws TrustNotChecked
     */
    public function ofApp(string $path, #[SensitiveParameter] string $jwt): array
    {
        return $this->answerTo(fn (): ResponseInterface => $this->calls->asApp($path, ['auth_bearer' => $jwt, 'headers' => self::HEADERS]));
    }

    /**
     * @return array<mixed>
     *
     * @throws TrustNotChecked
     * @throws ForgeRateLimited
     */
    public function ofInstallation(string $path, #[SensitiveParameter] string $token): array
    {
        return $this->answerTo(fn (): ResponseInterface => $this->calls->asInstallation('GET', $path, ['auth_bearer' => $token, 'headers' => self::HEADERS]));
    }

    /**
     * @param Closure(): ResponseInterface $request
     *
     * @return array<mixed>
     */
    private function answerTo(Closure $request): array
    {
        try {
            $response = $request();
            $status = $response->getStatusCode();
            $body = 200 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $failure) {
            throw TrustNotChecked::becauseGitHubWasUnreachable($failure::class);
        }

        return 200 === $status ? $body : throw TrustNotChecked::becauseGitHubRefused($status);
    }
}
