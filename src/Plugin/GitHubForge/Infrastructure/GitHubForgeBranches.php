<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Every answer is checked field by field, because a branch read wrongly could be deleted; a doubt fails the read and cleanup deletes nothing.
final readonly class GitHubForgeBranches implements ForgeBranches
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because a weekly run must end.
    private const int MAX_PAGES = 10;
    private const int OK = 200;
    private const int CREATED = 201;
    private const int NO_CONTENT = 204;
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private GitHubMoments $moments,
    ) {
    }

    public function servesProject(string $project): bool
    {
        return null !== $this->repositories->nameOf($project);
    }

    public function branchesOf(string $project): array
    {
        $path = $this->repositories->addressOf($project)->path();
        $default = $this->text($this->object($this->read($path, [])), 'default_branch');
        $listed = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $branches = $this->list($this->read($path.'/branches', ['per_page' => self::PAGE_SIZE, 'page' => $page]));
            foreach ($branches as $branch) {
                $listed[] = $this->branchOf($branch, $default);
            }

            if (count($branches) < self::PAGE_SIZE) {
                return $listed;
            }
        }

        throw new ForgeReadFailed('GitHub lists more branches than this forge reads.');
    }

    public function changeOf(string $project, ForgeBranch $branch): ?BranchChange
    {
        $address = $this->repositories->addressOf($project);
        $pulls = $this->list($this->read($address->path().'/pulls', ['state' => 'all', 'head' => $address->headOf($branch->name), 'per_page' => self::PAGE_SIZE]));
        $ofHead = array_values(array_filter(array_map($this->changeOfPull(...), $pulls), static fn (array $pull): bool => $pull[0] === $branch->head->hash));
        $changes = array_column($ofHead, 1);
        $open = array_filter($changes, static fn (BranchChange $change): bool => BranchChangeState::Open === $change->state);

        // GitHub lists the newest first, so the first closed one decides once none is open.
        return array_first($open) ?? array_first($changes);
    }

    public function deleteBranch(string $project, ForgeBranch $branch): void
    {
        $path = $this->repositories->addressOf($project)->path();
        $current = $this->text($this->object($this->read($path.'/branches/'.$this->segments($branch->name), [])), 'commit', 'sha');
        // Read again first, because GitHub's ref deletion takes no expected head.
        if ($current !== $branch->head->hash) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" moved since it was listed, so it was not deleted.', $branch->name));
        }

        $status = $this->write('DELETE', $path.'/git/refs/heads/'.$this->segments($branch->name), []);
        $this->expect(self::NO_CONTENT, $status, 'deletion');
    }

    public function restoreBranch(string $project, BranchRestore $restore): void
    {
        // Creating a ref fails when it exists, so a branch made again since is never overwritten; GitHub must still hold the commit, which a pull request's own ref keeps.
        $status = $this->write('POST', $this->repositories->addressOf($project)->path().'/git/refs', ['ref' => 'refs/heads/'.$restore->branch, 'sha' => $restore->head->hash]);
        $this->expect(self::CREATED, $status, 'restore');
    }

    /**
     * @param array<string, int|string> $query
     */
    private function read(string $path, array $query): string
    {
        try {
            $response = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        }

        if (self::OK !== $status) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function object(string $body): array
    {
        $data = $this->decode($body);

        return !array_is_list($data) || [] === $data ? $data : throw new ForgeReadFailed('GitHub answered a list where an object was expected.');
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

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @return list<mixed>
     */
    private function list(string $body): array
    {
        $data = $this->decode($body);

        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        return array_is_list($data) && str_starts_with(ltrim($body), '[') ? $data : throw new ForgeReadFailed('GitHub answered a listing that is not a list.');
    }

    private function branchOf(mixed $branch, string $default): ForgeBranch
    {
        $data = is_array($branch) ? $branch : throw new ForgeReadFailed('GitHub listed a branch that is not an object.');
        $name = $this->text($data, 'name');
        $protected = $data['protected'] ?? null;

        return new ForgeBranch($name, $this->commitOf($this->text($data, 'commit', 'sha')), $name === $default, is_bool($protected) ? $protected : throw new ForgeReadFailed('GitHub listed a branch without "protected".'));
    }

    /**
     * @return array{string, BranchChange}
     */
    private function changeOfPull(mixed $pull): array
    {
        $data = is_array($pull) ? $pull : throw new ForgeReadFailed('GitHub listed a pull request that is not an object.');
        $state = $this->text($data, 'state');
        $mergedAt = $data['merged_at'] ?? null;
        $closedAt = $data['closed_at'] ?? null;

        $change = match (true) {
            'open' === $state => new BranchChange(BranchChangeState::Open, null, $this->labelsOf($data)),
            'closed' === $state && is_string($mergedAt) => new BranchChange(BranchChangeState::Merged, $this->moments->parse($mergedAt), $this->labelsOf($data)),
            'closed' === $state && is_string($closedAt) => new BranchChange(BranchChangeState::ClosedUnmerged, $this->moments->parse($closedAt), $this->labelsOf($data)),
            default => throw new ForgeReadFailed(sprintf('GitHub listed a pull request in the state "%s" without the moment it closed.', $state)),
        };

        return [$this->text($data, 'head', 'sha'), $change];
    }

    /**
     * @param array<mixed> $pull
     *
     * @return list<string>
     */
    private function labelsOf(array $pull): array
    {
        $labels = $pull['labels'] ?? null;

        return is_array($labels) ? array_values(array_map(fn (mixed $label): string => $this->text(is_array($label) ? $label : [], 'name'), $labels)) : throw new ForgeReadFailed('GitHub listed a pull request without "labels".');
    }

    // Each part encoded, because a branch name holds slashes GitHub reads as part of the path.
    private function segments(string $branch): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $branch)));
    }

    /**
     * @param array<string, string> $body
     */
    private function write(string $method, string $path, array $body): int
    {
        try {
            $options = ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json'], ...([] === $body ? [] : ['json' => $body])];

            return $this->calls->asInstallation($method, $path, $options)->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw new ForgeWriteFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        } catch (ForgeReadFailed $noToken) {
            throw new ForgeWriteFailed($noToken->getMessage(), $noToken->getCode(), $noToken);
        }
    }

    private function expect(int $expected, int $status, string $write): void
    {
        if ($expected !== $status) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a branch %s.', $status, $write));
        }
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

    private function commitOf(string $sha): CommitId
    {
        try {
            return new CommitId($sha);
        } catch (CommitIdMalformed $malformed) {
            throw new ForgeReadFailed('GitHub listed a branch whose head is not a commit id.', $malformed->getCode(), $malformed);
        }
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a branch call.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub call before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
