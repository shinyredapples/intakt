<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\PullRequestBranches;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// No listing slot, because the budget reserve paces the one routine listing of each tick and intake asks this whenever it queues a task.
final readonly class GitHubPullRequestBranches implements PullRequestBranches
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;
    private const int OK = 200;
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private GitHubMoments $moments,
    ) {
    }

    public function openOf(string $project): array
    {
        $path = $this->repositories->addressOf($project)->path().'/pulls';
        $listed = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $pulls = $this->pageOf($path, $page);
            foreach ($pulls as $pull) {
                $listed[$pull->number] ??= $pull;
            }

            if (count($pulls) < self::PAGE_SIZE) {
                ksort($listed);

                return array_values($listed);
            }
        }

        throw new ForgeReadFailed('GitHub lists more open pull requests than this forge reads.');
    }

    /**
     * @return list<BranchPullRequest>
     */
    private function pageOf(string $path, int $page): array
    {
        $body = $this->read($path, ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => $page]);
        $pulls = $this->decode($body);
        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!array_is_list($pulls) || !str_starts_with(ltrim($body), '[')) {
            throw new ForgeReadFailed('GitHub answered a listing of pull requests that is not a list.');
        }

        return array_map($this->pullRequestOf(...), $pulls);
    }

    /**
     * @param array<string, int|string> $query
     *
     * @throws ForgeRateLimited
     */
    private function read(string $path, array $query): string
    {
        try {
            $response = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        // Only a whole page counts, because another 2xx such as `206 Partial Content` would list part of a page as all of it.
        if (self::OK !== $status) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($data) ? $data : throw new ForgeReadFailed('GitHub answered JSON that is not an object or a list.');
    }

    // Every field is required, because a pull request whose branch or author cannot be read may be the task's and must not pass for none.
    private function pullRequestOf(mixed $pull): BranchPullRequest
    {
        if (!is_array($pull)) {
            throw new ForgeReadFailed('GitHub listed a pull request that is not an object.');
        }

        $number = $pull['number'] ?? null;
        if (!is_int($number) || $number < 1) {
            throw new ForgeReadFailed('GitHub listed a pull request without a number.');
        }

        return new BranchPullRequest($number, $this->text($pull, 'head', 'ref'), $this->text($pull, 'user', 'login'), $this->moments->parse($this->text($pull, 'updated_at')));
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a read.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data, string ...$keys): string
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub listed a pull request without "%s".', implode('.', $keys)));
    }
}
