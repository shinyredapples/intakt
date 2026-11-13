<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

// The rows are the ones the API and the usage file can give; a first commit or a first release has no source there.
final readonly class GlanceTable
{
    private const int MILLION = 1_000_000;

    /**
     * @param list<Issue>       $issues
     * @param list<PullRequest> $pullRequests
     */
    public function region(array $issues, array $pullRequests, int $tokens, DateTimeImmutable $today): PageRegion
    {
        $merges = array_values(array_filter(array_map(static fn (PullRequest $pullRequest): ?DateTimeImmutable => $pullRequest->merged, $pullRequests)));
        $perDay = array_count_values(array_map(static fn (DateTimeImmutable $merged): string => $merged->format('Y-m-d'), $merges));
        ksort($perDay);

        $rows = [
            ['First merged pull request', [] === $merges ? 'none yet' : min($merges)->format('Y-m-d')],
            ['Pull requests merged', count($merges).' (to '.$today->format('Y-m-d').')'],
            ['Issues closed', $this->closed($issues)],
            ['Busiest day', [] === $perDay ? 'none yet' : max($perDay).' merges ('.array_search(max($perDay), $perDay, true).')'],
            ['Tokens per merged pull request', [] === $merges ? 'none yet' : $this->tokens(intdiv($tokens, count($merges))).' ('.$this->tokens($tokens).' tokens over '.count($merges).' merges)'],
        ];

        return new PageRegion('glance', implode("\n", ['| | |', '|---|---|', ...array_map(static fn (array $row): string => '| '.$row[0].' | '.$row[1].' |', $rows)]));
    }

    /**
     * @param list<Issue> $issues
     */
    private function closed(array $issues): string
    {
        $dropped = count(array_filter($issues, static fn (Issue $issue): bool => $issue->dropped && !$issue->isOpen()));
        $done = count(array_filter($issues, static fn (Issue $issue): bool => $issue->isDone()));

        return ($done + $dropped).' ('.$done.' done, '.$dropped.' dropped)';
    }

    private function tokens(int $tokens): string
    {
        if ($tokens < self::MILLION) {
            return (string) $tokens;
        }

        $millions = $tokens / self::MILLION;

        return ($millions >= 100 ? (string) round($millions) : number_format($millions, 2, '.', '')).'M';
    }
}
