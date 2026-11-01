<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// A failed exchange stops the call, because falling back to another credential would hide a broken identity.
final readonly class InstallationTokenMinter
{
    private const string API = 'https://api.github.com';

    public function __construct(
        private string $installationId,
        private AppJwt $jwt,
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('a minted token that is dropped was minted for nothing')]
    public function mint(): InstallationToken
    {
        $answer = $this->exchange($this->jwt->sign());

        return $this->tokenFrom($answer);
    }

    /**
     * @return array<mixed>
     */
    private function exchange(string $jwt): array
    {
        try {
            $response = $this->http->request('POST', self::API.'/app/installations/'.rawurlencode($this->installationId).'/access_tokens', [
                'auth_bearer' => $jwt,
                'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'],
            ]);
            $status = $response->getStatusCode();
            $body = 201 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $failure) {
            $this->logger->warning('The GitHub installation token exchange did not complete.', ['installation' => $this->installationId, 'error' => $failure::class]);

            throw InstallationTokenNotMinted::becauseGitHubWasUnreachable($failure);
        }

        if (201 !== $status) {
            $this->logger->warning('GitHub refused the installation token exchange.', ['installation' => $this->installationId, 'status' => $status]);

            throw InstallationTokenNotMinted::becauseGitHubRefused($status);
        }

        return $body;
    }

    /**
     * @param array<mixed> $answer
     */
    private function tokenFrom(array $answer): InstallationToken
    {
        $token = $answer['token'] ?? null;
        $expiresAt = is_string($answer['expires_at'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $answer['expires_at'], new DateTimeZone('UTC')) : false;
        if (!is_string($token) || '' === $token || false === $expiresAt) {
            throw InstallationTokenNotMinted::becauseTheAnswerIsInvalid();
        }

        return new InstallationToken($token, $expiresAt->setTimezone(new DateTimeZone('UTC')));
    }
}
