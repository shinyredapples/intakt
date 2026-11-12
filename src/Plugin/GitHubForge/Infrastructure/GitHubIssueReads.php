<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Apart from the pull request reads, because intake lists issues (pull requests among them) and needs who last edited one, which only GraphQL answers.
final readonly class GitHubIssueReads implements IssueReads
{
    public const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;
    private const int NOT_MODIFIED = 304;
    private const int UNAUTHORIZED = 401;
    private const string EDITOR_QUERY = 'query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { issue(number: $number) { author { login } editor { login } } } }';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private GitHubMoments $moments,
        private BudgetReserve $reserve,
    ) {
    }

    public function listOpen(string $project, ?string $validator): IssueListing
    {
        $repository = $this->repositories->addressOf($project);
        // A slot of its own, because the watcher's listing of the same tick already took the project's.
        $this->reserve->admitListing('issues:'.$project);
        $first = $this->get($repository->path().'/issues', $this->page(1), $validator);
        if (self::NOT_MODIFIED === $first['status']) {
            return IssueListing::unchanged();
        }

        $issues = $this->issuesOf($first['body']);
        $complete = count($issues) < self::PAGE_SIZE;
        $later = $complete ? [] : $this->laterIssues($repository);

        // The validator belongs to the first page only, so a list of several pages has none and the next listing is full.
        return IssueListing::listed(array_map($this->listed(...), [...$issues, ...$later]), $complete ? $first['etag'] : null);
    }

    public function lastEditorOf(string $project, int $number): string
    {
        [$owner, $name] = explode('/', $this->repositories->addressOf($project)->fullName(), 2);
        $body = json_encode(['query' => self::EDITOR_QUERY, 'variables' => ['owner' => $owner, 'name' => $name, 'number' => $number]], JSON_THROW_ON_ERROR);
        $answer = $this->decode($this->send('POST', '/graphql', ['body' => $body])['body']);

        $issue = $this->at($answer, 'data', 'repository', 'issue');
        if (!is_array($issue)) {
            throw new ForgeReadFailed('GitHub answered without the issue.');
        }

        // Refused without a login, because a text nobody can name is not trusted and a deleted account has none.
        $login = $this->at($issue, 'editor', 'login') ?? $this->at($issue, 'author', 'login');

        return is_string($login) && '' !== $login ? $login : throw new ForgeReadFailed('GitHub answered an issue without an editor.');
    }

    /**
     * @return array<string, int|string>
     */
    private function page(int $page): array
    {
        return ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => $page];
    }

    /**
     * @return list<array<mixed>>
     */
    private function laterIssues(RepositoryAddress $repository): array
    {
        $issues = [];
        for ($page = 2; $page <= self::MAX_PAGES; ++$page) {
            $more = $this->issuesOf($this->get($repository->path().'/issues', $this->page($page))['body']);
            $issues = [...$issues, ...$more];
            if (count($more) < self::PAGE_SIZE) {
                return $issues;
            }
        }

        throw new ForgeReadFailed('GitHub lists more open issues than this forge reads.');
    }

    /**
     * @return list<array<mixed>>
     */
    private function issuesOf(string $body): array
    {
        $issues = $this->decode($body);
        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!array_is_list($issues) || !str_starts_with(ltrim($body), '[')) {
            throw new ForgeReadFailed('GitHub answered a listing that is not a list.');
        }

        return array_map(static fn (mixed $issue): array => is_array($issue) ? $issue : throw new ForgeReadFailed('GitHub listed an issue that is not an object.'), $issues);
    }

    /**
     * @param array<mixed> $issue
     */
    private function listed(array $issue): ListedIssue
    {
        $number = $issue['number'] ?? null;
        $body = $issue['body'] ?? '';
        $due = $this->at($issue, 'milestone', 'due_on');
        if (!is_int($number) || $number < 1 || !is_string($body)) {
            throw new ForgeReadFailed('GitHub listed an issue this forge cannot read.');
        }

        return new ListedIssue(
            $number,
            $this->text($issue, 'title'),
            $body,
            $this->labelsOf($issue),
            $this->text($issue, 'user', 'login'),
            $this->moments->parse($this->text($issue, 'updated_at')),
            is_string($due) ? $this->moments->parse($due) : null,
            array_key_exists('pull_request', $issue),
        );
    }

    /**
     * @param array<mixed> $issue
     *
     * @return list<string>
     */
    private function labelsOf(array $issue): array
    {
        $labels = $issue['labels'] ?? [];
        if (!is_array($labels)) {
            throw new ForgeReadFailed('GitHub listed an issue with labels this forge cannot read.');
        }

        return array_values(array_map(fn (mixed $label): string => is_array($label) ? $this->text($label, 'name') : throw new ForgeReadFailed('GitHub listed a label that is not an object.'), $labels));
    }

    /**
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data, string ...$keys): string
    {
        $value = $this->at($data, ...$keys);

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
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
    private function get(string $path, array $query, ?string $validator = null): array
    {
        $headers = null === $validator ? [] : ['If-None-Match' => $validator];
        $answer = $this->send('GET', $path, ['query' => $query, 'headers' => $headers]);

        return self::NOT_MODIFIED === $answer['status'] && null !== $validator ? ['status' => $answer['status'], 'body' => '', 'etag' => $validator] : $answer;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, etag: ?string}
     *
     * @throws ForgeRateLimited
     */
    private function send(string $method, string $path, array $options): array
    {
        $options['auth_bearer'] = $this->bearer();
        $options['headers'] = ['Accept' => 'application/vnd.github+json', ...(is_array($options['headers'] ?? null) ? $options['headers'] : [])];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $etag = $response->getHeaders(false)['etag'][0] ?? null;
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if (self::NOT_MODIFIED !== $status && ($status < 200 || $status >= 300)) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['status' => $status, 'body' => $body, 'etag' => $etag];
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
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
}
