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
        $workflowOfSuite = $this->workflowsBySuite($workflowRuns);

        return [
            // Two workflows can share a display name and an event, so only the workflow's id tells their runs apart.
            ...$this->runsOf($repository, $workflowRuns, fn (array $run): array => [$this->text($run, 'name'), $this->text($run, 'event'), $this->workflowOf($run)], static fn (): bool => false),
            // A suite of a workflow run takes that run's workflow and trigger event as its key, so only the suite of a newer run of the same workflow and event replaces a suite that never started; any other suite keeps its own id and is never replaced, because one app can make suites for unrelated checks.
            ...$this->runsOf($repository, $this->ofHead($head, $this->entries($answers->checkSuites, 'check_suites')), function (array $suite) use ($workflowOfSuite): array {
                $id = $this->positiveNumber($suite, 'id');

                return [$this->text($suite, 'app', 'name'), self::CHECK_SUITE_EVENT, $workflowOfSuite[$id] ?? self::SUITE_KEY.$id];
            }, $this->hasCheckRuns(...)),
        ];
    }

    /**
     * @param list<array<mixed>> $workflowRuns
     *
     * @return array<int, string>
     */
    private function workflowsBySuite(array $workflowRuns): array
    {
        $workflows = [];
        foreach ($workflowRuns as $run) {
            if (array_key_exists('check_suite_id', $run)) {
                // The number ends at the colon, so a workflow and an event can never read as another pair.
                $workflows[$this->positiveNumber($run, 'check_suite_id')] = $this->workflowOf($run).':'.$this->text($run, 'event');
            }
        }

        return $workflows;
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
    private function hasCheckRuns(array $suite): bool
    {
        // Only a count of zero shows that no job was made: a queued suite with a check run, or without a count, may still finish red.
        return 0 !== ($suite['latest_check_runs_count'] ?? null);
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
     * @param list<array<mixed>>                                    $entries
     * @param callable(array<mixed>): array{string, string, string} $originOf
     * @param callable(array<mixed>): bool                          $hasStarted
     *
     * @return list<CiRun>
     */
    private function runsOf(RepositoryAddress $repository, array $entries, callable $originOf, callable $hasStarted): array
    {
        return array_map(function (array $entry) use ($repository, $originOf, $hasStarted): CiRun {
            [$name, $event, $definition] = $originOf($entry);

            return new CiRun(new CiRunOrigin($name, $event, $this->changeOf($repository, $entry), $definition), $this->stateOf($entry, $hasStarted), $this->moments->parse($this->text($entry, 'created_at')));
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
     * @param array<mixed>                 $entry
     * @param callable(array<mixed>): bool $hasStarted
     */
    private function stateOf(array $entry, callable $hasStarted): CiRunState
    {
        return match ($this->text($entry, 'status')) {
            'queued' => $hasStarted($entry) ? CiRunState::Unfinished : CiRunState::NotStarted,
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
