<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Symfony\Component\Clock\ClockInterface;

// Maps what GitHub answered to runs and nothing else, because folding them into pending, red or green is the caller's rule.
final readonly class GitHubCiRuns
{
    private const string CHECK_SUITE_EVENT = 'check_suite';
    private const string LINKED_SUITE_PREFIX = 'suite-of-workflow:';
    // The set time after which the design admits a pending head by age (docs/design/05-architecture/08-merge.md#waiting-for-ci), because an app that started nothing by then is not coming.
    private const string ABANDONED_AFTER = '30 minutes';

    public function __construct(private GitHubMoments $moments, private ClockInterface $clock)
    {
    }

    /**
     * @param array{workflow_runs: array<mixed>, check_suites: array<mixed>} $answers
     *
     * @return list<CiRun>
     */
    public function ofHead(RepositoryAddress $repository, CommitId $head, array $answers): array
    {
        $workflowRuns = $this->onHead($head, $this->entries($answers['workflow_runs'], 'workflow_runs'));
        $runsBySuite = $this->runsBySuite($workflowRuns);
        // An app that never starts its suite would keep every head pending, so only unlinked suites age out (Actions' queued workflows stay covered by their runs), and only once built, because a malformed suite must fail the read instead of vanishing.
        $suites = array_map(fn (array $suite): ?CiRun => $this->unlessAbandoned($suite, $this->suiteOf($repository, $suite, $runsBySuite), $runsBySuite), $this->onHead($head, $this->entries($answers['check_suites'], 'check_suites')));

        return [
            ...array_map(fn (array $run): CiRun => $this->workflowRunOf($repository, $run), $workflowRuns),
            ...array_values(array_filter($suites)),
        ];
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
    private function onHead(CommitId $head, array $entries): array
    {
        // A rerun or a newer push leaves other heads in the same answer, and they must not decide for this one.
        return array_values(array_filter($entries, fn (array $entry): bool => strtolower($this->text($entry, 'head_sha')) === $head->hash));
    }

    /**
     * @param list<array<mixed>> $workflowRuns
     *
     * @return array<int, array<mixed>>
     */
    private function runsBySuite(array $workflowRuns): array
    {
        $bySuite = [];
        foreach ($workflowRuns as $run) {
            // A run without the key, or with a null one, links no suite, so that suite keeps an origin of its own and nothing replaces it.
            if (null === ($run['check_suite_id'] ?? null)) {
                continue;
            }

            $suite = $this->positive($run, 'check_suite_id');
            $bySuite[$suite] = isset($bySuite[$suite]) ? throw new ForgeReadFailed('GitHub linked two workflow runs to one check suite.') : $run;
        }

        return $bySuite;
    }

    /**
     * @param array<mixed>             $suite
     * @param array<int, array<mixed>> $runsBySuite
     */
    private function unlessAbandoned(array $suite, CiRun $built, array $runsBySuite): ?CiRun
    {
        $abandoned = !isset($runsBySuite[$this->positive($suite, 'id')])
            && 'queued' === $this->text($suite, 'status')
            && 0 === ($suite['latest_check_runs_count'] ?? null)
            && $this->createdAt($suite) < $this->clock->now()->modify('-'.self::ABANDONED_AFTER);

        return $abandoned ? null : $built;
    }

    /**
     * @param array<mixed> $run
     */
    private function workflowRunOf(RepositoryAddress $repository, array $run): CiRun
    {
        return new CiRun($this->workflowOriginOf($repository, $run), $this->workflowRunStateOf($run), $this->createdAt($run), $this->runNumberOf($run));
    }

    /**
     * @param array<mixed>             $suite
     * @param array<int, array<mixed>> $runsBySuite
     */
    private function suiteOf(RepositoryAddress $repository, array $suite, array $runsBySuite): CiRun
    {
        $id = $this->positive($suite, 'id');
        $run = $runsBySuite[$id] ?? null;
        if (null === $run) {
            // Named by its own ID, because one app's unlinked suites are no proof of one check and must not replace each other.
            return new CiRun(new CiRunOrigin('suite:'.$id, self::CHECK_SUITE_EVENT, $this->changeOf($repository, $suite)), $this->stateOf($suite), $this->createdAt($suite));
        }

        // The run's whole origin, because GitHub often leaves a suite's own pull requests empty and two pull requests must not look like one.
        $origin = $this->workflowOriginOf($repository, $run);

        // Named apart from the runs, so that only a newer suite replaces a suite and a run never replaces its own suite; numbered by its run to order a same-second tie.
        return new CiRun(new CiRunOrigin(self::LINKED_SUITE_PREFIX.$origin->name, $origin->event, $origin->change), $this->linkedSuiteStateOf($suite, $run), $this->createdAt($suite), $this->runNumberOf($run));
    }

    /**
     * @param array<mixed> $run
     */
    private function workflowOriginOf(RepositoryAddress $repository, array $run): CiRunOrigin
    {
        // Named by the workflow's ID, because two workflows may share a display name and must not replace each other's runs.
        return new CiRunOrigin((string) $this->positive($run, 'workflow_id'), $this->text($run, 'event'), $this->changeOf($repository, $run));
    }

    /**
     * @param array<mixed> $entry
     */
    private function workflowRunStateOf(array $entry): CiRunState
    {
        // Only a queued first attempt never started, because a queued rerun keeps the first attempt's creation time.
        return 'queued' === $this->text($entry, 'status') && 1 === $this->positive($entry, 'run_attempt') && !$this->withoutItsPullRequest($entry) ? CiRunState::Queued : $this->stateOf($entry);
    }

    /**
     * @param array<mixed> $suite
     * @param array<mixed> $run
     */
    private function linkedSuiteStateOf(array $suite, array $run): CiRunState
    {
        // Only a queued suite with no check run never started, because one with a check run or without a count may still finish red.
        return 'queued' === $this->text($suite, 'status') && 0 === ($suite['latest_check_runs_count'] ?? null) && !$this->withoutItsPullRequest($run) ? CiRunState::Queued : $this->stateOf($suite);
    }

    /**
     * @param array<mixed> $entry
     */
    private function runNumberOf(array $entry): ?int
    {
        $number = $this->positive($entry, 'run_number');

        // Left out for a pull request run without its pull request, because two unknown pull requests are not one origin and a same-second tie must not order them.
        return $this->withoutItsPullRequest($entry) ? null : $number;
    }

    /**
     * @param array<mixed> $entry
     */
    private function withoutItsPullRequest(array $entry): bool
    {
        // Such a run stays Unfinished, because GitHub leaves out the pull request of a run from a fork and two unknown pull requests are not one origin.
        $pulls = $entry['pull_requests'] ?? null;

        return str_starts_with($this->text($entry, 'event'), 'pull_request') && !is_array(is_array($pulls) ? ($pulls[0] ?? null) : null);
    }

    /**
     * @param array<mixed> $entry
     */
    private function positive(array $entry, string $key): int
    {
        $value = $entry[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without a positive "%s".', $key));
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
    private function createdAt(array $entry): DateTimeImmutable
    {
        return $this->moments->parse($this->text($entry, 'created_at'));
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
