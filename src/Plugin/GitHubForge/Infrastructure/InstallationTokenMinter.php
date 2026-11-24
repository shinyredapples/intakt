<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use NoDiscard;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// A failed exchange stops the call, because falling back to another credential would hide a broken identity.
final readonly class InstallationTokenMinter
{
    private const string API = 'https://api.github.com';

    public function __construct(
        private AppJwt $jwt,
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('a minted token that is dropped was minted for nothing')]
    public function mint(string $installationId): InstallationToken
    {
        $answer = $this->exchange($this->jwt->sign(), $installationId);

        return $this->tokenFrom($answer);
    }

    /**
     * @return array<mixed>
     */
    private function exchange(#[SensitiveParameter] string $jwt, string $installationId): array
    {
        try {
            $response = $this->http->request('POST', self::API.'/app/installations/'.rawurlencode($installationId).'/access_tokens', [
                'auth_bearer' => $jwt,
                'timeout' => 5,
                'max_duration' => 15,
                'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'],
            ]);
            $status = $response->getStatusCode();
            $body = 201 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $failure) {
            $this->logger->warning('The GitHub installation token exchange did not complete.', ['installation' => $installationId, 'exception' => $failure]);

            throw InstallationTokenNotMinted::becauseGitHubWasUnreachable($failure::class);
        }

        if (201 !== $status) {
            $this->logger->warning('GitHub refused the installation token exchange.', ['installation' => $installationId, 'status' => $status]);

            throw InstallationTokenNotMinted::becauseGitHubRefused($status);
        }

        return $body;
    }

    /**
     * @param array<mixed> $answer
     */
    private function tokenFrom(#[SensitiveParameter] array $answer): InstallationToken
    {
        // Sensitive because the answer holds the minted token, which must not land in a trace's arguments.
        $token = $answer['token'] ?? null;
        $expiresAt = $this->expiryFrom($answer['expires_at'] ?? null);
        if (!is_string($token) || '' === $token || !$expiresAt instanceof DateTimeImmutable) {
            throw InstallationTokenNotMinted::becauseTheAnswerIsInvalid();
        }

        return new InstallationToken($token, $expiresAt->setTimezone(new DateTimeZone('UTC')));
    }

    private function expiryFrom(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $expiresAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $value, new DateTimeZone('UTC'));

        // Checks the warnings too, because PHP rolls a date that does not exist over into another moment and only warns.
        if (false === $expiresAt || false !== DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $expiresAt;
    }
}
