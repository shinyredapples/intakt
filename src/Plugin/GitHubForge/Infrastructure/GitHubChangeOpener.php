<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final readonly class GitHubChangeOpener implements ChangeOpening
{
    private const string JSON = 'application/vnd.github+json';
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private SecretScan $secrets,
        private WatchedRepositories $repositories,
    ) {
    }

    public function openOrUpdate(ProposedChange $change): ChangeId
    {
        $text = $change->text;

        if ($this->secrets->findsSecretIn($text->title."\n\n".$text->body)) {
            throw ChangeTextHoldsSecret::inTheText();
        }

        $repository = $this->addressOf($change->project);
        $open = $this->numberOfOpenPullRequest($repository, $change->branch);
        $payload = ['title' => $text->title, 'body' => $text->body, 'base' => $change->base];

        // The look-up comes first and a write is never retried, so a crash between the two leaves at most the pull request the next call finds.
        $number = null === $open
            ? $this->numberOf($this->send('POST', $repository->path().'/pulls', [...$payload, 'head' => $change->branch]))
            : $this->numberOf($this->send('PATCH', $repository->path().'/pulls/'.$open, $payload));

        return $repository->pullRequest($number);
    }

    private function addressOf(string $project): RepositoryAddress
    {
        try {
            return $this->repositories->addressOf($project);
        } catch (ForgeReadFailed $unknown) {
            throw new ForgeWriteFailed($unknown->getMessage(), 0, $unknown);
        }
    }

    private function numberOfOpenPullRequest(RepositoryAddress $repository, string $branch): ?int
    {
        $query = ['state' => 'open', 'head' => $repository->headOf($branch), 'per_page' => 100];
        $listed = $this->decoded($this->request('GET', $repository->path().'/pulls', ['query' => $query]));

        if (!array_is_list($listed)) {
            throw new ForgeWriteFailed('GitHub answered the pull request look-up with something other than a list.');
        }

        foreach ($listed as $pull) {
            if (is_array($pull) && is_array($pull['head'] ?? null) && ($pull['head']['ref'] ?? null) === $branch) {
                return $this->numberOf($pull);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $pull
     */
    private function numberOf(array $pull): int
    {
        $number = $pull['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : throw new ForgeWriteFailed('GitHub answered a pull request without a number.');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $payload): array
    {
        return $this->decoded($this->request($method, $path, ['json' => $payload, 'max_retries' => 0]));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options): string
    {
        $options += ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => self::JSON]];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a pull request call.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $body;
    }

    // Not chained, because the trace of a failed call holds the bearer token.
    private function unreachable(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a pull request call (%s).', $failure));
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub answered a pull request call with something other than JSON.', 0, $invalid);
        }

        return is_array($data) ? $data : throw new ForgeWriteFailed('GitHub answered a pull request call with JSON that is not an object or a list.');
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeWriteFailed('No GitHub installation token for a write.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub write before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
