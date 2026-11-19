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

// Apart from the pull request reads, because intake lists issues, pull requests among them, and must read every page of them.
final readonly class GitHubIssueReads implements IssueReads
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;
    private const int NOT_MODIFIED = 304;
    private const int UNAUTHORIZED = 401;
    // The answer's Date and an issue's `updated_at` come from different GitHub servers, whose clocks may differ by this much.
    private const int CLOCK_SLACK_SECONDS = 5;

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
            $listed = array_map($this->listed(...), $this->issuesOf($answer['body']));
            // Only a missing next link ends the listing, so a short page that still names one means issues went unread.
            if ($answer['next'] && count($listed) < self::PAGE_SIZE) {
                throw new ForgeReadFailed(sprintf('GitHub answered a short page %d of open issues but names a next page.', $page));
            }

            $issues = [...$issues, ...$listed];
            if (!$answer['next']) {
                $this->confirmUnmoved($path, $validators);

                return ['issues' => $issues, 'pages' => $page];
            }

            if (self::MAX_PAGES === $page) {
                throw new ForgeReadFailed('GitHub lists more open issues than this forge reads.');
            }

            $this->refuseChangedWhileServed($listed, $answer['date'], $page);
            $validators[$page] = $answer['etag'];
            $answer = $this->get($path, $this->page($page + 1));
        }
    }

    // A `304` compares representations, and `updated_at` counts whole seconds, so an issue closed and reopened in the second its page was served looks unchanged while a later page was read without the issue it pushed back; only an issue updated before that second rules this out.
    /**
     * @param list<ListedIssue> $listed
     *
     * @throws ForgeReadFailed
     */
    private function refuseChangedWhileServed(array $listed, ?string $date, int $page): void
    {
        $served = $this->moments->served($date ?? throw new ForgeReadFailed(sprintf('GitHub answered page %d of open issues without a Date.', $page)));
        $recent = array_find($listed, static fn (ListedIssue $issue): bool => $issue->updatedAt > $served->modify(sprintf('-%d seconds', self::CLOCK_SLACK_SECONDS)));
        if (null !== $recent) {
            throw new ForgeReadFailed(sprintf('Issue #%d on page %d of the open issues changed in the seconds GitHub served the page, so a close and reopen while the later pages were read cannot be ruled out.', $recent->number, $page));
        }
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
     * @return array<string, int|string>
     */
    private function page(int $page): array
    {
        return ['state' => 'open', 'per_page' => self::PAGE_SIZE, 'page' => $page];
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
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string, etag: ?string, next: bool, date: ?string}
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

        return ['status' => $status, 'body' => $body, 'etag' => $headers['etag'][0] ?? null, 'next' => $this->namesNext($headers['link'] ?? []), 'date' => $headers['date'][0] ?? null];
    }

    /**
     * @param list<string> $links
     */
    private function namesNext(array $links): bool
    {
        return array_any($links, static fn (string $link): bool => 1 === preg_match('/;\s*rel="?next"?/i', $link));
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
