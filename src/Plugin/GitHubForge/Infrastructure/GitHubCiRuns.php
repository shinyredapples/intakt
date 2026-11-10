<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// Maps what GitHub answered to runs and nothing else, because folding them into pending, red or green is the caller's rule.
final readonly class GitHubCiRuns
{
    private const string CHECK_SUITE_EVENT = 'check_suite';

    public function __construct(private GitHubMoments $moments)
    {
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<CiRun>
     */
    public function ofWorkflowRuns(RepositoryAddress $repository, CommitId $head, array $answer): array
    {
        return $this->runsOf($repository, $this->ofHead($head, $this->entries($answer, 'workflow_runs')), fn (array $entry): array => [$this->text($entry, 'name'), $this->text($entry, 'event')]);
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<CiRun>
     */
    public function ofCheckSuites(RepositoryAddress $repository, CommitId $head, array $answer): array
    {
        // A queued suite without check runs is kept, because its first job may still start and the other runs alone would make the head green too early.
        return $this->runsOf($repository, $this->ofHead($head, $this->entries($answer, 'check_suites')), fn (array $suite): array => [$this->text($suite, 'app', 'name'), self::CHECK_SUITE_EVENT]);
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<array<mixed>>
     */
    private function entries(array $answer, string $key): array
    {
        $entries = $answer[$key] ?? null;
        if (!is_array($entries)) {
            throw new ForgeReadFailed(sprintf('GitHub answered without a list of "%s".', $key));
        }

        // GitHub pages at 100 entries, and a head with more cannot be judged from the first page.
        if (count($entries) >= GitHubWatchReads::PAGE_SIZE || (is_int($answer['total_count'] ?? null) && $answer['total_count'] > count($entries))) {
            throw new ForgeReadFailed(sprintf('GitHub listed 100 or more "%s" for one head.', $key));
        }

        return array_map(static fn (mixed $entry): array => is_array($entry) ? $entry : throw new ForgeReadFailed(sprintf('GitHub listed a "%s" entry that is not an object.', $key)), array_values($entries));
    }

    /**
     * @param list<array<mixed>> $entries
     *
     * @return list<array<mixed>>
     */
    private function ofHead(CommitId $head, array $entries): array
    {
        // A rerun or a newer push leaves other heads in the same answer, and they must not decide for this one.
        return array_values(array_filter($entries, fn (array $entry): bool => strtolower($this->text($entry, 'head_sha')) === $head->hash));
    }

    /**
     * @param list<array<mixed>>                            $entries
     * @param callable(array<mixed>): array{string, string} $originOf
     *
     * @return list<CiRun>
     */
    private function runsOf(RepositoryAddress $repository, array $entries, callable $originOf): array
    {
        return array_map(function (array $entry) use ($repository, $originOf): CiRun {
            [$name, $event] = $originOf($entry);

            return new CiRun(new CiRunOrigin($name, $event, $this->changeOf($repository, $entry)), $this->stateOf($entry), $this->moments->parse($this->text($entry, 'created_at')));
        }, $entries);
    }

    /**
     * @param array<mixed> $entry
     */
    private function changeOf(RepositoryAddress $repository, array $entry): ?ChangeId
    {
        $pulls = $entry['pull_requests'] ?? null;
        $first = is_array($pulls) ? ($pulls[0] ?? null) : null;
        if (!is_array($first)) {
            return null;
        }

        $number = $first['number'] ?? null;

        return is_int($number) && $number > 0 ? $repository->pullRequest($number) : throw new ForgeReadFailed('GitHub listed a pull request of a run without its number.');
    }

    /**
     * @param array<mixed> $entry
     */
    private function stateOf(array $entry): CiRunState
    {
        if ('completed' !== $this->text($entry, 'status')) {
            return CiRunState::Unfinished;
        }

        return match ($this->text($entry, 'conclusion')) {
            'success', 'neutral', 'skipped' => CiRunState::Succeeded,
            'cancelled' => CiRunState::Cancelled,
            default => CiRunState::Failed,
        };
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
}
