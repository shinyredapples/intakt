<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use NoDiscard;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class InstallationReader
{
    private const string API = 'https://api.github.com';
    private const int PAGE_SIZE = 100;
    // Short, because the doctor reads it while the network may be half down.
    private const array LIMITS = ['timeout' => 5, 'max_duration' => 15];
    private const array HEADERS = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];

    public function __construct(
        private AppJwt $jwt,
        private InstallationTokens $tokens,
        private GitHubCalls $calls,
    ) {
    }

    /**
     * @throws InstallationNotReadBack
     */
    #[NoDiscard('the installation read back is what the configuration is compared with')]
    public function read(): Installation
    {
        try {
            $token = $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $failure) {
            throw InstallationNotReadBack::becauseNoTokenWasMinted($failure);
        }

        $permissions = $this->permissions($this->jwt->sign());

        return new Installation($permissions, $this->repositories($token));
    }

    /**
     * @return array<string, string>
     */
    private function permissions(#[SensitiveParameter] string $jwt): array
    {
        $url = self::API.'/app/installations/'.rawurlencode($this->tokens->installationId);
        $answer = $this->answerTo(fn (): ResponseInterface => $this->calls->asApp($url, $this->options($jwt)));
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
        $total = null;
        for ($page = 1;; ++$page) {
            $listing = $this->page($page, $token);
            $total ??= $listing['total'];
            $names = [...$names, ...$listing['names']];
            if ($this->isComplete($names, $total, $listing)) {
                return $this->distinct($names);
            }
        }
    }

    /**
     * @return array{names: list<string>, total: int}
     */
    private function page(int $page, #[SensitiveParameter] string $token): array
    {
        $url = self::API.'/installation/repositories?per_page='.self::PAGE_SIZE.'&page='.$page;
        $answer = $this->answerTo(fn (): ResponseInterface => $this->calls->asInstallation('GET', $url, $this->options($token)));
        $listed = $answer['repositories'] ?? null;
        $total = $answer['total_count'] ?? null;
        if (!is_array($listed) || !is_int($total)) {
            throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
        }

        return ['names' => array_values(array_map($this->fullName(...), $listed)), 'total' => $total];
    }

    /**
     * @param list<string>                           $names
     * @param array{names: list<string>, total: int} $listing
     */
    private function isComplete(array $names, int $total, array $listing): bool
    {
        if ($listing['total'] === $total && count($names) === $total) {
            return true;
        }
        // Refused, because a short or shifting listing could hide a repository beyond the configuration.
        if ($listing['total'] !== $total || [] === $listing['names'] || count($names) > $total) {
            throw InstallationNotReadBack::becauseTheListingIsIncomplete(count($names), $listing['total']);
        }

        return false;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function distinct(array $names): array
    {
        if (count(array_unique($names)) !== count($names)) {
            throw InstallationNotReadBack::becauseTheAnswerIsInvalid();
        }

        return $names;
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
     * @return array<string, mixed>
     */
    private function options(#[SensitiveParameter] string $credential): array
    {
        return ['auth_bearer' => $credential, 'headers' => self::HEADERS, ...self::LIMITS];
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
            throw InstallationNotReadBack::becauseGitHubWasUnreachable($failure::class);
        }

        return 200 === $status ? $body : throw InstallationNotReadBack::becauseGitHubRefused($status);
    }
}
