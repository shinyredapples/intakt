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

    // The keys start differently, so a workflow's key and a suite's key can never be equal.
    private const string WORKFLOW_KEY = 'workflow:';
    private const string SUITE_KEY = 'suite:';

    public function __construct(private GitHubMoments $moments)
    {
    }

    /**
     * @return list<CiRun>
     */
    public function of(RepositoryAddress $repository, CommitId $head, HeadCiAnswers $answers): array
    {
        $workflowRuns = $this->ofHead($head, $this->entries($answers->workflowRuns, 'workflow_runs'));
        $originOfSuite = $this->workflowsBySuite($repository, $workflowRuns);

        return [
            // Two workflows can share a display name and an event, so only the workflow's id tells their runs apart.
            ...$this->runsOf($workflowRuns, fn (array $run): array => [new CiRunOrigin($this->text($run, 'name'), $this->text($run, 'event'), $this->changeOf($repository, $run), $this->workflowOf($run)), $this->stateOf($run)]),
            // A suite of a workflow run takes that run's whole origin, because GitHub often leaves a suite's own pull requests empty; only the suite of a newer run of the same workflow, event and pull request then replaces a suite that never started. Any other suite keeps its own id and is never replaced, because one app can make suites for unrelated checks.
            ...$this->runsOf($this->ofHead($head, $this->entries($answers->checkSuites, 'check_suites')), function (array $suite) use ($repository, $originOfSuite): array {
                $id = $this->positiveNumber($suite, 'id');

                return [$originOfSuite[$id] ?? new CiRunOrigin($this->text($suite, 'app', 'name'), self::CHECK_SUITE_EVENT, $this->changeOf($repository, $suite), self::SUITE_KEY.$id), $this->suiteStateOf($suite)];
            }),
        ];
    }

    /**
     * @param list<array<mixed>> $workflowRuns
     *
     * @return array<int, CiRunOrigin>
     */
    private function workflowsBySuite(RepositoryAddress $repository, array $workflowRuns): array
    {
        $origins = [];
        foreach ($workflowRuns as $run) {
            if (array_key_exists('check_suite_id', $run)) {
                $event = $this->text($run, 'event');
                // The number ends at the colon, so a workflow and an event can never read as another pair, and the key never equals the run's own.
                $origins[$this->positiveNumber($run, 'check_suite_id')] = new CiRunOrigin($this->text($run, 'name'), $event, $this->changeOf($repository, $run), $this->workflowOf($run).':'.$event);
            }
        }

        return $origins;
    }

    /**
     * @param array<mixed> $run
     */
    private function workflowOf(array $run): string
    {
        return self::WORKFLOW_KEY.$this->positiveNumber($run, 'workflow_id');
    }

    /**
     * @param array<mixed> $suite
     */
    private function suiteStateOf(array $suite): CiRunState
    {
        $state = $this->stateOf($suite);

        // Only a count of zero counts as never started, because a queued suite with a check run, or without a count, may still finish red.
        return CiRunState::NotStarted === $state && 0 !== ($suite['latest_check_runs_count'] ?? null) ? CiRunState::Unfinished : $state;
    }

    /**
     * @param array<mixed> $data
     */
    private function positiveNumber(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without a positive "%s".', $key));
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
     * @param list<array<mixed>>                                       $entries
     * @param callable(array<mixed>): array{CiRunOrigin, CiRunState} $runOf
     *
     * @return list<CiRun>
     */
    private function runsOf(array $entries, callable $runOf): array
    {
        return array_map(function (array $entry) use ($runOf): CiRun {
            [$origin, $state] = $runOf($entry);

            return new CiRun($origin, $state, $this->moments->parse($this->text($entry, 'created_at')));
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
        return match ($this->text($entry, 'status')) {
            'queued' => CiRunState::NotStarted,
            'completed' => $this->conclusionOf($entry),
            default => CiRunState::Unfinished,
        };
    }

    /**
     * @param array<mixed> $entry
     */
    private function conclusionOf(array $entry): CiRunState
    {
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
