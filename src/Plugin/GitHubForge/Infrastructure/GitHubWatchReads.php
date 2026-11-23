<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeIdEmpty;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Apart from GitHubForge's reads of one change, because the watcher reads whole listings and heads, through the same installation-token client.
final readonly class GitHubWatchReads implements OpenChanges, HeadChecks
{
    public const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;
    private const int NOT_MODIFIED = 304;
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private GitHubCiRuns $runs,
        private GitHubMoments $moments,
        private BudgetReserve $reserve,
    ) {
    }

    /**
     * @throws ForgeRateLimited
     */
    public function listOpen(string $project, ?string $validator): OpenChangeListing
    {
        $repository = $this->repositories->addressOf($project);
        // Only the listing asks, because the reads of a waiting head's CI are essential and always go on.
        $this->reserve->admitListing($project);
        $first = $this->read($repository->path().'/pulls', ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => 1], $validator);
        if (self::NOT_MODIFIED === $first['status']) {
            return OpenChangeListing::unchanged();
        }

        $pulls = $this->pullsOf($first['body']);
        $complete = count($pulls) < self::PAGE_SIZE;
        $later = $complete ? [] : $this->laterPulls($repository);

        // The validator belongs to the first page only, and a reopened older pull request can fill page two without changing a full page one, so such a list has none and the next listing is full.
        return OpenChangeListing::listed($this->onceEach(array_map($this->openChange(...), [...$pulls, ...$later])), $complete ? $first['etag'] : null);
    }

    public function ciStateOf(string $project, CommitId $head): CiState
    {
        $repository = $this->repositories->addressOf($project);
        $workflowRuns = $this->read($repository->path().'/actions/runs', ['head_sha' => $head->hash, 'per_page' => self::PAGE_SIZE]);
        $suites = $this->read($repository->path().'/commits/'.$head->hash.'/check-suites', ['per_page' => self::PAGE_SIZE]);

        // Mapped together, because a check suite takes the origin of the workflow run it belongs to.
        return new CiState($head, $this->runs->ofHead($repository, $head, ['workflow_runs' => $this->decode($workflowRuns['body']), 'check_suites' => $this->decode($suites['body'])]));
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

    /**
     * @param array<string, int|string> $query
     *
     * @return array{status: int, body: string, etag: ?string}
     */
    private function read(string $path, array $query, ?string $validator = null): array
    {
        $headers = ['Accept' => 'application/vnd.github+json'];
        if (null !== $validator) {
            $headers['If-None-Match'] = $validator;
        }

        try {
            $response = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => $headers]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $etag = $response->getHeaders(false)['etag'][0] ?? null;
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if (self::NOT_MODIFIED === $status && null !== $validator) {
            return ['status' => $status, 'body' => '', 'etag' => $validator];
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['status' => $status, 'body' => $body, 'etag' => $etag];
    }

    /**
     * @return list<array<mixed>>
     */
    private function pullsOf(string $body): array
    {
        $pulls = $this->decode($body);
        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!array_is_list($pulls) || !str_starts_with(ltrim($body), '[')) {
            throw new ForgeReadFailed('GitHub answered a listing that is not a list.');
        }

        return array_map(static fn (mixed $pull): array => is_array($pull) ? $pull : throw new ForgeReadFailed('GitHub listed a pull request that is not an object.'), $pulls);
    }

    /**
     * @return list<array<mixed>>
     */
    private function laterPulls(RepositoryAddress $repository): array
    {
        $pulls = [];
        for ($page = 2; $page <= self::MAX_PAGES; ++$page) {
            $more = $this->pullsOf($this->read($repository->path().'/pulls', ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => $page])['body']);
            $pulls = [...$pulls, ...$more];
            if (count($more) < self::PAGE_SIZE) {
                return $pulls;
            }
        }

        throw new ForgeReadFailed('GitHub lists more open pull requests than this forge reads.');
    }

    /**
     * @param array<mixed> $pull
     */
    private function openChange(array $pull): OpenChange
    {
        try {
            $id = new ChangeId($this->text($pull, 'html_url'));
            // Only an address the forge's other reads accept, so a listed change can be read by them.
            PullRequestAddress::of($id);

            return new OpenChange($id, new CommitId($this->text($pull, 'head', 'sha')), $this->moments->parse($this->text($pull, 'updated_at')));
        } catch (ChangeNotFound|ChangeIdEmpty|CommitIdMalformed $invalid) {
            throw new ForgeReadFailed('GitHub listed a pull request this forge cannot read.', $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * @param list<OpenChange> $changes
     *
     * @return list<OpenChange>
     */
    private function onceEach(array $changes): array
    {
        // Pages shift between reads when a pull request opens or closes, so one pull request can come on two pages and must still be listed once.
        $byId = [];
        foreach ($changes as $change) {
            $byId[$change->id->value] ??= $change;
        }

        return array_values($byId);
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
}
