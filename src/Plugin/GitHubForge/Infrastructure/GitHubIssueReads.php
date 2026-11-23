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

// Apart from the pull request reads, because intake lists issues, pull requests among them, and must read every page of them, while a build reads its one task alone.
final readonly class GitHubIssueReads implements IssueReads
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;
    private const int OK = 200;
    private const int MOVED_PERMANENTLY = 301;
    private const int NOT_MODIFIED = 304;
    private const int UNAUTHORIZED = 401;
    private const int NOT_FOUND = 404;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private IssueFields $fields,
        private BudgetReserve $reserve,
        private ChangedWhileServed $changedWhileServed,
        private EntityTags $entityTags,
        private NextLinks $nextLinks,
    ) {
    }

    public function listOpen(string $project, ?string $validator): IssueListing
    {
        $path = $this->repositories->addressOf($project)->path().'/issues';
        // A slot of its own, because the watcher's listing of the same tick already took the project's.
        $this->reserve->admitListing('issues:'.$project);
        $first = $this->get($path, $this->page(1), $validator);
        if (self::NOT_MODIFIED === $first['status']) {
            return IssueListing::unchanged();
        }

        $issues = $this->allPagesFrom($first, $path);

        // The validator belongs to the first page only, so a list of several pages has none and the next listing is full.
        return IssueListing::listed($issues['issues'], 1 === $issues['pages'] ? $first['etag'] : null);
    }

    // No listing slot, because the budget reserve paces the watcher's routine listings and a build reads its one task once.
    public function readOpen(string $project, int $number): ?ListedIssue
    {
        // Redirects are not followed, because GitHub answers an issue transferred to another repository with a 301 to it, and that issue is no task of this project.
        $answer = $this->answerTo('GET', $this->repositories->addressOf($project)->path().'/issues/'.$number, ['max_redirects' => 0]);
        if (in_array($answer['status'], [self::MOVED_PERMANENTLY, self::NOT_FOUND], true)) {
            return null;
        }

        if (self::OK !== $answer['status']) {
            throw $this->refused($answer['status']);
        }

        $data = $this->issueOf($answer['body']);
        $issue = $this->fields->listedIssueOf($data);

        return $issue->isPullRequest || $issue->number !== $number || !$this->isOpen($data) ? null : $issue;
    }

    /**
     * @return array<string, int|string>
     */
    private function page(int $page): array
    {
        return ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => $page];
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array{status: int, body: string, etag: ?string, next: bool, date: ?string}
     */
    private function get(string $path, array $query, ?string $validator = null): array
    {
        $headers = null === $validator ? [] : ['If-None-Match' => $validator];
        $answer = $this->send('GET', $path, ['query' => $query, 'headers' => $headers]);
        if (self::NOT_MODIFIED !== $answer['status']) {
            return $answer;
        }

        // Without a validator there is no earlier listing a 304 could stand for, so taking it as unchanged would report issues never read.
        return null !== $validator ? ['status' => $answer['status'], 'body' => '', 'etag' => $validator, 'next' => false, 'date' => $answer['date']] : throw new ForgeReadFailed('GitHub answered 304 to a read without a validator.');
    }

    /**
     * @param array{status: int, body: string, etag: ?string, next: bool, date: ?string} $first
     *
     * @return array{issues: list<ListedIssue>, pages: int}
     */
    private function allPagesFrom(array $first, string $path): array
    {
        $issues = [];
        $validators = [];
        $answer = $first;
        for ($page = 1;; ++$page) {
            $listed = array_map($this->fields->listedIssueOf(...), $this->issuesOf($answer['body']));
            // Only a missing next link ends the listing, so a short page that still names one means issues went unread.
            if ($answer['next'] && count($listed) < self::PAGE_SIZE) {
                throw new ForgeReadFailed(sprintf('GitHub answered a short page %d of open issues but names a next page.', $page));
            }

            $issues = [...$issues, ...$listed];
            if (!$answer['next']) {
                $this->changedWhileServed->refuseListedTwice($issues);
                $this->confirmUnmoved($path, $validators);

                return ['issues' => $issues, 'pages' => $page];
            }

            if (self::MAX_PAGES === $page) {
                throw new ForgeReadFailed('GitHub lists more open issues than this forge reads.');
            }

            $this->changedWhileServed->refuse($listed, $answer['date'], $page);
            $validators[$page] = $answer['etag'];
            $answer = $this->get($path, $this->page($page + 1));
        }
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

    // An issue that closes or opens on a page already read moves every later page by one, so an issue at a page boundary is read twice or never; only a 304 for every page read before the last says none of them moved.
    /**
     * @param array<int, ?string> $validators
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function confirmUnmoved(string $path, array $validators): void
    {
        $moved = array_find_key($validators, fn (?string $validator, int $page): bool => self::NOT_MODIFIED !== $this->get($path, $this->page($page), $validator)['status']);
        if (null !== $moved) {
            throw new ForgeReadFailed(sprintf('GitHub did not confirm that page %d of the open issues stayed the same while the later pages were read.', $moved));
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, etag: ?string, next: bool, date: ?string}
     *
     * @throws ForgeRateLimited
     */
    private function send(string $method, string $path, array $options): array
    {
        $answer = $this->answerTo($method, $path, $options);
        $status = $answer['status'];
        $headers = $answer['headers'];

        // Only a whole page counts, because another 2xx such as `206 Partial Content` would list part of a page as all of it.
        if (self::OK !== $status && self::NOT_MODIFIED !== $status) {
            throw $this->refused($status);
        }

        return ['status' => $status, 'body' => $answer['body'], 'etag' => $this->entityTags->checked($headers['etag'][0] ?? null), 'next' => $this->nextLinks->namesNext($headers['link'] ?? [], $this->targetOf($path, $options)), 'date' => $headers['date'][0] ?? null];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function targetOf(string $path, array $options): string
    {
        $query = $options['query'] ?? null;

        return is_array($query) && [] !== $query ? $path.'?'.http_build_query($query, '', '&') : $path;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     *
     * @throws ForgeRateLimited
     */
    private function answerTo(string $method, string $path, array $options): array
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

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    private function refused(int $status): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
    }

    /**
     * @return array<mixed>
     */
    private function issueOf(string $body): array
    {
        $issue = $this->decode($body);
        // `[]` decodes to the same empty array as `{}`, so only the answer's first character tells a list from an object.
        if (!str_starts_with(ltrim($body), '{')) {
            throw new ForgeReadFailed('GitHub answered an issue that is not an object.');
        }

        return $issue;
    }

    /**
     * @param array<mixed> $issue
     */
    private function isOpen(array $issue): bool
    {
        return match ($issue['state'] ?? null) {
            'open' => true,
            'closed' => false,
            default => throw new ForgeReadFailed('GitHub answered an issue whose state is neither open nor closed.'),
        };
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
