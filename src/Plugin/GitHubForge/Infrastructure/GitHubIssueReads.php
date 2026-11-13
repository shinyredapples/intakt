<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// Apart from the pull request reads, because intake lists issues (pull requests among them) and needs who last edited one and who applied its labels, which only GraphQL answers.
final readonly class GitHubIssueReads implements IssueReads
{
    public const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because reading on has no end a tick could wait for.
    private const int MAX_PAGES = 10;

    public function __construct(
        private InstallationReads $reads,
        private GitHubIssueHistories $histories,
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
        if (InstallationReads::NOT_MODIFIED === $first['status']) {
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
        return $this->histories->of($project, $number);
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
        $issues = $this->reads->decode($answer['body']);
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
     * @param array<string, int|string> $query
     *
     * @return array{status: int, body: string, etag: ?string, next: bool}
     */
    private function get(string $path, array $query, ?string $validator = null): array
    {
        $headers = null === $validator ? [] : ['If-None-Match' => $validator];
        $answer = $this->reads->send('GET', $path, ['query' => $query, 'headers' => $headers]);

        return InstallationReads::NOT_MODIFIED === $answer['status'] && null !== $validator ? ['status' => $answer['status'], 'body' => '', 'etag' => $validator, 'next' => false] : $answer;
    }
}
