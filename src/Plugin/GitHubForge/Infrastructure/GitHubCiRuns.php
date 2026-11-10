<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// Maps what GitHub answered to runs and nothing else: folding them into pending, red or green is the caller's rule.
final readonly class GitHubCiRuns
{
    private const string CHECK_SUITE_EVENT = 'check_suite';

    /**
     * @param array<mixed> $answer
     *
     * @return list<CiRun>
     */
    public function ofWorkflowRuns(RepositoryAddress $repository, CommitId $head, array $answer): array
    {
        return $this->runsOf($repository, $head, $this->entries($answer, 'workflow_runs'), static fn (array $entry): array => [self::text($entry, 'name'), self::text($entry, 'event')]);
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<CiRun>
     */
    public function ofCheckSuites(RepositoryAddress $repository, CommitId $head, array $answer): array
    {
        // A suite with no check run never starts, so it would keep a head pending for good.
        $suites = array_filter($this->entries($answer, 'check_suites'), static fn (array $suite): bool => 0 !== ($suite['latest_check_runs_count'] ?? null));

        return $this->runsOf($repository, $head, array_values($suites), static fn (array $suite): array => [self::text($suite, 'app', 'name'), self::CHECK_SUITE_EVENT]);
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
     * @param list<array<mixed>>                         $entries
     * @param callable(array<mixed>): array{string, string} $originOf
     *
     * @return list<CiRun>
     */
    private function runsOf(RepositoryAddress $repository, CommitId $head, array $entries, callable $originOf): array
    {
        $runs = [];
        foreach ($entries as $entry) {
            // A rerun or a newer push leaves other heads in the same answer, and they must not decide for this one.
            if (strtolower(self::text($entry, 'head_sha')) !== $head->hash) {
                continue;
            }

            [$name, $event] = $originOf($entry);
            $runs[] = new CiRun(new CiRunOrigin($name, $event, $this->changeOf($repository, $entry)), $this->stateOf($entry), $this->momentOf(self::text($entry, 'created_at')));
        }

        return $runs;
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
        if ('completed' !== self::text($entry, 'status')) {
            return CiRunState::Unfinished;
        }

        return match (self::text($entry, 'conclusion')) {
            'success', 'neutral', 'skipped' => CiRunState::Succeeded,
            'cancelled' => CiRunState::Cancelled,
            default => CiRunState::Failed,
        };
    }

    private function momentOf(string $text): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($text)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $invalid) {
            throw new ForgeReadFailed('GitHub answered a moment this forge cannot read.', $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string ...$keys): string
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }
}
