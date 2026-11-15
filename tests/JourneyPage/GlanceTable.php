<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\FigureKind;
use Intakt\Core\Platform\Contract\Figures;

// Rows are limited to what the API and the usage file can give, because a first commit or a first release has no source there.
final readonly class GlanceTable
{
    private const int MILLION = 1_000_000;

    private Figures $figures;

    public function __construct(private DateTimeImmutable $today)
    {
        $this->figures = new Figures();
    }

    /**
     * @param list<Issue>       $issues
     * @param list<PullRequest> $pullRequests
     */
    public function region(array $issues, array $pullRequests, int $tokens): PageRegion
    {
        $merges = array_values(array_filter(array_map(static fn (PullRequest $pullRequest): ?DateTimeImmutable => $pullRequest->merged, $pullRequests)));
        $perDay = array_count_values(array_map(static fn (DateTimeImmutable $merged): string => $merged->format('Y-m-d'), $merges));
        ksort($perDay);

        $rows = [
            ['First merged pull request', [] === $merges ? 'none yet' : min($merges)->format('Y-m-d')],
            ['Pull requests merged', $this->counted(count($merges)).' (to '.$this->today->format('Y-m-d').')'],
            ['Issues closed', $this->closed($issues)],
            ['Busiest day', [] === $perDay ? 'none yet' : $this->counted(max($perDay)).' merges ('.array_search(max($perDay), $perDay, true).')'],
            ['Tokens per merged pull request', [] === $merges ? 'none yet' : $this->perMerge($tokens, count($merges))],
        ];
        $sources = [new FigureSource(FigureKind::Counted, 'from the pull requests and issues the GitHub API lists'), new FigureSource(FigureKind::Measured, TokenCharts::USAGE)];

        return new PageRegion('glance', implode("\n", [
            '| | |',
            '|---|---|',
            ...array_map(static fn (array $row): string => '| '.$row[0].' | '.$row[1].' |', $rows),
            '',
            implode(' ', array_map(static fn (FigureSource $source): string => $source->line(), $sources)),
        ]));
    }

    private function counted(int $count): string
    {
        return $this->figures->marked((string) $count, FigureKind::Counted);
    }

    /**
     * @param list<Issue> $issues
     */
    private function closed(array $issues): string
    {
        $dropped = count(array_filter($issues, static fn (Issue $issue): bool => $issue->dropped && !$issue->isOpen()));
        $done = count(array_filter($issues, static fn (Issue $issue): bool => $issue->isDone()));

        return $this->counted($done + $dropped).' ('.$done.' done, '.$dropped.' dropped)';
    }

    // Measured tokens over counted merges: the parts stand beside the ratio with their own markers, never as one figure of mixed kinds.
    private function perMerge(int $tokens, int $merges): string
    {
        $measured = fn (int $amount): string => $this->figures->marked($this->tokens($amount), FigureKind::Measured);

        return $measured(intdiv($tokens, $merges)).' ('.$measured($tokens).' tokens over '.$this->counted($merges).' merges)';
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
