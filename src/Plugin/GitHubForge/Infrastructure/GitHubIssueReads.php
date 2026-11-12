<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
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
    private const string NEXT_LINK = '/<[^>]*>\s*;\s*rel="?next"?/';
    // The last hundred label events, because the label that counts is the one applied last; an older application is not trusted unseen.
    private const string HISTORY_FIELDS = 'author { __typename login } editor { __typename login } timelineItems(last: 100, itemTypes: [LABELED_EVENT]) { nodes { ... on LabeledEvent { actor { __typename login } label { name } } } }';
    private const string HISTORY_QUERY = 'query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { issueOrPullRequest(number: $number) { ... on Issue { '.self::HISTORY_FIELDS.' } ... on PullRequest { '.self::HISTORY_FIELDS.' } } } }';

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

        $issues = $this->issuesOf($first);
        $complete = $this->isLastPage($first, $issues);
        $later = $complete ? [] : $this->laterIssues($repository);

        // The validator belongs to the first page only, so a list of several pages has none and the next listing is full.
        return IssueListing::listed(array_map($this->listed(...), [...$issues, ...$later]), $complete ? $first['etag'] : null);
    }

    public function historyOf(string $project, int $number): IssueHistory
    {
        [$owner, $name] = explode('/', $this->repositories->addressOf($project)->fullName(), 2);
        $body = json_encode(['query' => self::HISTORY_QUERY, 'variables' => ['owner' => $owner, 'name' => $name, 'number' => $number]], JSON_THROW_ON_ERROR);
        $answer = $this->decode($this->send('POST', '/graphql', ['body' => $body])['body']);

        // GitHub answers a field it could not resolve with null beside an error, and a null editor would then pass for the author.
        if ([] !== ($answer['errors'] ?? [])) {
            throw new ForgeReadFailed('GitHub answered the history of an item with errors.');
        }

        $item = $this->at($answer, 'data', 'repository', 'issueOrPullRequest');
        if (!is_array($item) || [] === $item) {
            throw new ForgeReadFailed('GitHub answered without the issue.');
        }

        // Refused without a login, because a text nobody can name is not trusted and a deleted account has none.
        $editor = $this->loginOf($this->at($item, 'editor')) ?? $this->loginOf($this->at($item, 'author'));

        return new IssueHistory($editor ?? throw new ForgeReadFailed('GitHub answered an issue without an editor.'), $this->labelAppliersOf($item));
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, ?string>
     */
    private function labelAppliersOf(array $item): array
    {
        $events = $this->at($item, 'timelineItems', 'nodes');
        if (!is_array($events)) {
            throw new ForgeReadFailed('GitHub answered without the label history.');
        }

        $appliers = [];
        foreach ($events as $event) {
            $label = is_array($event) ? $this->at($event, 'label', 'name') : null;
            if (!is_string($label) || !is_array($event)) {
                throw new ForgeReadFailed('GitHub answered a label event this forge cannot read.');
            }

            // Moved to the end, because the order of the list is the order of the last applications.
            unset($appliers[$label]);
            $appliers[$label] = $this->loginOf($this->at($event, 'actor'));
        }

        return $appliers;
    }

    private function loginOf(mixed $account): ?string
    {
        $login = is_array($account) ? $this->at($account, 'login') : null;
        if (!is_array($account) || !is_string($login) || '' === $login) {
            return null;
        }

        // GraphQL names an app without the suffix the permission check knows it by.
        return 'Bot' === $this->at($account, '__typename') ? $login.'[bot]' : $login;
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
            $answer = $this->get($repository->path().'/issues', $this->page($page));
            $more = $this->issuesOf($answer);
            $issues = [...$issues, ...$more];
            if ($this->isLastPage($answer, $more)) {
                return $issues;
            }
        }

        throw new ForgeReadFailed('GitHub lists more open issues than this forge reads.');
    }

    /**
     * @param array{status: int, body: string, etag: ?string, next: bool} $answer
     *
     * @return list<array<mixed>>
     */
    private function issuesOf(array $answer): array
    {
        $issues = $this->decode($answer['body']);
        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!array_is_list($issues) || !str_starts_with(ltrim($answer['body']), '[')) {
            throw new ForgeReadFailed('GitHub answered a listing that is not a list.');
        }

        // Every page before the last one is full, so a short one that names a next page lost issues on the way.
        if ($answer['next'] && count($issues) < self::PAGE_SIZE) {
            throw new ForgeReadFailed('GitHub answered a short page before the last one.');
        }

        return array_map(static fn (mixed $issue): array => is_array($issue) ? $issue : throw new ForgeReadFailed('GitHub listed an issue that is not an object.'), $issues);
    }

    // Read on after a full page even without a next link, because only a short page proves that nothing follows.
    /**
     * @param array{status: int, body: string, etag: ?string, next: bool} $answer
     * @param list<array<mixed>>                                          $issues
     */
    private function isLastPage(array $answer, array $issues): bool
    {
        return !$answer['next'] && count($issues) < self::PAGE_SIZE;
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
     * @return array{status: int, body: string, etag: ?string, next: bool}
     */
    private function get(string $path, array $query, ?string $validator = null): array
    {
        $headers = null === $validator ? [] : ['If-None-Match' => $validator];
        $answer = $this->send('GET', $path, ['query' => $query, 'headers' => $headers]);

        return self::NOT_MODIFIED === $answer['status'] && null !== $validator ? ['status' => $answer['status'], 'body' => '', 'etag' => $validator, 'next' => false] : $answer;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, etag: ?string, next: bool}
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
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if (self::NOT_MODIFIED !== $status && ($status < 200 || $status >= 300)) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['status' => $status, 'body' => $body, 'etag' => $headers['etag'][0] ?? null, 'next' => 1 === preg_match(self::NEXT_LINK, implode(',', $headers['link'] ?? []))];
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
