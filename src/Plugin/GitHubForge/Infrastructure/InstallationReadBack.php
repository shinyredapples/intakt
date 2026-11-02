<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use NoDiscard;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// A failed read stops the start, because trusting an installation nobody could inspect would hide a wrong identity.
final readonly class InstallationReadBack
{
    private const string API = 'https://api.github.com';
    private const int PAGE_SIZE = 100;
    private const array HEADERS = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];

    public function __construct(
        private AppJwt $jwt,
        private InstallationTokens $tokens,
        private HttpClientInterface $http,
        private ExpectedInstallation $expected,
        private string $installationId,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InstallationNotReadBack
     * @throws InstallationDoesNotMatchConfiguration
     */
    #[NoDiscard('an installation that is read back and not looked at proves nothing')]
    public function check(): Installation
    {
        $installation = $this->readBack();
        $differences = $this->expected->differencesFrom($installation);
        if ([] !== $differences) {
            $this->logger->warning('The GitHub App installation does not match the configuration.', ['installation' => $this->installationId, 'differences' => $differences]);

            throw InstallationDoesNotMatchConfiguration::with($differences);
        }

        return $installation;
    }

    private function readBack(): Installation
    {
        try {
            $token = $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $failure) {
            throw InstallationNotReadBack::becauseNoTokenWasMinted($failure->getMessage());
        }

        $permissions = $this->permissions($this->jwt->sign());

        return new Installation($permissions, $this->repositories($token));
    }

    /**
     * @return array<string, string>
     */
    private function permissions(#[SensitiveParameter] string $jwt): array
    {
        $answer = $this->get('/app/installations/'.rawurlencode($this->installationId), $jwt);
        $granted = $answer['permissions'] ?? null;
        if (!is_array($granted)) {
            throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
        }

        $permissions = [];
        foreach ($granted as $name => $level) {
            if (!is_string($name) || !is_string($level)) {
                throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
            }
            $permissions[$name] = $level;
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private function repositories(#[SensitiveParameter] string $token): array
    {
        $names = [];
        for ($page = 1;; ++$page) {
            $answer = $this->get('/installation/repositories?per_page='.self::PAGE_SIZE.'&page='.$page, $token);
            $listed = $answer['repositories'] ?? null;
            $total = $answer['total_count'] ?? null;
            if (!is_array($listed) || !is_int($total)) {
                throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
            }

            $names = [...$names, ...array_values(array_map($this->fullName(...), $listed))];
            if ([] === $listed || count($names) >= $total) {
                return $names;
            }
        }
    }

    private function fullName(mixed $repository): string
    {
        $name = is_array($repository) ? ($repository['full_name'] ?? null) : null;
        if (!is_string($name) || '' === $name) {
            throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
        }

        return $name;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, #[SensitiveParameter] string $credential): array
    {
        try {
            $response = $this->http->request('GET', self::API.$path, ['auth_bearer' => $credential, 'headers' => self::HEADERS]);
            $status = $response->getStatusCode();
            $body = 200 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $failure) {
            $this->logger->warning('The GitHub installation read back did not complete.', ['installation' => $this->installationId, 'error' => $failure::class]);

            throw InstallationNotReadBack::becauseGitHubWasUnreachable($failure::class);
        }

        if (200 !== $status) {
            $this->logger->warning('GitHub refused the installation read back.', ['installation' => $this->installationId, 'status' => $status]);

            throw InstallationNotReadBack::becauseGitHubRefused($status);
        }

        return $body;
    }
}
